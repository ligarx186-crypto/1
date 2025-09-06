-- UC Coin Ultra Database Setup
-- Run this file to create the complete database structure

CREATE DATABASE IF NOT EXISTS c828_ligarx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE c828_ligarx;

-- Users table with correct column names (camelCase to match frontend)
CREATE TABLE IF NOT EXISTS users (
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
    mining_rate DECIMAL(15,8) DEFAULT 0.001,
    min_claim_time INT DEFAULT 1800,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missions table
CREATE TABLE IF NOT EXISTS missions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User missions table
CREATE TABLE IF NOT EXISTS user_missions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Referrals table
CREATE TABLE IF NOT EXISTS referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referrer_id VARCHAR(255) NOT NULL,
    referred_id VARCHAR(255) NOT NULL,
    earned INT DEFAULT 200,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_referral (referrer_id, referred_id),
    INDEX idx_referrer (referrer_id),
    INDEX idx_referred (referred_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversions table
CREATE TABLE IF NOT EXISTS conversions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting table
CREATE TABLE IF NOT EXISTS rate_limits (
    ip VARCHAR(45) NOT NULL,
    request_count INT DEFAULT 1,
    window_start BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ip),
    INDEX idx_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Config table
CREATE TABLE IF NOT EXISTS config (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promo codes table
CREATE TABLE IF NOT EXISTS promo_codes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallet categories table
CREATE TABLE IF NOT EXISTS wallet_categories (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert mega admin (no default categories - will be added via panel)
INSERT IGNORE INTO config (setting_key, setting_value) VALUES 
    ('bot_username', 'tanga'),
    ('banner_url', 'https://mining-master.onrender.com//assets/banner-BH8QO14f.png');