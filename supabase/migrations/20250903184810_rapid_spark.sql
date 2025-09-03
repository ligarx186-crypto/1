-- UC Coin Ultra Database Setup
-- Run this file to create the complete database structure

CREATE DATABASE IF NOT EXISTS c828_ligarx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE c828_ligarx;

-- Users table with correct column names
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(255) PRIMARY KEY,
    firstName VARCHAR(255) NOT NULL,
    lastName VARCHAR(255) DEFAULT '',
    avatarUrl TEXT,
    authKey VARCHAR(128) UNIQUE NOT NULL,
    refAuth VARCHAR(32) DEFAULT '',
    refAuthUsed VARCHAR(32) DEFAULT '',
    balance DECIMAL(15,8) DEFAULT 0,
    ucBalance DECIMAL(15,8) DEFAULT 0,
    energyLimit INT DEFAULT 500,
    multiTapValue INT DEFAULT 1,
    rechargingSpeed INT DEFAULT 1,
    tapBotPurchased BOOLEAN DEFAULT FALSE,
    tapBotActive BOOLEAN DEFAULT FALSE,
    bonusClaimed BOOLEAN DEFAULT FALSE,
    pubgId VARCHAR(255) DEFAULT '',
    totalTaps INT DEFAULT 0,
    totalEarned DECIMAL(15,8) DEFAULT 0,
    lastJackpotTime BIGINT DEFAULT 0,
    referredBy VARCHAR(255) DEFAULT '',
    referralCount INT DEFAULT 0,
    levelNum INT DEFAULT 1,
    xp INT DEFAULT 0,
    streak INT DEFAULT 0,
    combo INT DEFAULT 0,
    lastTapTime BIGINT DEFAULT 0,
    isMining BOOLEAN DEFAULT FALSE,
    miningStartTime BIGINT DEFAULT 0,
    lastClaimTime BIGINT DEFAULT 0,
    pendingRewards DECIMAL(15,8) DEFAULT 0,
    miningRate DECIMAL(15,8) DEFAULT 0.001,
    minClaimTime INT DEFAULT 1800,
    miningSpeedLevel INT DEFAULT 1,
    claimTimeLevel INT DEFAULT 1,
    miningRateLevel INT DEFAULT 1,
    soundEnabled BOOLEAN DEFAULT TRUE,
    vibrationEnabled BOOLEAN DEFAULT TRUE,
    notificationsEnabled BOOLEAN DEFAULT TRUE,
    joinedAt BIGINT NOT NULL,
    lastActive BIGINT NOT NULL,
    isReturningUser BOOLEAN DEFAULT FALSE,
    dataInitialized BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'banned', 'suspended') DEFAULT 'active',
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_authKey (authKey),
    INDEX idx_referredBy (referredBy),
    INDEX idx_totalEarned (totalEarned),
    INDEX idx_lastActive (lastActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missions table
CREATE TABLE IF NOT EXISTS missions (
    id VARCHAR(255) PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    detailedDescription TEXT,
    reward INT NOT NULL,
    requiredCount INT DEFAULT 1,
    channelId VARCHAR(255),
    url TEXT,
    code VARCHAR(255),
    requiredTime INT,
    active BOOLEAN DEFAULT TRUE,
    category VARCHAR(100) NOT NULL,
    type ENUM('join_channel', 'join_group', 'url_timer', 'promo_code', 'multi_promo_code', 'daily_taps', 'invite_friends') NOT NULL,
    icon VARCHAR(255),
    img TEXT,
    priority INT DEFAULT 999,
    instructions JSON,
    tips JSON,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiresAt TIMESTAMP NULL,
    resetDaily BOOLEAN DEFAULT FALSE,
    INDEX idx_active (active),
    INDEX idx_type (type),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User missions table
CREATE TABLE IF NOT EXISTS userMissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(255) NOT NULL,
    missionId VARCHAR(255) NOT NULL,
    started BOOLEAN DEFAULT FALSE,
    completed BOOLEAN DEFAULT FALSE,
    claimed BOOLEAN DEFAULT FALSE,
    currentCount INT DEFAULT 0,
    startedDate BIGINT,
    completedAt BIGINT,
    claimedAt BIGINT,
    lastVerifyAttempt BIGINT,
    timerStarted BIGINT,
    codeSubmitted VARCHAR(255),
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_mission (userId, missionId),
    INDEX idx_userId (userId),
    INDEX idx_missionId (missionId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Referrals table
CREATE TABLE IF NOT EXISTS referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referrerId VARCHAR(255) NOT NULL,
    referredId VARCHAR(255) NOT NULL,
    earned INT DEFAULT 200,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_referral (referrerId, referredId),
    INDEX idx_referrer (referrerId),
    INDEX idx_referred (referredId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversions table
CREATE TABLE IF NOT EXISTS conversions (
    id VARCHAR(255) PRIMARY KEY,
    userId VARCHAR(255) NOT NULL,
    fromCurrency VARCHAR(50) NOT NULL,
    toCurrency VARCHAR(50) NOT NULL,
    amount DECIMAL(15,8) NOT NULL,
    convertedAmount DECIMAL(15,8) NOT NULL,
    category VARCHAR(100) NOT NULL,
    packageType VARCHAR(100) NOT NULL,
    packageImage TEXT,
    requiredInfo JSON,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    requestedAt BIGINT NOT NULL,
    completedAt BIGINT,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_userId (userId),
    INDEX idx_status (status),
    INDEX idx_requestedAt (requestedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting table
CREATE TABLE IF NOT EXISTS rateLimits (
    ip VARCHAR(45) NOT NULL,
    requestCount INT DEFAULT 1,
    windowStart BIGINT NOT NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ip),
    INDEX idx_windowStart (windowStart)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Config table
CREATE TABLE IF NOT EXISTS config (
    settingKey VARCHAR(100) PRIMARY KEY,
    settingValue TEXT NOT NULL,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admins table
CREATE TABLE IF NOT EXISTS admins (
    adminId VARCHAR(255) PRIMARY KEY,
    addedBy VARCHAR(255) NOT NULL,
    addedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promo codes table
CREATE TABLE IF NOT EXISTS promoCodes (
    id VARCHAR(255) PRIMARY KEY,
    code VARCHAR(255) UNIQUE NOT NULL,
    reward INT NOT NULL,
    description TEXT,
    usedBy VARCHAR(255) DEFAULT NULL,
    usedAt TIMESTAMP NULL,
    expiresAt TIMESTAMP NULL,
    createdBy VARCHAR(255) NOT NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_usedBy (usedBy),
    INDEX idx_expiresAt (expiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallet categories table
CREATE TABLE IF NOT EXISTS walletCategories (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    image TEXT,
    iconUrl TEXT,
    active BOOLEAN DEFAULT TRUE,
    conversionRate DECIMAL(10,4) DEFAULT 1,
    minConversion INT DEFAULT 1,
    maxConversion INT DEFAULT 10000,
    processingTime VARCHAR(255) DEFAULT '24-48 hours',
    instructions TEXT,
    requiredFields JSON,
    packages JSON,
    priority INT DEFAULT 999,
    minIdLength INT DEFAULT 9,
    maxIdLength INT DEFAULT 12,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert mega admin
INSERT IGNORE INTO admins (adminId, addedBy, addedAt) VALUES ('6547102814', 'system', NOW());

-- Insert default wallet categories
INSERT IGNORE INTO walletCategories (
    id, name, description, image, iconUrl, active, conversionRate,
    minConversion, maxConversion, processingTime, instructions,
    requiredFields, packages, priority, minIdLength, maxIdLength
) VALUES 
(
    'pubg_mobile',
    'PUBG Mobile',
    'Convert DRX to UC for PUBG Mobile',
    'https://i.ibb.co/60fDXMV8/pubgm-app-icon-512x512-1-e9f7efc0.png',
    'https://i.ibb.co/60fDXMV8/pubgm-app-icon-512x512-1-e9f7efc0.png',
    TRUE,
    1,
    60,
    8100,
    '24-48 hours',
    'Make sure your PUBG Mobile ID is correct. UC will be delivered to your account within 24-48 hours.',
    '[{"id":"pubg_id","name":"pubgId","label":"PUBG Mobile ID","type":"number","placeholder":"Enter your PUBG Mobile ID","required":true,"validation":"^[0-9]+$","helpText":"Your PUBG Mobile ID can be found in game settings"}]',
    '[{"id":"uc_60","name":"60 UC","amount":60,"drxCost":60,"popular":false,"description":"Basic UC package","image":"https://i.ibb.co/GfgyhNCy/1599546007887-MVe-NUt-B6.png"},{"id":"uc_325","name":"325 UC","amount":325,"drxCost":325,"popular":true,"bonus":25,"description":"Popular choice with bonus","image":"https://i.ibb.co/1g83YH9/1599546030876-PIvqw-Gaa.png"},{"id":"uc_660","name":"660 UC","amount":660,"drxCost":660,"popular":false,"description":"Great value package","image":"https://i.ibb.co/TBZb1ndk/1599546041426-W8hm-Er-MS.png"},{"id":"uc_1800","name":"1800 UC","amount":1800,"drxCost":1800,"popular":true,"bonus":100,"description":"Best value with bonus","image":"https://i.ibb.co/svwxRf4c/1599546052747-L5g-Su7-VB.png"},{"id":"uc_3850","name":"3850 UC","amount":3850,"drxCost":3850,"popular":false,"description":"Premium package","image":"https://i.ibb.co/hFq5xFr9/1599546071746-Kqk-Ihrz-G.png"},{"id":"uc_8100","name":"8100 UC","amount":8100,"drxCost":8100,"popular":false,"bonus":500,"description":"Ultimate package with huge bonus","image":"https://i.ibb.co/hFq5xFr9/1599546071746-Kqk-Ihrz-G.png"}]',
    1,
    9,
    12
),
(
    'telegram',
    'Telegram Stars',
    'Convert DRX to Telegram Stars',
    'https://i.ibb.co/tMSTSQHf/images-3.jpg',
    'https://i.ibb.co/tMSTSQHf/images-3.jpg',
    TRUE,
    10,
    100,
    10000,
    '1-2 hours',
    'Make sure your Telegram username is correct and your profile is public. Stars will be sent to your account.',
    '[{"id":"telegram_username","name":"telegramUsername","label":"Telegram Username","type":"text","placeholder":"@username","required":true,"validation":"^@[a-zA-Z0-9_]{5,32}$","helpText":"Your Telegram username (make sure your profile is public)"}]',
    '[{"id":"stars_10","name":"10 Stars","amount":10,"drxCost":100,"popular":false,"description":"Basic stars package","image":"https://i.ibb.co/tMSTSQHf/images-3.jpg"},{"id":"stars_50","name":"50 Stars","amount":50,"drxCost":500,"popular":true,"bonus":5,"description":"Popular choice","image":"https://i.ibb.co/tMSTSQHf/images-3.jpg"},{"id":"stars_100","name":"100 Stars","amount":100,"drxCost":1000,"popular":false,"bonus":10,"description":"Great value","image":"https://i.ibb.co/tMSTSQHf/images-3.jpg"},{"id":"stars_500","name":"500 Stars","amount":500,"drxCost":5000,"popular":true,"bonus":50,"description":"Best value","image":"https://i.ibb.co/tMSTSQHf/images-3.jpg"},{"id":"stars_1000","name":"1000 Stars","amount":1000,"drxCost":10000,"popular":false,"bonus":100,"description":"Premium package","image":"https://i.ibb.co/tMSTSQHf/images-3.jpg"}]',
    2,
    5,
    32
);