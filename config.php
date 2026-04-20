<?php

declare(strict_types=1);

// Bot Configuration
define('BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN');
define('LOG_FILE', __DIR__ . '/bot.log');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'telesfsbot');
define('DB_USER', 'root');
define('DB_PASS', '');

// Webhook Configuration (optional)
define('WEBHOOK_SECRET', ''); // Set for additional security

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_FILE);
