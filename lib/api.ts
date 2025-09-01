// Optimized PHP Backend API Service with Telegram Authentication
const API_BASE_URL = '/backend/api.php'

class APIService {
  private authKey: string = ''
  private telegramInitData: string = ''
  
  setAuthKey(authKey: string) {
    this.authKey = authKey
  }
  
  setTelegramInitData(initData: string) {
    this.telegramInitData = initData
  }
  
  private async request(endpoint: string, options: RequestInit = {}) {
    const url = `${API_BASE_URL}?path=${encodeURIComponent(endpoint)}`
    
    const headers = {
      'Content-Type': 'application/json',
      'X-Auth-Key': this.authKey,
      'X-Telegram-Init-Data': this.telegramInitData,
      'X-Ref-Id': this.getUrlParam('ref') || '',
      'X-Ref-Auth': this.getUrlParam('refauth') || '',
      ...options.headers
    }
    
    try {
      const response = await fetch(url, {
        ...options,
        headers,
        mode: 'cors'
      })
      
      if (!response.ok) {
        const errorText = await response.text()
        throw new Error(`HTTP ${response.status}: ${errorText}`)
      }
      
      return await response.json()
    } catch (error) {
      console.error(`API Request failed for ${endpoint}:`, error)
      throw error
    }
  }
  
  private getUrlParam(name: string): string {
    if (typeof window === 'undefined') return ''
    const urlParams = new URLSearchParams(window.location.search)
    return urlParams.get(name) || ''
  }
  
  // Authentication with Telegram validation
  async authenticate(userData: {
    userId: string
    firstName: string
    lastName?: string
    avatarUrl?: string
    referredBy?: string
    refAuth?: string
    telegramInitData?: string
  }) {
    return this.request('auth', {
      method: 'POST',
      body: JSON.stringify(userData)
    })
  }
  
  // User operations
  async getUser(userId: string) {
    return this.request(`user&userId=${userId}`)
  }
  
  async updateUser(userId: string, userData: any) {
    return this.request(`user&userId=${userId}`, {
      method: 'PUT',
      body: JSON.stringify(userData)
    })
  }
  
  // Fast mining status check without auth
  async getMiningStatus(userId: string) {
    return this.request(`mining-status&userId=${userId}`)
  }
  
  // Start mining
  async startMining(userId: string) {
    return this.request(`user&userId=${userId}`, {
      method: 'PUT',
      body: JSON.stringify({ startMining: true })
    })
  }
  
  // Claim mining rewards
  async claimMining(userId: string) {
    return this.request(`user&userId=${userId}`, {
      method: 'PUT',
      body: JSON.stringify({ claimMining: true })
    })
  }
  
  // Upgrade boost
  async upgradeBoost(userId: string, boostType: string) {
    return this.request(`user&userId=${userId}`, {
      method: 'PUT',
      body: JSON.stringify({ upgradeBoost: boostType })
    })
  }
  
  // Mission operations
  async getMissions() {
    return this.request('missions')
  }
  
  async getUserMissions(userId: string) {
    return this.request(`user-missions&userId=${userId}`)
  }
  
  async updateUserMission(userId: string, missionId: string, missionData: any) {
    return this.request(`user-missions&userId=${userId}`, {
      method: 'PUT',
      body: JSON.stringify({ missionId, missionData })
    })
  }
  
  // Referral operations
  async getReferralData(userId: string) {
    return this.request(`referrals&userId=${userId}`)
  }
  
  // Conversion operations
  async getUserConversions(userId: string) {
    return this.request(`conversions&userId=${userId}`)
  }
  
  async createConversion(userId: string, conversionData: any) {
    return this.request(`conversions&userId=${userId}`, {
      method: 'POST',
      body: JSON.stringify(conversionData)
    })
  }
  
  // Config operations
  async getConfig() {
    return this.request('config')
  }
  
  async getBotUsername() {
    const config = await this.getConfig()
    return config.bot_username || 'UCCoinUltraBot'
  }
  
  async getBannerUrl() {
    const config = await this.getConfig()
    return config.banner_url || 'https://mining-master.onrender.com//assets/banner-BH8QO14f.png'
  }
  
  // Wallet operations
  async getWalletCategories() {
    return this.request('wallet-categories')
  }
  
  // Leaderboard operations
  async getGlobalLeaderboard(type: 'balance' | 'level' = 'balance') {
    return this.request(`leaderboard&type=${type}`)
  }
  
  async getLeaderboard() {
    const referrals = await this.request('referrals')
    return Object.entries(referrals).map(([id, data]: [string, any]) => ({
      id,
      count: data.count || 0,
      earned: data.totalUC || 0,
      user: { firstName: data.firstName || 'User' }
    })).sort((a, b) => b.count - a.count).slice(0, 100)
  }
  
  // Telegram verification
  async verifyTelegramMembership(userId: string, channelId: string) {
    return this.request(`verify-telegram&userId=${userId}&channelId=${encodeURIComponent(channelId)}`)
  }
  
  // Promo code submission
  async submitPromoCode(userId: string, code: string) {
    return this.request(`submit-promo-code&userId=${userId}`, {
      method: 'POST',
      body: JSON.stringify({ code })
    })
  }
}

export const apiService = new APIService()