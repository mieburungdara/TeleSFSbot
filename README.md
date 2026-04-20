# Telegram Sub-for-Sub Escrow Bot

PHP 8.1 backend for a Telegram bot that enforces mutual channel following agreements and detects cheating.

## Features

- ✅ Webhook-based updates (no polling)
- ✅ MySQL InnoDB with proper foreign keys
- ✅ PDO prepared statements for security
- ✅ Automatic cheating detection
- ✅ Retaliation system with inline buttons
- ✅ Agreement status tracking
- ✅ Error logging

## Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.2+
- HTTPS web server (required for Telegram webhooks)
- cURL extension enabled

## Installation

### 1. Database Setup

Import the schema:
```bash
mysql -u username -p database_name < schema.sql
```

### 2. Configuration

Edit `config.php` with your credentials:
```php
define('BOT_TOKEN', 'your-telegram-bot-token');
define('DB_HOST', 'localhost');
define('DB_NAME', 'database_name');
define('DB_USER', 'database_user');
define('DB_PASS', 'database_password');
```

### 3. Webhook Setup

Set up your Telegram webhook:
```
https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://your-domain.com/bot.php
```

For added security, set a webhook secret:
```
https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://your-domain.com/bot.php&secret_token=your-secret
```

And update `WEBHOOK_SECRET` in `config.php`.

### 4. Bot Permissions

When adding the bot to channels, ensure it has the following admin permissions:
- ✅ Delete messages
- ✅ Ban users
- ✅ Add admins

## File Structure

| File | Description |
|------|-------------|
| `schema.sql` | Database schema |
| `config.php` | Configuration settings |
| `db.php` | PDO database wrapper |
| `telegram.php` | Telegram API client |
| `bot.php` | Main webhook handler |
| `bot.log` | Error log file (auto-created) |

## Update Types Handled

1. **my_chat_member** - Bot added/removed as channel admin
2. **chat_member** - User joins/leaves channels
3. **callback_query** - Inline button responses

## Agreement States

- `pending` - Agreement created but not yet active
- `active` - Both users are following each other's channels
- `canceled` - Agreement was canceled by one party
- `compromised` - Bot was removed from one of the channels

## Logging

All errors are logged to `bot.log` with timestamps. Monitor this file for issues.

## Security Notes

- Always use HTTPS for webhook endpoint
- Never commit `config.php` with real credentials to version control
- Restrict file permissions on `config.php` and `bot.log`
- Use webhook secret token for additional security

## Troubleshooting

1. Check `bot.log` for errors
2. Verify bot is admin in channels with correct permissions
3. Ensure webhook URL is accessible via HTTPS
4. Check PHP error logs for syntax errors