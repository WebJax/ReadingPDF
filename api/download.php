<?php
/**
 * Download finished audio.
 */

declare(strict_types=1);

$jobId = $_GET['id'] ?? '';
if (!$jobId || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
    die("Invalid Job ID");
}

$baseDir = dirname(__DIR__);
$audioPath = "{$baseDir}/storage/audio/{$jobId}.wav";

if (!file_exists($audioPath)) {
    http_response_code(404);
    die("Audio file not found.");
}

header('Content-Type: audio/wav');
header('Content-Disposition: attachment; filename="audiobook.wav"');
header('Content-Length: ' . filesize($audioPath));
readfile($audioPath);
