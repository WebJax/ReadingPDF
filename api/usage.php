<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

$baseDir = dirname(__DIR__);
$usageFile = "{$baseDir}/storage/usage.json";
$month = date('Y-m');

$usage = 0;
if (file_exists($usageFile)) {
    $data = json_decode(file_get_contents($usageFile), true) ?: [];
    $usage = $data[$month] ?? 0;
}

echo json_encode([
    'month' => $month,
    'chars_used' => $usage,
    'limit' => 1000000
]);
