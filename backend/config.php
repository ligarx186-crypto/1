<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'c828_ligarx');
define('DB_USER', 'c828_ligarx');
define('DB_PASS', 'ligarx');

// Security configuration
define('AUTH_KEY_LENGTH', 64);
define('MAX_REQUEST_SIZE', 1024 * 1024); // 1MB
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 3600); // 1 hour

// Game configuration
define('WELCOME_BONUS', 100);
define('REFERRAL_BONUS', 200);
define('BASE_MINING_RATE', 0.001);
define('MIN_CLAIM_TIME', 1800); // 30 minutes minimum mining time
define('MAX_MINING_TIME', 1800); // 30 minutes maximum mining time (not 39!)
define('CLAIM_TIME_REDUCTION', 60); // seconds per boost level
define('MIN_CLAIM_INTERVAL', 300); // 5 minutes between claims

// API Security Settings - Can be toggled on/off
define('AUTH_KEY_DETECTION', true); // Set to false to disable authKey validation
define('ANTI_DDOS_PROTECTION', true); // Set to false to disable anti-DDoS

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
    
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    public function commit() {
        return $this->pdo->commit();
    }
    
    public function rollback() {
        return $this->pdo->rollback();
    }
}

// Auto-initialize database tables with correct column names
function initializeTables() {
    $db = Database::getInstance()->getConnection();
    
    try {
        // Users table with snake_case column names (matching database)
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id VARCHAR(255) PRIMARY KEY,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) DEFAULT '',
            avatar_url TEXT,
            auth_key VARCHAR(128) UNIQUE NOT NULL,
            ref_auth VARCHAR(32) DEFAULT '',
            ref_auth_used VARCHAR(32) DEFAULT '',
            balance DECIMAL(15,8) DEFAULT 0,
            uc_balance DECIMAL(15,8) DEFAULT 0,
            energy_limit INT DEFAULT 500,
            multi_tap_value INT DEFAULT 1,
            recharging_speed INT DEFAULT 1,
            tap_bot_purchased BOOLEAN DEFAULT FALSE,
            tap_bot_active BOOLEAN DEFAULT FALSE,
            bonus_claimed BOOLEAN DEFAULT FALSE,
            pubg_id VARCHAR(255) DEFAULT '',
            total_taps INT DEFAULT 0,
            total_earned DECIMAL(15,8) DEFAULT 0,
            last_jackpot_time BIGINT DEFAULT 0,
            referred_by VARCHAR(255) DEFAULT '',
            referral_count INT DEFAULT 0,
            level_num INT DEFAULT 1,
            xp INT DEFAULT 0,
            streak INT DEFAULT 0,
            combo INT DEFAULT 0,
            last_tap_time BIGINT DEFAULT 0,
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
            INDEX idx_last_active (last_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Missions table
        $db->exec("CREATE TABLE IF NOT EXISTS missions (
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
        $db->exec("CREATE TABLE IF NOT EXISTS user_missions (
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
        $db->exec("CREATE TABLE IF NOT EXISTS referrals (
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
        $db->exec("CREATE TABLE IF NOT EXISTS conversions (
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
        
        // Rate limiting table
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            ip VARCHAR(45) NOT NULL,
            request_count INT DEFAULT 1,
            window_start BIGINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ip),
            INDEX idx_window_start (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Config table
        $db->exec("CREATE TABLE IF NOT EXISTS config (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Promo codes table
        $db->exec("CREATE TABLE IF NOT EXISTS promo_codes (
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
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_categories (
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
        
        // Insert default config (no default categories - will be added via panel)
        $db->exec("INSERT IGNORE INTO config (setting_key, setting_value) VALUES 
            ('bot_username', 'tanga'),
            ('banner_url', 'https://mining-master.onrender.com//assets/banner-BH8QO14f.png')");
        
        error_log("Database tables initialized successfully");
    } catch (Exception $e) {
        error_log("Failed to initialize tables: " . $e->getMessage());
        throw $e;
    }
}

// Auto-initialize tables on first run
try {
    initializeTables();
} catch (Exception $e) {
    error_log("Database initialization failed: " . $e->getMessage());
}

/**
 * Session-based rate limiting for fast DDOS protection
 */
function checkSessionRateLimit($ip, $limit = 20, $window = 60, $banDuration = 300) {
    if (!ANTI_DDOS_PROTECTION) return ['ok' => true];
    
    session_start();
    $now = time();
    
    if (!isset($_SESSION['ip_data'][$ip])) {
        $_SESSION['ip_data'][$ip] = ['timestamps' => [], 'ban_until' => 0];
    }

    // Check if IP is banned
    if ($_SESSION['ip_data'][$ip]['ban_until'] > $now) {
        return ['ok' => false, 'reason' => 'Banned', 'wait' => $_SESSION['ip_data'][$ip]['ban_until'] - $now];
    }

    // Clean old requests
    $_SESSION['ip_data'][$ip]['timestamps'] = array_filter(
        $_SESSION['ip_data'][$ip]['timestamps'],
        fn($t) => $t > $now - $window
    );

    // Check rate limit
    if (count($_SESSION['ip_data'][$ip]['timestamps']) >= $limit) {
        $_SESSION['ip_data'][$ip]['ban_until'] = $now + $banDuration;
        return ['ok' => false, 'reason' => 'Rate limit', 'wait' => $banDuration];
    }

    // Add new request
    $_SESSION['ip_data'][$ip]['timestamps'][] = $now;
    return ['ok' => true];
}

/**
 * Verify Telegram init data
 */
function verifyInitData($initData, $botToken) {
    if (empty($initData)) return false;
    
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
}
?>