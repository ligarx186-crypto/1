"use client"

import { useState } from "react"
import { Sparkles, Gift, Star, Coins } from "lucide-react"

export const WelcomeModal = ({ isOpen, onClose, onClaimBonus, onSkip }) => {
  const [claiming, setClaiming] = useState(false)

  const handleClaimBonus = async () => {
    setClaiming(true)
    try {
      await onClaimBonus()
      onClose()
    } finally {
      setClaiming(false)
    }
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-sm p-4">
      {/* Modal Content */}
      <div className="relative w-full max-w-sm bg-gradient-to-br from-gray-900/98 to-black/98 backdrop-blur-xl border-2 border-green-500/50 rounded-3xl shadow-2xl shadow-green-500/40 overflow-hidden">
        {/* Animated Background */}
        <div className="absolute inset-0 overflow-hidden">
          <div className="absolute -top-8 -left-8 w-16 h-16 bg-green-400/30 rounded-full animate-pulse" />
          <div className="absolute -bottom-8 -right-8 w-24 h-24 bg-blue-400/30 rounded-full animate-pulse delay-1000" />
          <div className="absolute top-1/3 right-4 w-3 h-3 bg-yellow-400/60 rounded-full animate-ping" />
          <div className="absolute bottom-1/3 left-4 w-2 h-2 bg-purple-400/60 rounded-full animate-ping delay-500" />
        </div>

        <div className="relative z-10 p-6 text-center">
          {/* Header */}
          <div className="mb-6">
            <div className="relative inline-block">
              <div className="w-20 h-20 mx-auto bg-gradient-to-br from-green-400 via-blue-500 to-purple-600 rounded-full flex items-center justify-center text-4xl animate-bounce shadow-2xl shadow-green-500/60 border-4 border-white/20">
                ⛏️
              </div>
              <div className="absolute -top-1 -right-1 w-6 h-6 bg-yellow-400 rounded-full flex items-center justify-center text-sm animate-spin">
                ✨
              </div>
            </div>
            
            <h1 className="text-2xl font-bold text-white mt-4 mb-2 font-display">
              Welcome to 
              <span className="bg-gradient-to-r from-green-400 via-blue-500 to-purple-600 bg-clip-text text-transparent">
                {" "}DRX Mining!
              </span>
            </h1>
            
            <p className="text-gray-300 text-sm">
              🎮 Start your mining adventure
            </p>
          </div>

          {/* Welcome Bonus Card */}
          <div className="relative bg-gradient-to-br from-yellow-500/20 via-orange-500/20 to-red-500/20 backdrop-blur-md border-2 border-yellow-500/60 rounded-2xl p-5 mb-6 shadow-xl shadow-yellow-500/40 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-r from-yellow-400/10 via-orange-400/10 to-red-400/10 animate-pulse" />
            
            <div className="relative">
              <div className="flex items-center justify-center gap-2 mb-3">
                <Gift className="w-5 h-5 text-yellow-400" />
                <span className="text-sm text-yellow-300 font-bold uppercase tracking-wide">Welcome Bonus</span>
              </div>
              
              <div className="flex items-center justify-center gap-2 mb-2">
                <Coins className="w-8 h-8 text-green-400 animate-spin" />
                <div className="text-4xl font-bold text-transparent bg-gradient-to-r from-yellow-400 via-orange-500 to-red-500 bg-clip-text animate-pulse font-display">
                  100
                </div>
              </div>
              
              <div className="text-lg text-green-400 font-bold mb-1">DRX COINS</div>
              <div className="text-xs text-gray-300">⛏️ Start mining immediately!</div>
            </div>
            
            {/* Floating particles */}
            <div className="absolute top-2 left-3 w-1.5 h-1.5 bg-yellow-400 rounded-full animate-ping opacity-60" />
            <div className="absolute bottom-2 right-4 w-1 h-1 bg-orange-400 rounded-full animate-ping delay-500 opacity-60" />
            <div className="absolute top-1/2 right-2 w-0.5 h-0.5 bg-red-400 rounded-full animate-ping delay-1000 opacity-60" />
          </div>

          {/* Features List */}
          <div className="space-y-2 mb-6 text-left">
            <div className="flex items-center gap-3 text-sm text-gray-300">
              <div className="w-6 h-6 bg-green-500/20 rounded-lg flex items-center justify-center">
                <span className="text-xs">⛏️</span>
              </div>
              <span>Mine DRX coins offline & online</span>
            </div>
            <div className="flex items-center gap-3 text-sm text-gray-300">
              <div className="w-6 h-6 bg-blue-500/20 rounded-lg flex items-center justify-center">
                <span className="text-xs">🎯</span>
              </div>
              <span>Complete missions for rewards</span>
            </div>
            <div className="flex items-center gap-3 text-sm text-gray-300">
              <div className="w-6 h-6 bg-purple-500/20 rounded-lg flex items-center justify-center">
                <span className="text-xs">👥</span>
              </div>
              <span>Invite friends and earn bonuses</span>
            </div>
          </div>

          {/* Actions */}
          <div className="space-y-3">
            <button
              onClick={handleClaimBonus}
              disabled={claiming}
              className="w-full bg-gradient-to-r from-green-400 via-blue-500 to-purple-600 hover:from-green-500 hover:via-blue-600 hover:to-purple-700 disabled:from-gray-700 disabled:to-gray-800 text-white font-bold py-3 px-6 rounded-2xl transition-all duration-300 hover:scale-105 disabled:hover:scale-100 shadow-xl hover:shadow-2xl flex items-center justify-center gap-3 relative overflow-hidden group"
            >
              <div className="absolute inset-0 bg-gradient-to-r from-white/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300" />
              <Gift className="w-5 h-5 relative z-10" />
              <span className="relative z-10">{claiming ? "Claiming..." : "Claim 100 DRX"}</span>
            </button>

            <button
              onClick={onSkip}
              className="w-full bg-gray-700/30 hover:bg-gray-700/50 text-gray-300 hover:text-white font-semibold py-2.5 px-4 rounded-xl transition-all duration-200 border border-gray-700/40 hover:border-gray-600/60 text-sm"
            >
              Skip for Now
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}