# ReadingPDF

A PHP + JavaScript web application that converts a PDF file to a spoken-word audiobook using the Google Gemini API.

* Upload a PDF (up to 20 MB)
* Gemini extracts the readable body text (stripping page numbers, headers and footers)
* Gemini TTS converts the text to a WAV audio file
* Play the result directly in the browser or download it

## Security

The Gemini API key is stored exclusively in `config.php` on the server and is **never** exposed to the browser or included in any client-side code. All Gemini API calls are made server-side by `api/convert.php`.

Additional security measures:
* CSRF token on every upload request
* File-type validation using `finfo` (not browser-supplied MIME type)
* File-size limit enforced server-side
* Strict HTTP security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Cache-Control: no-store`)

## Requirements

* PHP 8.1+ with the `curl` and `fileinfo` extensions enabled
* A web server (Apache / Nginx) or `php -S` for local development
* A [Google Gemini API key](https://aistudio.google.com/app/apikey)

## Setup

```bash
# 1. Clone the repository
git clone https://github.com/WebJax/ReadingPDF.git
cd ReadingPDF

# 2. Create config.php from the example and add your API key
cp config.example.php config.php
# Edit config.php and replace YOUR_GEMINI_API_KEY_HERE with your actual key

# 3. Start a local development server
php -S localhost:8080

# 4. Open http://localhost:8080 in your browser
```

## File structure

```
.
├── index.php            Frontend entry point (HTML + JavaScript)
├── api/
│   └── convert.php      Backend API — PDF upload, text extraction, TTS
├── config.php           API key (gitignored — created from config.example.php)
├── config.example.php   Template for config.php
└── .gitignore
```
