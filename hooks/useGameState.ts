"use client"

import type React from "react"
import { useState, useEffect, useCallback } from "react"
import type { User, GameState } from "@/types"
import { apiService } from "@/lib/api"
import { gameLogic, GAME_CONFIG } from "@/lib/game-logic"
import { telegram } from "@/lib/telegram"
import { getUrlParameter, parseReferralFromUrl, parseRefAuthFromUrl, debounce } from "@/lib/utils"

const defaultUserData: User = {
  id: "",
  firstName: "User",
  lastName: "",
  avatarUrl: "",
  balance: 0,
  ucBalance: 0,
  energyLimit: 500,
  multiTapValue: 1,
  rechargingSpeed: 1,
  tapBotPurchased: false,
  tapBotActive: false,
  bonusClaimed: false,
  pubgId: "",
  totalTaps: 0,
  totalEarned: 0,
  lastJackpotTime: 0,
  referredBy: "",
  referralCount: 0,
  level: 1,
  xp: 0,
  streak: 0,
  combo: 0,
  lastTapTime: 0,
  isMining: false,
  miningStartTime: 0,
  lastClaimTime: 0,
  pendingRewards: 0,
  miningRate: GAME_CONFIG.BASE_MINING_RATE,
  minClaimTime: GAME_CONFIG.MIN_CLAIM_TIME,
  settings: {
    sound: true,
    vibration: true,
    notifications: true,
  },
  boosts: {
    miningSpeedLevel: 1,
    claimTimeLevel: 1,
    miningRateLevel: 1,
  },
  missions: {},
  withdrawals: [],
  conversions: [],
  joinedAt: Date.now(),
  lastActive: Date.now(),
  isReturningUser: false,
  dataInitialized: false,
  authKey: "",
}

export const useGameState = () => {
  const [user, setUser] = useState<User>(defaultUserData)
  const [gameState, setGameState] = useState<GameState>({
    isPlaying: false,
    soundEnabled: true,
    vibrationEnabled: true,
    dataLoaded: false,
    saveInProgress: false,
    lastSaveTime: 0,
  })
  const [loading, setLoading] = useState(true)

  // Optimized save function with debouncing
  const performSave = useCallback(async () => {
    if (!user.id || !user.authKey) return

    setGameState((prev) => ({ ...prev, saveInProgress: true }))
    try {
      await apiService.updateUser(user.id, user)
      setGameState((prev) => ({ ...prev, lastSaveTime: Date.now() }))
    } catch (error) {
      console.error("Failed to save user data:", error)
    } finally {
      setGameState((prev) => ({ ...prev, saveInProgress: false }))
    }
  }, [user])

  const debouncedSaveUserData = useCallback(debounce(performSave, 1000), [performSave])

  // Initialize user and game
  const initializeGame = useCallback(async () => {
    try {
      telegram.init()
      const telegramUser = telegram.getUser()
      
      // Get Telegram init data properly
      let telegramInitData = ''
      if (typeof window !== 'undefined' && window.Telegram?.WebApp) {
        telegramInitData = window.Telegram.WebApp.initData || ''
      }

      const userId = getUrlParameter("id") || telegramUser?.id?.toString() || "user123"
      const authKey = getUrlParameter("authKey")
      
      if (!authKey) {
        console.error('No auth key provided')
        return
      }
      
      // Set auth data
      apiService.setAuthKey(authKey)
      if (telegramInitData) {
        apiService.setTelegramInitData(telegramInitData)
      }
      
      // Authenticate with PHP backend
      const authResult = await apiService.authenticate({
        userId,
        firstName: telegramUser?.first_name || getUrlParameter("first_name") || "User",
        lastName: telegramUser?.last_name || getUrlParameter("last_name") || "",
        avatarUrl: "",
        referredBy: parseReferralFromUrl() || "",
        refAuth: parseRefAuthFromUrl() || "",
        telegramInitData
      })
      
      if (!authResult.success) {
        if (authResult.banned) {
          // Show banned message
          setUser({ ...defaultUserData, id: userId, authKey: '', banned: true })
          setGameState((prev) => ({ ...prev, dataLoaded: true }))
          setLoading(false)
          return
        }
        throw new Error('Authentication failed')
      }

      if (!authResult.isNewUser && authResult.userData) {
        // Existing user with valid auth
        const existingUser = { 
          ...defaultUserData, 
          ...authResult.userData, 
          id: userId, 
          authKey: authResult.authKey,
          isReturningUser: true 
        }
        
        setUser(existingUser)
      } else {
        // New user
        const newUser = { 
          ...defaultUserData,
          ...authResult.userData,
          authKey: authResult.authKey,
          isReturningUser: false
        }
        setUser(newUser)
      }

      setGameState((prev) => ({ ...prev, dataLoaded: true }))
      setLoading(false)
    } catch (error) {
      console.error("Failed to initialize game:", error)
    }
  }, [])

  // Start mining
  const startMining = useCallback(async () => {
    if (user.isMining) return { success: false, message: "Already mining!" }

    try {
      const result = await apiService.startMining(user.id)
      if (result.success) {
        const now = Date.now()
        const updatedUser = {
          ...user,
          isMining: true,
          miningStartTime: now,
          pendingRewards: 0,
        }
        setUser(updatedUser)
        telegram.hapticFeedback("success")
        return { success: true, message: "Mining started!" }
      }
    } catch (error) {
      console.error("Failed to start mining:", error)
    }
    
    return { success: false, message: "Failed to start mining" }
  }, [user])

  // Claim mining rewards
  const claimMiningRewards = useCallback(async () => {
    if (!gameLogic.canClaimMining(user)) {
      telegram.hapticFeedback("error")
      return { success: false, message: "Mining time not reached!" }
    }

    try {
      const result = await apiService.claimMining(user.id)
      if (result.success) {
        const updatedUser = {
          ...user,
          balance: user.balance + result.earned,
          totalEarned: user.totalEarned + result.earned,
          isMining: false,
          miningStartTime: 0,
          pendingRewards: 0,
          lastClaimTime: Date.now(),
          xp: user.xp + (result.xp || 0),
        }
        setUser(updatedUser)
        telegram.hapticFeedback("success")
        return { 
          success: true, 
          earned: result.earned, 
          message: `Claimed ${gameLogic.formatNumber(result.earned)} DRX!` 
        }
      }
    } catch (error) {
      console.error("Failed to claim rewards:", error)
    }
    
    return { success: false, message: "Failed to claim rewards" }
  }, [user])

  // Upgrade boost
  const upgradeBoost = useCallback(
    async (boostType: "miningSpeed" | "claimTime" | "miningRate") => {
      const currentLevel = user.boosts[`${boostType}Level` as keyof typeof user.boosts]
      const cost = gameLogic.getBoostCost(boostType, currentLevel)

      if (user.balance < cost) {
        telegram.hapticFeedback("error")
        return { success: false, message: `Need ${gameLogic.formatNumber(cost)} DRX` }
      }

      try {
        const result = await apiService.upgradeBoost(user.id, boostType)
        if (result.success) {
          const updates: Partial<User> = {
            balance: user.balance - cost,
            boosts: { ...user.boosts, [`${boostType}Level`]: currentLevel + 1 },
          }

          // Update mining rate and claim time
          const newMiningSpeedLevel = boostType === "miningSpeed" ? currentLevel + 1 : user.boosts.miningSpeedLevel
          const newClaimTimeLevel = boostType === "claimTime" ? currentLevel + 1 : user.boosts.claimTimeLevel
          const newMiningRateLevel = boostType === "miningRate" ? currentLevel + 1 : user.boosts.miningRateLevel
          
          const miningRateMultiplier = Math.pow(GAME_CONFIG.MINING_RATE_MULTIPLIER, (newMiningRateLevel || 1) - 1)
          const miningSpeedMultiplier = Math.pow(GAME_CONFIG.MINING_SPEED_MULTIPLIER, (newMiningSpeedLevel || 1) - 1)
          updates.miningRate = GAME_CONFIG.BASE_MINING_RATE * miningRateMultiplier * miningSpeedMultiplier
          updates.minClaimTime = Math.max(300, GAME_CONFIG.MIN_CLAIM_TIME - (GAME_CONFIG.CLAIM_TIME_REDUCTION * ((newClaimTimeLevel || 1) - 1)))

          const updatedUser = { ...user, ...updates }
          setUser(updatedUser)
          telegram.hapticFeedback("success")
          return { success: true, message: `${boostType} upgraded!` }
        }
      } catch (error) {
        console.error("Failed to upgrade boost:", error)
      }
      
      return { success: false, message: "Failed to upgrade boost" }
    },
    [user],
  )

  // Claim welcome bonus
  const claimWelcomeBonus = useCallback(async () => {
    if (user.bonusClaimed) return { success: false, message: "Already claimed" }

    const updatedUser = {
      ...user,
      balance: user.balance + GAME_CONFIG.WELCOME_BONUS,
      totalEarned: user.totalEarned + GAME_CONFIG.WELCOME_BONUS,
      bonusClaimed: true,
      dataInitialized: true,
    }

    setUser(updatedUser)
    await apiService.updateUser(updatedUser.id, updatedUser)

    telegram.hapticFeedback("success")
    return { success: true, message: `Claimed ${GAME_CONFIG.WELCOME_BONUS} DRX!` }
  }, [user])

  // Initialize on mount
  useEffect(() => {
    initializeGame()
  }, [initializeGame])

  return {
    user,
    gameState,
    loading,
    startMining,
    claimMiningRewards,
    upgradeBoost,
    claimWelcomeBonus,
    saveUserData: debouncedSaveUserData,
    setUser,
  }
}