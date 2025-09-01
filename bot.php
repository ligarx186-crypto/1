<?php
require_once 'backend/config.php';

// Bot configuration
$botToken = BOT_TOKEN;
$botUsername = BOT_USERNAME;
$webAppUrl = WEBAPP_URL; // Will be set in config

class TelegramBot {
    private $db;
    private $botToken;
    private $webAppUrl;
    
    public function __construct($token, $webAppUrl) {
        $this->botToken = $token;
        $this->webAppUrl = $webAppUrl;
        $this->db = Database::getInstance()->getConnection();
    }
    
    private function log($message) {
        error_log("[" . date('Y-m-d H:i:s') . "] Bot: $message");
    }
    
    private function sendMessage($chatId, $text, $replyMarkup = null) {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        
        $context = stream_context_create($options);
        return file_get_contents($url, false, $context);
    }
    
    private function generateAuthKey() {
        return bin2hex(random_bytes(32));
    }
    
    private function generateRefAuth() {
        return bin2hex(random_bytes(16)); // 32 character hex string
    }
    
    private function generateAuthUrl($userId, $authKey, $refId = null, $refAuth = null) {
        $params = [
            'id' => $userId,
            'authKey' => $authKey
        ];
        
        if ($refId && $refAuth) {
            $params['ref'] = $refId;
            $params['refauth'] = $refAuth;
        }
        
        $queryString = http_build_query($params);
        return $this->webAppUrl . '?' . $queryString;
    }
    
    private function getUser($userId) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    private function downloadAndSaveAvatar($userId, $avatarUrl) {
        if (empty($avatarUrl)) return '';
        
        try {
            $avatarDir = __DIR__ . '/avatars/';
            if (!is_dir($avatarDir)) {
                mkdir($avatarDir, 0755, true);
            }
            
            $avatarPath = $avatarDir . $userId . '.png';
            $avatarContent = file_get_contents($avatarUrl);
            
            if ($avatarContent !== false) {
                file_put_contents($avatarPath, $avatarContent);
                return AVATAR_BASE_URL . '/' . $userId . '.png';
            }
        } catch (Exception $e) {
            $this->log("Failed to download avatar for user $userId: " . $e->getMessage());
        }
        
        return '';
    }
    
    private function createUser($userData) {
        $authKey = $this->generateAuthKey();
        $refAuth = $this->generateRefAuth();
        $now = time() * 1000;
        
        try {
            $this->db->beginTransaction();
            
            // Download and save avatar
            $localAvatarUrl = '';
            if (!empty($userData['avatar_url'])) {
                $localAvatarUrl = $this->downloadAndSaveAvatar($userData['id'], $userData['avatar_url']);
            }
            
            $stmt = $this->db->prepare("INSERT INTO users (
                id, first_name, last_name, avatar_url, auth_key, ref_auth,
                referred_by, ref_auth_used, joined_at, last_active, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            
            $stmt->execute([
                $userData['id'],
                $userData['first_name'],
                $userData['last_name'] ?? '',
                $localAvatarUrl,
                $authKey,
                $refAuth,
                $userData['referred_by'] ?? '',
                $userData['ref_auth_used'] ?? '',
                $now,
                $now
            ]);
            
            $this->db->commit();
            return ['authKey' => $authKey, 'refAuth' => $refAuth];
        } catch (Exception $e) {
            $this->db->rollback();
            $this->log("User creation failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function handleWebhook() {
        $input = file_get_contents('php://input');
        $update = json_decode($input, true);
        
        if (!$update) {
            return;
        }
        
        $this->log("Received update: " . json_encode($update));
        
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }
    
    private function handleMessage($message) {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';
        
        if (strpos($text, '/start') === 0) {
            $this->handleStartCommand($chatId, $userId, $message['from'], $text);
        } elseif ($text === '/help') {
            $this->handleHelpCommand($chatId);
        } elseif ($text === '/stats') {
            $this->handleStatsCommand($chatId, $userId);
        } else {
            $this->handleRegularMessage($chatId, $userId);
        }
    }
    
    private function handleStartCommand($chatId, $userId, $user, $text) {
        $refId = null;
        $refAuth = null;
        
        // Parse referral from start parameter
        if (preg_match('/\/start\s+(.+)/', $text, $matches)) {
            $startParam = $matches[1];
            if (strpos($startParam, 'ref_') === 0) {
                $refId = substr($startParam, 4);
                
                // Get referrer's ref_auth
                $stmt = $this->db->prepare("SELECT ref_auth FROM users WHERE id = ?");
                $stmt->execute([$refId]);
                $refAuth = $stmt->fetchColumn();
            }
        }
        
        try {
            // Check if user exists
            $existingUser = $this->getUser($userId);
            
            if ($existingUser) {
                // Existing user
                $authKey = $existingUser['auth_key'];
                $refAuth = $existingUser['ref_auth'];
                
                // Update last active
                $stmt = $this->db->prepare("UPDATE users SET last_active = ? WHERE id = ?");
                $stmt->execute([time() * 1000, $userId]);
                
                $welcomeText = "🎮 Welcome back, {$user['first_name']}!\n\n⛏️ Continue your DRX mining journey!";
            } else {
                // New user - create account with only Telegram data
                $userData = [
                    'id' => $userId,
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'] ?? '',
                    'avatar_url' => $user['photo_url'] ?? '',
                    'referred_by' => $refId ?? '',
                    'ref_auth_used' => $refAuth ?? ''
                ];
                
                $result = $this->createUser($userData);
                
                if (!$result) {
                    $this->sendMessage($chatId, "❌ Something went wrong. Please try again later.");
                    return;
                }
                
                $authKey = $result['authKey'];
                $refAuth = $result['refAuth'];
                
                $welcomeText = "🎮 Welcome to DRX Mining, {$user['first_name']}!\n\n⛏️ Start mining DRX coins\n💎 Complete missions for rewards\n👥 Invite friends to earn more!";
            }
            
            // Generate secure auth URL
            $authUrl = $this->generateAuthUrl($userId, $authKey, $refId, $refAuth);
            
            // Create inline keyboard
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🎮 Open DRX Mining',
                            'web_app' => ['url' => $authUrl]
                        ]
                    ],
                    [
                        [
                            'text' => '📢 Join Channel',
                            'url' => 'https://t.me/ligarx_boy'
                        ]
                    ],
                    [
                        [
                            'text' => '👥 Invite Friends',
                            'switch_inline_query' => "🎮 Join DRX Mining and start earning!\n\n💎 Get welcome bonus\n⛏️ Mine to earn more DRX\n🎁 Complete missions for rewards\n\nJoin: https://t.me/{$botUsername}?start=ref_{$userId}"
                        ]
                    ]
                ]
            ];
            
            $this->sendMessage($chatId, $welcomeText, $keyboard);
            
        } catch (Exception $e) {
            $this->log("Start command failed for user $userId: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ Something went wrong. Please try again later.");
        }
    }
    
    private function handleHelpCommand($chatId) {
        $helpText = "🎮 <b>DRX Mining Bot Help</b>\n\n";
        $helpText .= "⛏️ <b>Mining:</b>\n";
        $helpText .= "• Start mining to earn DRX coins\n";
        $helpText .= "• Minimum mining time: 5 minutes\n";
        $helpText .= "• Maximum mining time: 24 hours\n";
        $helpText .= "• Works offline - rewards accumulate!\n\n";
        $helpText .= "🚀 <b>Boosts:</b>\n";
        $helpText .= "• Mining Speed: Increase efficiency\n";
        $helpText .= "• Claim Time: Reduce minimum wait time\n";
        $helpText .= "• Mining Rate: Earn more DRX per second\n\n";
        $helpText .= "🎯 <b>Missions:</b>\n";
        $helpText .= "• Join channels for rewards\n";
        $helpText .= "• Complete timer tasks\n";
        $helpText .= "• Enter promo codes\n";
        $helpText .= "• Earn bonus DRX\n\n";
        $helpText .= "💰 <b>Wallet:</b>\n";
        $helpText .= "• Convert DRX to UC or Stars\n";
        $helpText .= "• Instant processing\n";
        $helpText .= "• Secure transactions\n\n";
        $helpText .= "👥 <b>Referrals:</b>\n";
        $helpText .= "• Invite friends with your link\n";
        $helpText .= "• Earn " . REFERRAL_BONUS . " DRX per referral\n";
        $helpText .= "• Build your mining network\n\n";
        $helpText .= "🔧 <b>Commands:</b>\n";
        $helpText .= "/start - Start the bot\n";
        $helpText .= "/help - Show this help\n";
        $helpText .= "/stats - View your statistics";
        
        $this->sendMessage($chatId, $helpText);
    }
    
    private function handleStatsCommand($chatId, $userId) {
        try {
            $userData = $this->getUser($userId);
            if (!$userData) {
                $this->sendMessage($chatId, "❌ User not found. Please use /start first.");
                return;
            }
            
            $statsText = "📊 <b>Your Statistics</b>\n\n";
            $statsText .= "👤 <b>User ID:</b> {$userData['id']}\n";
            $statsText .= "📅 <b>Joined:</b> " . date('Y-m-d', $userData['joined_at']/1000) . "\n";
            $statsText .= "🔑 <b>Auth Key:</b> " . substr($userData['auth_key'], 0, 8) . "...\n";
            $statsText .= "🔗 <b>Ref Auth:</b> " . substr($userData['ref_auth'], 0, 8) . "...\n\n";
            $statsText .= "🎮 <b>Open the app to see full statistics!</b>";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🎮 Open Game',
                            'web_app' => ['url' => $this->generateAuthUrl($userId, $userData['auth_key'])]
                        ]
                    ]
                ]
            ];
            
            $this->sendMessage($chatId, $statsText, $keyboard);
            
        } catch (Exception $e) {
            $this->log("Stats command failed: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ Failed to get statistics.");
        }
    }
    
    private function handleRegularMessage($chatId, $userId) {
        // Check if user exists
        $userData = $this->getUser($userId);
        if (!$userData) {
            $this->sendMessage($chatId, "👋 Welcome! Please use /start to begin your DRX mining journey!");
            return;
        }
        
        // Generate game URL
        $authUrl = $this->generateAuthUrl($userId, $userData['auth_key']);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🎮 Open DRX Mining',
                        'web_app' => ['url' => $authUrl]
                    ]
                ]
            ]
        ];
        
        $this->sendMessage($chatId, "🎮 Click the button below to open DRX Mining!", $keyboard);
    }
}

// Handle webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get webapp URL from config
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT setting_value FROM config WHERE setting_key = 'webapp_url'");
    $stmt->execute();
    $webAppUrl = $stmt->fetchColumn() ?: 'https://your-domain.com';
    
    $bot = new TelegramBot($botToken, $webAppUrl);
    $bot->handleWebhook();
} else {
    echo "DRX Mining Bot is running!";
}
?>