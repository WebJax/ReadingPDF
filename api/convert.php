<?php
/**
 * PDF to Audiobook converter — initiate job.
 */

declare(strict_types=1);

session_name('PHPSESSID');
session_start();

header('Content-Type: application/json; charset=UTF-8');

// CSRF Check
$clientToken = $_POST['csrf_token'] ?? '';
$serverToken = $_SESSION['csrf_token'] ?? '';
if (!$serverToken || !hash_equals($serverToken, $clientToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

$jobId = bin2hex(random_bytes(16));
$baseDir = dirname(__DIR__);
$uploadPath = "{$baseDir}/storage/uploads/{$jobId}.pdf";
$jobPath = "{$baseDir}/storage/jobs/{$jobId}.json";
$jobsDir = "{$baseDir}/storage/jobs";

$rawText = trim($_POST['raw_text'] ?? '');

if ($rawText !== '') {
    // Generate text directly to cache so worker skips Gemini extraction
    file_put_contents("{$jobsDir}/{$jobId}_text.txt", $rawText);

    // Create a dummy PDF to satisfy worker arguments
    file_put_contents($uploadPath, "DUMMY_PDF_NOT_USED");
} else {
    // Standard PDF flow
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Upload failed.']);
        exit;
    }

    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $uploadPath)) {
        echo json_encode(['error' => 'Failed to save file.']);
        exit;
    }
}


$voice = $_POST['voice'] ?? 'da-DK-Standard-C';
$speed = (float) ($_POST['speed'] ?? 0.90);

$allowedProviders = ['google', 'openai'];
$ttsProvider = in_array($_POST['tts_provider'] ?? '', $allowedProviders, true)
    ? $_POST['tts_provider']
    : 'google';

$allowedOpenaiModels = ['tts-1', 'tts-1-hd'];
$openaiModel = in_array($_POST['openai_model'] ?? '', $allowedOpenaiModels, true)
    ? $_POST['openai_model']
    : 'tts-1';

// Initialize job state
file_put_contents($jobPath, json_encode([
    'id' => $jobId,
    'step' => 'queued',
    'voice' => $voice,
    'speed' => $speed,
    'tts_provider' => $ttsProvider,
    'openai_model' => $openaiModel,
    'created_at' => time(),
]));

// Spawn background worker
$phpBinary = '/opt/homebrew/bin/php'; // Default common path on modern macOS
if (!file_exists($phpBinary)) {
    $phpBinary = 'php'; // Fallback to PATH
}

$workerScript = "{$baseDir}/bin/worker.php";
$logFile = "{$baseDir}/storage/logs/worker.log";
$cmd = "{$phpBinary} " . escapeshellarg($workerScript) . " " . escapeshellarg($jobId) . " " . escapeshellarg($uploadPath) . " >> " . escapeshellarg($logFile) . " 2>&1 &";
exec($cmd);

echo json_encode(['success' => true, 'job_id' => $jobId]);
