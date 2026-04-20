<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram.php';

class SubForSubBot {
    private Database $db;
    private TelegramAPI $telegram;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->telegram = TelegramAPI::getInstance();
    }

    public function handleWebhook(): void {
        // Set proper content type header
        header('Content-Type: application/json');
        
        // Get raw input
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
            exit;
        }

        // Verify webhook secret if set
        if (!empty(WEBHOOK_SECRET)) {
            $secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
            if ($secretHeader !== WEBHOOK_SECRET) {
                $this->logError('Invalid webhook secret');
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Invalid secret']);
                exit;
            }
        }

        $update = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError('Invalid JSON payload: ' . json_last_error_msg());
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
            exit;
        }

        try {
            $this->routeUpdate($update);
            http_response_code(200);
            echo json_encode(['status' => 'ok']);
        } catch (Exception $e) {
            $this->logError('Update handling failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
        }
    }

    private function routeUpdate(array $update): void {
        if (isset($update['my_chat_member'])) {
            $this->handleMyChatMember($update['my_chat_member']);
        } elseif (isset($update['chat_member'])) {
            $this->handleChatMember($update['chat_member']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    private function handleMyChatMember(array $myChatMember): void {
        $chat = $myChatMember['chat'];
        $from = $myChatMember['from'];
        $newStatus = $myChatMember['new_chat_member']['status'];
        
        // Only handle channels
        if ($chat['type'] !== 'channel') {
            return;
        }

        $channelId = $chat['id'];
        $ownerId = $from['id'];
        $channelTitle = $chat['title'] ?? 'Unknown Channel';

        // Upsert user
        $this->upsertUser($ownerId, $from['username'] ?? null);

        // Check if bot was added as admin
        $isAdmin = $newStatus === 'administrator';

        if ($isAdmin) {
            // Bot added as admin
            $this->upsertChannel($channelId, $ownerId, $channelTitle, true);
            $this->telegram->sendMessage($ownerId, "✅ Bot has been added as admin to your channel <b>" . $this->sanitizeHtml($channelTitle) . "</b>.\n\nYou can now create sub-for-sub agreements!");
        } else {
            // Bot removed from channel
            $this->updateChannelAdminStatus($channelId, false);
            $this->handleBotRemoval($channelId, $channelTitle);
        }
    }

    private function handleBotRemoval(int $channelId, string $channelTitle): void {
        // Find all active agreements involving this channel
        $agreements = $this->db->fetchAll("
            SELECT * FROM agreements 
            WHERE status = 'active' 
            AND (channel_a_id = ? OR channel_b_id = ?)
        ", [$channelId, $channelId]);

        if (empty($agreements)) {
            return;
        }

        try {
            $this->db->beginTransaction();

            foreach ($agreements as $agreement) {
                // Update agreement status
                $this->db->execute("
                    UPDATE agreements 
                    SET status = 'compromised' 
                    WHERE id = ?
                ", [$agreement['id']]);

                // Determine counter-party user
                if ($agreement['channel_a_id'] == $channelId) {
                    $victimId = $agreement['user_b_id'];
                } else {
                    $victimId = $agreement['user_a_id'];
                }

                // Notify counter-party
                $message = "⚠️ <b>Agreement Compromised!</b>\n\n";
                $message .= "The owner of channel <b>" . $this->sanitizeHtml($channelTitle) . "</b> has removed the bot.\n\n";
                $message .= "You should manually unfollow this channel to protect yourself.";
                
                $this->telegram->sendMessage($victimId, $message);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logError('Failed to process bot removal: ' . $e->getMessage());
            throw $e;
        }
    }

    private function handleChatMember(array $chatMember): void {
        $chat = $chatMember['chat'];
        $user = $chatMember['from'];
        $newStatus = $chatMember['new_chat_member']['status'];
        $oldStatus = $chatMember['old_chat_member']['status'];

        // Only handle channels
        if ($chat['type'] !== 'channel') {
            return;
        }

        $channelId = $chat['id'];
        $userId = $chatMember['new_chat_member']['user']['id'];
        $userLeft = ($newStatus === 'left' || $newStatus === 'kicked');
        $wasMember = in_array($oldStatus, ['member', 'administrator', 'creator']);

        if (!$userLeft || !$wasMember) {
            return;
        }

        // Check if bot is still admin in this channel
        $channel = $this->db->fetch("
            SELECT * FROM channels 
            WHERE channel_id = ? AND bot_is_admin = 1
        ", [$channelId]);

        if (!$channel) {
            return;
        }

        // Find active agreements where user is a member and this channel is part of the agreement
        $agreements = $this->db->fetchAll("
            SELECT a.*, 
                c_a.channel_title as channel_a_title,
                c_b.channel_title as channel_b_title
            FROM agreements a
            LEFT JOIN channels c_a ON a.channel_a_id = c_a.channel_id
            LEFT JOIN channels c_b ON a.channel_b_id = c_b.channel_id
            WHERE a.status = 'active'
            AND (
                (a.user_a_id = ? AND a.channel_b_id = ?) OR
                (a.user_b_id = ? AND a.channel_a_id = ?)
            )
        ", [$userId, $channelId, $userId, $channelId]);

        try {
            $this->db->beginTransaction();
            
            // Lock agreements for update to prevent race conditions
            $agreements = $this->db->fetchAll("
                SELECT a.*, 
                    c_a.channel_title as channel_a_title,
                    c_b.channel_title as channel_b_title
                FROM agreements a
                LEFT JOIN channels c_a ON a.channel_a_id = c_a.channel_id
                LEFT JOIN channels c_b ON a.channel_b_id = c_b.channel_id
                WHERE a.status = 'active'
                AND (
                    (a.user_a_id = ? AND a.channel_b_id = ?) OR
                    (a.user_b_id = ? AND a.channel_a_id = ?)
                )
                FOR UPDATE
            ", [$userId, $channelId, $userId, $channelId]);

            foreach ($agreements as $agreement) {
                // Determine cheater and victim
                if ($agreement['user_a_id'] == $userId && $agreement['channel_b_id'] == $channelId) {
                    $cheaterId = $userId;
                    $victimId = $agreement['user_b_id'];
                    $cheaterChannelId = $agreement['channel_a_id'];
                    $cheaterChannelTitle = $agreement['channel_a_title'];
                } else {
                    $cheaterId = $userId;
                    $victimId = $agreement['user_a_id'];
                    $cheaterChannelId = $agreement['channel_b_id'];
                    $cheaterChannelTitle = $agreement['channel_b_title'];
                }

                // Mark agreement as flagged
                $this->db->execute("
                    UPDATE agreements 
                    SET status = 'flagged' 
                    WHERE id = ? AND status = 'active'
                ", [$agreement['id']]);

            // Send alert to victim
            $message = "🚨 <b>CHEATING DETECTED!</b>\n\n";
            $message .= "A user has unfollowed your channel <b>" . $this->sanitizeHtml($chat['title']) . "</b> despite being in a sub-for-sub agreement.\n\n";
            $message .= "Cheater's channel: <b>" . $this->sanitizeHtml($cheaterChannelTitle) . "</b>\n\n";
            $message .= "You can retaliate by removing them from your channel.";

                // Create retaliation button
                $keyboard = $this->telegram->createInlineKeyboard([
                    [
                        [
                            'text' => '🚫 Unfollow Back (Ban)',
                            'callback_data' => "retaliate_user_{$cheaterId}_channel_{$cheaterChannelId}"
                        ]
                    ]
                ]);

                $this->telegram->sendMessage($victimId, $message, $keyboard);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logError('Failed to process user leave event: ' . $e->getMessage());
            throw $e;
        }
    }

    private function handleMessage(array $message): void {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';
        $username = $message['from']['username'] ?? null;

        // Only handle private messages
        if ($message['chat']['type'] !== 'private') {
            return;
        }

        // Upsert user
        $this->upsertUser($userId, $username);

        // Handle commands
        if (str_starts_with($text, '/')) {
            $this->handleCommand($chatId, $userId, $text);
        } else {
            $this->telegram->sendMessage($chatId, "Send /start to see available commands.");
        }
    }

    private function handleCommand(int $chatId, int $userId, string $text): void {
        $command = trim(strtolower(explode(' ', $text)[0]));

        switch ($command) {
            case '/start':
                $welcome = "🤖 <b>SubForSub Bot</b>\n\n";
                $welcome .= "This bot helps you create safe sub-for-sub agreements between channel owners.\n\n";
                $welcome .= "<b>How to use:</b>\n";
                $welcome .= "1. Add this bot as admin to your channel\n";
                $welcome .= "2. Create agreement with another channel owner\n";
                $welcome .= "3. Bot will monitor both channels for compliance\n\n";
                $welcome .= "<b>Commands:</b>\n";
                $welcome .= "/start - Show this message\n";
                $welcome .= "/help - Show detailed help\n";
                $welcome .= "/mychannels - List your channels\n";
                
                $this->telegram->sendMessage($chatId, $welcome);
                break;
                
            case '/help':
                $help = "📖 <b>Help Guide</b>\n\n";
                $help .= "<b>Adding bot to channel:</b>\n";
                $help .= "1. Go to your channel settings\n";
                $help .= "2. Add administrators\n";
                $help .= "3. Search for this bot and add\n";
                $help .= "4. Grant 'Ban users' permission\n\n";
                $help .= "Bot will automatically detect when it's added.\n";
                
                $this->telegram->sendMessage($chatId, $help);
                break;
                
            case '/mychannels':
                $channels = $this->db->fetchAll("
                    SELECT * FROM channels 
                    WHERE owner_id = ? AND bot_is_admin = 1
                ", [$userId]);
                
                if (empty($channels)) {
                    $this->telegram->sendMessage($chatId, "You have no channels with this bot as admin.");
                } else {
                    $response = "📋 <b>Your Channels:</b>\n\n";
                    foreach ($channels as $channel) {
                        $response .= "• " . $this->sanitizeHtml($channel['channel_title']) . "\n";
                        $response .= "  ID: <code>" . $this->sanitizeHtml((string)$channel['channel_id']) . "</code>\n\n";
                    }
                    $this->telegram->sendMessage($chatId, $response);
                }
                break;
                
            default:
                $this->telegram->sendMessage($chatId, "Unknown command. Use /start for available commands.");
        }
    }

    private function handleCallbackQuery(array $callbackQuery): void {
        $callbackId = $callbackQuery['id'];
        $userId = $callbackQuery['from']['id'];
        $data = $callbackQuery['data'];

        // Handle retaliation
        if (str_starts_with($data, 'retaliate_user_')) {
            $this->handleRetaliation($callbackId, $userId, $data);
        }
    }

    private function handleRetaliation(string $callbackId, int $victimId, string $data): void {
        // Rate limiting - 1 action per 3 seconds per user
        $rateLimitKey = 'retaliate_' . $victimId;
        $rateLimitFile = sys_get_temp_dir() . '/' . $rateLimitKey;
        
        if (file_exists($rateLimitFile) && (time() - filemtime($rateLimitFile)) < 3) {
            $this->telegram->answerCallbackQuery($callbackId, 'Please wait a moment before trying again', true);
            return;
        }
        
        touch($rateLimitFile);

        // Parse callback data
        if (!preg_match('/retaliate_user_(\d+)_channel_(\d+)/', $data, $matches)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Invalid request', true);
            unlink($rateLimitFile);
            return;
        }

        $cheaterId = (int)$matches[1];
        $cheaterChannelId = (int)$matches[2];
        
        // Validate IDs
        if ($cheaterId <= 0 || $cheaterChannelId == 0 || $victimId <= 0) {
            $this->telegram->answerCallbackQuery($callbackId, 'Invalid request parameters', true);
            $this->logError("Invalid retaliation request: cheaterId={$cheaterId}, channelId={$cheaterChannelId}, victimId={$victimId}");
            unlink($rateLimitFile);
            return;
        }

        // Find active agreement
        // Logic: victim is subscribed to cheater's channel
        // So:
        // If victim is user_a, then cheater's channel is channel_b
        // If victim is user_b, then cheater's channel is channel_a
        $agreement = $this->db->fetch("
            SELECT * FROM agreements 
            WHERE status = 'active'
            AND (
                (user_a_id = ? AND channel_b_id = ? AND user_b_id = ?) OR
                (user_b_id = ? AND channel_a_id = ? AND user_a_id = ?)
            )
        ", [$victimId, $cheaterChannelId, $cheaterId, $victimId, $cheaterChannelId, $cheaterId]);

        if (!$agreement) {
            $this->telegram->answerCallbackQuery($callbackId, 'Agreement no longer active', true);
            unlink($rateLimitFile);
            return;
        }

        // Determine victim's channel
        if ($agreement['user_a_id'] == $victimId) {
            $victimChannelId = $agreement['channel_a_id'];
        } else {
            $victimChannelId = $agreement['channel_b_id'];
        }

        // Verify victim is channel owner
        $channel = $this->db->fetch("
            SELECT * FROM channels 
            WHERE channel_id = ? AND owner_id = ? AND bot_is_admin = 1
        ", [$victimChannelId, $victimId]);

        if (!$channel) {
            $this->telegram->answerCallbackQuery($callbackId, 'You are not the owner of this channel', true);
            unlink($rateLimitFile);
            return;
        }

        // Execute ban
        $banResult = $this->telegram->banChatMember($victimChannelId, $cheaterId);

        if ($banResult['ok'] ?? false) {
            // Update agreement status
            $this->db->execute("
                UPDATE agreements 
                SET status = 'canceled' 
                WHERE id = ?
            ", [$agreement['id']]);

            $this->telegram->answerCallbackQuery($callbackId, '✅ User has been banned from your channel!', true);
            
            // Notify both parties
            $this->telegram->sendMessage($victimId, "✅ You have banned the cheater from your channel <b>" . $this->sanitizeHtml($channel['channel_title']) . "</b>.\n\nAgreement has been canceled.");
            $this->telegram->sendMessage($cheaterId, "⚠️ You have been banned from <b>" . $this->sanitizeHtml($channel['channel_title']) . "</b> for violating the sub-for-sub agreement.\n\nAgreement has been canceled.");
        } else {
            $this->telegram->answerCallbackQuery($callbackId, '❌ Failed to ban user. Please check bot permissions.', true);
        }
        
        // Clean up rate limit
        unlink($rateLimitFile);
    }

    /**
     * Sanitize text for HTML output in Telegram messages
     * Prevents XSS and HTML injection attacks
     */
    private function sanitizeHtml(?string $text): string {
        if ($text === null) {
            return '';
        }
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function upsertUser(int $telegramId, ?string $username): void {
        // Sanitize username before storing
        $sanitizedUsername = $username ? trim($username) : null;
        
        $this->db->execute("
            INSERT INTO users (telegram_id, username) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE username = VALUES(username)
        ", [$telegramId, $sanitizedUsername]);
    }

    private function upsertChannel(int $channelId, int $ownerId, string $title, bool $isAdmin): void {
        $this->db->execute("
            INSERT INTO channels (channel_id, owner_id, channel_title, bot_is_admin)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                channel_title = VALUES(channel_title),
                bot_is_admin = VALUES(bot_is_admin)
        ", [$channelId, $ownerId, $title, $isAdmin]);
    }

    private function updateChannelAdminStatus(int $channelId, bool $isAdmin): void {
        $this->db->execute("
            UPDATE channels 
            SET bot_is_admin = ? 
            WHERE channel_id = ?
        ", [$isAdmin, $channelId]);
    }

    /**
     * Webhook Setup Helper
     * 
     * To set up your webhook, uncomment and run this once:
     * 
     * $bot = new SubForSubBot();
     * $result = $bot->setupWebhook('https://your-domain.com/bot.php');
     * var_dump($result);
     */
    public function setupWebhook(string $url, ?string $secret = null): array {
        return $this->telegram->setWebhook($url, $secret);
    }

    /**
     * Webhook Deletion Helper
     * 
     * To remove your webhook:
     * $bot = new SubForSubBot();
     * $result = $bot->deleteWebhook();
     */
    public function deleteWebhook(): array {
        return $this->telegram->deleteWebhook();
    }

    private function logError(string $message): void {
        $logMessage = date('[Y-m-d H:i:s] ') . '[Bot] ' . $message . PHP_EOL;
        
        // Check if log file is writable
        if (!file_exists(LOG_FILE)) {
            // Try to create file
            if (!touch(LOG_FILE)) {
                return; // Cannot create log file
            }
        }
        
        if (is_writable(LOG_FILE)) {
            file_put_contents(LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
        }
    }
}

// Run the bot
try {
    $bot = new SubForSubBot();
    $bot->handleWebhook();
} catch (Exception $e) {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . '[FATAL] ' . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
