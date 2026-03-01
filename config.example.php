<?php
// Copy this file to config.php and fill in your API keys.
// config.php is listed in .gitignore and must NEVER be committed.
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');

// ── OneDrive / Microsoft Graph API ───────────────────────────────────────────
// Register an app at https://portal.azure.com (Azure Active Directory → App registrations).
// Required API permission: Files.ReadWrite (Microsoft Graph, Delegated).
// Set the Redirect URI to: https://YOUR_DOMAIN/api/cloud_upload.php
// Leave blank ('') to disable OneDrive upload.
define('ONEDRIVE_CLIENT_ID',     'YOUR_ONEDRIVE_CLIENT_ID_HERE');
define('ONEDRIVE_CLIENT_SECRET', 'YOUR_ONEDRIVE_CLIENT_SECRET_HERE');
define('ONEDRIVE_REDIRECT_URI',  'https://YOUR_DOMAIN/api/cloud_upload.php');
