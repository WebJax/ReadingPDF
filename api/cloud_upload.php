<?php
/**
 * Cloud Storage Upload — OneDrive via Microsoft Graph API.
 *
 * Endpoints
 * ─────────
 * POST action=auth_url
 *   CSRF-protected. Accepts job_id, returns {"auth_url": "..."} JSON.
 *   The client should open auth_url in a popup window.
 *
 * GET  (OAuth callback, no explicit action parameter)
 *   Microsoft redirects here with ?code=…&state=…
 *   The server exchanges the code for an access token, uploads the WAV
 *   to the user's OneDrive/Audiobooks folder, and renders a small HTML
 *   result page that calls window.opener.postMessage back to the parent.
 */

declare(strict_types=1);

session_name('PHPSESSID');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$baseDir = dirname(__DIR__);

// Load configuration (tolerant – config.php may not exist in dev).
if (file_exists("{$baseDir}/config.php")) {
    require_once "{$baseDir}/config.php";
}

$clientId     = defined('ONEDRIVE_CLIENT_ID')     ? ONEDRIVE_CLIENT_ID     : '';
$clientSecret = defined('ONEDRIVE_CLIENT_SECRET') ? ONEDRIVE_CLIENT_SECRET : '';
$redirectUri  = defined('ONEDRIVE_REDIRECT_URI')  ? ONEDRIVE_REDIRECT_URI  : '';

// ── Route ─────────────────────────────────────────────────────────────────────

// OAuth callback arrives as a GET request with ?code=…
if (isset($_GET['code']) || isset($_GET['error'])) {
    handleOAuthCallback($baseDir, $clientId, $clientSecret, $redirectUri);
    exit;
}

// AJAX call from the front-end asking for the authorization URL.
$action = $_POST['action'] ?? '';
if ($action === 'auth_url') {
    header('Content-Type: application/json; charset=UTF-8');
    handleAuthUrlRequest($baseDir, $clientId, $redirectUri);
    exit;
}

// Fallback – nothing to do.
http_response_code(400);
echo json_encode(['error' => 'Invalid request.']);

// ── Handlers ──────────────────────────────────────────────────────────────────

function handleAuthUrlRequest(string $baseDir, string $clientId, string $redirectUri): void
{
    // CSRF check.
    $clientToken = $_POST['csrf_token'] ?? '';
    $serverToken = $_SESSION['csrf_token'] ?? '';
    if (!$serverToken || !hash_equals($serverToken, $clientToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token.']);
        return;
    }

    // Validate job_id.
    $jobId = $_POST['job_id'] ?? '';
    if (!$jobId || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid job ID.']);
        return;
    }

    // Confirm the audio file exists.
    $audioPath = "{$baseDir}/storage/audio/{$jobId}.wav";
    if (!file_exists($audioPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Audio file not found.']);
        return;
    }

    // Check that OneDrive is configured.
    if (!$clientId || !$redirectUri) {
        http_response_code(503);
        echo json_encode(['error' => 'OneDrive is not configured on this server. '
            . 'Please add ONEDRIVE_CLIENT_ID and ONEDRIVE_REDIRECT_URI to config.php.']);
        return;
    }

    // Create a one-time nonce that ties this auth request to a specific job.
    $nonce = bin2hex(random_bytes(16));
    if (!isset($_SESSION['cloud_uploads'])) {
        $_SESSION['cloud_uploads'] = [];
    }
    $_SESSION['cloud_uploads'][$nonce] = [
        'job_id'     => $jobId,
        'created_at' => time(),
    ];

    // Prune stale entries (older than 1 hour).
    foreach ($_SESSION['cloud_uploads'] as $key => $entry) {
        if (time() - $entry['created_at'] > 3600) {
            unset($_SESSION['cloud_uploads'][$key]);
        }
    }

    $params = http_build_query([
        'client_id'     => $clientId,
        'response_type' => 'code',
        'redirect_uri'  => $redirectUri,
        'response_mode' => 'query',
        'scope'         => 'Files.ReadWrite',
        'state'         => $nonce,
    ]);

    $authUrl = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?{$params}";
    echo json_encode(['auth_url' => $authUrl]);
}

function handleOAuthCallback(
    string $baseDir,
    string $clientId,
    string $clientSecret,
    string $redirectUri
): void {
    // Provider returned an error.
    if (isset($_GET['error'])) {
        $desc = $_GET['error_description'] ?? $_GET['error'];
        renderResult(false, htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'));
        return;
    }

    $code  = $_GET['code']  ?? '';
    $nonce = $_GET['state'] ?? '';

    if (!$code || !$nonce) {
        renderResult(false, 'Missing OAuth parameters.');
        return;
    }

    // Validate nonce and retrieve the associated job.
    $uploads = $_SESSION['cloud_uploads'] ?? [];
    if (!isset($uploads[$nonce])) {
        renderResult(false, 'Session expired or invalid state. Please try again.');
        return;
    }

    $jobId = $uploads[$nonce]['job_id'];
    unset($_SESSION['cloud_uploads'][$nonce]); // One-time use.

    if (!$clientId || !$clientSecret || !$redirectUri) {
        renderResult(false, 'OneDrive is not fully configured on this server.');
        return;
    }

    // Exchange authorization code for access token.
    $tokenData = httpPost('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]);

    if (empty($tokenData['access_token'])) {
        $err = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Token exchange failed.';
        renderResult(false, htmlspecialchars($err, ENT_QUOTES, 'UTF-8'));
        return;
    }

    $accessToken = $tokenData['access_token'];

    // Locate the audio file.
    $audioPath = "{$baseDir}/storage/audio/{$jobId}.wav";
    if (!file_exists($audioPath)) {
        renderResult(false, 'Audio file not found on server.');
        return;
    }

    // Upload to OneDrive — Microsoft Graph simple upload (≤ 4 MB per the simple PUT
    // endpoint; files larger than that require an upload session via the Graph API).
    // WAV files from a typical audiobook are well within this limit.
    $filename  = 'audiobook_' . $jobId . '_' . date('Y-m-d_His') . '.wav';
    $uploadUrl = "https://graph.microsoft.com/v1.0/me/drive/root:/Audiobooks/{$filename}:/content";

    $fh = fopen($audioPath, 'rb');
    if ($fh === false) {
        renderResult(false, 'Failed to read audio file.');
        return;
    }

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => filesize($audioPath),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: audio/wav',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120, // 2 minutes — generous for large WAV files.
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($status >= 200 && $status < 300) {
        $result = json_decode((string) $body, true);
        $webUrl = $result['webUrl'] ?? null;
        renderResult(true, 'File uploaded to OneDrive successfully!', $webUrl);
    } else {
        $errData = json_decode((string) $body, true);
        $errMsg  = $errData['error']['message'] ?? "Upload failed (HTTP {$status}).";
        renderResult(false, htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8'));
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * HTTP POST helper using cURL; returns the decoded JSON response as an array.
 *
 * @param array<string,string> $data
 * @return array<string,mixed>
 */
function httpPost(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) ($body ?: '{}'), true) ?: [];
}

/**
 * Renders a small self-contained HTML result page shown inside the popup.
 * It also sends a postMessage to the opener so the parent page can react.
 */
function renderResult(bool $success, string $message, ?string $webUrl = null): void
{
    $icon    = $success ? '✅' : '❌';
    $color   = $success ? '#4ade80' : '#ff5c5c';
    $linkHtml = '';
    if ($success && $webUrl !== null) {
        $safeUrl  = htmlspecialchars($webUrl, ENT_QUOTES, 'UTF-8');
        $linkHtml = '<br><a href="' . $safeUrl . '" target="_blank" '
            . 'style="color:#6c7cff;margin-top:.5rem;display:inline-block;">'
            . 'Open in OneDrive →</a>';
    }
    $jsonSuccess = $success ? 'true' : 'false';
    $jsonMessage = json_encode($message);
    $jsonWebUrl  = json_encode($webUrl);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>OneDrive Upload</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f1117;
            color: #e8eaf0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 1.5rem;
            box-sizing: border-box;
        }
        .box  { text-align: center; max-width: 360px; }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        .msg  { color: <?= $color ?>; font-size: .95rem; line-height: 1.5; }
        .close {
            margin-top: 1.5rem;
            padding: .6rem 1.5rem;
            background: #6c7cff;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: .9rem;
        }
        .close:hover { background: #8b97ff; }
    </style>
</head>
<body>
<div class="box">
    <div class="icon"><?= $icon ?></div>
    <div class="msg"><?= $message ?><?= $linkHtml ?></div>
    <button class="close" onclick="window.close()">Close</button>
</div>
<script>
    if (window.opener && !window.opener.closed) {
        window.opener.postMessage({
            type: 'cloud_upload_result',
            success: <?= $jsonSuccess ?>,
            message: <?= $jsonMessage ?>,
            webUrl: <?= $jsonWebUrl ?>
        }, window.location.origin);
    }
</script>
</body>
</html>
    <?php
}
