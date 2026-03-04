<?php
/**
 * PDF to Audiobook — frontend entry point.
 *
 * Generates a CSRF token stored in the PHP session and renders the single-page
 * UI.  All communication with the Gemini API is handled server-side in
 * api/convert.php — the API key is never exposed to the browser.
 */

declare(strict_types=1);

// ── Security headers ─────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; media-src 'self' blob:; font-src https://fonts.gstatic.com; style-src-elem 'unsafe-inline' https://fonts.googleapis.com;");
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Secure session ──────────────────────────────────────────────────────────
$isLocalDev = isset($_SERVER['HTTP_HOST']) && (str_ends_with($_SERVER['HTTP_HOST'], '.test') || $_SERVER['HTTP_HOST'] === 'localhost');
$isSecure = !$isLocalDev && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_name('PHPSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => $isLocalDev ? 'Lax' : 'Strict',
]);
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$maxMb = 20;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ReadingPDF — PDF to Audiobook</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #0f1117;
            --surface: #1a1d27;
            --surface-2: #242836;
            --primary: #6c7cff;
            --primary-h: #8b97ff;
            --primary-glow: rgba(108, 124, 255, .25);
            --text: #e8eaf0;
            --muted: #8b8fa3;
            --border: #2e3347;
            --danger: #ff5c5c;
            --danger-bg: rgba(255, 92, 92, .1);
            --success: #4ade80;
            --success-bg: rgba(74, 222, 128, .1);
            --warn: #fbbf24;
            --radius: 14px;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 8px 40px rgba(0, 0, 0, .35), 0 0 80px rgba(108, 124, 255, .05);
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 580px;
        }

        h1 {
            font-size: 1.6rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: .25rem;
            background: linear-gradient(135deg, #6c7cff, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtitle {
            text-align: center;
            color: var(--muted);
            font-size: .9rem;
            margin-bottom: 2rem;
        }

        /* ── Drop zone ── */
        #drop-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 2.5rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: border-color .25s, background .25s, box-shadow .25s;
            background: var(--surface-2);
        }

        #drop-zone.drag-over,
        #drop-zone:hover {
            border-color: var(--primary);
            background: rgba(108, 124, 255, .06);
            box-shadow: 0 0 20px var(--primary-glow);
        }

        #drop-zone svg {
            width: 48px;
            height: 48px;
            margin-bottom: .75rem;
            color: var(--primary);
        }

        #drop-zone p {
            font-size: .95rem;
            color: var(--muted);
        }

        #drop-zone strong {
            color: var(--text);
        }

        #file-input {
            display: none;
        }

        #file-name {
            text-align: center;
            font-size: .875rem;
            color: var(--muted);
            margin-top: .6rem;
            min-height: 1.25rem;
            word-break: break-all;
        }

        /* ── Tabs for Input ── */
        .input-tabs {
            display: flex;
            background: var(--surface-2);
            padding: .3rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        .tab-btn {
            flex: 1;
            background: transparent;
            border: none;
            padding: .6rem;
            font-size: .9rem;
            font-weight: 600;
            color: var(--muted);
            border-radius: 8px;
            cursor: pointer;
            transition: all .2s;
        }

        .tab-btn.active {
            background: var(--surface);
            color: var(--text);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .input-pane {
            display: none;
        }

        .input-pane.active {
            display: block;
            animation: paneFade .3s ease;
        }

        @keyframes paneFade {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        #raw-text-input {
            width: 100%;
            height: 180px;
            padding: 1rem;
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            background: rgba(139, 143, 163, .03);
            color: var(--text);
            font-family: inherit;
            font-size: .9rem;
            resize: vertical;
            transition: border-color .2s;
        }

        #raw-text-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .settings-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.25rem;
        }

        .setting-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: .4rem;
        }

        .setting-item label {
            font-size: .85rem;
            font-weight: 600;
            color: var(--muted);
        }

        .setting-item select {
            width: 100%;
            padding: .65rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-2);
            color: var(--text);
            font-size: .9rem;
            outline: none;
            cursor: pointer;
            transition: border-color .2s;
        }

        .setting-item select:focus,
        .setting-item select:hover {
            border-color: var(--primary);
        }

        .usage-badge {
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            font-size: .8rem;
            color: var(--muted);
            background: rgba(139, 143, 163, .05);
            padding: .6rem 1rem;
            border-radius: 99px;
            border: 1px solid var(--border);
            width: max-content;
            margin-left: auto;
            margin-right: auto;
        }

        .usage-badge svg {
            width: 14px;
            height: 14px;
            color: var(--primary);
        }

        /* ── Buttons ── */
        #convert-btn {
            display: block;
            width: 100%;
            margin-top: 1.25rem;
            padding: .8rem;
            border: none;
            border-radius: var(--radius);
            background: linear-gradient(135deg, #6c7cff, #8b5cf6);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .2s, box-shadow .2s, transform .1s;
            box-shadow: 0 4px 15px rgba(108, 124, 255, .3);
        }

        #convert-btn:hover:not(:disabled) {
            box-shadow: 0 6px 25px rgba(108, 124, 255, .45);
            transform: translateY(-1px);
        }

        #convert-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* ── Progress section ── */
        #progress-section {
            margin-top: 1.5rem;
            display: none;
        }

        .progress-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .progress-header .elapsed {
            font-size: .8rem;
            color: var(--muted);
            font-variant-numeric: tabular-nums;
        }

        .progress-header .eta {
            font-size: .8rem;
            color: var(--primary-h);
            font-variant-numeric: tabular-nums;
        }

        /* Overall progress bar */
        .progress-bar-track {
            height: 6px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
            margin-bottom: 1.25rem;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #6c7cff, #a78bfa);
            border-radius: 99px;
            width: 0%;
            transition: width .4s ease;
        }

        /* Step list */
        .steps {
            list-style: none;
        }

        .steps li {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            padding: .55rem 0;
            font-size: .88rem;
            color: var(--muted);
            transition: color .3s;
        }

        .steps li.active {
            color: var(--text);
        }

        .steps li.done {
            color: var(--muted);
        }

        .step-icon {
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--border);
            transition: border-color .3s, background .3s;
            margin-top: 1px;
        }

        .steps li.active .step-icon {
            border-color: var(--primary);
            background: var(--primary-glow);
        }

        .steps li.done .step-icon {
            border-color: var(--success);
            background: var(--success-bg);
        }

        /* Spinner inside active icon */
        .step-icon .spinner {
            width: 12px;
            height: 12px;
            border: 2px solid transparent;
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin .8s linear infinite;
            display: none;
        }

        .steps li.active .step-icon .spinner {
            display: block;
        }

        /* Check inside done icon */
        .step-icon .check {
            width: 10px;
            height: 10px;
            color: var(--success);
            display: none;
        }

        .steps li.done .step-icon .check {
            display: block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .step-content {
            flex: 1;
            min-width: 0;
        }

        .step-label {
            font-weight: 500;
        }

        .step-detail {
            font-size: .78rem;
            color: var(--muted);
            margin-top: 2px;
            font-variant-numeric: tabular-nums;
        }

        .steps li.active .step-detail {
            color: var(--primary-h);
        }

        /* TTS chunk sub-progress */
        .chunk-bar-track {
            height: 4px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
            margin-top: 6px;
            max-width: 100%;
        }

        .chunk-bar-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 99px;
            width: 0%;
            transition: width .3s ease;
        }

        /* ── Result ── */
        #result-section {
            margin-top: 1.75rem;
            display: none;
        }

        #result-section h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .result-stats {
            font-size: .8rem;
            color: var(--muted);
            margin-bottom: 1rem;
            display: flex;
            gap: 1.5rem;
        }

        .result-stats span {
            display: flex;
            align-items: center;
            gap: .35rem;
        }

        audio {
            width: 100%;
            border-radius: 8px;
            margin-bottom: 1rem;
            filter: invert(1) hue-rotate(180deg);
        }

        #download-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            width: 100%;
            padding: .7rem;
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            background: transparent;
            color: var(--primary);
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .2s, color .2s, box-shadow .2s;
        }

        #download-btn:hover {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 15px rgba(108, 124, 255, .3);
        }

        #download-text-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            width: 100%;
            padding: .7rem;
            border: 2px solid var(--muted);
            border-radius: var(--radius);
            background: transparent;
            color: var(--muted);
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .2s, color .2s, box-shadow .2s;
            margin-top: 0.75rem;
        }

        #download-text-btn:hover {
            border-color: var(--text);
            color: var(--text);
            background: rgba(255, 255, 255, 0.05);
        }

        /* ── Error ── */
        #error-section {
            margin-top: 1.25rem;
            display: none;
            padding: .85rem 1rem;
            background: var(--danger-bg);
            border: 1px solid rgba(255, 92, 92, .3);
            border-radius: var(--radius);
            color: var(--danger);
            font-size: .9rem;
        }

        #reset-btn {
            display: none;
            margin-top: 1rem;
            width: 100%;
            padding: .6rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: transparent;
            color: var(--muted);
            font-size: .875rem;
            cursor: pointer;
            transition: border-color .2s, color .2s;
        }

        #reset-btn:hover {
            border-color: var(--text);
            color: var(--text);
        }

        #resume-btn {
            display: none;
            width: 100%;
            margin-top: 1rem;
            padding: .8rem;
            border: none;
            border-radius: var(--radius);
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(16, 185, 129, .3);
            transition: transform .1s, box-shadow .2s;
        }

        #resume-btn:hover {
            box-shadow: 0 6px 25px rgba(16, 185, 129, .45);
            transform: translateY(-1px);
        }

        /* ── Cloud upload ── */
        .cloud-upload-section {
            margin-top: 1rem;
        }

        .cloud-upload-label {
            font-size: .78rem;
            color: var(--muted);
            text-align: center;
            margin-bottom: .6rem;
        }

        .cloud-btn-group {
            display: flex;
            gap: .75rem;
        }

        .cloud-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            padding: .6rem .5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface-2);
            color: var(--text);
            font-size: .88rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            font-family: inherit;
        }

        .cloud-btn:hover:not(:disabled) {
            border-color: var(--primary);
            background: rgba(108, 124, 255, .07);
        }

        .cloud-btn:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        #cloud-upload-status {
            margin-top: .65rem;
            font-size: .82rem;
            text-align: center;
            padding: .5rem .75rem;
            border-radius: 8px;
            background: rgba(139, 143, 163, .06);
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>📖 ReadingPDF</h1>
        <p class="subtitle">Upload a PDF and convert it to a spoken-word audio file</p>

        <div id="usage-badge" class="usage-badge" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <span>Brugt denne måned: <strong id="usage-text">...</strong></span>
        </div>

        <form id="upload-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="input-tabs">
                <button type="button" class="tab-btn active" data-target="pane-pdf">Upload PDF</button>
                <button type="button" class="tab-btn" data-target="pane-text">Indsæt Tekst</button>
            </div>

            <div id="pane-pdf" class="input-pane active">
                <div id="drop-zone" role="button" tabindex="0" aria-label="Click or drag and drop a PDF file here">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                    </svg>
                    <p><strong>Click to browse</strong> or drag &amp; drop a PDF</p>
                    <p style="font-size:.8rem;margin-top:.3rem;">Max <?= $maxMb ?> MB</p>
                </div>
                <input type="file" id="file-input" name="pdf" accept="application/pdf">
                <p id="file-name"></p>
            </div>

            <div id="pane-text" class="input-pane">
                <textarea id="raw-text-input" name="raw_text"
                    placeholder="Indsæt artiklens tekst eller din bogtekst her..."></textarea>
                <p
                    style="text-align: center; font-size: .875rem; color: var(--muted); margin-top: .6rem; min-height: 1.25rem;">
                    Tekst indtastet her vil springe AI-fasen over og gå direkte til lyd.
                </p>
            </div>

            <div class="settings-group">
                <div class="setting-item" style="grid-column: 1 / -1;">
                    <label for="tts-provider-select">TTS Udbyder</label>
                    <select id="tts-provider-select" name="tts_provider">
                        <option value="google" selected>Google Cloud TTS</option>
                        <option value="openai">OpenAI TTS</option>
                    </select>
                </div>
                <div class="setting-item" id="google-voice-item">
                    <label for="voice-select">Stemme</label>
                    <select id="voice-select">
                        <option value="da-DK-Journey-D">Journey Mand (AI)</option>
                        <option value="da-DK-Journey-F">Journey Kvinde (AI)</option>
                        <option value="da-DK-Wavenet-C">Wavenet Mand</option>
                        <option value="da-DK-Wavenet-A">Wavenet Kvinde A</option>
                        <option value="da-DK-Wavenet-D">Wavenet Kvinde D</option>
                        <option value="da-DK-Standard-C" selected>Standard Mand</option>
                        <option value="da-DK-Standard-E">Standard Kvinde</option>
                    </select>
                </div>
                <div class="setting-item" id="openai-voice-item" style="display:none;">
                    <label for="openai-voice-select">Stemme</label>
                    <select id="openai-voice-select">
                        <option value="alloy">Alloy (neutral)</option>
                        <option value="echo">Echo (mand)</option>
                        <option value="fable">Fable (mand)</option>
                        <option value="onyx">Onyx (mand)</option>
                        <option value="nova" selected>Nova (kvinde)</option>
                        <option value="shimmer">Shimmer (kvinde)</option>
                    </select>
                </div>
                <div class="setting-item" id="openai-model-item" style="display:none;">
                    <label for="openai-model-select">Kvalitet</label>
                    <select id="openai-model-select" name="openai_model">
                        <option value="tts-1" selected>Standard (tts-1)</option>
                        <option value="tts-1-hd">HD (tts-1-hd)</option>
                    </select>
                </div>
                <div class="setting-item">
                    <label for="speed-select">Hastighed</label>
                    <select id="speed-select" name="speed">
                        <option value="0.80">Meget langsom</option>
                        <option value="0.90" selected>Lidt roligere</option>
                        <option value="1.00">Normal hastighed</option>
                        <option value="1.10">Lidt hurtigere</option>
                    </select>
                </div>
            </div>

            <!-- Text-only checkbox -->
            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" id="text-only-checkbox" name="text_only" value="true"
                    style="width: 18px; height: 18px; cursor: pointer;">
                <label for="text-only-checkbox" style="font-size: 0.9rem; cursor: pointer; color: var(--muted);">Kun
                    tekst (ingen lyd)</label>
            </div>
            <!-- Hidden field that mirrors the active voice select -->
            <input type="hidden" id="voice-hidden" name="voice" value="da-DK-Standard-C">

            <button type="submit" id="convert-btn" disabled>Convert to Audiobook</button>
        </form>

        <!-- Progress section with step indicators -->
        <div id="progress-section" role="status" aria-live="polite">
            <div class="progress-header">
                <span class="elapsed" id="elapsed-time">0:00</span>
                <span class="eta" id="eta-time"></span>
            </div>
            <div class="progress-bar-track">
                <div class="progress-bar-fill" id="progress-bar"></div>
            </div>
            <ul class="steps" id="step-list">
                <li id="step-upload" class="active">
                    <div class="step-icon">
                        <div class="spinner"></div>
                        <svg class="check" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 5.5L4 7.5L8 3" />
                        </svg>
                    </div>
                    <div class="step-content">
                        <div class="step-label">Uploading PDF</div>
                        <div class="step-detail" id="upload-detail"></div>
                    </div>
                </li>
                <li id="step-extract">
                    <div class="step-icon">
                        <div class="spinner"></div>
                        <svg class="check" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 5.5L4 7.5L8 3" />
                        </svg>
                    </div>
                    <div class="step-content">
                        <div class="step-label">Extracting text with AI</div>
                        <div class="step-detail" id="extract-detail"></div>
                    </div>
                </li>
                <li id="step-tts">
                    <div class="step-icon">
                        <div class="spinner"></div>
                        <svg class="check" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 5.5L4 7.5L8 3" />
                        </svg>
                    </div>
                    <div class="step-content">
                        <div class="step-label">Generating speech</div>
                        <div class="step-detail" id="tts-detail"></div>
                        <div class="chunk-bar-track" id="chunk-bar-track" style="display:none;">
                            <div class="chunk-bar-fill" id="chunk-bar"></div>
                        </div>
                    </div>
                </li>
                <li id="step-build">
                    <div class="step-icon">
                        <div class="spinner"></div>
                        <svg class="check" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 5.5L4 7.5L8 3" />
                        </svg>
                    </div>
                    <div class="step-content">
                        <div class="step-label">Building audio file</div>
                        <div class="step-detail" id="build-detail"></div>
                    </div>
                </li>
            </ul>
        </div>

        <div id="error-section" role="alert" aria-live="assertive"></div>

        <div id="result-section">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Your audiobook is ready!
            </h2>
            <div class="result-stats" id="result-stats"></div>
            <audio id="audio-player" controls preload="auto"></audio>

            <div class="download-container" style="display: flex; gap: 1rem; margin-top: 1rem;">
                <a id="download-btn" class="primary-btn" download="audiobook.wav" href="#">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Download WAV
                </a>

                <a id="download-text-btn" class="primary-btn" style="background: var(--surface-2); color: var(--text);"
                    download="tekst.txt" href="#">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download Tekst
                </a>
            </div>

            <div class="cloud-upload-section">
                <p class="cloud-upload-label">☁️ Save to cloud storage</p>
                <div class="cloud-btn-group">
                    <button id="onedrive-btn" class="cloud-btn" type="button" disabled>
                        <!-- Microsoft OneDrive icon -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                            fill="currentColor" aria-hidden="true">
                            <path
                                d="M19.35 10.03A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.03A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.97z" />
                        </svg>
                        OneDrive
                    </button>
                    <button id="icloud-btn" class="cloud-btn" type="button" disabled>
                        <!-- Cloud / iCloud icon -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                        </svg>
                        iCloud
                    </button>
                </div>
                <div id="cloud-upload-status" style="display:none;"></div>
            </div>
        </div>

        <button id="resume-btn" type="button">Genoptag opgave</button>

        <button id="reset-btn" type="button">Convert another PDF</button>
    </div>

    <script>
        (function () {
            'use strict';

            const $ = id => document.getElementById(id);

            const dropZone = $('drop-zone');
            const fileInput = $('file-input');
            const fileNameEl = $('file-name');
            const convertBtn = $('convert-btn');
            const uploadForm = $('upload-form');
            const progressSec = $('progress-section');
            const progressBar = $('progress-bar');
            const elapsedEl = $('elapsed-time');
            const etaEl = $('eta-time');
            const errorSec = $('error-section');
            const resultSec = $('result-section');
            const resultStats = $('result-stats');
            const audioPlayer = $('audio-player');
            const downloadBtn = $('download-btn');
            const downloadTextBtn = $('download-text-btn');
            const resetBtn = $('reset-btn');
            const resumeBtn = $('resume-btn');
            const onedriveBtn = $('onedrive-btn');
            const icloudBtn = $('icloud-btn');
            const cloudStatus = $('cloud-upload-status');

            let currentJobId = null;

            // TTS provider toggle
            const ttsProviderSelect = $('tts-provider-select');
            const googleVoiceItem = $('google-voice-item');
            const openaiVoiceItem = $('openai-voice-item');
            const openaiModelItem = $('openai-model-item');
            const voiceSelect = $('voice-select');
            const openaiVoiceSelect = $('openai-voice-select');
            const voiceHidden = $('voice-hidden');

            function updateTtsProviderUi() {
                const isOpenAi = ttsProviderSelect.value === 'openai';
                googleVoiceItem.style.display = isOpenAi ? 'none' : '';
                openaiVoiceItem.style.display = isOpenAi ? '' : 'none';
                openaiModelItem.style.display = isOpenAi ? '' : 'none';
                voiceHidden.value = isOpenAi ? openaiVoiceSelect.value : voiceSelect.value;
            }

            ttsProviderSelect.addEventListener('change', updateTtsProviderUi);
            voiceSelect.addEventListener('change', () => { voiceHidden.value = voiceSelect.value; });
            openaiVoiceSelect.addEventListener('change', () => { voiceHidden.value = openaiVoiceSelect.value; });
            updateTtsProviderUi();

            // Tab logic
            const tabBtns = document.querySelectorAll('.tab-btn');
            const inputPanes = document.querySelectorAll('.input-pane');
            let currentInputType = 'pdf'; // 'pdf' or 'text'

            tabBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    tabBtns.forEach(b => b.classList.remove('active'));
                    inputPanes.forEach(p => p.classList.remove('active'));
                    btn.classList.add('active');
                    $(btn.dataset.target).classList.add('active');
                    currentInputType = btn.dataset.target === 'pane-pdf' ? 'pdf' : 'text';
                    validateForm();
                });
            });

            const MAX_BYTES = <?= $maxMb ?> * 1024 * 1024;

            // Step elements
            const steps = ['upload', 'extract', 'tts', 'build'];
            const stepEls = Object.fromEntries(steps.map(s => [s, $('step-' + s)]));
            const detailEls = Object.fromEntries(steps.map(s => [s, $(s + '-detail')]));

            // ── Timing state ────────────────────────────────────────────────────────
            let conversionStartTime = null;
            let elapsedTimer = null;
            let ttsChunkTimes = [];       // Duration of each completed TTS chunk (seconds)
            let ttsStartTime = null;      // When the first TTS chunk started
            let totalChunks = 0;
            let completedChunks = 0;
            let extractionTime = 0;       // How long text extraction took

            // ── Fetch Usage ─────────────────────────────────────────────────────────
            async function fetchUsage() {
                try {
                    const res = await fetch('api/usage.php');
                    if (res.ok) {
                        const data = await res.json();
                        $('usage-badge').style.display = 'flex';
                        $('usage-text').textContent = data.chars_used.toLocaleString('da-DK') + ' / ' + data.limit.toLocaleString('da-DK') + ' tegn';
                    }
                } catch (e) {
                    console.error('Could not load usage data', e);
                }
            }
            fetchUsage();

            function startElapsedTimer() {
                conversionStartTime = performance.now();
                elapsedTimer = setInterval(() => {
                    const sec = Math.floor((performance.now() - conversionStartTime) / 1000);
                    elapsedEl.textContent = formatTime(sec);
                }, 500);
            }
            function stopElapsedTimer() {
                if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
            }

            function formatTime(totalSec) {
                const m = Math.floor(totalSec / 60);
                const s = totalSec % 60;
                return m + ':' + String(s).padStart(2, '0');
            }

            function updateEta() {
                if (completedChunks < 1 || totalChunks <= 0) {
                    etaEl.textContent = '';
                    return;
                }
                const avgChunkTime = ttsChunkTimes.reduce((a, b) => a + b, 0) / ttsChunkTimes.length;
                const remainingChunks = totalChunks - completedChunks;
                const etaSec = Math.ceil(remainingChunks * avgChunkTime);
                if (etaSec > 0) {
                    etaEl.textContent = '~' + formatTime(etaSec) + ' remaining';
                } else {
                    etaEl.textContent = 'Almost done…';
                }
            }

            // ── Step management ─────────────────────────────────────────────────────
            function activateStep(name) {
                for (const s of steps) {
                    const el = stepEls[s];
                    if (s === name) {
                        el.classList.remove('done');
                        el.classList.add('active');
                    } else if (steps.indexOf(s) < steps.indexOf(name)) {
                        el.classList.remove('active');
                        el.classList.add('done');
                    } else {
                        el.classList.remove('active', 'done');
                    }
                }
            }

            function completeStep(name) {
                stepEls[name].classList.remove('active');
                stepEls[name].classList.add('done');
            }

            function completeAllSteps() {
                for (const s of steps) {
                    stepEls[s].classList.remove('active');
                    stepEls[s].classList.add('done');
                }
            }

            function resetSteps() {
                for (const s of steps) {
                    stepEls[s].classList.remove('active', 'done');
                    detailEls[s].textContent = '';
                }
                $('chunk-bar-track').style.display = 'none';
                $('chunk-bar').style.width = '0%';
                etaEl.textContent = '';
                progressBar.style.width = '0%';
            }

            // Overall progress: upload=0-10%, extract=10-30%, tts=30-90%, build=90-100%
            function setOverallProgress(pct) {
                progressBar.style.width = Math.min(100, Math.max(0, pct)) + '%';
            }

            // ── File & Text selection ─────────────────────────────────────────────
            $('raw-text-input').addEventListener('input', validateForm);

            function validateForm() {
                if (currentInputType === 'pdf') {
                    convertBtn.disabled = !fileInput.files.length;
                } else {
                    convertBtn.disabled = $('raw-text-input').value.trim() === '';
                }
            }

            dropZone.addEventListener('click', () => fileInput.click());
            dropZone.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
            });

            dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
            dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
            dropZone.addEventListener('drop', e => {
                e.preventDefault(); dropZone.classList.remove('drag-over');
                if (e.dataTransfer.files.length) selectFile(e.dataTransfer.files[0]);
            });

            fileInput.addEventListener('change', () => {
                if (fileInput.files.length) selectFile(fileInput.files[0]);
            });

            function selectFile(file) {
                hideError();
                if (audioPlayer.src && audioPlayer.src.startsWith('blob:')) {
                    URL.revokeObjectURL(audioPlayer.src);
                    audioPlayer.pause();
                    audioPlayer.removeAttribute('src');
                    audioPlayer.load();
                }
                resultSec.style.display = 'none';
                resetBtn.style.display = 'none';
                downloadBtn.href = '#';

                const mime = file.type || '';
                const lower = (file.name || '').toLowerCase();
                if (mime !== 'application/pdf' && mime !== 'application/x-pdf' && !lower.endsWith('.pdf')) {
                    showError('Please select a PDF file.'); return;
                }
                if (file.size > MAX_BYTES) {
                    showError('File is too large. Maximum size is <?= $maxMb ?> MB.'); return;
                }

                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                fileNameEl.textContent = file.name + ' (' + formatBytes(file.size) + ')';
                validateForm();
            }

            // ── Form submit ─────────────────────────────────────────────────────────
            uploadForm.addEventListener('submit', e => {
                e.preventDefault();
                hideError();

                if (currentInputType === 'pdf') {
                    if (!fileInput.files.length) { showError('Vælg en PDF fil først.'); return; }
                } else {
                    if ($('raw-text-input').value.trim() === '') { showError('Indsæt venligst noget tekst i feltet.'); return; }
                }

                const formData = new FormData(uploadForm);
                const isTextOnly = $('text-only-checkbox').checked;
                if (isTextOnly) {
                    formData.set('text_only', 'true');
                }

                startConversion(formData);
            });

            async function startConversion(formData) {
                setUiState('loading');
                resetSteps();
                activateStep('upload');
                detailEls.upload.textContent = 'Sending file to server…';
                setOverallProgress(5);

                // Reset timing
                ttsChunkTimes = [];
                ttsStartTime = null;
                totalChunks = 0;
                completedChunks = 0;
                extractionTime = 0;
                startElapsedTimer();

                let response;
                try {
                    response = await fetch('api/convert.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                    });
                } catch (err) {
                    stopElapsedTimer();
                    showError('Network error. Please check your connection and try again.');
                    setUiState('idle');
                    return;
                }

                const data = await response.json();
                if (!response.ok || !data.success) {
                    stopElapsedTimer();
                    showError(data.error || 'Server error.');
                    setUiState('idle');
                    return;
                }

                const jobId = data.job_id;
                completeStep('upload');
                detailEls.upload.textContent = 'Uploaded & Queued';
                setOverallProgress(10);

                // ── Start Polling ──────────────────────────────────────────────────
                pollJobStatus(jobId);
            }

            async function pollJobStatus(jobId) {
                const interval = 1500; // 1.5s
                let chunkStartTime = null;
                let lastChunk = 0;

                const poll = async () => {
                    try {
                        const response = await fetch('api/status.php?id=' + jobId);
                        if (!response.ok) throw new Error('Status check failed');

                        const job = await response.json();

                        if (job.step === 'error') {
                            stopElapsedTimer();
                            showError(job.error || 'Job failed.');
                            resumeBtn.style.display = 'block';
                            resumeBtn.dataset.jobid = jobId;
                            setUiState('error');
                            return;
                        }

                        const isTextOnlyJob = job.text_only || false;

                        switch (job.step) {
                            case 'extracting':
                                activateStep('extract');
                                detailEls.extract.textContent = 'Reading PDF with Gemini AI…';
                                setOverallProgress(15);
                                break;

                            case 'extracted':
                                completeStep('extract');
                                totalChunks = job.totalChunks || 1;
                                let extractText =
                                    formatNumber(job.charCount) + ' characters · ' +
                                    totalChunks + ' audio chunk' + (totalChunks !== 1 ? 's' : '');
                                if (job.warning) {
                                    extractText += ' · ⚠ ' + job.warning;
                                }
                                detailEls.extract.textContent = extractText;
                                setOverallProgress(30);
                                break;

                            case 'tts_start':
                                activateStep('tts');
                                $('chunk-bar-track').style.display = 'block';
                                detailEls.tts.textContent =
                                    'Chunk ' + job.chunk + ' of ' + job.totalChunks +
                                    ' (' + formatNumber(job.chunkChars) + ' chars)';

                                if (job.chunk !== lastChunk) {
                                    chunkStartTime = performance.now();
                                    lastChunk = job.chunk;
                                }

                                const chunkProgressStart = ((job.chunk - 1) / job.totalChunks) * 100;
                                $('chunk-bar').style.width = chunkProgressStart + '%';
                                setOverallProgress(30 + (chunkProgressStart / 100) * 60);
                                break;

                            case 'tts_done': {
                                if (job.chunk > completedChunks) {
                                    if (chunkStartTime) {
                                        const chunkDur = (performance.now() - chunkStartTime) / 1000;
                                        ttsChunkTimes.push(chunkDur);
                                        chunkStartTime = null; // Wait for next start
                                    }
                                    completedChunks = job.chunk;
                                }

                                const chunkPct = (job.chunk / job.totalChunks) * 100;
                                $('chunk-bar').style.width = chunkPct + '%';
                                detailEls.tts.textContent =
                                    'Chunk ' + job.chunk + ' of ' + job.totalChunks + ' ✓';
                                setOverallProgress(30 + (chunkPct / 100) * 60);
                                updateEta();

                                if (job.chunk >= job.totalChunks) {
                                    completeStep('tts');
                                    etaEl.textContent = 'Almost done…';
                                }
                                break;
                            }

                            case 'building':
                                activateStep('build');
                                detailEls.build.textContent = 'Combining audio chunks into WAV…';
                                setOverallProgress(92);
                                etaEl.textContent = 'Almost done…';
                                break;

                            case 'done':
                                completeAllSteps();
                                detailEls.build.textContent = 'Complete';
                                setOverallProgress(100);
                                stopElapsedTimer();
                                etaEl.textContent = '';
                                fetchUsage(); // Refresh usage after job succeeds

                                // Track job for cloud upload
                                currentJobId = jobId;
                                onedriveBtn.disabled = false;
                                icloudBtn.disabled = false;
                                cloudStatus.style.display = 'none';

                                // Show result
                                if (isTextOnlyJob) {
                                    audioPlayer.style.display = 'none';
                                    downloadBtn.style.display = 'none';
                                    $('result-section').querySelector('h2').innerHTML = `
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Teksten er nu udtrukket!
                                    `;
                                    // Make text button more prominent
                                    downloadTextBtn.style.border = '2px solid var(--primary)';
                                    downloadTextBtn.style.color = 'var(--primary)';
                                    downloadTextBtn.style.background = 'transparent';
                                } else {
                                    audioPlayer.style.display = 'block';
                                    downloadBtn.style.display = 'flex';
                                    audioPlayer.src = job.audio_url;
                                    downloadBtn.href = job.audio_url;
                                    $('result-section').querySelector('h2').innerHTML = `
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Your audiobook is ready!
                                    `;
                                    downloadTextBtn.style.border = '2px solid var(--muted)';
                                    downloadTextBtn.style.color = 'var(--muted)';
                                    downloadTextBtn.style.background = 'transparent';
                                }

                                const finalName = fileInput.files.length ? fileInput.files[0].name.replace(/\.pdf$/i, '') : 'lydbog';
                                downloadTextBtn.href = 'api/download_text.php?id=' + jobId;
                                downloadTextBtn.download = finalName + '_tekst.txt';

                                // Show stats
                                const totalSec = Math.round(job.elapsed);
                                if (isTextOnlyJob) {
                                    resultStats.innerHTML = '<span>⏱ ' + formatTime(totalSec) + ' total</span>';
                                } else {
                                    const audioSizeMb = (job.audio_size / (1024 * 1024)).toFixed(1);
                                    resultStats.innerHTML =
                                        '<span>⏱ ' + formatTime(totalSec) + ' total</span>' +
                                        '<span>📦 ' + audioSizeMb + ' MB</span>' +
                                        (job.totalChunks > 1 ? '<span>🔊 ' + job.totalChunks + ' chunks</span>' : '') +
                                        (job.warning ? '<span>⚠ ' + job.warning + '</span>' : '');
                                }

                                setUiState('done');
                                return; // Stop polling
                        }

                        // Continue polling
                        setTimeout(poll, interval);

                    } catch (err) {
                        console.error('[ReadingPDF] Polling error:', err);
                        setTimeout(poll, interval); // Try again
                    }
                };

                poll();
            }

            // ── Reset & Resume ──────────────────────────────────────────────────────
            resetBtn.addEventListener('click', () => {
                if (audioPlayer.src) URL.revokeObjectURL(audioPlayer.src);
                audioPlayer.src = '';
                downloadBtn.href = '#';
                fileInput.value = '';
                fileNameEl.textContent = '';
                convertBtn.disabled = true;
                currentJobId = null;
                onedriveBtn.disabled = true;
                icloudBtn.disabled = true;
                cloudStatus.textContent = '';
                cloudStatus.style.display = 'none';
                hideError();
                setUiState('idle');
            });

            resumeBtn.addEventListener('click', async () => {
                resumeBtn.style.display = 'none';
                hideError();
                setUiState('loading');

                const jobId = resumeBtn.dataset.jobid;
                const formData = new FormData();
                formData.append('job_id', jobId);

                try {
                    const response = await fetch('api/resume.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        showError(data.error || 'Resume failed.');
                        resumeBtn.style.display = 'block';
                        setUiState('error');
                        return;
                    }
                    startElapsedTimer();
                    pollJobStatus(jobId);
                } catch (err) {
                    showError('Network error during resume.');
                    resumeBtn.style.display = 'block';
                    setUiState('error');
                }
            });

            // ── UI helpers ───────────────────────────────────────────────────────────
            function setUiState(state) {
                progressSec.style.display = (state === 'loading' || state === 'error') ? 'block' : 'none';
                resultSec.style.display = state === 'done' ? 'block' : 'none';
                resetBtn.style.display = state === 'done' ? 'block' : 'none';
                convertBtn.disabled = state !== 'idle';
                dropZone.style.pointerEvents = (state === 'loading' || state === 'error') ? 'none' : '';
                if (state === 'idle') {
                    resetSteps();
                    stopElapsedTimer();
                    resumeBtn.style.display = 'none';
                }
            }

            function showError(msg) {
                errorSec.textContent = msg;
                errorSec.style.display = 'block';
            }
            function hideError() {
                errorSec.style.display = 'none';
                errorSec.textContent = '';
            }

            function formatBytes(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }

            function formatNumber(n) {
                return n.toLocaleString('en-US');
            }

            function base64ToUint8Array(b64) {
                const bin = atob(b64);
                const arr = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
                return arr;
            }

            // ── Cloud upload ─────────────────────────────────────────────────────────

            function showCloudStatus(msg, isError = false) {
                cloudStatus.textContent = msg;
                cloudStatus.style.display = 'block';
                cloudStatus.style.color = isError ? 'var(--danger)' : 'var(--success)';
            }

            let onedrivePopupHandled = false;

            // Listen for result messages sent back from the OAuth popup window.
            window.addEventListener('message', (e) => {
                if (e.origin !== window.location.origin) return;
                if (!e.data || e.data.type !== 'cloud_upload_result') return;
                onedrivePopupHandled = true;
                onedriveBtn.disabled = false;
                if (e.data.success) {
                    showCloudStatus('✅ Uploaded to OneDrive successfully!');
                } else {
                    showCloudStatus('❌ OneDrive upload failed: ' + e.data.message, true);
                }
            });

            onedriveBtn.addEventListener('click', async () => {
                if (!currentJobId) return;
                onedriveBtn.disabled = true;
                showCloudStatus('Opening OneDrive authentication…');

                const csrfInput = document.querySelector('input[name=csrf_token]');
                if (!csrfInput) { onedriveBtn.disabled = false; return; }
                const csrfTokenVal = csrfInput.value;
                try {
                    const res = await fetch('api/cloud_upload.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'auth_url',
                            job_id: currentJobId,
                            csrf_token: csrfTokenVal,
                        }),
                        credentials: 'same-origin',
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) {
                        showCloudStatus('❌ ' + (data.error || 'Failed to start upload.'), true);
                        onedriveBtn.disabled = false;
                        return;
                    }
                    const popup = window.open(data.auth_url, 'onedrive_auth',
                        'width=520,height=680,scrollbars=yes,resizable=yes');
                    if (!popup) {
                        showCloudStatus('❌ Popup blocked — please allow popups for this site and try again.', true);
                        onedriveBtn.disabled = false;
                        return;
                    }
                    // Poll for popup closure so we can re-enable the button if the
                    // user dismisses the window before auth completes.
                    onedrivePopupHandled = false;
                    const closedPoll = setInterval(() => {
                        if (popup.closed) {
                            clearInterval(closedPoll);
                            if (!onedrivePopupHandled) {
                                onedriveBtn.disabled = false;
                                showCloudStatus('OneDrive sign-in was cancelled. You can try again.', true);
                            }
                        }
                    }, 500);
                } catch (err) {
                    showCloudStatus('❌ Network error — could not reach server.', true);
                    onedriveBtn.disabled = false;
                }
            });

            icloudBtn.addEventListener('click', async () => {
                if (!currentJobId) return;
                icloudBtn.disabled = true;

                // Try the Web Share API with files — works on iOS/iPadOS/macOS Safari
                // and allows the user to save directly to iCloud Drive via the Files app.
                if (typeof navigator.canShare === 'function') {
                    try {
                        const response = await fetch(downloadBtn.href);
                        if (response.ok) {
                            const blob = await response.blob();
                            const file = new File([blob], 'audiobook_' + currentJobId + '.wav', { type: 'audio/wav' });
                            if (navigator.canShare({ files: [file] })) {
                                await navigator.share({ files: [file], title: 'Audiobook' });
                                showCloudStatus('✅ File shared — save it to iCloud Drive in the Files app.');
                                icloudBtn.disabled = false;
                                return;
                            }
                        }
                    } catch (err) {
                        if (err.name === 'AbortError') {
                            // User cancelled the share sheet — not an error.
                            icloudBtn.disabled = false;
                            return;
                        }
                        // Fall through to the download fallback.
                    }
                }

                // Fallback: trigger a regular download and guide the user.
                showCloudStatus('ℹ️ Download the file and move it to your iCloud Drive folder to sync it across devices.');
                downloadBtn.click();
                icloudBtn.disabled = false;
            });
        }());
    </script>
</body>

</html>