<?php
/**
 * Poll job status.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$jobId = $_GET['id'] ?? '';
if (!$jobId || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
    echo json_encode(['error' => 'Invalid Job ID']);
    exit;
}

$baseDir = dirname(__DIR__);
$jobPath = "{$baseDir}/storage/jobs/{$jobId}.json";

if (!file_exists($jobPath)) {
    echo json_encode(['error' => 'Job not found']);
    exit;
}

echo file_get_contents($jobPath);
