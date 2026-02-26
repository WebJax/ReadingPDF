<?php
/**
 * PDF to Audiobook converter — download extracted text.
 */

declare(strict_types=1);

session_name('PHPSESSID');
session_start();

$jobId = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
    http_response_code(400);
    echo "Invalid Job ID.";
    exit;
}

$baseDir = dirname(__DIR__);
$textPath = "{$baseDir}/storage/jobs/{$jobId}_text.txt";

if (!file_exists($textPath)) {
    http_response_code(404);
    echo "Text file not found or not yet extracted.";
    exit;
}

header('Content-Description: File Transfer');
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $jobId . '.txt"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($textPath));
readfile($textPath);
exit;
