<?php
/**
 * PDF to Audiobook — frontend entry point.
 *
 * Generates a CSRF token stored in the PHP session and renders the single-page
 * UI.  All communication with the Gemini API is handled server-side in
 * api/convert.php — the API key is never exposed to the browser.
 */

declare(strict_types=1);

session_start();

// Rotate the CSRF token on each full page load.
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

// Maximum accepted file size shown in the UI (must match api/convert.php).
$maxMb = 20;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ReadingPDF — PDF to Audiobook</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #f4f6f9;
            --surface:  #ffffff;
            --primary:  #4f6ef7;
            --primary-h:#3a57d6;
            --text:     #1a1c23;
            --muted:    #6b7280;
            --border:   #d1d5db;
            --danger:   #dc2626;
            --success:  #16a34a;
            --radius:   12px;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
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
            border-radius: var(--radius);
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 560px;
        }

        h1 {
            font-size: 1.6rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: .25rem;
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
            transition: border-color .2s, background .2s;
        }
        #drop-zone.drag-over,
        #drop-zone:hover {
            border-color: var(--primary);
            background: #f0f3ff;
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
        #drop-zone strong { color: var(--text); }

        #file-input { display: none; }

        #file-name {
            text-align: center;
            font-size: .875rem;
            color: var(--muted);
            margin-top: .6rem;
            min-height: 1.25rem;
            word-break: break-all;
        }

        /* ── Convert button ── */
        #convert-btn {
            display: block;
            width: 100%;
            margin-top: 1.25rem;
            padding: .75rem;
            border: none;
            border-radius: var(--radius);
            background: var(--primary);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, opacity .2s;
        }
        #convert-btn:hover:not(:disabled) { background: var(--primary-h); }
        #convert-btn:disabled { opacity: .55; cursor: not-allowed; }

        /* ── Progress ── */
        #progress-section { margin-top: 1.5rem; display: none; }
        #progress-label {
            font-size: .875rem;
            color: var(--muted);
            margin-bottom: .5rem;
            text-align: center;
        }
        #progress-bar-track {
            height: 8px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
        }
        #progress-bar {
            height: 100%;
            background: var(--primary);
            border-radius: 99px;
            width: 0%;
            transition: width .3s;
        }

        /* ── Result ── */
        #result-section { margin-top: 1.75rem; display: none; }
        #result-section h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        audio {
            width: 100%;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        #download-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            width: 100%;
            padding: .65rem;
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            background: transparent;
            color: var(--primary);
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .2s, color .2s;
        }
        #download-btn:hover { background: var(--primary); color: #fff; }

        /* ── Error ── */
        #error-section {
            margin-top: 1.25rem;
            display: none;
            padding: .85rem 1rem;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: var(--radius);
            color: var(--danger);
            font-size: .9rem;
        }

        /* ── Reset ── */
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
        #reset-btn:hover { border-color: var(--text); color: var(--text); }
    </style>
</head>
<body>
<div class="card">
    <h1>📖 ReadingPDF</h1>
    <p class="subtitle">Upload a PDF and convert it to a spoken-word audio file</p>

    <form id="upload-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div id="drop-zone" role="button" tabindex="0"
             aria-label="Click or drag and drop a PDF file here">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
            </svg>
            <p><strong>Click to browse</strong> or drag &amp; drop a PDF</p>
            <p style="font-size:.8rem;margin-top:.3rem;">Max <?= $maxMb ?> MB</p>
        </div>
        <input type="file" id="file-input" name="pdf" accept="application/pdf">
        <p id="file-name"></p>

        <button type="submit" id="convert-btn" disabled>Convert to Audiobook</button>
    </form>

    <div id="progress-section" role="status" aria-live="polite">
        <p id="progress-label">Uploading…</p>
        <div id="progress-bar-track">
            <div id="progress-bar"></div>
        </div>
    </div>

    <div id="error-section" role="alert" aria-live="assertive"></div>

    <div id="result-section">
        <h2>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M5 13l4 4L19 7"/>
            </svg>
            Your audiobook is ready!
        </h2>
        <audio id="audio-player" controls preload="auto"></audio>
        <a id="download-btn" download="audiobook.wav" href="#">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download WAV
        </a>
    </div>

    <button id="reset-btn" type="button">Convert another PDF</button>
</div>

<script>
(function () {
    'use strict';

    const dropZone      = document.getElementById('drop-zone');
    const fileInput     = document.getElementById('file-input');
    const fileName      = document.getElementById('file-name');
    const convertBtn    = document.getElementById('convert-btn');
    const uploadForm    = document.getElementById('upload-form');
    const progressSec   = document.getElementById('progress-section');
    const progressLabel = document.getElementById('progress-label');
    const progressBar   = document.getElementById('progress-bar');
    const errorSec      = document.getElementById('error-section');
    const resultSec     = document.getElementById('result-section');
    const audioPlayer   = document.getElementById('audio-player');
    const downloadBtn   = document.getElementById('download-btn');
    const resetBtn      = document.getElementById('reset-btn');

    const MAX_BYTES = <?= $maxMb ?> * 1024 * 1024;

    // ── File selection ────────────────────────────────────────────────────────
    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        const files = e.dataTransfer.files;
        if (files.length) selectFile(files[0]);
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) selectFile(fileInput.files[0]);
    });

    function selectFile(file) {
        hideError();
        if (file.type !== 'application/pdf') {
            showError('Please select a PDF file.');
            return;
        }
        if (file.size > MAX_BYTES) {
            showError('File is too large. Maximum size is <?= $maxMb ?> MB.');
            return;
        }
        // Replace the FileList in the hidden input with a DataTransfer trick.
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;

        fileName.textContent = file.name + ' (' + formatBytes(file.size) + ')';
        convertBtn.disabled = false;
    }

    // ── Form submit ───────────────────────────────────────────────────────────
    uploadForm.addEventListener('submit', (e) => {
        e.preventDefault();
        hideError();

        if (!fileInput.files.length) {
            showError('Please select a PDF file first.');
            return;
        }

        const formData = new FormData(uploadForm);
        startConversion(formData);
    });

    function startConversion(formData) {
        setUiState('loading');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/convert.php', true);

        // Upload progress (0 → 50 %).
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 50);
                setProgress(pct, 'Uploading…');
            }
        });

        xhr.upload.addEventListener('load', () => {
            setProgress(50, 'Converting PDF to text…');
            // Animate the bar from 50 % to 90 % during server processing.
            animateProgress(50, 90, 15000, 'Generating audio…');
        });

        xhr.addEventListener('load', () => {
            clearProgressAnimation();
            setProgress(100, 'Done!');

            let data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch {
                showError('Unexpected server response. Please try again.');
                setUiState('idle');
                return;
            }

            if (!data.success) {
                showError(data.error || 'An unknown error occurred.');
                setUiState('idle');
                return;
            }

            // Build a Blob from the base64 WAV data and wire up the UI.
            const wavBytes  = base64ToUint8Array(data.audio);
            const blob      = new Blob([wavBytes], { type: data.mimeType });
            const objectUrl = URL.createObjectURL(blob);

            audioPlayer.src = objectUrl;
            downloadBtn.href = objectUrl;
            downloadBtn.download = fileInput.files[0].name.replace(/\.pdf$/i, '') + '.wav';

            setUiState('done');
        });

        xhr.addEventListener('error', () => {
            clearProgressAnimation();
            showError('Network error. Please check your connection and try again.');
            setUiState('idle');
        });

        xhr.send(formData);
    }

    // ── Reset ─────────────────────────────────────────────────────────────────
    resetBtn.addEventListener('click', () => {
        if (audioPlayer.src) URL.revokeObjectURL(audioPlayer.src);
        audioPlayer.src = '';
        downloadBtn.href = '#';
        fileInput.value = '';
        fileName.textContent = '';
        convertBtn.disabled = true;
        hideError();
        setUiState('idle');
    });

    // ── UI state helpers ──────────────────────────────────────────────────────
    function setUiState(state) {
        progressSec.style.display = state === 'loading' ? 'block' : 'none';
        resultSec.style.display   = state === 'done'    ? 'block' : 'none';
        resetBtn.style.display    = state === 'done'    ? 'block' : 'none';
        convertBtn.disabled       = state !== 'idle';
        dropZone.style.pointerEvents = state === 'loading' ? 'none' : '';

        if (state === 'idle') {
            setProgress(0, '');
        }
    }

    let _animFrame = null;
    function animateProgress(from, to, durationMs, label) {
        const start = performance.now();
        function step(now) {
            const elapsed = now - start;
            const pct = Math.min(from + (to - from) * (elapsed / durationMs), to);
            setProgress(Math.round(pct), label);
            if (pct < to) _animFrame = requestAnimationFrame(step);
        }
        _animFrame = requestAnimationFrame(step);
    }
    function clearProgressAnimation() {
        if (_animFrame !== null) { cancelAnimationFrame(_animFrame); _animFrame = null; }
    }

    function setProgress(pct, label) {
        progressBar.style.width   = pct + '%';
        progressLabel.textContent = label;
    }

    function showError(msg) {
        errorSec.textContent = msg;
        errorSec.style.display = 'block';
    }
    function hideError() {
        errorSec.style.display = 'none';
        errorSec.textContent = '';
    }

    // ── Utilities ─────────────────────────────────────────────────────────────
    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function base64ToUint8Array(b64) {
        const bin = atob(b64);
        const arr = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        return arr;
    }
}());
</script>
</body>
</html>
