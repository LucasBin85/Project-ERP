<script setup lang="ts">
import { ref, computed, nextTick, onMounted, watch } from 'vue'
import { usePage, useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuSeparator,
  DropdownMenuItem,
} from '@/components/ui/dropdown-menu'
import { Button } from '@/components/ui/button'
import { Wallet as WalletIcon, Plus, Pencil, Trash, X } from 'lucide-vue-next'
import { useToast } from "vue-toastification"
import { useWalletStore } from '@/stores/wallet'

//import axios from '@/plugins/axios'
//import { useLoadingStore } from '@/stores/loading'

const walletStore = useWalletStore()
const toast = useToast()
const editingId = ref<number | null>(null)
const editing = ref(false)

interface Wallet {
  id: number
  name: string
  currency: string
}

interface SharedData {
  auth: {
    user: {
      wallets: Wallet[];
      active_wallet: number;
    };
  };
  [key: string]: any
}

const page = usePage<SharedData>()
//const activeWallet = computed(() => page.props.auth.user.active_wallet)

// Sincroniza store com Inertia props
onMounted(() => {
  walletStore.setWallets(page.props.auth.user.wallets)
  walletStore.setActive(page.props.auth.user.active_wallet)
})

const showModal = ref(false)
const form = useForm({ name: '' })
const nameInput = ref<HTMLInputElement | null>(null)

const isDuplicate = computed(() => {
  const nome = form.name.trim().toLowerCase()

  return walletStore.wallets.some(w =>
    w.name.toLowerCase() === nome && w.id !== editingId.value
  ) && nome.length > 0
})
const isSameName = computed(() => {
  const nome = form.name.trim().toLowerCase()
  const current = walletStore.wallets.find(w => w.id === editingId.value)

  return current && current.name.toLowerCase() === nome
})
const canSubmit = computed(() => {
  const validName = form.name.trim().length > 0
  return validName && !isDuplicate.value && !isSameName.value
})
const modalTitle = computed(() =>
  editing.value ? 'Editar Carteira' : 'Nova Carteira'
)
const submitLabel = computed(() =>
  editing.value ? 'Salvar' : 'Criar'
)

// fecha o modal ao clicar em Esc
watch(showModal, (visible) => {
  if (visible) {
    document.addEventListener('keydown', handleKeyDown)
  } else {
    document.removeEventListener('keydown', handleKeyDown)
  }
})

function handleKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    closeModal()
  }
}

function openModal() {
  showModal.value = true
  nextTick(() => {
    setTimeout(() => {
      nameInput.value?.focus()
    }, 200)
  })
}

function closeModal() {
  showModal.value = false
  form.reset()
  editing.value = false
}

function submit() {
  if (editing.value) {
    form.put(route('wallets.update', { wallet: editingId.value }), {
      onSuccess: () => {
        walletStore.setWallets(page.props.auth.user.wallets);
        walletStore.setActive(page.props.auth.user.active_wallet);
        closeModal()
        toast.success('Carteira atualizada com sucesso!')
        //router.reload({ only: ['auth.user.wallets', 'auth.user.active_wallet'] })
      },
      onError: () => {
        toast.error('Erro ao atualizar carteira')
      }
    })
  } else {
      form.post(route('wallets.store'), {
        onSuccess: () => {
          walletStore.setWallets(page.props.auth.user.wallets)
          walletStore.setActive(page.props.auth.user.active_wallet)
          toast.success('Carteira criada e ativada!')
          closeModal()
        }
      });
  }
}

function editWallet(wallet: Wallet) {
  editing.value = true
  form.name = wallet.name
  editingId.value = wallet.id
  showModal.value = true
  nextTick(() => {
    setTimeout(() => {
      nameInput.value?.focus()
    }, 200)
  })
}

function deleteWallet(id: number) {
  if (confirm('Tem certeza que deseja remover esta carteira?')) {
    router.delete(route('wallets.destroy', id), {
      onSuccess: () => {
        walletStore.setWallets(page.props.auth.user.wallets)
        walletStore.setActive(page.props.auth.user.active_wallet)
        
        toast.success('Carteira removida com sucesso!')
        router.reload({ 
          only: [
            'auth.user.wallets', 
            'auth.user.active_wallet',
            'auth.user.tree',
          ], 
        })
      },
      onError: () => {
        toast.error('Erro ao remover carteira')
      }
    })
  }
}

function selectWallet(id: number) {
  router.post(route('wallets.active'), { wallet_id: id }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      walletStore.setActive(page.props.auth.user.active_wallet)
      toast.success('Carteira ativa alterada!')
    },
    onError: () => {
      toast.error('Erro ao alterar carteira')
    }
  })
}

</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="px-3 py-1 flex items-center space-x-2 cursor-pointer">
        <WalletIcon class="w-5 h-5" />
        <span>{{ walletStore.activeWallet.name }}</span>
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end" class="w-56">
      <DropdownMenuItem
        v-for="w in walletStore.wallets"
        :key="w.id"
        as="div"
        class="flex items-center justify-between group px-2 py-1"
      >
        <Button
          @click="selectWallet(w.id)"
          class="flex-1 justify-start cursor-pointer"
          :variant="w.id === walletStore.activeWallet.id ? 'secondary' : 'ghost'"
        >
          {{ w.name }}
        </Button>

        <!-- Ícones visíveis só no hover -->
        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
          <Button
            @click="editWallet(w)"
            variant="ghost"
            size="icon"
            class="w-8 h-8 text-blue-500 hover:text-blue-700 transition-transform duration-150 transform hover:scale-140 hover:shadow-md cursor-pointer"
          >
            <Pencil class="w-4 h-4" />
          </Button>

          <Button
            @click="deleteWallet(w.id)"
            variant="ghost"
            size="icon"
            class="w-8 h-8 text-red-500 hover:text-red-700 transition-transform duration-150 transform hover:scale-140 hover:shadow-md cursor-pointer"
          >
            <Trash class="w-4 h-4" />
          </Button>

        </div>
      </DropdownMenuItem>



      <DropdownMenuSeparator />
      <DropdownMenuItem
        as="button"
        @click="openModal"
        class="flex items-center gap-2 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer"
      >
        <Plus class="w-4 h-4" />
        Nova Carteira
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <Teleport to="body">
    <div
      v-if="showModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
      @click.self="closeModal"
    >
      <div class="bg-white dark:bg-gray-900 rounded-lg p-6 w-80 shadow-lg relative">
        <button
          id="wallet-modal-close"
          @click="closeModal"
          class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 cursor-pointer"
          aria-label="close"
        >
          <X class="w-4 h-4" />
        </button>
        <h2 class="text-lg font-medium mb-4 text-gray-900 dark:text-gray-100">
          {{ modalTitle }}
        </h2>

        <form @submit.prevent="submit">
          <div class="mb-4">
            <label class="block mb-1 text-gray-700 dark:text-gray-300">Nome</label>
            <input
              ref="nameInput"
              v-model="form.name"
              type="text"
              class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded p-2 focus:ring-2 focus:ring-blue-400"
            />

            <p v-if="isDuplicate" class="text-red-600 text-sm mt-1">
              Você já tem uma carteira com este nome.
            </p>
            <p v-else-if="isSameName" class="text-gray-500 text-sm mt-1">
              Este é o nome atual da carteira.
            </p>
            <p v-else-if="form.errors.name" class="text-red-600 text-sm mt-1">
              {{ form.errors.name }}
            </p>

          </div>

          <div class="flex justify-end space-x-2 mt-6">
            <button
              type="button"
              @click="closeModal"
              class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded text-gray-700 dark:text-gray-300"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center gap-2"
              :disabled="!canSubmit || form.processing"
            >
              <svg
                v-if="form.processing"
                class="animate-spin h-4 w-4 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
              </svg>
              <span v-else>
                {{ submitLabel }}
              </span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>
