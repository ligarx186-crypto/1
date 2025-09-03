<?php
require_once 'config.php';

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
    private $botToken = '8188857509:AAHjKKUaC_kljF1KKHZ0VW1pWkcWDfaY65k';
    
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
        
        // Always validate Telegram init data first
        if ($telegramInitData) {
            $telegramData = $this->checkTelegramAuthorization($telegramInitData, $this->botToken);
            if (!$telegramData || $telegramData['user']['id'] != $userId) {
                return false;
            }
        }
        
        // Check authKey only if AUTH_KEY_DETECTION is enabled
        if (AUTH_KEY_DETECTION) {
            if (empty($authKey)) return false;
            
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND authKey = ? AND status = 'active'");
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
        $stmt = $this->db->prepare("SELECT requestCount, windowStart FROM rateLimits WHERE ip = ?");
        $stmt->execute([$ip]);
        $result = $stmt->fetch();
        
        $now = time();
        $windowStart = $now - RATE_LIMIT_WINDOW;
        
        if ($result) {
            if ($result['windowStart'] < $windowStart) {
                $stmt = $this->db->prepare("UPDATE rateLimits SET requestCount = 1, windowStart = ? WHERE ip = ?");
                $stmt->execute([$now, $ip]);
                return true;
            } elseif ($result['requestCount'] >= RATE_LIMIT_REQUESTS) {
                return false;
            } else {
                $stmt = $this->db->prepare("UPDATE rateLimits SET requestCount = requestCount + 1 WHERE ip = ?");
                $stmt->execute([$ip]);
                return true;
            }
        } else {
            $stmt = $this->db->prepare("INSERT INTO rateLimits (ip, requestCount, windowStart) VALUES (?, 1, ?)");
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
            $telegramData = $this->checkTelegramAuthorization($telegramInitData, $this->botToken);
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
            if ($existingUser['isMining'] && $existingUser['miningStartTime']) {
                $offlineDuration = floor(($now - $existingUser['miningStartTime']) / 1000);
                $limitedDuration = min($offlineDuration, MAX_MINING_TIME);
                
                if ($limitedDuration > 0) {
                    $earned = $existingUser['miningRate'] * $limitedDuration;
                    $stmt = $this->db->prepare("UPDATE users SET 
                        pendingRewards = ?, 
                        lastActive = ? 
                        WHERE id = ?");
                    $stmt->execute([$earned, $now, $userId]);
                    
                    // Refresh user data
                    $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $existingUser = $stmt->fetch();
                }
            } else {
                $stmt = $this->db->prepare("UPDATE users SET lastActive = ? WHERE id = ?");
                $stmt->execute([$now, $userId]);
            }
            
            echo json_encode([
                'success' => true,
                'authKey' => $existingUser['authKey'],
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
            isMining, miningStartTime, pendingRewards, miningRate, 
            minClaimTime, lastClaimTime 
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
        
        if ($user['isMining'] && $user['miningStartTime']) {
            $miningDuration = floor(($now - $user['miningStartTime']) / 1000);
            $limitedDuration = min($miningDuration, MAX_MINING_TIME);
            $pendingRewards = $user['miningRate'] * $limitedDuration;
            
            // Check if can claim (30 min minimum + 5 min between claims)
            $timeSinceLastClaim = floor(($now - ($user['lastClaimTime'] ?: 0)) / 1000);
            $canClaim = $miningDuration >= $user['minClaimTime'] && $timeSinceLastClaim >= MIN_CLAIM_INTERVAL;
        }
        
        echo json_encode([
            'isMining' => (bool)$user['isMining'],
            'miningDuration' => $miningDuration,
            'pendingRewards' => $pendingRewards,
            'canClaim' => $canClaim,
            'remainingTime' => max(0, $user['minClaimTime'] - $miningDuration),
            'claimCooldown' => max(0, MIN_CLAIM_INTERVAL - floor(($now - ($user['lastClaimTime'] ?: 0)) / 1000))
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
                $stmt = $this->db->prepare("SELECT bonusClaimed, referredBy, refAuthUsed FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                if (!$user['bonusClaimed']) {
                    $this->db->beginTransaction();
                    
                    // Give welcome bonus
                    $stmt = $this->db->prepare("UPDATE users SET 
                        balance = balance + ?, 
                        totalEarned = totalEarned + ?, 
                        bonusClaimed = TRUE,
                        dataInitialized = TRUE,
                        lastActive = ?
                        WHERE id = ?");
                    $stmt->execute([WELCOME_BONUS, WELCOME_BONUS, time() * 1000, $userId]);
                    
                    // Process referral bonus if valid
                    if ($user['referredBy'] && $user['refAuthUsed']) {
                        $this->processReferralBonus($user['referredBy'], $userId, $user['refAuthUsed']);
                    }
                    
                    $this->db->commit();
                }
            }
            
            // Handle mining operations
            if (isset($input['startMining']) && $input['startMining']) {
                $now = time() * 1000;
                $stmt = $this->db->prepare("UPDATE users SET 
                    isMining = TRUE, 
                    miningStartTime = ?, 
                    pendingRewards = 0,
                    lastActive = ?
                    WHERE id = ?");
                $stmt->execute([$now, $now, $userId]);
            }
            
            if (isset($input['claimMining']) && $input['claimMining']) {
                $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                if ($user && $user['isMining'] && $user['miningStartTime']) {
                    $now = time() * 1000;
                    $miningDuration = floor(($now - $user['miningStartTime']) / 1000);
                    $timeSinceLastClaim = floor(($now - ($user['lastClaimTime'] ?: 0)) / 1000);
                    
                    // Check if can claim
                    if ($miningDuration >= $user['minClaimTime'] && $timeSinceLastClaim >= MIN_CLAIM_INTERVAL) {
                        $limitedDuration = min($miningDuration, MAX_MINING_TIME);
                        $earned = $user['miningRate'] * $limitedDuration;
                        $xp = floor($limitedDuration / 60); // 1 XP per minute
                        
                        $stmt = $this->db->prepare("UPDATE users SET 
                            balance = balance + ?, 
                            totalEarned = totalEarned + ?,
                            isMining = FALSE,
                            miningStartTime = 0,
                            pendingRewards = 0,
                            lastClaimTime = ?,
                            xp = xp + ?,
                            lastActive = ?
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
                $updateFields[] = 'totalEarned = ?';
                $updateValues[] = $input['totalEarned'];
            }
            if (isset($input['settings'])) {
                $updateFields[] = 'soundEnabled = ?';
                $updateValues[] = $input['settings']['sound'] ?? true;
                $updateFields[] = 'vibrationEnabled = ?';
                $updateValues[] = $input['settings']['vibration'] ?? true;
                $updateFields[] = 'notificationsEnabled = ?';
                $updateValues[] = $input['settings']['notifications'] ?? true;
            }
            if (isset($input['pubgId'])) {
                $updateFields[] = 'pubgId = ?';
                $updateValues[] = $input['pubgId'];
            }
            
            if (!empty($updateFields)) {
                $updateFields[] = 'lastActive = ?';
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
                $levelField = 'miningSpeedLevel';
                $baseCost = 100;
                break;
            case 'claimTime':
                $levelField = 'claimTimeLevel';
                $baseCost = 150;
                break;
            case 'miningRate':
                $levelField = 'miningRateLevel';
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
        $miningSpeedLevel = $boostType === 'miningSpeed' ? $newLevel : $user['miningSpeedLevel'];
        $claimTimeLevel = $boostType === 'claimTime' ? $newLevel : $user['claimTimeLevel'];
        $miningRateLevel = $boostType === 'miningRate' ? $newLevel : $user['miningRateLevel'];
        
        $miningRateMultiplier = pow(1.5, ($miningRateLevel - 1));
        $miningSpeedMultiplier = pow(1.2, ($miningSpeedLevel - 1));
        $newMiningRate = BASE_MINING_RATE * $miningRateMultiplier * $miningSpeedMultiplier;
        $newClaimTime = max(300, MIN_CLAIM_TIME - (CLAIM_TIME_REDUCTION * ($claimTimeLevel - 1)));
        
        $stmt = $this->db->prepare("UPDATE users SET 
            balance = ?, 
            $levelField = ?,
            miningRate = ?,
            minClaimTime = ?,
            lastActive = ?
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
            $stmt = $this->db->prepare("SELECT refAuth FROM users WHERE id = ?");
            $stmt->execute([$referrerId]);
            $referrerRefAuth = $stmt->fetchColumn();
            
            if (!$referrerRefAuth || $referrerRefAuth !== $refAuth) {
                $this->log("Invalid ref_auth for referrer: $referrerId");
                return false;
            }
            
            // Check if referral already processed
            $stmt = $this->db->prepare("SELECT id FROM referrals WHERE referrerId = ? AND referredId = ?");
            $stmt->execute([$referrerId, $referredId]);
            if ($stmt->fetchColumn()) {
                return false;
            }
            
            // Add referral record
            $stmt = $this->db->prepare("INSERT INTO referrals (referrerId, referredId, earned) VALUES (?, ?, ?)");
            $stmt->execute([$referrerId, $referredId, REFERRAL_BONUS]);
            
            // Update referrer's balance
            $stmt = $this->db->prepare("UPDATE users SET 
                balance = balance + ?, 
                totalEarned = totalEarned + ?, 
                referralCount = referralCount + 1,
                xp = xp + 60,
                lastActive = ?
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
                'detailedDescription' => $mission['detailedDescription'],
                'reward' => (int)$mission['reward'],
                'requiredCount' => (int)$mission['requiredCount'],
                'channelId' => $mission['channelId'],
                'url' => $mission['url'],
                'code' => $mission['code'],
                'requiredTime' => $mission['requiredTime'] ? (int)$mission['requiredTime'] : null,
                'active' => (bool)$mission['active'],
                'category' => $mission['category'],
                'type' => $mission['type'],
                'icon' => $mission['icon'],
                'img' => $mission['img'],
                'priority' => (int)$mission['priority'],
                'instructions' => $mission['instructions'] ? json_decode($mission['instructions'], true) : [],
                'tips' => $mission['tips'] ? json_decode($mission['tips'], true) : [],
                'createdAt' => $mission['createdAt']
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
            $stmt = $this->db->prepare("SELECT * FROM userMissions WHERE userId = ?");
            $stmt->execute([$userId]);
            $userMissions = $stmt->fetchAll();
            
            $result = [];
            foreach ($userMissions as $mission) {
                $result[$mission['missionId']] = [
                    'started' => (bool)$mission['started'],
                    'completed' => (bool)$mission['completed'],
                    'claimed' => (bool)$mission['claimed'],
                    'currentCount' => (int)$mission['currentCount'],
                    'startedDate' => $mission['startedDate'],
                    'completedAt' => $mission['completedAt'],
                    'claimedAt' => $mission['claimedAt'],
                    'lastVerifyAttempt' => $mission['lastVerifyAttempt'],
                    'timerStarted' => $mission['timerStarted'],
                    'codeSubmitted' => $mission['codeSubmitted']
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
            $stmt = $this->db->prepare("INSERT INTO userMissions (
                userId, missionId, started, completed, claimed, currentCount,
                startedDate, completedAt, claimedAt, lastVerifyAttempt,
                timerStarted, codeSubmitted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                started = VALUES(started),
                completed = VALUES(completed),
                claimed = VALUES(claimed),
                currentCount = VALUES(currentCount),
                completedAt = VALUES(completedAt),
                claimedAt = VALUES(claimedAt),
                lastVerifyAttempt = VALUES(lastVerifyAttempt),
                timerStarted = VALUES(timerStarted),
                codeSubmitted = VALUES(codeSubmitted)");
            
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
            SELECT r.*, u.firstName, u.lastName, u.avatarUrl, u.joinedAt
            FROM referrals r 
            JOIN users u ON r.referredId = u.id 
            WHERE r.referrerId = ? 
            ORDER BY r.createdAt DESC
        ");
        $stmt->execute([$userId]);
        $referrals = $stmt->fetchAll();
        
        $result = [
            'count' => count($referrals),
            'totalUC' => array_sum(array_column($referrals, 'earned')),
            'referrals' => []
        ];
        
        foreach ($referrals as $referral) {
            $result['referrals'][$referral['referredId']] = [
                'date' => $referral['createdAt'],
                'earned' => (int)$referral['earned'],
                'firstName' => $referral['firstName'],
                'lastName' => $referral['lastName'] ?? '',
                'avatarUrl' => $referral['avatarUrl'] ?? ''
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
            $stmt = $this->db->prepare("SELECT * FROM conversions WHERE userId = ? ORDER BY requestedAt DESC");
            $stmt->execute([$userId]);
            $conversions = $stmt->fetchAll();
            
            $result = [];
            foreach ($conversions as $conv) {
                $result[] = [
                    'id' => $conv['id'],
                    'fromCurrency' => $conv['fromCurrency'],
                    'toCurrency' => $conv['toCurrency'],
                    'amount' => (float)$conv['amount'],
                    'convertedAmount' => (float)$conv['convertedAmount'],
                    'category' => $conv['category'],
                    'packageType' => $conv['packageType'],
                    'packageImage' => $conv['packageImage'],
                    'status' => $conv['status'],
                    'requestedAt' => (int)$conv['requestedAt'],
                    'completedAt' => $conv['completedAt'] ? (int)$conv['completedAt'] : null,
                    'requiredInfo' => $conv['requiredInfo'] ? json_decode($conv['requiredInfo'], true) : []
                ];
            }
            
            echo json_encode($result);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $conversionId = uniqid('conv_', true);
            $now = time() * 1000;
            
            $stmt = $this->db->prepare("INSERT INTO conversions (
                id, userId, fromCurrency, toCurrency, amount, convertedAmount,
                category, packageType, packageImage, requiredInfo, status, requestedAt
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
        
        $stmt = $this->db->prepare("SELECT settingKey, settingValue FROM config");
        $stmt->execute();
        $config = $stmt->fetchAll();
        
        $result = [];
        foreach ($config as $item) {
            $result[$item['settingKey']] = $item['settingValue'];
        }
        
        echo json_encode($result);
    }
    
    private function handleWalletCategories() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $stmt = $this->db->prepare("SELECT * FROM walletCategories WHERE active = TRUE ORDER BY priority ASC");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        $result = [];
        foreach ($categories as $category) {
            $packages = json_decode($category['packages'], true) ?: [];
            $requiredFields = json_decode($category['requiredFields'], true) ?: [];
            
            $result[] = [
                'id' => $category['id'],
                'name' => $category['name'],
                'description' => $category['description'],
                'image' => $category['image'],
                'iconUrl' => $category['iconUrl'],
                'active' => (bool)$category['active'],
                'conversionRate' => (float)$category['conversionRate'],
                'minConversion' => (int)$category['minConversion'],
                'maxConversion' => (int)$category['maxConversion'],
                'processingTime' => $category['processingTime'],
                'instructions' => $category['instructions'],
                'packages' => $packages,
                'requiredFields' => $requiredFields,
                'minIdLength' => (int)$category['minIdLength'],
                'maxIdLength' => (int)$category['maxIdLength']
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
            $stmt = $this->db->prepare("SELECT id, firstName, lastName, avatarUrl, totalEarned, xp FROM users WHERE status = 'active' ORDER BY totalEarned DESC LIMIT 100");
        } else {
            $stmt = $this->db->prepare("SELECT id, firstName, lastName, avatarUrl, totalEarned, xp FROM users WHERE status = 'active' ORDER BY xp DESC LIMIT 100");
        }
        
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'id' => $user['id'],
                'firstName' => $user['firstName'],
                'lastName' => $user['lastName'] ?? '',
                'avatarUrl' => $user['avatarUrl'] ?? '',
                'totalEarned' => (float)$user['totalEarned'],
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
            $url = "https://api.telegram.org/bot" . $this->botToken . "/getChatMember";
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
            $stmt = $this->db->prepare("SELECT * FROM promoCodes WHERE code = ? AND (expiresAt IS NULL OR expiresAt > NOW()) AND usedBy IS NULL");
            $stmt->execute([$code]);
            $promoCode = $stmt->fetch();
            
            if ($promoCode) {
                // Mark promo code as used
                $stmt = $this->db->prepare("UPDATE promoCodes SET usedBy = ?, usedAt = NOW() WHERE id = ?");
                $stmt->execute([$userId, $promoCode['id']]);
                
                // Update user balance
                $stmt = $this->db->prepare("UPDATE users SET balance = balance + ?, totalEarned = totalEarned + ?, lastActive = ? WHERE id = ?");
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
            'firstName' => $userData['firstName'],
            'lastName' => $userData['lastName'],
            'avatarUrl' => $userData['avatarUrl'],
            'authKey' => $userData['authKey'],
            'balance' => (float)$userData['balance'],
            'ucBalance' => (float)$userData['ucBalance'],
            'energyLimit' => (int)$userData['energyLimit'],
            'multiTapValue' => (int)$userData['multiTapValue'],
            'rechargingSpeed' => (int)$userData['rechargingSpeed'],
            'tapBotPurchased' => (bool)$userData['tapBotPurchased'],
            'tapBotActive' => (bool)$userData['tapBotActive'],
            'bonusClaimed' => (bool)$userData['bonusClaimed'],
            'pubgId' => $userData['pubgId'],
            'totalTaps' => (int)$userData['totalTaps'],
            'totalEarned' => (float)$userData['totalEarned'],
            'lastJackpotTime' => (int)$userData['lastJackpotTime'],
            'referredBy' => $userData['referredBy'],
            'referralCount' => (int)$userData['referralCount'],
            'level' => (int)$userData['levelNum'],
            'xp' => (int)$userData['xp'],
            'streak' => (int)$userData['streak'],
            'combo' => (int)$userData['combo'],
            'lastTapTime' => (int)$userData['lastTapTime'],
            'isMining' => (bool)$userData['isMining'],
            'miningStartTime' => (int)$userData['miningStartTime'],
            'lastClaimTime' => (int)$userData['lastClaimTime'],
            'pendingRewards' => (float)$userData['pendingRewards'],
            'miningRate' => (float)$userData['miningRate'],
            'minClaimTime' => (int)$userData['minClaimTime'],
            'settings' => [
                'sound' => (bool)$userData['soundEnabled'],
                'vibration' => (bool)$userData['vibrationEnabled'],
                'notifications' => (bool)$userData['notificationsEnabled']
            ],
            'boosts' => [
                'miningSpeedLevel' => (int)$userData['miningSpeedLevel'],
                'claimTimeLevel' => (int)$userData['claimTimeLevel'],
                'miningRateLevel' => (int)$userData['miningRateLevel']
            ],
            'missions' => new stdClass(),
            'withdrawals' => [],
            'conversions' => [],
            'joinedAt' => (int)$userData['joinedAt'],
            'lastActive' => (int)$userData['lastActive'],
            'isReturningUser' => (bool)$userData['isReturningUser'],
            'dataInitialized' => (bool)$userData['dataInitialized']
        ];
    }
}

// Initialize and handle request
$api = new FastSecureAPI();
$api->handleRequest();
?>