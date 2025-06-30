import { defineStore } from 'pinia'

export interface Wallet {
  id: number
  name: string
  currency: string
}

interface WalletState {
  wallets: Wallet[]
  activeWalletId: number | null
}

export const useWalletStore = defineStore('wallet', {
  state: (): WalletState => ({
    wallets: [],
    activeWalletId: null,
  }),

  getters: {
    activeWallet: (state) =>
      state.wallets.find(w => w.id === state.activeWalletId) ?? { id: 0, name: '—', currency: '' }
  },

  actions: {
    setWallets(wallets: Wallet[]) {
      this.wallets = wallets
    },
    setActive(id: number) {
      this.activeWalletId = id
    },
    addWallet(wallet: Wallet) {
      this.wallets.push(wallet)
    }
  }
})
