<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'c828_ligarx');
define('DB_USER', 'c828_ligarx');
define('DB_PASS', 'ligarx');

// Bot configuration
define('BOT_TOKEN', '8188857509:AAHjKKUaC_kljF1KKHZ0VW1pWkcWDfaY65k');
define('BOT_USERNAME', 'tanga');
define('WEBAPP_URL', 'https://your-domain.com');
define('AVATAR_BASE_URL', 'http://c828.coresuz.ru/avatars');

// Security configuration - Toggle ON/OFF from config.php (not database)
define('AUTH_KEY_DETECTION', true); // Set to false to disable authKey validation
define('ANTI_DDOS_PROTECTION', true); // Set to false to disable anti-DDoS

// Rate limiting configuration (session-based for speed)
define('DDOS_RATE_LIMIT', 100); // 100 requests per minute
define('DDOS_TIME_WINDOW', 60); // 1 minute window
define('DDOS_BAN_DURATION', 300); // 5 minutes ban

// Game configuration
define('WELCOME_BONUS', 100);
define('REFERRAL_BONUS', 200);
define('BASE_MINING_RATE', 0.001);
define('MIN_CLAIM_TIME', 1800); // 30 minutes minimum mining time
define('MIN_CLAIM_INTERVAL', 300); // 5 minutes between claims

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
            
            // Auto-initialize tables
            $this->initializeTables();
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    private function initializeTables() {
        try {
            // Users table with correct snake_case column names
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id VARCHAR(255) PRIMARY KEY,
                first_name VARCHAR(255) NOT NULL,
                last_name VARCHAR(255) DEFAULT '',
                avatar_url TEXT,
                auth_key VARCHAR(128) UNIQUE NOT NULL,
                ref_auth VARCHAR(32) DEFAULT '',
                ref_auth_used VARCHAR(32) DEFAULT '',
                balance DECIMAL(15,8) DEFAULT 0,
                total_earned DECIMAL(15,8) DEFAULT 0,
                bonus_claimed BOOLEAN DEFAULT FALSE,
                referred_by VARCHAR(255) DEFAULT '',
                referral_count INT DEFAULT 0,
                level_num INT DEFAULT 1,
                xp INT DEFAULT 0,
                is_mining BOOLEAN DEFAULT FALSE,
                mining_start_time BIGINT DEFAULT 0,
                last_claim_time BIGINT DEFAULT 0,
                pending_rewards DECIMAL(15,8) DEFAULT 0,
                mining_rate DECIMAL(15,8) DEFAULT " . BASE_MINING_RATE . ",
                min_claim_time INT DEFAULT " . MIN_CLAIM_TIME . ",
                mining_speed_level INT DEFAULT 1,
                claim_time_level INT DEFAULT 1,
                mining_rate_level INT DEFAULT 1,
                sound_enabled BOOLEAN DEFAULT TRUE,
                vibration_enabled BOOLEAN DEFAULT TRUE,
                notifications_enabled BOOLEAN DEFAULT TRUE,
                joined_at BIGINT NOT NULL,
                last_active BIGINT NOT NULL,
                is_returning_user BOOLEAN DEFAULT FALSE,
                data_initialized BOOLEAN DEFAULT FALSE,
                status ENUM('active', 'banned', 'suspended') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_auth_key (auth_key),
                INDEX idx_referred_by (referred_by),
                INDEX idx_total_earned (total_earned),
                INDEX idx_last_active (last_active),
                INDEX idx_status (status),
                INDEX idx_xp (xp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Missions table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS missions (
                id VARCHAR(255) PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                detailed_description TEXT,
                reward INT NOT NULL,
                required_count INT DEFAULT 1,
                channel_id VARCHAR(255),
                url TEXT,
                code VARCHAR(255),
                required_time INT,
                active BOOLEAN DEFAULT TRUE,
                category VARCHAR(100) NOT NULL,
                type ENUM('join_channel', 'join_group', 'url_timer', 'promo_code', 'multi_promo_code', 'daily_taps', 'invite_friends') NOT NULL,
                icon VARCHAR(255),
                img TEXT,
                priority INT DEFAULT 999,
                instructions JSON,
                tips JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                reset_daily BOOLEAN DEFAULT FALSE,
                INDEX idx_active (active),
                INDEX idx_type (type),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // User missions table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_missions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                mission_id VARCHAR(255) NOT NULL,
                started BOOLEAN DEFAULT FALSE,
                completed BOOLEAN DEFAULT FALSE,
                claimed BOOLEAN DEFAULT FALSE,
                current_count INT DEFAULT 0,
                started_date BIGINT,
                completed_at BIGINT,
                claimed_at BIGINT,
                last_verify_attempt BIGINT,
                timer_started BIGINT,
                code_submitted VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_mission (user_id, mission_id),
                INDEX idx_user_id (user_id),
                INDEX idx_mission_id (mission_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Referrals table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS referrals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                referrer_id VARCHAR(255) NOT NULL,
                referred_id VARCHAR(255) NOT NULL,
                earned INT DEFAULT " . REFERRAL_BONUS . ",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_referral (referrer_id, referred_id),
                INDEX idx_referrer (referrer_id),
                INDEX idx_referred (referred_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Conversions table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS conversions (
                id VARCHAR(255) PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                from_currency VARCHAR(50) NOT NULL,
                to_currency VARCHAR(50) NOT NULL,
                amount DECIMAL(15,8) NOT NULL,
                converted_amount DECIMAL(15,8) NOT NULL,
                category VARCHAR(100) NOT NULL,
                package_type VARCHAR(100) NOT NULL,
                package_image TEXT,
                required_info JSON,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                requested_at BIGINT NOT NULL,
                completed_at BIGINT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_requested_at (requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Config table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS config (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Promo codes table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS promo_codes (
                id VARCHAR(255) PRIMARY KEY,
                code VARCHAR(255) UNIQUE NOT NULL,
                reward INT NOT NULL,
                description TEXT,
                used_by VARCHAR(255) DEFAULT NULL,
                used_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                created_by VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_code (code),
                INDEX idx_used_by (used_by),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Wallet categories table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS wallet_categories (
                id VARCHAR(255) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                image TEXT,
                icon_url TEXT,
                active BOOLEAN DEFAULT TRUE,
                conversion_rate DECIMAL(10,4) DEFAULT 1,
                min_conversion INT DEFAULT 1,
                max_conversion INT DEFAULT 10000,
                processing_time VARCHAR(255) DEFAULT '24-48 hours',
                instructions TEXT,
                required_fields JSON,
                packages JSON,
                priority INT DEFAULT 999,
                min_id_length INT DEFAULT 9,
                max_id_length INT DEFAULT 12,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (active),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Insert default config (only bot username and banner)
            $this->pdo->exec("INSERT IGNORE INTO config (setting_key, setting_value) VALUES 
                ('bot_username', '" . BOT_USERNAME . "'),
                ('banner_url', 'https://mining-master.onrender.com//assets/banner-BH8QO14f.png')");
            
        } catch (Exception $e) {
            error_log("Failed to initialize tables: " . $e->getMessage());
            throw $e;
        }
    }
}

/**
 * Fast session-based DDOS protection (no MySQL for speed)
 */
function checkSessionRateLimit($ip, $limit = DDOS_RATE_LIMIT, $window = DDOS_TIME_WINDOW, $banDuration = DDOS_BAN_DURATION) {
    // Check if DDOS protection is enabled in config.php
    if (!ANTI_DDOS_PROTECTION) return ['ok' => true];
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $now = time();
    
    if (!isset($_SESSION['ddos_data'][$ip])) {
        $_SESSION['ddos_data'][$ip] = ['requests' => [], 'ban_until' => 0];
    }

    // Check if IP is banned
    if ($_SESSION['ddos_data'][$ip]['ban_until'] > $now) {
        return ['ok' => false, 'reason' => 'IP Banned', 'wait' => $_SESSION['ddos_data'][$ip]['ban_until'] - $now];
    }

    // Clean old requests (older than window)
    $_SESSION['ddos_data'][$ip]['requests'] = array_filter(
        $_SESSION['ddos_data'][$ip]['requests'],
        fn($timestamp) => $timestamp > ($now - $window)
    );

    // Check rate limit
    if (count($_SESSION['ddos_data'][$ip]['requests']) >= $limit) {
        $_SESSION['ddos_data'][$ip]['ban_until'] = $now + $banDuration;
        error_log("IP banned for DDOS: $ip");
        return ['ok' => false, 'reason' => 'Rate limit exceeded', 'wait' => $banDuration];
    }

    // Add current request
    $_SESSION['ddos_data'][$ip]['requests'][] = $now;
    return ['ok' => true];
}

/**
 * Verify Telegram init data properly
 */
function verifyTelegramInitData($initData, $botToken) {
    if (empty($initData)) return false;
    
    try {
        parse_str($initData, $data);
        if (!isset($data['hash'])) return false;

        $checkHash = $data['hash'];
        unset($data['hash']);
        ksort($data);

        $checkString = "";
        foreach ($data as $k => $v) {
            $checkString .= "$k=$v\n";
        }
        $checkString = rtrim($checkString, "\n");

        $secretKey = hash_hmac('sha256', $botToken, "WebAppData", true);
        $hash = hash_hmac('sha256', $checkString, $secretKey);

        return hash_equals($hash, $checkHash) ? $data : false;
    } catch (Exception $e) {
        error_log("Telegram init data verification failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = explode(',', $ip)[0];
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Auto-initialize database on first load
try {
    Database::getInstance();
} catch (Exception $e) {
    error_log("Database initialization failed: " . $e->getMessage());
}
?>