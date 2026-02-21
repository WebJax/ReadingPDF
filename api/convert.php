<?php
/**
 * PDF to Audiobook converter — backend API endpoint.
 *
 * Accepts a PDF file via POST (multipart/form-data, field name "pdf"),
 * verifies a CSRF token, extracts the readable text using Gemini, converts
 * that text to speech, and returns a JSON payload containing a base64-encoded
 * WAV audio file.
 *
 * The Gemini API key is read from config.php and is NEVER sent to the client.
 */

declare(strict_types=1);

// Allow up to 5 minutes for multi-chunk TTS conversions.
set_time_limit(300);

// ── mbstring requirement check ────────────────────────────────────────────────
// The text-chunking code relies on mb_strlen / mb_str_split.
if (!extension_loaded('mbstring')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'PHP extension "mbstring" is required. Please enable it on the server.']);
    exit;
}

// ── Security headers ────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// ── Session / CSRF ───────────────────────────────────────────────────────────
session_start();

// ── Configuration ────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration missing. Copy config.example.php to config.php and set your API key.']);
    exit;
}
require_once $configFile;

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
    http_response_code(500);
    echo json_encode(['error' => 'Gemini API key is not configured.']);
    exit;
}

// ── Allow POST only ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// ── CSRF check ───────────────────────────────────────────────────────────────
$clientToken = $_POST['csrf_token'] ?? '';
$serverToken = $_SESSION['csrf_token'] ?? '';
if (!$serverToken || !hash_equals($serverToken, $clientToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

// ── File validation ──────────────────────────────────────────────────────────
if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
    ];
    $code = $_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg  = $uploadErrors[$code] ?? 'Unknown upload error.';
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

$file = $_FILES['pdf'];

// Verify this is a genuine HTTP upload (guards against path injection).
if (!is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file upload.']);
    exit;
}

// Validate MIME type using finfo (not trusting the browser-supplied type).
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
if ($mimeType !== 'application/pdf') {
    http_response_code(400);
    echo json_encode(['error' => 'Only PDF files are accepted.']);
    exit;
}

// Limit file size to 20 MB.
$maxBytes = 20 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['error' => 'File is too large. Maximum size is 20 MB.']);
    exit;
}

// ── Read & encode PDF ────────────────────────────────────────────────────────
$pdfBytes = file_get_contents($file['tmp_name']);
if ($pdfBytes === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read uploaded PDF file from temporary storage.']);
    exit;
}
$pdfBase64 = base64_encode($pdfBytes);

// ── Step 1 : Extract clean text from the PDF via Gemini ─────────────────────
$extractedText = extractTextFromPdf($pdfBase64, GEMINI_API_KEY);
if ($extractedText === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to extract text from the PDF. Please try again.']);
    exit;
}

if (trim($extractedText) === '') {
    http_response_code(422);
    echo json_encode(['error' => 'The PDF appears to contain no readable text.']);
    exit;
}

// ── Step 2 : Convert text to speech via Gemini TTS ──────────────────────────
$audioResult = convertTextToSpeech($extractedText, GEMINI_API_KEY);
if ($audioResult === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to convert text to speech. Please try again.']);
    exit;
}

echo json_encode([
    'success'  => true,
    'audio'    => $audioResult['audio'],    // base64-encoded WAV
    'mimeType' => $audioResult['mimeType'], // "audio/wav"
]);
exit;


// ════════════════════════════════════════════════════════════════════════════
// Helper functions
// ════════════════════════════════════════════════════════════════════════════

/**
 * Ask Gemini to extract clean, narration-ready text from a base64-encoded PDF.
 *
 * @return string|false  Extracted text, or false on failure.
 */
function extractTextFromPdf(string $pdfBase64, string $apiKey): string|false
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey);

    $body = json_encode([
        'contents' => [[
            'parts' => [
                [
                    'inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data'      => $pdfBase64,
                    ],
                ],
                [
                    'text' => 'Extract all readable body text from this PDF document. '
                        . 'Remove page numbers, running headers, running footers, footnote markers, '
                        . 'and any purely decorative or navigation elements. '
                        . 'Preserve paragraph breaks. '
                        . 'Return only the cleaned main content text, ready to be read aloud.',
                ],
            ],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => 8192,
            'temperature'     => 0.1,
        ],
    ]);

    $response = geminiPost($url, $body);
    if ($response === false) {
        return false;
    }

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? false;
}

/**
 * Convert text to speech using Gemini TTS.
 * Long texts are automatically split into chunks; the resulting PCM frames are
 * concatenated and wrapped in a single WAV file.
 *
 * @return array{audio:string,mimeType:string}|false
 */
function convertTextToSpeech(string $text, string $apiKey): array|false
{
    // Gemini TTS works best with chunks up to ~4 000 characters.
    $chunks   = splitTextIntoChunks($text, 4000);
    $pcmParts = [];

    foreach ($chunks as $chunk) {
        $pcm = callGeminiTts($chunk, $apiKey);
        if ($pcm === false) {
            return false;
        }
        $pcmParts[] = $pcm;
    }

    $combinedPcm = implode('', $pcmParts);
    $wav         = buildWavFile($combinedPcm);

    return [
        'audio'    => base64_encode($wav),
        'mimeType' => 'audio/wav',
    ];
}

/**
 * Call the Gemini TTS endpoint for a single text chunk.
 *
 * @return string|false  Raw PCM16 bytes, or false on failure.
 */
function callGeminiTts(string $text, string $apiKey): string|false
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key=' . urlencode($apiKey);

    $body = json_encode([
        'contents' => [[
            'parts' => [['text' => $text]],
        ]],
        'generationConfig' => [
            'responseModalities' => ['AUDIO'],
            'speechConfig'       => [
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [
                        'voiceName' => 'Aoede',
                    ],
                ],
            ],
        ],
    ]);

    $response = geminiPost($url, $body);
    if ($response === false) {
        return false;
    }

    $data    = json_decode($response, true);
    $b64Data = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? false;
    if ($b64Data === false) {
        return false;
    }

    return base64_decode($b64Data);
}

/**
 * Perform a POST request to the Gemini REST API.
 *
 * @return string|false  Response body, or false on failure.
 */
function geminiPost(string $url, string $jsonBody): string|false
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 120,
        // Always verify TLS certificates in production.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    return $response;
}

/**
 * Split text into chunks no longer than $maxLen characters,
 * breaking only at sentence or paragraph boundaries where possible.
 *
 * @return string[]
 */
function splitTextIntoChunks(string $text, int $maxLen): array
{
    if (mb_strlen($text) <= $maxLen) {
        return [$text];
    }

    $chunks    = [];
    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $current   = '';

    foreach ($sentences as $sentence) {
        if (mb_strlen($current) + mb_strlen($sentence) + 1 > $maxLen) {
            if ($current !== '') {
                $chunks[] = trim($current);
            }
            // If a single sentence is longer than the limit, hard-split it.
            if (mb_strlen($sentence) > $maxLen) {
                foreach (mb_str_split($sentence, $maxLen) as $part) {
                    $chunks[] = $part;
                }
                $current = '';
            } else {
                $current = $sentence;
            }
        } else {
            $current .= ($current === '' ? '' : ' ') . $sentence;
        }
    }
    if ($current !== '') {
        $chunks[] = trim($current);
    }

    return $chunks;
}

/**
 * Wrap raw PCM16 samples (24 kHz, mono) in a WAV RIFF container.
 */
function buildWavFile(string $pcmData): string
{
    $sampleRate    = 24000;
    $numChannels   = 1;
    $bitsPerSample = 16;
    $byteRate      = $sampleRate * $numChannels * ($bitsPerSample / 8);
    $blockAlign    = $numChannels * ($bitsPerSample / 8);
    $dataSize      = strlen($pcmData);

    $header  = 'RIFF';
    $header .= pack('V', 36 + $dataSize);   // ChunkSize
    $header .= 'WAVE';
    $header .= 'fmt ';
    $header .= pack('V', 16);               // Subchunk1Size (PCM)
    $header .= pack('v', 1);                // AudioFormat  (PCM = 1)
    $header .= pack('v', $numChannels);
    $header .= pack('V', $sampleRate);
    $header .= pack('V', $byteRate);
    $header .= pack('v', $blockAlign);
    $header .= pack('v', $bitsPerSample);
    $header .= 'data';
    $header .= pack('V', $dataSize);

    return $header . $pcmData;
}
