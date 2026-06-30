<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import MainGroupColumn from '@/components/chartOfAccounts/MainGroupColumn.vue'

import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, nextTick, watch, computed } from 'vue'
import { route } from 'ziggy-js'
import { useToast } from 'vue-toastification'
import { X as XIcon } from 'lucide-vue-next'
import type { TreeNode } from '@/types/types'

const props = defineProps<{
  tree: TreeNode[]
  financialGroups?: string[]
}>()

const showModal = ref(false)
const isEditing = ref(false)
const editingId = ref<number | null>(null)

const form = useForm<{
  name: string
  parent_id: number | null
  allows_posting: boolean
  financial_group: string | null
}>({
  name: '',
  parent_id: null,
  allows_posting: true,
  financial_group: null,
})

const toast = useToast()
const nameInput = ref<HTMLInputElement | null>(null)

const allNodes = computed(() => {
  const result: TreeNode[] = []

  function traverse(nodes: TreeNode[]) {
    for (const node of nodes) {
      result.push(node)
      if (node.children && node.children.length > 0) {
        traverse(node.children)
      }
    }
  }

  traverse(props.tree)
  return result
})

const isDuplicateName = computed(() => {
  const nome = form.name.trim().toLowerCase()
  if (!nome) return false

  return allNodes.value.some(node =>
    node.parent_id === form.parent_id &&
    node.name.trim().toLowerCase() === nome &&
    node.id !== editingId.value
  )
})

const isSameName = computed(() => {
  if (!isEditing.value || editingId.value === null) return false
  const current = allNodes.value.find(n => n.id === editingId.value)

  return !!(
    current &&
    current.name.trim().toLowerCase() === form.name.trim().toLowerCase()
  )
})

const canSubmit = computed(() => {
  return (
    form.name.trim().length > 0 &&
    !isDuplicateName.value &&
    !isSameName.value
  )
})

function resetForm() {
  form.reset()
  form.clearErrors()
  form.name = ''
  form.parent_id = null
  form.allows_posting = true
  form.financial_group = null
}

function closeModal() {
  showModal.value = false
  resetForm()
}

function openCreate(node: TreeNode | null) {
  isEditing.value = false
  editingId.value = null
  resetForm()

  form.parent_id = node ? node.id : null
  form.allows_posting = true
  form.financial_group = null

  showModal.value = true
  nextTick(() => nameInput.value?.focus())
}

function openEdit(node: TreeNode) {
  isEditing.value = true
  editingId.value = node.id
  resetForm()

  form.name = node.name
  form.parent_id = node.parent_id
  form.allows_posting = !!node.allows_posting
  form.financial_group = node.financial_group ?? null

  showModal.value = true
  nextTick(() => nameInput.value?.focus())
}

watch(
  () => form.allows_posting,
  (value) => {
    if (value) {
      form.financial_group = null
    }
  }
)

function submit() {
  if (isEditing.value) {
    form.put(
      route('chart-of-accounts.update', { chart_of_account: editingId.value }),
      {
        preserveScroll: true,
        onSuccess: () => {
          toast.success('Conta atualizada!')
          closeModal()
          router.reload({ only: ['tree', 'financialGroups'] })
        },
        onError: () => {
          toast.error('Não foi possível atualizar a conta.')
        },
      }
    )
  } else {
    form.post(route('chart-of-accounts.store'), {
      preserveScroll: true,
      onSuccess: () => {
        toast.success('Conta criada!')
        closeModal()
        router.reload({ only: ['tree', 'financialGroups'] })
      },
      onError: () => {
        toast.error('Não foi possível criar a conta.')
      },
    })
  }
}

function deleteNode(node: TreeNode) {
  if (!confirm(`Excluir a conta "${node.code} - ${node.name}"?`)) return

  router.delete(
    route('chart-of-accounts.destroy', { chart_of_account: node.id }),
    {
      preserveScroll: true,
      onSuccess: () => {
        toast.success('Conta removida!')
        router.reload({ only: ['tree', 'financialGroups'] })
      },
      onError: () => {
        toast.error('Não foi possível excluir a conta.')
      },
    }
  )
}

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Plano de Contas',
    href: '/chart-of-accounts',
  },
]

function handleKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    closeModal()
  }
}

watch(showModal, visible => {
  if (visible) {
    document.addEventListener('keydown', handleKeyDown)
  } else {
    document.removeEventListener('keydown', handleKeyDown)
  }
})
</script>

<template>
  <Head title="Plano de Contas" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="p-5">
      <div
        class="mb-4 rounded border-l-4 border-blue-400 bg-blue-50 p-2 text-blue-700"
      >
        🛈 <strong>Observação:</strong>
        Crie suas contas apenas em <em>Ativo Circulante</em> (1.1) ou
        <em>Passivo Circulante</em> (2.1). As contas de longo prazo
        (não-circulante) serão apresentadas automaticamente no relatório de
        balanço.
      </div>

      <h1 class="mb-4 text-2xl font-bold">Plano de Contas</h1>

      <div class="mb-8 grid grid-cols-1 items-stretch gap-4 md:grid-cols-2">
        <MainGroupColumn
          v-for="n in 5"
          :key="n"
          :group-number="n"
          :tree="props.tree"
          @create-child="openCreate"
          @edit="openEdit"
          @delete="deleteNode"
        />
      </div>
    </div>
  </AppLayout>

  <Teleport to="body">
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="closeModal"
    >
      <div
        class="relative w-[26rem] rounded-lg bg-white p-6 shadow-lg dark:bg-gray-900"
      >
        <button
          @click="closeModal"
          class="absolute top-3 right-3 cursor-pointer text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
          aria-label="Fechar"
        >
          <XIcon class="h-4 w-4" />
        </button>

        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">
          {{ isEditing ? 'Editar Conta' : 'Nova Conta' }}
        </h2>

        <form @submit.prevent="submit">
          <div class="mb-4">
            <label
              for="account-name"
              class="mb-1 block text-gray-700 dark:text-gray-300"
            >
              Nome
            </label>
            <input
              id="account-name"
              ref="nameInput"
              v-model="form.name"
              name="name"
              type="text"
              class="w-full rounded border border-gray-300 bg-white p-2 text-gray-900 focus:ring-2 focus:ring-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            />

            <p v-if="isDuplicateName" class="mt-1 text-sm text-red-600">
              Já existe uma conta com este nome neste nível.
            </p>
            <p v-else-if="isSameName" class="mt-1 text-sm text-gray-500">
              Este é o nome atual da conta.
            </p>
            <p v-else-if="form.errors.name" class="mt-1 text-sm text-red-600">
              {{ form.errors.name }}
            </p>
          </div>

          <div class="mb-4">
            <label
              for="allows-posting"
              class="mb-1 block text-gray-700 dark:text-gray-300"
            >
              Tipo da conta
            </label>

            <select
              id="allows-posting"
              v-model="form.allows_posting"
              name="allows_posting"
              class="w-full rounded border border-gray-300 bg-white p-2 text-gray-900 focus:ring-2 focus:ring-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
              <option :value="true">Analítica (permite lançamentos)</option>
              <option :value="false">Sintética (não permite lançamentos)</option>
            </select>

            <p
              v-if="form.errors.allows_posting"
              class="mt-1 text-sm text-red-600"
            >
              {{ form.errors.allows_posting }}
            </p>
          </div>

          <div class="mb-4">
            <label
              for="financial-group"
              class="mb-1 block text-gray-700 dark:text-gray-300"
            >
              Grupo financeiro
            </label>

            <select
              id="financial-group"
              v-model="form.financial_group"
              name="financial_group"
              :disabled="form.allows_posting"
              class="w-full rounded border border-gray-300 bg-white p-2 text-gray-900 focus:ring-2 focus:ring-blue-400 disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-700"
            >
              <option :value="null">Nenhum</option>
              <option
                v-for="group in props.financialGroups ?? []"
                :key="group"
                :value="group"
              >
                {{ group }}
              </option>
            </select>

            <p class="mt-1 text-xs text-gray-500">
              Disponível apenas para contas sintéticas.
            </p>

            <p
              v-if="form.errors.financial_group"
              class="mt-1 text-sm text-red-600"
            >
              {{ form.errors.financial_group }}
            </p>
          </div>

          <div class="mt-6 flex justify-end space-x-2">
            <button
              type="button"
              @click="closeModal"
              class="rounded border border-gray-300 px-4 py-2 text-gray-700 dark:border-gray-600 dark:text-gray-300"
            >
              Cancelar
            </button>

            <button
              type="submit"
              class="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400"
              :disabled="!canSubmit || form.processing"
            >
              {{ isEditing ? 'Atualizar' : 'Criar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>