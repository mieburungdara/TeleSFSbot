<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class TelegramAPI {
    private string $botToken;
    private string $apiUrl;
    private static ?self $instance = null;

    private function __construct(string $botToken) {
        $this->botToken = $botToken;
        $this->apiUrl = 'https://api.telegram.org/bot' . $this->botToken . '/';
    }

    public static function getInstance(string $botToken = BOT_TOKEN): self {
        if (self::$instance === null) {
            // Thread-safe singleton with double-checked locking
            static $lock = null;
            if ($lock === null) {
                $lock = fopen(__FILE__, 'r');
            }
            
            flock($lock, LOCK_EX);
            try {
                if (self::$instance === null) {
                    self::$instance = new self($botToken);
                }
            } finally {
                flock($lock, LOCK_UN);
            }
        }
        return self::$instance;
    }

    private function request(string $method, array $params = []): array {
        $url = $this->apiUrl . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Add connection timeout
        curl_setopt($ch, CURLOPT_FAILONERROR, false); // Don't fail on HTTP error codes, let us handle them

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $this->logError('CURL timeout error: ' . $error . ' | Method: ' . $method);
            return ['ok' => false, 'description' => 'Request timed out'];
        }

        if ($error) {
            $this->logError('CURL error: ' . $error . ' | Method: ' . $method);
            return ['ok' => false, 'description' => $error];
        }

        // Handle empty response
        if ($response === false || $response === '') {
            $this->logError('Empty response from Telegram API | Method: ' . $method . ' | HTTP Code: ' . $httpCode);
            return ['ok' => false, 'description' => 'Empty response from API'];
        }

        $result = json_decode($response, true);
        
        // Handle JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError('JSON decode error: ' . json_last_error_msg() . ' | Method: ' . $method . ' | Response: ' . substr($response, 0, 500));
            return ['ok' => false, 'description' => 'Invalid API response'];
        }
        
        if (!$result['ok'] ?? false) {
            $this->logError('API error: ' . ($result['description'] ?? 'Unknown error') . ' | Method: ' . $method . ' | Params: ' . json_encode($params));
        }

        return $result;
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->request('sendMessage', $params);
    }

    public function banChatMember(int $chatId, int $userId, int $untilDate = 0): array {
        return $this->request('banChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'until_date' => $untilDate
        ]);
    }

    public function unbanChatMember(int $chatId, int $userId): array {
        return $this->request('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): array {
        $params = ['callback_query_id' => $callbackQueryId];
        
        if ($text !== null) {
            $params['text'] = $text;
            $params['show_alert'] = $showAlert;
        }

        return $this->request('answerCallbackQuery', $params);
    }

    public function getChatMember(int $chatId, int $userId): array {
        return $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getChat(int $chatId): array {
        return $this->request('getChat', [
            'chat_id' => $chatId
        ]);
    }

    public function setWebhook(string $url, ?string $secret = null): array {
        $params = ['url' => $url];
        if ($secret !== null) {
            $params['secret_token'] = $secret;
        }
        return $this->request('setWebhook', $params);
    }

    public function deleteWebhook(): array {
        return $this->request('deleteWebhook');
    }

    public function createInlineKeyboard(array $buttons): array {
        return ['inline_keyboard' => $buttons];
    }

    private function logError(string $message): void {
        $logMessage = date('[Y-m-d H:i:s] ') . '[Telegram] ' . $message . PHP_EOL;
        
        // Check if log file is writable
        if (!file_exists(LOG_FILE)) {
            if (!touch(LOG_FILE)) {
                return;
            }
        }
        
        if (is_writable(LOG_FILE)) {
            file_put_contents(LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
        }
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
