<?php
/**
 * Background worker for PDF-to-Audiobook conversion.
 * 
 * Usage: php bin/worker.php {job_id} {pdf_path}
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script must be run via CLI.\n");
}

$jobId = $argv[1] ?? die("Missing job_id\n");
$pdfPath = $argv[2] ?? die("Missing pdf_path\n");

$baseDir = dirname(__DIR__);
$jobFile = "{$baseDir}/storage/jobs/{$jobId}.json";
$audioDir = "{$baseDir}/storage/audio";
$configFile = "{$baseDir}/config.php";

$logFile = "{$baseDir}/storage/logs/worker.log";

if (!file_exists($configFile)) {
    logMessage($logFile, "[$jobId] Error: Server configuration missing.");
    updateJob($jobFile, ['step' => 'error', 'error' => 'Server configuration missing.']);
    exit(1);
}
require_once $configFile;

// Provide a safe default for OPENAI_API_KEY if not yet defined in config.php
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', '');
}

/**
 * Log a message to the worker log file.
 */
function logMessage(string $file, string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($file, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Update the job state file.
 */
function updateJob(string $file, array $data): void
{
    $current = [];
    if (file_exists($file)) {
        $current = json_decode(file_get_contents($file), true) ?: [];
    }
    $newData = array_merge($current, $data);
    $newData['updated_at'] = time();
    file_put_contents($file, json_encode($newData));
}

$startTime = microtime(true);
logMessage($logFile, "[$jobId] Starting job for PDF: $pdfPath");

try {
    if (!file_exists($pdfPath)) {
        throw new Exception("PDF file not found at {$pdfPath}");
    }

    $pdfBytes = file_get_contents($pdfPath);
    $pdfBase64 = base64_encode($pdfBytes);
    $estimatedPageCount = estimatePdfPageCount($pdfBytes);

    // Read job config for voice options
    $jobData = [];
    if (file_exists($jobFile)) {
        $jobData = json_decode(file_get_contents($jobFile), true) ?: [];
    }
    $jobVoice = $jobData['voice'] ?? 'da-DK-Standard-C';
    $jobSpeed = (float) ($jobData['speed'] ?? 0.90);
    $jobTtsProvider = $jobData['tts_provider'] ?? 'google';
    $jobOpenaiModel = $jobData['openai_model'] ?? 'tts-1';
    $jobTextOnly = $jobData['text_only'] ?? false;

    // ── Step 1 : Extract text ──────────────────────────────────────────────
    updateJob($jobFile, ['step' => 'extracting', 'elapsed' => round(microtime(true) - $startTime, 2)]);

    $extractedText = '';
    $textCachePath = "{$baseDir}/storage/jobs/{$jobId}_text.txt";

    // Skip Gemini text extraction if we already successfully extracted it previously
    if (file_exists($textCachePath)) {
        $extractedText = file_get_contents($textCachePath);
        logMessage($logFile, "[$jobId] Resuming: Loaded extracted text from cache");
    } else {
        $extractResult = extractTextFromPdf($pdfBase64, GEMINI_API_KEY);
        if (!$extractResult['success']) {
            throw new Exception('Text extraction failed: ' . $extractResult['error']);
        }
        $extractedText = $extractResult['data'];

        // Persist extracted text so we don't have to call Gemini again if TTS fails
        file_put_contents($textCachePath, $extractedText);
    }
    if (trim($extractedText) === '') {
        throw new Exception('The PDF appears to contain no readable text.');
    }

    $charCount = mb_strlen($extractedText);
    $extractionWarning = buildExtractionWarning($estimatedPageCount, $charCount);

    if ($extractionWarning !== null) {
        logMessage($logFile, "[$jobId] Warning: {$extractionWarning}");
    }

    // Track usage
    $usageFile = "{$baseDir}/storage/usage.json";
    $month = date('Y-m');
    $usageData = file_exists($usageFile) ? (json_decode(file_get_contents($usageFile), true) ?: []) : [];
    $usageData[$month] = ($usageData[$month] ?? 0) + $charCount;
    file_put_contents($usageFile, json_encode($usageData));

    $chunks = splitTextIntoChunks($extractedText, 3000);
    $totalChunks = count($chunks);

    updateJob($jobFile, [
        'step' => 'extracted',
        'charCount' => $charCount,
        'estimatedPages' => $estimatedPageCount,
        'charsPerPage' => $estimatedPageCount > 0 ? round($charCount / $estimatedPageCount, 1) : null,
        'warning' => $extractionWarning,
        'totalChunks' => $totalChunks,
        'elapsed' => round(microtime(true) - $startTime, 2)
    ]);

    // ── Text-only shortcut ────────────────────────────────────────────────
    if ($jobTextOnly) {
        updateJob($jobFile, [
            'step' => 'done',
            'text_url' => "api/download_text.php?id={$jobId}",
            'elapsed' => round(microtime(true) - $startTime, 2)
        ]);
        logMessage($logFile, "[$jobId] Text-only job completed successfully.");
        exit(0);
    }

    // ── Step 2 : TTS ───────────────────────────────────────────────────────
    $chunkDir = "{$baseDir}/storage/jobs/{$jobId}_chunks";
    if (!is_dir($chunkDir)) {
        mkdir($chunkDir, 0755, true);
    }

    foreach ($chunks as $i => $chunk) {
        $chunkNum = $i + 1;

        $chunkFile = "{$chunkDir}/chunk_{$chunkNum}.raw";
        if (file_exists($chunkFile)) {
            // Already processed this chunk previously, skip API request
            $audioData = file_get_contents($chunkFile);
            logMessage($logFile, "[$jobId] Resuming: Loaded chunk {$chunkNum} from cache");
        } else {
            updateJob($jobFile, [
                'step' => 'tts_start',
                'chunk' => $chunkNum,
                'totalChunks' => $totalChunks,
                'chunkChars' => mb_strlen($chunk),
                'elapsed' => round(microtime(true) - $startTime, 2)
            ]);

            $result = $jobTtsProvider === 'openai'
                ? callOpenAiTts($chunk, OPENAI_API_KEY, $jobVoice, $jobSpeed, $jobOpenaiModel)
                : callCloudTts($chunk, GEMINI_API_KEY, $jobVoice, $jobSpeed);
            if (!$result['success']) {
                throw new Exception("TTS chunk {$chunkNum}/{$totalChunks} failed: " . $result['error']);
            }

            $audioData = $result['data'];
            // Google Cloud TTS LINEAR16 actually returns a WAV file with a 44-byte header.
            // We strip this header from every chunk so we can seamlessly concatenate the raw PCM,
            // avoiding "click" sounds between sentences.
            if (substr($audioData, 0, 4) === 'RIFF') {
                $audioData = substr($audioData, 44);
            }
            // Save to disk cache
            file_put_contents($chunkFile, $audioData);
        }

        // $pcmParts is no longer needed; appending directly to disk in Step 3

        updateJob($jobFile, [
            'step' => 'tts_done',
            'chunk' => $chunkNum,
            'totalChunks' => $totalChunks,
            'elapsed' => round(microtime(true) - $startTime, 2)
        ]);
    }

    // ── Step 3 : Build WAV (Streamed to avoid OOM) ──────────────────────────
    updateJob($jobFile, ['step' => 'building', 'elapsed' => round(microtime(true) - $startTime, 2)]);

    $outputPath = "{$audioDir}/{$jobId}.wav";
    $outFp = fopen($outputPath, 'wb');
    if (!$outFp) {
        throw new Exception("Could not open output WAV file for writing: {$outputPath}");
    }

    // Write a dummy 44-byte WAV header first. We will overwrite it at the end.
    $dummyHeader = str_repeat("\0", 44);
    fwrite($outFp, $dummyHeader);

    $totalPcmBytes = 0;

    foreach ($chunks as $i => $chunk) {
        $chunkNum = $i + 1;
        $chunkFile = "{$chunkDir}/chunk_{$chunkNum}.raw";

        if (file_exists($chunkFile)) {
            $chunkData = file_get_contents($chunkFile);
            fwrite($outFp, $chunkData);
            $totalPcmBytes += strlen($chunkData);
        } else {
            // Fault tolerance: skip missing chunks instead of crashing the whole assembly
            logMessage($logFile, "[$jobId] Warning: Chunk {$chunkNum} is missing during assembly. Skipping.");
        }
    }

    // Now calculate and write the real WAV header
    $sampleRate = 24000;
    $channels = 1;
    $bits = 16;
    $byteRate = $sampleRate * $channels * ($bits / 8);
    $blockAlign = $channels * ($bits / 8);

    $realHeader = 'RIFF' .
        pack('V', 36 + $totalPcmBytes) .
        'WAVEfmt ' .
        pack('V', 16) .
        pack('v', 1) .
        pack('v', $channels) .
        pack('V', $sampleRate) .
        pack('V', $byteRate) .
        pack('v', $blockAlign) .
        pack('v', $bits) .
        'data' .
        pack('V', $totalPcmBytes);

    // Rewind and overwrite dummy header
    rewind($outFp);
    fwrite($outFp, $realHeader);
    fclose($outFp);

    $finalSize = filesize($outputPath);

    updateJob($jobFile, [
        'step' => 'done',
        'audio_url' => "api/download.php?id={$jobId}",
        'audio_size' => $finalSize,
        'elapsed' => round(microtime(true) - $startTime, 2)
    ]);

    logMessage($logFile, "[$jobId] Job completed successfully in " . round(microtime(true) - $startTime, 2) . "s");

    // Clean up upload is REMOVED to keep intermediate files for debugging.
    // @unlink($pdfPath);

} catch (Throwable $e) {
    $errorMessage = $e->getMessage();

    // Friendly quota error
    if (str_contains($errorMessage, 'Quota exceeded') || str_contains($errorMessage, 'limit: 0')) {
        $errorMessage = "Gemini API kvote overskredet. Vent venligst et minut eller tjek din API-nøgle i Google AI Studio.";
    }

    logMessage($logFile, "[$jobId] Error: $errorMessage");
    updateJob($jobFile, ['step' => 'error', 'error' => $errorMessage]);

    // Clean up upload is REMOVED to keep intermediate files for debugging.
    // @unlink($pdfPath);
    exit(1);
}

// ── Shared Gemini Helpers (Copied from convert.php logic) ────────────────────

function extractTextFromPdf(string $pdfBase64, string $apiKey): array
{
    $url = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . urlencode($apiKey);
    $body = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $pdfBase64]],
                    ['text' => "Træk al brødteksten ud fra dette PDF-dokument på originalsproget. Du SKAL udtrække AL tekst fra ALLE sider i dokumentet. Det er strengt forbudt at opsummere, forkorte, eller udelade noget af indholdet. Spring dog sidetal, bogtitler og standard headers/footers over. Sørg for at den fulde faktiske fortælling eller faglige tekst bliver bevaret fyldestgørende fra start til slut. Teksten skal samles så den hænger naturligt sammen. Undgå at tilføje AI-kommentarer, returner kun selve teksten."]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 65535,
            'temperature' => 0.1
        ]
    ]);
    return geminiPost($url, $body, true);
}

function callCloudTts(string $text, string $apiKey, string $voice = 'da-DK-Standard-C', float $speed = 0.90): array
{
    $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . urlencode($apiKey);
    $body = json_encode([
        'input' => ['text' => $text],
        'voice' => [
            'languageCode' => 'da-DK',
            'name' => $voice
        ],
        'audioConfig' => [
            'audioEncoding' => 'LINEAR16',
            'sampleRateHertz' => 24000,
            'speakingRate' => $speed
        ]
    ]);
    return geminiPost($url, $body, false);
}

function callOpenAiTts(string $text, string $apiKey, string $voice = 'alloy', float $speed = 1.0, string $model = 'tts-1'): array
{
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'OpenAI API key is not configured.'];
    }
    $url = 'https://api.openai.com/v1/audio/speech';
    $body = json_encode([
        'model' => $model,
        'input' => $text,
        'voice' => $voice,
        'response_format' => 'pcm',
        'speed' => $speed
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        return ['success' => false, 'error' => 'cURL error'];
    }
    if ($httpCode !== 200) {
        $data = json_decode($response, true);
        return ['success' => false, 'error' => $data['error']['message'] ?? "HTTP {$httpCode}"];
    }
    // Response is raw PCM (24 kHz, 16-bit, mono) — ready to concatenate directly.
    return ['success' => true, 'data' => $response];
}

function geminiPost(string $url, string $jsonBody, bool $isText): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false)
        return ['success' => false, 'error' => 'cURL error'];
    $data = json_decode($response, true);
    if ($httpCode !== 200)
        return ['success' => false, 'error' => $data['error']['message'] ?? "HTTP {$httpCode}"];

    if ($isText) {
        return ['success' => true, 'data' => $data['candidates'][0]['content']['parts'][0]['text'] ?? null];
    } else {
        // Cloud TTS API returns audio base64 encoded in the "audioContent" key instead of the Gemini structure
        if (isset($data['audioContent'])) {
            return ['success' => true, 'data' => base64_decode($data['audioContent'])];
        }
        $b64 = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
        return ['success' => true, 'data' => $b64 ? base64_decode($b64) : null];
    }
}

function splitTextIntoChunks(string $text, int $maxLen): array
{
    $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if (!$sentences || count($sentences) === 0) {
        return [];
    }

    $chunks = [];
    $currentChunk = '';

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }

        // If a single sentence is longer than maxLen, force-split it safely.
        if (mb_strlen($sentence) > $maxLen) {
            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = '';
            }

            $offset = 0;
            $sentenceLen = mb_strlen($sentence);
            while ($offset < $sentenceLen) {
                $piece = mb_substr($sentence, $offset, $maxLen);
                $chunks[] = $piece;
                $offset += mb_strlen($piece);
            }
            continue;
        }

        $candidate = $currentChunk === '' ? $sentence : ($currentChunk . ' ' . $sentence);
        if (mb_strlen($candidate) <= $maxLen) {
            $currentChunk = $candidate;
        } else {
            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
            }
            $currentChunk = $sentence;
        }
    }

    if ($currentChunk !== '') {
        $chunks[] = $currentChunk;
    }

    return $chunks;
}

function estimatePdfPageCount(string $pdfBytes): int
{
    if ($pdfBytes === '') {
        return 0;
    }

    $matches = [];
    preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes, $matches);
    $count = isset($matches[0]) ? count($matches[0]) : 0;

    return max(0, $count);
}

function buildExtractionWarning(int $estimatedPageCount, int $charCount): ?string
{
    if ($estimatedPageCount < 5) {
        return null;
    }

    $minCharsPerPage = 250;
    $charsPerPage = $charCount / max(1, $estimatedPageCount);

    if ($charsPerPage >= $minCharsPerPage) {
        return null;
    }

    return sprintf(
        'Mulig afkortet tekst: ~%d sider men kun %d tegn (ca. %.1f tegn/side). Prøv konvertering igen eller del PDF i mindre dele.',
        $estimatedPageCount,
        $charCount,
        $charsPerPage
    );
}

function buildWavFile(string $pcmData): string
{
    $sampleRate = 24000;
    $channels = 1;
    $bits = 16;
    $header = 'RIFF' . pack('V', 36 + strlen($pcmData)) . 'WAVEfmt ' . pack('V', 16) . pack('v', 1) . pack('v', $channels) . pack('V', $sampleRate) . pack('V', $sampleRate * $channels * ($bits / 8)) . pack('v', $channels * ($bits / 8)) . pack('v', $bits) . 'data' . pack('V', strlen($pcmData));
    return $header . $pcmData;
}
