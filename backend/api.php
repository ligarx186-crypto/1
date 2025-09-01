<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Key, X-Telegram-Init-Data, X-Ref-Id, X-Ref-Auth');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class SecureAPI {
    private $db;
    private $authAttempts = [];
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    private function log($message) {
        error_log("[" . date('Y-m-d H:i:s') . "] API: $message");
    }
    
    private function checkTelegramAuthorization($initDataRaw, $botToken) {
        if (!$initDataRaw) return false;

        parse_str($initDataRaw, $data);
        if (!isset($data['hash'])) return false;

        $check_hash = $data['hash'];
        unset($data['hash']);

        ksort($data);
        $data_check_string = '';
        foreach ($data as $k => $v) {
            $data_check_string .= "$k=$v\n";
        }
        $data_check_string = rtrim($data_check_string, "\n");

        // Secret key
        $secret_key = hash('sha256', $botToken, true);

        // Calculate hash
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);

        return hash_equals($hash, $check_hash) ? $data : false;
    }
    
    private function validateUser($userId, $authKey, $telegramInitData = '') {
        if (empty($userId) || empty($authKey)) {
            return false;
        }
        
        // Check auth attempts limit
        $clientId = $_SERVER['REMOTE_ADDR'] . '_' . $userId;
        if (isset($this->authAttempts[$clientId]) && $this->authAttempts[$clientId] >= 3) {
            return false;
        }
        
        // Validate Telegram data if provided
        if ($telegramInitData) {
            $telegramData = $this->checkTelegramAuthorization($telegramInitData, BOT_TOKEN);
            if (!$telegramData || $telegramData['user']['id'] != $userId) {
                $this->authAttempts[$clientId] = ($this->authAttempts[$clientId] ?? 0) + 1;
                return false;
            }
        }
        
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND auth_key = ? AND status = 'active'");
        $stmt->execute([$userId, $authKey]);
        return $stmt->fetchColumn() !== false;
    }
    
    private function checkRateLimit($ip) {
        $stmt = $this->db->prepare("SELECT request_count, window_start FROM rate_limits WHERE ip = ?");
        $stmt->execute([$ip]);
        $result = $stmt->fetch();
        
        $now = time();
        $windowStart = $now - RATE_LIMIT_WINDOW;
        
        if ($result) {
            if ($result['window_start'] < $windowStart) {
                $stmt = $this->db->prepare("UPDATE rate_limits SET request_count = 1, window_start = ? WHERE ip = ?");
                $stmt->execute([$now, $ip]);
                return true;
            } elseif ($result['request_count'] >= RATE_LIMIT_REQUESTS) {
                return false;
            } else {
                $stmt = $this->db->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip = ?");
                $stmt->execute([$ip]);
                return true;
            }
        } else {
            $stmt = $this->db->prepare("INSERT INTO rate_limits (ip, request_count, window_start) VALUES (?, 1, ?)");
            $stmt->execute([$ip, $now]);
            return true;
        }
    }
    
    public function handleRequest() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        if (!$this->checkRateLimit($ip)) {
            http_response_code(429);
            echo json_encode(['error' => 'Rate limit exceeded']);
            return;
        }
        
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_GET['path'] ?? '';
        $authKey = $_SERVER['HTTP_X_AUTH_KEY'] ?? '';
        $telegramInitData = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
        $refId = $_SERVER['HTTP_X_REF_ID'] ?? '';
        $refAuth = $_SERVER['HTTP_X_REF_AUTH'] ?? '';
        
        $this->log("$method $path from $ip");
        
        try {
            switch ($path) {
                case 'auth':
                    $this->handleAuth($telegramInitData, $refId, $refAuth);
                    break;
                case 'user':
                    $this->handleUser($authKey, $telegramInitData);
                    break;
                case 'missions':
                    $this->handleMissions();
                    break;
                case 'user-missions':
                    $this->handleUserMissions($authKey, $telegramInitData);
                    break;
                case 'referrals':
                    $this->handleReferrals($authKey, $telegramInitData);
                    break;
                case 'conversions':
                    $this->handleConversions($authKey, $telegramInitData);
                    break;
                case 'config':
                    $this->handleConfig();
                    break;
                case 'wallet-categories':
                    $this->handleWalletCategories();
                    break;
                case 'leaderboard':
                    $this->handleLeaderboard();
                    break;
                case 'verify-telegram':
                    $this->handleTelegramVerification($authKey, $telegramInitData);
                    break;
                case 'submit-promo-code':
                    $this->handlePromoCodeSubmission($authKey, $telegramInitData);
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Endpoint not found']);
            }
        } catch (Exception $e) {
            $this->log("Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    private function handleAuth($telegramInitData, $refId, $refAuth) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['userId'] ?? '';
        
        if (empty($userId)) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID required']);
            return;
        }
        
        // Validate Telegram data
        if ($telegramInitData) {
            $telegramData = $this->checkTelegramAuthorization($telegramInitData, BOT_TOKEN);
            if (!$telegramData || $telegramData['user']['id'] != $userId) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid Telegram authorization']);
                return;
            }
        }
        
        // Check if user exists
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $existingUser = $stmt->fetch();
        
        if ($existingUser) {
            // Update last active and calculate offline mining
            $now = time() * 1000;
            if ($existingUser['is_mining'] && $existingUser['mining_start_time']) {
                $offlineDuration = floor(($now - $existingUser['mining_start_time']) / 1000);
                $limitedDuration = min($offlineDuration, MAX_MINING_TIME);
                
                if ($limitedDuration > 0) {
                    $earned = $existingUser['mining_rate'] * $limitedDuration;
                    $stmt = $this->db->prepare("UPDATE users SET 
                        pending_rewards = ?, 
                        last_active = ? 
                        WHERE id = ?");
                    $stmt->execute([$earned, $now, $userId]);
                    
                    // Refresh user data
                    $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $existingUser = $stmt->fetch();
                }
            } else {
                $stmt = $this->db->prepare("UPDATE users SET last_active = ? WHERE id = ?");
                $stmt->execute([$now, $userId]);
            }
            
            echo json_encode([
                'success' => true,
                'authKey' => $existingUser['auth_key'],
                'isNewUser' => false,
                'userData' => $this->formatUserData($existingUser)
            ]);
            return;
        }
        
        // Create new user with game data
        $authKey = bin2hex(random_bytes(32));
        $now = time() * 1000;
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("INSERT INTO users (
                id, first_name, last_name, avatar_url, auth_key, 
                referred_by, ref_auth_used, joined_at, last_active, 
                balance, total_earned, mining_rate, min_claim_time, 
                mining_speed_level, claim_time_level, mining_rate_level,
                bonus_claimed, data_initialized
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, FALSE)");
            
            $stmt->execute([
                $userId,
                $input['firstName'] ?? 'User',
                $input['lastName'] ?? '',
                $input['avatarUrl'] ?? '',
                $authKey,
                $refId ?? '',
                $refAuth ?? '',
                $now,
                $now,
                0, // No welcome bonus here - will be claimed in app
                0,
                BASE_MINING_RATE,
                MIN_CLAIM_TIME,
                1, 1, 1
            ]);
            
            $this->db->commit();
            
            // Get created user data
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'authKey' => $authKey,
                'isNewUser' => true,
                'userData' => $this->formatUserData($userData)
            ]);
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->log("User creation failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create user']);
        }
    }
    
    private function handleUser($authKey, $telegramInitData) {
        $userId = $_GET['userId'] ?? '';
        
        if (!$this->validateUser($userId, $authKey, $telegramInitData)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            if ($userData) {
                echo json_encode($this->formatUserData($userData));
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Handle welcome bonus claim
            if (isset($input['claimWelcomeBonus']) && $input['claimWelcomeBonus']) {
                $stmt = $this->db->prepare("SELECT bonus_claimed, referred_by, ref_auth_used FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                if (!$user['bonus_claimed']) {
                    $this->db->beginTransaction();
                    
                    // Give welcome bonus
                    $stmt = $this->db->prepare("UPDATE users SET 
                        balance = balance + ?, 
                        total_earned = total_earned + ?, 
                        bonus_claimed = TRUE,
                        data_initialized = TRUE
                        WHERE id = ?");
                    $stmt->execute([WELCOME_BONUS, WELCOME_BONUS, $userId]);
                    
                    // Process referral bonus if valid
                    if ($user['referred_by'] && $user['ref_auth_used']) {
                        $this->processReferralBonus($user['referred_by'], $userId, $user['ref_auth_used']);
                    }
                    
                    $this->db->commit();
                }
            }
            
            // Update other user data
            $stmt = $this->db->prepare("UPDATE users SET 
                balance = ?, total_earned = ?, is_mining = ?, mining_start_time = ?,
                last_claim_time = ?, pending_rewards = ?, mining_rate = ?, min_claim_time = ?,
                mining_speed_level = ?, claim_time_level = ?, mining_rate_level = ?,
                sound_enabled = ?, vibration_enabled = ?, notifications_enabled = ?,
                bonus_claimed = ?, data_initialized = ?, xp = ?, level_num = ?,
                last_active = ?, pubg_id = ?
                WHERE id = ?");
            
            $result = $stmt->execute([
                $input['balance'] ?? 0,
                $input['totalEarned'] ?? 0,
                $input['isMining'] ?? false,
                $input['miningStartTime'] ?? 0,
                $input['lastClaimTime'] ?? 0,
                $input['pendingRewards'] ?? 0,
                $input['miningRate'] ?? BASE_MINING_RATE,
                $input['minClaimTime'] ?? MIN_CLAIM_TIME,
                $input['boosts']['miningSpeedLevel'] ?? 1,
                $input['boosts']['claimTimeLevel'] ?? 1,
                $input['boosts']['miningRateLevel'] ?? 1,
                $input['settings']['sound'] ?? true,
                $input['settings']['vibration'] ?? true,
                $input['settings']['notifications'] ?? true,
                $input['bonusClaimed'] ?? false,
                $input['dataInitialized'] ?? false,
                $input['xp'] ?? 0,
                $input['level'] ?? 1,
                time() * 1000,
                $input['pubgId'] ?? '',
                $userId
            ]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update user']);
            }
        }
    }
    
    private function processReferralBonus($referrerId, $referredId, $refAuth) {
        try {
            // Validate referrer and ref_auth
            $stmt = $this->db->prepare("SELECT ref_auth FROM users WHERE id = ?");
            $stmt->execute([$referrerId]);
            $referrerRefAuth = $stmt->fetchColumn();
            
            if (!$referrerRefAuth || $referrerRefAuth !== $refAuth) {
                $this->log("Invalid ref_auth for referrer: $referrerId");
                return false;
            }
            
            // Check if referral already processed
            $stmt = $this->db->prepare("SELECT id FROM referrals WHERE referrer_id = ? AND referred_id = ?");
            $stmt->execute([$referrerId, $referredId]);
            if ($stmt->fetchColumn()) {
                return false;
            }
            
            // Add referral record
            $stmt = $this->db->prepare("INSERT INTO referrals (referrer_id, referred_id, earned) VALUES (?, ?, ?)");
            $stmt->execute([$referrerId, $referredId, REFERRAL_BONUS]);
            
            // Update referrer's balance
            $stmt = $this->db->prepare("UPDATE users SET 
                balance = balance + ?, 
                total_earned = total_earned + ?, 
                referral_count = referral_count + 1,
                xp = xp + 60
                WHERE id = ?");
            $stmt->execute([REFERRAL_BONUS, REFERRAL_BONUS, $referrerId]);
            
            $this->log("Referral bonus processed: $referrerId -> $referredId");
            return true;
        } catch (Exception $e) {
            $this->log("Referral processing failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function handleMissions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $stmt = $this->db->prepare("SELECT * FROM missions WHERE active = TRUE ORDER BY priority ASC");
        $stmt->execute();
        $missions = $stmt->fetchAll();
        
        $result = [];
        foreach ($missions as $mission) {
            $result[$mission['id']] = [
                'id' => $mission['id'],
                'title' => $mission['title'],
                'description' => $mission['description'],
                'detailedDescription' => $mission['detailed_description'],
                'reward' => (int)$mission['reward'],
                'requiredCount' => (int)$mission['required_count'],
                'channelId' => $mission['channel_id'],
                'url' => $mission['url'],
                'code' => $mission['code'],
                'requiredTime' => $mission['required_time'] ? (int)$mission['required_time'] : null,
                'active' => (bool)$mission['active'],
                'category' => $mission['category'],
                'type' => $mission['type'],
                'icon' => $mission['icon'],
                'img' => $mission['img'],
                'priority' => (int)$mission['priority'],
                'instructions' => $mission['instructions'] ? json_decode($mission['instructions'], true) : [],
                'tips' => $mission['tips'] ? json_decode($mission['tips'], true) : [],
                'createdAt' => $mission['created_at']
            ];
        }
        
        echo json_encode($result);
    }
    
    private function handlePromoCodeSubmission($authKey, $telegramInitData) {
        $userId = $_GET['userId'] ?? '';
        
        if (!$this->validateUser($userId, $authKey, $telegramInitData)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $code = trim($input['code'] ?? '');
        
        if (empty($code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Code required']);
            return;
        }
        
        try {
            // Check for multi-promo code first
            $stmt = $this->db->prepare("SELECT * FROM promo_codes WHERE code = ? AND (expires_at IS NULL OR expires_at > NOW()) AND used_by IS NULL");
            $stmt->execute([$code]);
            $promoCode = $stmt->fetch();
            
            if ($promoCode) {
                // Mark promo code as used
                $stmt = $this->db->prepare("UPDATE promo_codes SET used_by = ?, used_at = NOW() WHERE id = ?");
                $stmt->execute([$userId, $promoCode['id']]);
                
                // Update user balance
                $stmt = $this->db->prepare("UPDATE users SET balance = balance + ?, total_earned = total_earned + ? WHERE id = ?");
                $stmt->execute([$promoCode['reward'], $promoCode['reward'], $userId]);
                
                echo json_encode([
                    'success' => true,
                    'reward' => (int)$promoCode['reward'],
                    'message' => 'Multi-promo code redeemed successfully!'
                ]);
                return;
            }
            
            // Check regular mission promo codes
            $stmt = $this->db->prepare("SELECT * FROM missions WHERE code = ? AND type = 'promo_code' AND active = TRUE");
            $stmt->execute([$code]);
            $mission = $stmt->fetch();
            
            if ($mission) {
                echo json_encode([
                    'success' => true,
                    'missionId' => $mission['id'],
                    'reward' => (int)$mission['reward'],
                    'message' => 'Mission promo code verified!'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid or expired promo code'
                ]);
            }
            
        } catch (Exception $e) {
            $this->log("Promo code submission failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process promo code']);
        }
    }
    
    private function handleWalletCategories() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $stmt = $this->db->prepare("SELECT * FROM wallet_categories WHERE active = TRUE ORDER BY priority ASC");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        $result = [];
        foreach ($categories as $category) {
            $packages = json_decode($category['packages'], true) ?: [];
            $requiredFields = json_decode($category['required_fields'], true) ?: [];
            
            $result[] = [
                'id' => $category['id'],
                'name' => $category['name'],
                'description' => $category['description'],
                'image' => $category['image'],
                'active' => (bool)$category['active'],
                'conversionRate' => (float)$category['conversion_rate'],
                'minConversion' => (int)$category['min_conversion'],
                'maxConversion' => (int)$category['max_conversion'],
                'processingTime' => $category['processing_time'],
                'instructions' => $category['instructions'],
                'packages' => $packages,
                'requiredFields' => $requiredFields
            ];
        }
        
        echo json_encode($result);
    }
    
    // ... (other methods remain similar with proper validation)
    
    private function formatUserData($userData) {
        return [
            'id' => $userData['id'],
            'firstName' => $userData['first_name'],
            'lastName' => $userData['last_name'],
            'avatarUrl' => $userData['avatar_url'],
            'authKey' => $userData['auth_key'],
            'balance' => (float)$userData['balance'],
            'ucBalance' => (float)$userData['uc_balance'],
            'energyLimit' => (int)$userData['energy_limit'],
            'multiTapValue' => (int)$userData['multi_tap_value'],
            'rechargingSpeed' => (int)$userData['recharging_speed'],
            'tapBotPurchased' => (bool)$userData['tap_bot_purchased'],
            'tapBotActive' => (bool)$userData['tap_bot_active'],
            'bonusClaimed' => (bool)$userData['bonus_claimed'],
            'pubgId' => $userData['pubg_id'],
            'totalTaps' => (int)$userData['total_taps'],
            'totalEarned' => (float)$userData['total_earned'],
            'lastJackpotTime' => (int)$userData['last_jackpot_time'],
            'referredBy' => $userData['referred_by'],
            'referralCount' => (int)$userData['referral_count'],
            'level' => (int)$userData['level_num'],
            'xp' => (int)$userData['xp'],
            'streak' => (int)$userData['streak'],
            'combo' => (int)$userData['combo'],
            'lastTapTime' => (int)$userData['last_tap_time'],
            'isMining' => (bool)$userData['is_mining'],
            'miningStartTime' => (int)$userData['mining_start_time'],
            'lastClaimTime' => (int)$userData['last_claim_time'],
            'pendingRewards' => (float)$userData['pending_rewards'],
            'miningRate' => (float)$userData['mining_rate'],
            'minClaimTime' => (int)$userData['min_claim_time'],
            'settings' => [
                'sound' => (bool)$userData['sound_enabled'],
                'vibration' => (bool)$userData['vibration_enabled'],
                'notifications' => (bool)$userData['notifications_enabled']
            ],
            'boosts' => [
                'miningSpeedLevel' => (int)$userData['mining_speed_level'],
                'claimTimeLevel' => (int)$userData['claim_time_level'],
                'miningRateLevel' => (int)$userData['mining_rate_level']
            ],
            'missions' => new stdClass(),
            'withdrawals' => [],
            'conversions' => [],
            'joinedAt' => (int)$userData['joined_at'],
            'lastActive' => (int)$userData['last_active'],
            'isReturningUser' => (bool)$userData['is_returning_user'],
            'dataInitialized' => (bool)$userData['data_initialized']
        ];
    }
}

// Initialize and handle request
$api = new SecureAPI();
$api->handleRequest();
?>