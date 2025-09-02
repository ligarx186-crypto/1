<?php
require_once 'config.php';

// Bot configuration - moved from config.php
define('BOT_TOKEN', '7270345128:AAEuRX7lABDMBRh6lRU1d-4aFzbiIhNgOWE');
define('BOT_USERNAME', 'UCCoinUltraBot');
define('WEBAPP_URL', 'https://your-domain.com');
define('AVATAR_BASE_URL', 'https://your-domain.com/avatars');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Key, X-Telegram-Init-Data, X-Ref-Id, X-Ref-Auth');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class FastSecureAPI {
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

        $secret_key = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);

        return hash_equals($hash, $check_hash) ? $data : false;
    }
    
    private function validateUser($userId, $authKey, $telegramInitData = '') {
        if (empty($userId)) return false;
        
        // Always validate Telegram init data
        if ($telegramInitData) {
            $telegramData = $this->checkTelegramAuthorization($telegramInitData, BOT_TOKEN);
            if (!$telegramData || $telegramData['user']['id'] != $userId) {
                return false;
            }
        }
        
        // Check authKey only if AUTH_KEY_DETECTION is enabled
        if (AUTH_KEY_DETECTION) {
            if (empty($authKey)) return false;
            
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND auth_key = ? AND status = 'active'");
            $stmt->execute([$userId, $authKey]);
            return $stmt->fetchColumn() !== false;
        } else {
            // Only check if user exists and is active
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn() !== false;
        }
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
        
        try {
            switch ($path) {
                case 'auth':
                    $this->handleAuth($telegramInitData, $refId, $refAuth);
                    break;
                case 'user':
                    $this->handleUser($authKey, $telegramInitData);
                    break;
                case 'mining-status':
                    $this->handleMiningStatus();
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
            // Calculate offline mining rewards
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
        
        // Only bot can create new users
        http_response_code(403);
        echo json_encode(['error' => 'User creation not allowed from frontend']);
    }
    
    private function handleMiningStatus() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $userId = $_GET['userId'] ?? '';
        if (empty($userId)) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID required']);
            return;
        }
        
        // Fast mining status check without auth for performance
        $stmt = $this->db->prepare("SELECT 
            is_mining, mining_start_time, pending_rewards, mining_rate, 
            min_claim_time, last_claim_time 
            FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }
        
        $now = time() * 1000;
        $miningDuration = 0;
        $pendingRewards = 0;
        $canClaim = false;
        
        if ($user['is_mining'] && $user['mining_start_time']) {
            $miningDuration = floor(($now - $user['mining_start_time']) / 1000);
            $limitedDuration = min($miningDuration, MAX_MINING_TIME);
            $pendingRewards = $user['mining_rate'] * $limitedDuration;
            
            // Check if can claim (30 min minimum + 5 min between claims)
            $timeSinceLastClaim = floor(($now - ($user['last_claim_time'] ?: 0)) / 1000);
            $canClaim = $miningDuration >= $user['min_claim_time'] && $timeSinceLastClaim >= MIN_CLAIM_INTERVAL;
        }
        
        echo json_encode([
            'isMining' => (bool)$user['is_mining'],
            'miningDuration' => $miningDuration,
            'pendingRewards' => $pendingRewards,
            'canClaim' => $canClaim,
            'remainingTime' => max(0, $user['min_claim_time'] - $miningDuration),
            'claimCooldown' => max(0, MIN_CLAIM_INTERVAL - floor(($now - ($user['last_claim_time'] ?: 0)) / 1000))
        ]);
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
                        data_initialized = TRUE,
                        last_active = ?
                        WHERE id = ?");
                    $stmt->execute([WELCOME_BONUS, WELCOME_BONUS, time() * 1000, $userId]);
                    
                    // Process referral bonus if valid
                    if ($user['referred_by'] && $user['ref_auth_used']) {
                        $this->processReferralBonus($user['referred_by'], $userId, $user['ref_auth_used']);
                    }
                    
                    $this->db->commit();
                }
            }
            
            // Handle mining operations
            if (isset($input['startMining']) && $input['startMining']) {
                $now = time() * 1000;
                $stmt = $this->db->prepare("UPDATE users SET 
                    is_mining = TRUE, 
                    mining_start_time = ?, 
                    pending_rewards = 0,
                    last_active = ?
                    WHERE id = ?");
                $stmt->execute([$now, $now, $userId]);
            }
            
            if (isset($input['claimMining']) && $input['claimMining']) {
                $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                if ($user && $user['is_mining'] && $user['mining_start_time']) {
                    $now = time() * 1000;
                    $miningDuration = floor(($now - $user['mining_start_time']) / 1000);
                    $timeSinceLastClaim = floor(($now - ($user['last_claim_time'] ?: 0)) / 1000);
                    
                    // Check if can claim
                    if ($miningDuration >= $user['min_claim_time'] && $timeSinceLastClaim >= MIN_CLAIM_INTERVAL) {
                        $limitedDuration = min($miningDuration, MAX_MINING_TIME);
                        $earned = $user['mining_rate'] * $limitedDuration;
                        $xp = floor($limitedDuration / 60); // 1 XP per minute
                        
                        $stmt = $this->db->prepare("UPDATE users SET 
                            balance = balance + ?, 
                            total_earned = total_earned + ?,
                            is_mining = FALSE,
                            mining_start_time = 0,
                            pending_rewards = 0,
                            last_claim_time = ?,
                            xp = xp + ?,
                            last_active = ?
                            WHERE id = ?");
                        $stmt->execute([$earned, $earned, $now, $xp, $now, $userId]);
                        
                        echo json_encode([
                            'success' => true, 
                            'earned' => $earned,
                            'xp' => $xp,
                            'message' => 'Mining rewards claimed!'
                        ]);
                        return;
                    }
                }
                
                echo json_encode(['success' => false, 'message' => 'Cannot claim yet']);
                return;
            }
            
            // Handle boost upgrades
            if (isset($input['upgradeBoost'])) {
                $boostType = $input['upgradeBoost'];
                $this->handleBoostUpgrade($userId, $boostType);
                return;
            }
            
            // Update other user data
            $updateFields = [];
            $updateValues = [];
            
            if (isset($input['balance'])) {
                $updateFields[] = 'balance = ?';
                $updateValues[] = $input['balance'];
            }
            if (isset($input['totalEarned'])) {
                $updateFields[] = 'total_earned = ?';
                $updateValues[] = $input['totalEarned'];
            }
            if (isset($input['settings'])) {
                $updateFields[] = 'sound_enabled = ?';
                $updateValues[] = $input['settings']['sound'] ?? true;
                $updateFields[] = 'vibration_enabled = ?';
                $updateValues[] = $input['settings']['vibration'] ?? true;
                $updateFields[] = 'notifications_enabled = ?';
                $updateValues[] = $input['settings']['notifications'] ?? true;
            }
            if (isset($input['pubgId'])) {
                $updateFields[] = 'pubg_id = ?';
                $updateValues[] = $input['pubgId'];
            }
            
            if (!empty($updateFields)) {
                $updateFields[] = 'last_active = ?';
                $updateValues[] = time() * 1000;
                $updateValues[] = $userId;
                
                $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($updateValues);
            }
            
            echo json_encode(['success' => true]);
        }
    }
    
    private function handleBoostUpgrade($userId, $boostType) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        
        $levelField = '';
        $baseCost = 0;
        
        switch ($boostType) {
            case 'miningSpeed':
                $levelField = 'mining_speed_level';
                $baseCost = 100;
                break;
            case 'claimTime':
                $levelField = 'claim_time_level';
                $baseCost = 150;
                break;
            case 'miningRate':
                $levelField = 'mining_rate_level';
                $baseCost = 200;
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid boost type']);
                return;
        }
        
        $currentLevel = $user[$levelField];
        $cost = floor($baseCost * pow(1.5, max(0, $currentLevel - 1)));
        
        if ($user['balance'] < $cost) {
            echo json_encode(['success' => false, 'message' => 'Insufficient balance']);
            return;
        }
        
        // Calculate new values
        $newLevel = $currentLevel + 1;
        $newBalance = $user['balance'] - $cost;
        
        // Update mining rate and claim time based on all boosts
        $miningSpeedLevel = $boostType === 'miningSpeed' ? $newLevel : $user['mining_speed_level'];
        $claimTimeLevel = $boostType === 'claimTime' ? $newLevel : $user['claim_time_level'];
        $miningRateLevel = $boostType === 'miningRate' ? $newLevel : $user['mining_rate_level'];
        
        $miningRateMultiplier = pow(1.5, ($miningRateLevel - 1));
        $miningSpeedMultiplier = pow(1.2, ($miningSpeedLevel - 1));
        $newMiningRate = BASE_MINING_RATE * $miningRateMultiplier * $miningSpeedMultiplier;
        $newClaimTime = max(300, MIN_CLAIM_TIME - (CLAIM_TIME_REDUCTION * ($claimTimeLevel - 1)));
        
        $stmt = $this->db->prepare("UPDATE users SET 
            balance = ?, 
            $levelField = ?,
            mining_rate = ?,
            min_claim_time = ?,
            last_active = ?
            WHERE id = ?");
        $stmt->execute([$newBalance, $newLevel, $newMiningRate, $newClaimTime, time() * 1000, $userId]);
        
        echo json_encode([
            'success' => true, 
            'message' => ucfirst($boostType) . ' upgraded!',
            'newLevel' => $newLevel,
            'cost' => $cost
        ]);
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
                xp = xp + 60,
                last_active = ?
                WHERE id = ?");
            $stmt->execute([REFERRAL_BONUS, REFERRAL_BONUS, time() * 1000, $referrerId]);
            
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
    
    private function handleUserMissions($authKey, $telegramInitData) {
        $userId = $_GET['userId'] ?? '';
        
        if (!$this->validateUser($userId, $authKey, $telegramInitData)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $this->db->prepare("SELECT * FROM user_missions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $userMissions = $stmt->fetchAll();
            
            $result = [];
            foreach ($userMissions as $mission) {
                $result[$mission['mission_id']] = [
                    'started' => (bool)$mission['started'],
                    'completed' => (bool)$mission['completed'],
                    'claimed' => (bool)$mission['claimed'],
                    'currentCount' => (int)$mission['current_count'],
                    'startedDate' => $mission['started_date'],
                    'completedAt' => $mission['completed_at'],
                    'claimedAt' => $mission['claimed_at'],
                    'lastVerifyAttempt' => $mission['last_verify_attempt'],
                    'timerStarted' => $mission['timer_started'],
                    'codeSubmitted' => $mission['code_submitted']
                ];
            }
            
            echo json_encode($result);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true);
            $missionId = $input['missionId'] ?? '';
            $missionData = $input['missionData'] ?? [];
            
            if (empty($missionId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Mission ID required']);
                return;
            }
            
            // Insert or update user mission
            $stmt = $this->db->prepare("INSERT INTO user_missions (
                user_id, mission_id, started, completed, claimed, current_count,
                started_date, completed_at, claimed_at, last_verify_attempt,
                timer_started, code_submitted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                started = VALUES(started),
                completed = VALUES(completed),
                claimed = VALUES(claimed),
                current_count = VALUES(current_count),
                completed_at = VALUES(completed_at),
                claimed_at = VALUES(claimed_at),
                last_verify_attempt = VALUES(last_verify_attempt),
                timer_started = VALUES(timer_started),
                code_submitted = VALUES(code_submitted)");
            
            $result = $stmt->execute([
                $userId,
                $missionId,
                $missionData['started'] ?? false,
                $missionData['completed'] ?? false,
                $missionData['claimed'] ?? false,
                $missionData['currentCount'] ?? 0,
                $missionData['startedDate'] ?? null,
                $missionData['completedAt'] ?? null,
                $missionData['claimedAt'] ?? null,
                $missionData['lastVerifyAttempt'] ?? null,
                $missionData['timerStarted'] ?? null,
                $missionData['codeSubmitted'] ?? null
            ]);
            
            echo json_encode(['success' => $result]);
        }
    }
    
    private function handleReferrals($authKey, $telegramInitData) {
        $userId = $_GET['userId'] ?? '';
        
        if (!$this->validateUser($userId, $authKey, $telegramInitData)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $stmt = $this->db->prepare("
            SELECT r.*, u.first_name, u.last_name, u.avatar_url, u.joined_at
            FROM referrals r 
            JOIN users u ON r.referred_id = u.id 
            WHERE r.referrer_id = ? 
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userId]);
        $referrals = $stmt->fetchAll();
        
        $result = [
            'count' => count($referrals),
            'totalUC' => array_sum(array_column($referrals, 'earned')),
            'referrals' => []
        ];
        
        foreach ($referrals as $referral) {
            $result['referrals'][$referral['referred_id']] = [
                'date' => $referral['created_at'],
                'earned' => (int)$referral['earned'],
                'firstName' => $referral['first_name'],
                'lastName' => $referral['last_name'] ?? '',
                'avatarUrl' => $referral['avatar_url'] ?? ''
            ];
        }
        
        echo json_encode($result);
    }
    
    private function handleConversions($authKey, $telegramInitData) {
        $userId = $_GET['userId'] ?? '';
        
        if (!$this->validateUser($userId, $authKey, $telegramInitData)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $this->db->prepare("SELECT * FROM conversions WHERE user_id = ? ORDER BY requested_at DESC");
            $stmt->execute([$userId]);
            $conversions = $stmt->fetchAll();
            
            $result = [];
            foreach ($conversions as $conv) {
                $result[] = [
                    'id' => $conv['id'],
                    'fromCurrency' => $conv['from_currency'],
                    'toCurrency' => $conv['to_currency'],
                    'amount' => (float)$conv['amount'],
                    'convertedAmount' => (float)$conv['converted_amount'],
                    'category' => $conv['category'],
                    'packageType' => $conv['package_type'],
                    'packageImage' => $conv['package_image'],
                    'status' => $conv['status'],
                    'requestedAt' => (int)$conv['requested_at'],
                    'completedAt' => $conv['completed_at'] ? (int)$conv['completed_at'] : null,
                    'requiredInfo' => $conv['required_info'] ? json_decode($conv['required_info'], true) : []
                ];
            }
            
            echo json_encode($result);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $conversionId = uniqid('conv_', true);
            $now = time() * 1000;
            
            $stmt = $this->db->prepare("INSERT INTO conversions (
                id, user_id, from_currency, to_currency, amount, converted_amount,
                category, package_type, package_image, required_info, status, requested_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
            
            $result = $stmt->execute([
                $conversionId,
                $userId,
                $input['fromCurrency'],
                $input['toCurrency'],
                $input['amount'],
                $input['convertedAmount'],
                $input['category'],
                $input['packageType'],
                $input['packageImage'] ?? null,
                json_encode($input['requiredInfo'] ?? []),
                $now
            ]);
            
            if ($result) {
                echo json_encode(['success' => true, 'conversionId' => $conversionId]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create conversion']);
            }
        }
    }
    
    private function handleConfig() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM config");
        $stmt->execute();
        $config = $stmt->fetchAll();
        
        $result = [];
        foreach ($config as $item) {
            $result[$item['setting_key']] = $item['setting_value'];
        }
        
        echo json_encode($result);
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
                'iconUrl' => $category['icon_url'],
                'active' => (bool)$category['active'],
                'conversionRate' => (float)$category['conversion_rate'],
                'minConversion' => (int)$category['min_conversion'],
                'maxConversion' => (int)$category['max_conversion'],
                'processingTime' => $category['processing_time'],
                'instructions' => $category['instructions'],
                'packages' => $packages,
                'requiredFields' => $requiredFields,
                'minIdLength' => (int)$category['min_id_length'],
                'maxIdLength' => (int)$category['max_id_length']
            ];
        }
        
        echo json_encode($result);
    }
    
    private function handleLeaderboard() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $type = $_GET['type'] ?? 'balance';
        
        if ($type === 'balance') {
            $stmt = $this->db->prepare("SELECT id, first_name, last_name, avatar_url, total_earned, xp FROM users WHERE status = 'active' ORDER BY total_earned DESC LIMIT 100");
        } else {
            $stmt = $this->db->prepare("SELECT id, first_name, last_name, avatar_url, total_earned, xp FROM users WHERE status = 'active' ORDER BY xp DESC LIMIT 100");
        }
        
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'id' => $user['id'],
                'firstName' => $user['first_name'],
                'lastName' => $user['last_name'] ?? '',
                'avatarUrl' => $user['avatar_url'] ?? '',
                'totalEarned' => (float)$user['total_earned'],
                'xp' => (int)$user['xp']
            ];
        }
        
        echo json_encode($result);
    }
    
    private function handleTelegramVerification($authKey, $telegramInitData) {
        $userId = $_GET['userId'] ?? '';
        $channelId = $_GET['channelId'] ?? '';
        
        if (!$this->validateUser($userId, $authKey, $telegramInitData)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        // Backend determines channel membership without exposing bot token
        try {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getChatMember";
            $data = [
                'chat_id' => '@' . $channelId,
                'user_id' => $userId
            ];
            
            $options = [
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($data)
                ]
            ];
            
            $context = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $result = json_decode($response, true);
            
            $verified = false;
            if ($result && $result['ok'] && isset($result['result']['status'])) {
                $status = $result['result']['status'];
                $verified = in_array($status, ['member', 'administrator', 'creator']);
            }
            
            echo json_encode(['verified' => $verified]);
        } catch (Exception $e) {
            echo json_encode(['verified' => false]);
        }
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
                $stmt = $this->db->prepare("UPDATE users SET balance = balance + ?, total_earned = total_earned + ?, last_active = ? WHERE id = ?");
                $stmt->execute([$promoCode['reward'], $promoCode['reward'], time() * 1000, $userId]);
                
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
$api = new FastSecureAPI();
$api->handleRequest();
?>