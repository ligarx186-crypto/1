<?php
require_once 'backend/config.php';

// Bot configuration
$botToken = '8188857509:AAHjKKUaC_kljF1KKHZ0VW1pWkcWDfaY65k';
$botUsername = 'tanga';
$webAppUrl = 'https://your-domain.com';
$avatarBaseUrl = 'http://c828.coresuz.ru/avatars';

class TelegramBot {
    private $db;
    private $botToken;
    private $webAppUrl;
    private $avatarBaseUrl;
    
    public function __construct($token, $webAppUrl, $avatarBaseUrl) {
        $this->botToken = $token;
        $this->webAppUrl = $webAppUrl;
        $this->avatarBaseUrl = $avatarBaseUrl;
        $this->db = Database::getInstance()->getConnection();
    }
    
    private function log($message) {
        error_log("[" . date('Y-m-d H:i:s') . "] Bot: $message");
    }
    
    private function logError($message) {
        error_log("[" . date('Y-m-d H:i:s') . "] Bot ERROR: $message");
    }
    
    private function sendMessage($chatId, $text, $replyMarkup = null) {
        try {
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
        } catch (Exception $e) {
            $this->logError("Failed to send message to $chatId: " . $e->getMessage());
            return false;
        }
    }
    
    private function generateAuthKey() {
        return bin2hex(random_bytes(32));
    }
    
    private function generateRefAuth() {
        return bin2hex(random_bytes(16));
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
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            $this->logError("Failed to get user $userId: " . $e->getMessage());
            return false;
        }
    }
    
    private function downloadAndSaveAvatar($userId, $avatarUrl) {
        if (empty($avatarUrl)) return '';
        
        try {
            $avatarDir = dirname(__FILE__) . '/avatars/';
            if (!is_dir($avatarDir)) {
                if (!mkdir($avatarDir, 0755, true)) {
                    $this->logError("Failed to create avatar directory for user $userId");
                    return '';
                }
            }
            
            $avatarPath = $avatarDir . $userId . '.png';
            
            // Check if avatar already exists and URL hasn't changed
            $stmt = $this->db->prepare("SELECT avatar_url FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $currentAvatarUrl = $stmt->fetchColumn();
            
            $expectedUrl = $this->avatarBaseUrl . '/' . $userId . '.png';
            if ($currentAvatarUrl && file_exists($avatarPath) && $currentAvatarUrl === $expectedUrl) {
                return $currentAvatarUrl; // Avatar already exists and hasn't changed
            }
            
            // Download avatar with error handling
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (compatible; TelegramBot/1.0)'
                ]
            ]);
            
            $avatarContent = @file_get_contents($avatarUrl, false, $context);
            
            if ($avatarContent !== false) {
                if (file_put_contents($avatarPath, $avatarContent)) {
                    $this->log("Avatar saved for user $userId");
                    return $expectedUrl;
                } else {
                    $this->logError("Failed to save avatar file for user $userId");
                }
            } else {
                $this->logError("Failed to download avatar from $avatarUrl for user $userId");
            }
        } catch (Exception $e) {
            $this->logError("Avatar download exception for user $userId: " . $e->getMessage());
        }
        
        return '';
    }
    
    private function createUser($userData) {
        $authKey = $this->generateAuthKey();
        $refAuth = $this->generateRefAuth();
        $now = time() * 1000;
        
        try {
            $this->db->beginTransaction();
            
            // Download and save avatar only for new users
            $localAvatarUrl = '';
            if (!empty($userData['avatar_url'])) {
                $localAvatarUrl = $this->downloadAndSaveAvatar($userData['id'], $userData['avatar_url']);
            }
            
            $stmt = $this->db->prepare("INSERT INTO users (
                id, first_name, last_name, avatar_url, auth_key, ref_auth,
                referred_by, ref_auth_used, joined_at, last_active, 
                balance, total_earned, mining_rate, min_claim_time, 
                mining_speed_level, claim_time_level, mining_rate_level,
                bonus_claimed, data_initialized, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, FALSE, 'active')");
            
            $result = $stmt->execute([
                $userData['id'],
                $userData['first_name'],
                $userData['last_name'] ?? '',
                $localAvatarUrl,
                $authKey,
                $refAuth,
                $userData['referred_by'] ?? '',
                $userData['ref_auth_used'] ?? '',
                $now,
                $now,
                0, // No welcome bonus here - will be claimed in app
                0,
                BASE_MINING_RATE,
                MIN_CLAIM_TIME,
                1, 1, 1
            ]);
            
            if ($result) {
                $this->db->commit();
                $this->log("User created successfully: " . $userData['id']);
                return ['authKey' => $authKey, 'refAuth' => $refAuth];
            } else {
                $this->db->rollback();
                $this->logError("Failed to insert user data for: " . $userData['id']);
                return false;
            }
        } catch (Exception $e) {
            $this->db->rollback();
            $this->logError("User creation failed for user " . $userData['id'] . ": " . $e->getMessage());
            return false;
        }
    }
    
    private function updateUserAvatar($userId, $newAvatarUrl) {
        if (empty($newAvatarUrl)) return;
        
        try {
            // Check if avatar has changed
            $stmt = $this->db->prepare("SELECT avatar_url FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $currentAvatarUrl = $stmt->fetchColumn();
            
            $expectedUrl = $this->avatarBaseUrl . '/' . $userId . '.png';
            
            // Only update if avatar URL has changed or doesn't exist
            if ($currentAvatarUrl !== $expectedUrl) {
                $localAvatarUrl = $this->downloadAndSaveAvatar($userId, $newAvatarUrl);
                if ($localAvatarUrl) {
                    $stmt = $this->db->prepare("UPDATE users SET avatar_url = ? WHERE id = ?");
                    $stmt->execute([$localAvatarUrl, $userId]);
                    $this->log("Avatar updated for user $userId");
                }
            }
        } catch (Exception $e) {
            $this->logError("Failed to update avatar for user $userId: " . $e->getMessage());
        }
    }
    
    public function handleWebhook() {
        try {
            $input = file_get_contents('php://input');
            $update = json_decode($input, true);
            
            if (!$update) {
                $this->logError("Invalid webhook data received");
                return;
            }
            
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }
        } catch (Exception $e) {
            $this->logError("Webhook handling failed: " . $e->getMessage());
        }
    }
    
    private function handleMessage($message) {
        try {
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
        } catch (Exception $e) {
            $this->logError("Message handling failed: " . $e->getMessage());
        }
    }
    
    private function handleStartCommand($chatId, $userId, $user, $text) {
        $refId = null;
        $refAuth = null;
        
        try {
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
            
            // Check if user exists
            $existingUser = $this->getUser($userId);
            
            if ($existingUser) {
                // Existing user - check for avatar updates
                if (!empty($user['photo_url'])) {
                    $this->updateUserAvatar($userId, $user['photo_url']);
                }
                
                $authKey = $existingUser['auth_key'];
                
                // Update last active
                $stmt = $this->db->prepare("UPDATE users SET last_active = ? WHERE id = ?");
                $stmt->execute([time() * 1000, $userId]);
                
                $welcomeText = "🎮 Welcome back, {$user['first_name']}!\n\n⛏️ Continue your DRX mining journey!";
            } else {
                // New user - create account with Telegram data
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
                
                $welcomeText = "🎮 Welcome to DRX Mining, {$user['first_name']}!\n\n⛏️ Start mining DRX coins\n💎 Complete missions for rewards\n👥 Invite friends to earn more!";
            }
            
            // Generate secure auth URL
            $authUrl = $this->generateAuthUrl($userId, $authKey, $refId, $refAuth);
            
            // Send welcome message with photo and buttons
            $this->sendWelcomeMessage($chatId, $welcomeText, $authUrl, $userId);
            
        } catch (Exception $e) {
            $this->logError("Start command failed for user $userId: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ Something went wrong. Please try again later.");
        }
    }
    
    private function sendWelcomeMessage($chatId, $text, $authUrl, $userId) {
        try {
            // Send photo with caption and inline keyboard
            $photoUrl = "https://api.telegram.org/bot{$this->botToken}/sendPhoto";
            
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
                            'switch_inline_query' => "🎮 Join DRX Mining and start earning!\n\n💎 Get welcome bonus\n⛏️ Mine to earn more DRX\n🎁 Complete missions for rewards\n\nJoin: https://t.me/{$this->botUsername}?start=ref_{$userId}"
                        ]
                    ]
                ]
            ];
            
            $data = [
                'chat_id' => $chatId,
                'photo' => 'https://i.ibb.co/whrjJxzQ/download-2.png',
                'caption' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ];
            
            $options = [
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($data),
                    'timeout' => 10
                ]
            ];
            
            $context = stream_context_create($options);
            $response = @file_get_contents($photoUrl, false, $context);
            
            if ($response === false) {
                $this->logError("Failed to send welcome photo to $chatId");
                // Fallback to text message
                $this->sendMessage($chatId, $text, $keyboard);
            }
        } catch (Exception $e) {
            $this->logError("Welcome message failed for chat $chatId: " . $e->getMessage());
        }
    }
    
    private function handleHelpCommand($chatId) {
        $helpText = "🎮 <b>DRX Mining Bot Help</b>\n\n";
        $helpText .= "⛏️ <b>Mining:</b>\n";
        $helpText .= "• Start mining to earn DRX coins\n";
        $helpText .= "• Minimum mining time: 30 minutes\n";
        $helpText .= "• Works offline - rewards accumulate!\n";
        $helpText .= "• 5-minute cooldown between claims\n\n";
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
            $statsText .= "💰 <b>Balance:</b> " . number_format($userData['balance'], 3) . " DRX\n";
            $statsText .= "⛏️ <b>Total Earned:</b> " . number_format($userData['total_earned'], 3) . " DRX\n";
            $statsText .= "👥 <b>Referrals:</b> {$userData['referral_count']}\n";
            $statsText .= "📅 <b>Joined:</b> " . date('Y-m-d', $userData['joined_at']/1000) . "\n\n";
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
            $this->logError("Stats command failed: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ Failed to get statistics.");
        }
    }
    
    private function handleRegularMessage($chatId, $userId) {
        try {
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
        } catch (Exception $e) {
            $this->logError("Regular message handling failed: " . $e->getMessage());
        }
    }
}

// Handle webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $bot = new TelegramBot($botToken, $webAppUrl, $avatarBaseUrl);
        $bot->handleWebhook();
    } catch (Exception $e) {
        error_log("Bot webhook error: " . $e->getMessage());
    }
} else {
    echo "DRX Mining Bot is running!";
}
?>