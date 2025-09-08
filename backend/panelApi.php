<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Email, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class AdminPanelAPI {
    private $db;
    private $validAdmins = [
        'admin-davronov@gmail.com' => 'Davronov-07'
    ];
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    private function log($message) {
        error_log("[" . date('Y-m-d H:i:s') . "] AdminAPI: $message");
    }
    
    private function validateAdmin($email, $password) {
        return isset($this->validAdmins[$email]) && $this->validAdmins[$email] === $password;
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_GET['path'] ?? '';
        $adminEmail = $_SERVER['HTTP_X_ADMIN_EMAIL'] ?? '';
        $adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
        
        if ($path === 'login') {
            $this->handleLogin();
            return;
        }
        
        if (!$this->validateAdmin($adminEmail, $adminPassword)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        $this->log("$method $path from admin $adminEmail");
        
        try {
            switch ($path) {
                case 'stats':
                    $this->handleStats();
                    break;
                case 'users':
                    $this->handleUsers();
                    break;
                case 'missions':
                    $this->handleMissions($adminEmail);
                    break;
                case 'conversions':
                    $this->handleConversions();
                    break;
                case 'config':
                    $this->handleConfig($adminEmail);
                    break;
                case 'promo-codes':
                    $this->handlePromoCodes($adminEmail);
                    break;
                case 'categories':
                    $this->handleCategories($adminEmail);
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Endpoint not found']);
            }
        } catch (Exception $e) {
            $this->log("Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
        }
    }
    
    private function handleLogin() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        if ($this->validateAdmin($email, $password)) {
            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'adminEmail' => $email
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
        }
    }
    
    private function handleStats() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        try {
            $now = time() * 1000;
            $oneDayAgo = $now - (24 * 60 * 60 * 1000);
            
            // User statistics with error handling
            $totalUsers = 0;
            $activeToday = 0;
            $totalEarned = 0;
            $pendingConversions = 0;
            
            try {
                $totalUsers = $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0;
            } catch (Exception $e) {
                $this->log("Failed to get total users: " . $e->getMessage());
            }
            
            try {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE last_active >= ?");
                $stmt->execute([$oneDayAgo]);
                $activeToday = $stmt->fetchColumn() ?: 0;
            } catch (Exception $e) {
                $this->log("Failed to get active users: " . $e->getMessage());
            }
            
            try {
                $totalEarned = $this->db->query("SELECT SUM(total_earned) FROM users")->fetchColumn() ?: 0;
            } catch (Exception $e) {
                $this->log("Failed to get total earned: " . $e->getMessage());
            }
            
            try {
                $pendingConversions = $this->db->query("SELECT COUNT(*) FROM conversions WHERE status = 'pending'")->fetchColumn() ?: 0;
            } catch (Exception $e) {
                $this->log("Failed to get pending conversions: " . $e->getMessage());
            }
            
            echo json_encode([
                'users' => [
                    'total' => (int)$totalUsers,
                    'activeToday' => (int)$activeToday
                ],
                'mining' => [
                    'totalEarned' => (float)$totalEarned
                ],
                'conversions' => [
                    'pending' => (int)$pendingConversions
                ]
            ]);
        } catch (Exception $e) {
            $this->log("Stats error: " . $e->getMessage());
            echo json_encode([
                'users' => ['total' => 0, 'activeToday' => 0],
                'mining' => ['totalEarned' => 0],
                'conversions' => ['pending' => 0]
            ]);
        }
    }
    
    private function handleUsers() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 50);
            $search = $_GET['search'] ?? '';
            $offset = ($page - 1) * $limit;
            
            $whereClause = '';
            $params = [];
            
            if ($search) {
                $whereClause = "WHERE first_name LIKE ? OR last_name LIKE ? OR id LIKE ?";
                $searchParam = "%$search%";
                $params = [$searchParam, $searchParam, $searchParam];
            }
            
            $stmt = $this->db->prepare("SELECT * FROM users $whereClause ORDER BY joined_at DESC LIMIT ? OFFSET ?");
            $stmt->execute([...$params, $limit, $offset]);
            $users = $stmt->fetchAll();
            
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users $whereClause");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();
            
            echo json_encode([
                'users' => $users,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $userId = $_GET['userId'] ?? '';
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID required']);
                return;
            }
            
            $updateFields = [];
            $updateValues = [];
            
            foreach ($input as $key => $value) {
                switch ($key) {
                    case 'firstName':
                        $updateFields[] = 'first_name = ?';
                        $updateValues[] = $value;
                        break;
                    case 'lastName':
                        $updateFields[] = 'last_name = ?';
                        $updateValues[] = $value;
                        break;
                    case 'balance':
                        $updateFields[] = 'balance = ?';
                        $updateValues[] = $value;
                        break;
                    case 'totalEarned':
                        $updateFields[] = 'total_earned = ?';
                        $updateValues[] = $value;
                        break;
                    case 'level':
                        $updateFields[] = 'level_num = ?';
                        $updateValues[] = $value;
                        break;
                    case 'xp':
                        $updateFields[] = 'xp = ?';
                        $updateValues[] = $value;
                        break;
                    case 'status':
                        $updateFields[] = 'status = ?';
                        $updateValues[] = $value;
                        break;
                }
            }
            
            if (!empty($updateFields)) {
                $updateFields[] = 'last_active = ?';
                $updateValues[] = time() * 1000;
                $updateValues[] = $userId;
                
                $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $result = $stmt->execute($updateValues);
                
                if ($result) {
                    echo json_encode(['success' => true]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to update user']);
                }
            } else {
                echo json_encode(['success' => true, 'message' => 'No fields to update']);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Create new user
            $input = json_decode(file_get_contents('php://input'), true);
            
            $userId = $input['id'] ?? uniqid('user_', true);
            $authKey = bin2hex(random_bytes(32));
            $now = time() * 1000;
            
            $stmt = $this->db->prepare("INSERT INTO users (
                id, first_name, last_name, avatar_url, auth_key, 
                balance, total_earned, joined_at, last_active, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            
            $result = $stmt->execute([
                $userId,
                $input['firstName'] ?? 'User',
                $input['lastName'] ?? '',
                $input['avatarUrl'] ?? '',
                $authKey,
                $input['balance'] ?? 0,
                $input['totalEarned'] ?? 0,
                $now,
                $now
            ]);
            
            if ($result) {
                echo json_encode(['success' => true, 'userId' => $userId]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create user']);
            }
        }
    }
    
    private function handleCategories($adminEmail) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $this->db->prepare("SELECT * FROM wallet_categories ORDER BY priority ASC, created_at DESC");
            $stmt->execute();
            $categories = $stmt->fetchAll();
            
            $result = [];
            foreach ($categories as $category) {
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
                    'iconUrl' => $category['icon_url'],
                    'minIdLength' => (int)$category['min_id_length'],
                    'maxIdLength' => (int)$category['max_id_length'],
                    'packages' => json_decode($category['packages'], true) ?: [],
                    'requiredFields' => json_decode($category['required_fields'], true) ?: []
                ];
            }
            
            echo json_encode($result);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $this->db->prepare("INSERT INTO wallet_categories (
                id, name, description, image, icon_url, active, conversion_rate,
                min_conversion, max_conversion, processing_time, instructions,
                required_fields, packages, priority, min_id_length, max_id_length
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $result = $stmt->execute([
                $input['id'],
                $input['name'],
                $input['description'],
                $input['image'],
                $input['iconUrl'] ?? '',
                $input['active'] ?? true,
                $input['conversionRate'] ?? 1,
                $input['minConversion'] ?? 1,
                $input['maxConversion'] ?? 10000,
                $input['processingTime'] ?? '24-48 hours',
                $input['instructions'] ?? '',
                json_encode($input['requiredFields'] ?? []),
                json_encode($input['packages'] ?? []),
                $input['priority'] ?? 999,
                $input['minIdLength'] ?? 9,
                $input['maxIdLength'] ?? 12
            ]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create category']);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $categoryId = $_GET['categoryId'] ?? '';
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($categoryId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Category ID required']);
                return;
            }
            
            $stmt = $this->db->prepare("UPDATE wallet_categories SET 
                name = ?, description = ?, image = ?, icon_url = ?, active = ?,
                conversion_rate = ?, min_conversion = ?, max_conversion = ?,
                processing_time = ?, instructions = ?, required_fields = ?,
                packages = ?, priority = ?, min_id_length = ?, max_id_length = ?
                WHERE id = ?");
            
            $result = $stmt->execute([
                $input['name'],
                $input['description'],
                $input['image'],
                $input['iconUrl'] ?? '',
                $input['active'] ?? true,
                $input['conversionRate'] ?? 1,
                $input['minConversion'] ?? 1,
                $input['maxConversion'] ?? 10000,
                $input['processingTime'] ?? '24-48 hours',
                $input['instructions'] ?? '',
                json_encode($input['requiredFields'] ?? []),
                json_encode($input['packages'] ?? []),
                $input['priority'] ?? 999,
                $input['minIdLength'] ?? 9,
                $input['maxIdLength'] ?? 12,
                $categoryId
            ]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update category']);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $categoryId = $_GET['categoryId'] ?? '';
            
            if (empty($categoryId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Category ID required']);
                return;
            }
            
            $stmt = $this->db->prepare("DELETE FROM wallet_categories WHERE id = ?");
            $result = $stmt->execute([$categoryId]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete category']);
            }
        }
    }
    
    private function handleMissions($adminEmail) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $this->db->prepare("SELECT * FROM missions ORDER BY priority ASC, created_at DESC");
            $stmt->execute();
            $missions = $stmt->fetchAll();
            
            echo json_encode($missions);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $missionId = uniqid('mission_', true);
            
            $stmt = $this->db->prepare("INSERT INTO missions (
                id, title, description, detailed_description, reward, required_count,
                channel_id, url, code, required_time, active, category, type,
                icon, img, priority, instructions, tips
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $result = $stmt->execute([
                $missionId,
                $input['title'],
                $input['description'],
                $input['detailedDescription'] ?? '',
                $input['reward'],
                $input['requiredCount'] ?? 1,
                $input['channelId'] ?? null,
                $input['url'] ?? null,
                $input['code'] ?? null,
                $input['requiredTime'] ?? null,
                $input['active'] ?? true,
                $input['category'],
                $input['type'],
                $input['icon'] ?? null,
                $input['img'] ?? null,
                $input['priority'] ?? 999,
                json_encode($input['instructions'] ?? []),
                json_encode($input['tips'] ?? [])
            ]);
            
            if ($result) {
                echo json_encode(['success' => true, 'missionId' => $missionId]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create mission']);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $missionId = $_GET['missionId'] ?? '';
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($missionId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Mission ID required']);
                return;
            }
            
            $stmt = $this->db->prepare("UPDATE missions SET 
                title = ?, description = ?, detailed_description = ?, reward = ?,
                required_count = ?, channel_id = ?, url = ?, code = ?,
                required_time = ?, active = ?, category = ?, type = ?,
                icon = ?, img = ?, priority = ?, instructions = ?, tips = ?
                WHERE id = ?");
            
            $result = $stmt->execute([
                $input['title'],
                $input['description'],
                $input['detailedDescription'] ?? '',
                $input['reward'],
                $input['requiredCount'] ?? 1,
                $input['channelId'] ?? null,
                $input['url'] ?? null,
                $input['code'] ?? null,
                $input['requiredTime'] ?? null,
                $input['active'] ?? true,
                $input['category'],
                $input['type'],
                $input['icon'] ?? null,
                $input['img'] ?? null,
                $input['priority'] ?? 999,
                json_encode($input['instructions'] ?? []),
                json_encode($input['tips'] ?? []),
                $missionId
            ]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update mission']);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $missionId = $_GET['missionId'] ?? '';
            
            if (empty($missionId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Mission ID required']);
                return;
            }
            
            $stmt = $this->db->prepare("DELETE FROM missions WHERE id = ?");
            $result = $stmt->execute([$missionId]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete mission']);
            }
        }
    }
    
    private function handlePromoCodes($adminEmail) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $this->db->prepare("
                SELECT p.*, u.first_name as used_by_name 
                FROM promo_codes p 
                LEFT JOIN users u ON p.used_by = u.id 
                ORDER BY p.created_at DESC
            ");
            $stmt->execute();
            $promoCodes = $stmt->fetchAll();
            
            echo json_encode($promoCodes);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $codes = $input['codes'] ?? [];
            $reward = $input['reward'] ?? 0;
            $description = $input['description'] ?? '';
            $expiresAt = $input['expiresAt'] ?? null;
            
            $createdCodes = [];
            
            foreach ($codes as $code) {
                $codeId = uniqid('promo_', true);
                
                try {
                    $stmt = $this->db->prepare("INSERT INTO promo_codes (
                        id, code, reward, description, expires_at, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?)");
                    
                    $result = $stmt->execute([
                        $codeId,
                        trim($code),
                        $reward,
                        $description,
                        $expiresAt,
                        $adminEmail
                    ]);
                    
                    if ($result) {
                        $createdCodes[] = $code;
                    }
                } catch (Exception $e) {
                    $this->log("Failed to create promo code '$code': " . $e->getMessage());
                }
            }
            
            echo json_encode(['success' => true, 'createdCodes' => $createdCodes]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $codeId = $_GET['codeId'] ?? '';
            
            if (empty($codeId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Code ID required']);
                return;
            }
            
            $stmt = $this->db->prepare("DELETE FROM promo_codes WHERE id = ?");
            $result = $stmt->execute([$codeId]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete promo code']);
            }
        }
    }
    
    private function handleConversions() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $status = $_GET['status'] ?? 'all';
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = ($page - 1) * $limit;
            
            $whereClause = '';
            $params = [];
            
            if ($status !== 'all') {
                $whereClause = "WHERE c.status = ?";
                $params[] = $status;
            }
            
            $stmt = $this->db->prepare("SELECT c.*, u.first_name, u.last_name 
                FROM conversions c 
                JOIN users u ON c.user_id = u.id 
                $whereClause 
                ORDER BY c.requested_at DESC 
                LIMIT ? OFFSET ?");
            $stmt->execute([...$params, $limit, $offset]);
            $conversions = $stmt->fetchAll();
            
            $result = [];
            foreach ($conversions as $conv) {
                $result[] = [
                    'id' => $conv['id'],
                    'userId' => $conv['user_id'],
                    'userName' => $conv['first_name'] . ' ' . $conv['last_name'],
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
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $conversionId = $_GET['conversionId'] ?? '';
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($conversionId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Conversion ID required']);
                return;
            }
            
            $stmt = $this->db->prepare("UPDATE conversions SET status = ?, completed_at = ? WHERE id = ?");
            $completedAt = $input['status'] === 'approved' ? time() * 1000 : null;
            $result = $stmt->execute([$input['status'], $completedAt, $conversionId]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update conversion']);
            }
        }
    }
    
    private function handleConfig($adminEmail) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM config");
            $stmt->execute();
            $config = $stmt->fetchAll();
            
            $result = [];
            foreach ($config as $item) {
                $result[$item['setting_key']] = $item['setting_value'];
            }
            
            echo json_encode($result);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            foreach ($input as $key => $value) {
                $stmt = $this->db->prepare("INSERT INTO config (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$key, $value]);
            }
            
            echo json_encode(['success' => true]);
        }
    }
}

$api = new AdminPanelAPI();
$api->handleRequest();
?>