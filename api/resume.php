<?php
/**
 * PDF to Audiobook converter — resume job.
 */

declare(strict_types=1);

session_name('PHPSESSID');
session_start();

header('Content-Type: application/json; charset=UTF-8');

// CSRF Check (optional but good for consistency, if passed)
// If triggered from the generic UI, we expect the frontend to attach CSRF.
$clientToken = $_POST['csrf_token'] ?? '';
$serverToken = $_SESSION['csrf_token'] ?? '';
if (!$serverToken || !hash_equals($serverToken, $clientToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

$jobId = $_POST['job_id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
    echo json_encode(['error' => 'Invalid Job ID.']);
    exit;
}

$baseDir = dirname(__DIR__);
$uploadPath = "{$baseDir}/storage/uploads/{$jobId}.pdf";
$jobPath = "{$baseDir}/storage/jobs/{$jobId}.json";

if (!file_exists($jobPath)) {
    echo json_encode(['error' => 'Job not found.']);
    exit;
}

// Read current job data
$jobData = json_decode(file_get_contents($jobPath), true) ?: [];

// Reset error state and put back in queue
$jobData['step'] = 'queued';
unset($jobData['error']);
file_put_contents($jobPath, json_encode($jobData));

// Spawn background worker again
$phpBinary = '/opt/homebrew/bin/php'; // Default common path on modern macOS
if (!file_exists($phpBinary)) {
    $phpBinary = 'php'; // Fallback to PATH
}

$workerScript = "{$baseDir}/bin/worker.php";
$logFile = "{$baseDir}/storage/logs/worker.log";
$cmd = "{$phpBinary} " . escapeshellarg($workerScript) . " " . escapeshellarg($jobId) . " " . escapeshellarg($uploadPath) . " >> " . escapeshellarg($logFile) . " 2>&1 &";
exec($cmd);

echo json_encode(['success' => true, 'job_id' => $jobId]);
