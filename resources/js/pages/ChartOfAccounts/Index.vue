<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import AccountTreeNode from '@/components/chartOfAccounts/AccountTreeNode.vue'
import MainGroupColumn from '@/components/chartOfAccounts/MainGroupColumn.vue'

import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, nextTick, watch, computed } from 'vue'
import { route } from 'ziggy-js'
import { useToast } from 'vue-toastification'
import { X as XIcon } from 'lucide-vue-next'
import type { TreeNode } from '@/types/types'

// Props vindos do Inertia
const props = defineProps<{
  tree: TreeNode[]
}>()

// Estado do modal
const showModal = ref(false)
const isEditing = ref(false)
const editingId = ref<number | null>(null)

const form = useForm<{
  name: string
  parent_id: number | null
}>({
  name: '',
  parent_id: null,
})

const toast = useToast()
const nameInput = ref<HTMLInputElement | null>(null)

/* ------------------------------------------------------------------
  HELPER: árvore achatada (pra validação de duplicidade)
-------------------------------------------------------------------*/
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

/* ------------------------------------------------------------------
  Validações: duplicidade e mesmo nome em edição
-------------------------------------------------------------------*/
const isDuplicateName = computed(() => {
  const nome = form.name.trim().toLowerCase()
  if (!nome) return false

  return allNodes.value.some(node =>
    node.parent_id === form.parent_id && // mesmo pai
    node.name.trim().toLowerCase() === nome && // mesmo nome
    node.id !== editingId.value // e não for o registro que estamos editando
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

/* ------------------------------------------------------------------
  Modal handlers
-------------------------------------------------------------------*/
function closeModal() {
  showModal.value = false
  form.reset()
}

function openCreate(node: TreeNode | null) {
  isEditing.value = false
  editingId.value = null
  form.reset()
  form.name = ''
  form.parent_id = node ? node.id : null
  showModal.value = true

  nextTick(() => nameInput.value?.focus())
}

function openEdit(node: TreeNode) {
  isEditing.value = true
  editingId.value = node.id
  form.reset()
  form.name = node.name
  form.parent_id = node.parent_id
  showModal.value = true

  nextTick(() => nameInput.value?.focus())
}

/* ------------------------------------------------------------------
  Submit (create / update)
-------------------------------------------------------------------*/
function submit() {
  if (isEditing.value) {
    form.put(
      route('chart-of-accounts.update', { chart_of_account: editingId.value }),
      {
        preserveState: true,
        onSuccess: () => {
          toast.success('Conta atualizada!')
          showModal.value = false
          router.reload({ only: ['tree'] })
        },
      }
    )
  } else {
    form.post(route('chart-of-accounts.store'), {
      preserveState: true,
      onSuccess: () => {
        toast.success('Conta criada!')
        showModal.value = false
        router.reload({ only: ['tree'] })
      },
    })
  }
}

/* ------------------------------------------------------------------
  Delete
-------------------------------------------------------------------*/
function deleteNode(node: TreeNode) {
  if (!confirm('Excluir esta conta?')) return

  router.delete(
    route('chart-of-accounts.destroy', { chart_of_account: node.id }),
    {
      preserveState: true,
      onSuccess: () => {
        toast.success('Conta removida!')
        router.reload({ only: ['tree'] })
      },
    }
  )
}

/* ------------------------------------------------------------------
  Breadcrumbs
-------------------------------------------------------------------*/
const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Plano de Contas',
    href: '/chart-of-accounts',
  },
]

/* ------------------------------------------------------------------
  Fechar modal com ESC
-------------------------------------------------------------------*/
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
        class="mb-4 p-2 bg-blue-50 border-l-4 border-blue-400 text-blue-700 rounded"
      >
        🛈 <strong>Observação:</strong>
        Crie suas contas apenas em <em>Ativo Circulante</em> (1.1) ou
        <em>Passivo Circulante</em> (2.1). As contas de longo prazo
        (não-circulante) serão apresentadas automaticamente no relatório de
        balanço.
      </div>

      <h1 class="text-2xl font-bold mb-4">Plano de Contas</h1>

      <!-- Grid 3x2 com grupos 1,2,3,4,5 -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8 items-stretch">
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

      <!-- Grupo 5 (Despesas) embaixo ocupando a largura toda 
      <div class="mb-8 grid grid-cols-1 items-stretch">
        <MainGroupColumn
          :group-number="5"
          :tree="props.tree"
          @create-child="openCreate"
          @edit="openEdit"
          @delete="deleteNode"
        />
      </div>
      -->
<!-- Removemos a árvore completa abaixo -->

    </div>
  </AppLayout>

  <!-- Modal de Create/Edit -->
  <Teleport to="body">
    <div
      v-if="showModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
      @click.self="closeModal"
    >
      <div
        class="bg-white dark:bg-gray-900 rounded-lg p-6 w-80 shadow-lg relative"
      >
        <!-- botão fechar -->
        <button
          @click="closeModal"
          class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 cursor-pointer"
          aria-label="Fechar"
        >
          <XIcon class="w-4 h-4" />
        </button>

        <!-- título dinâmico -->
        <h2 class="text-lg font-medium mb-4 text-gray-900 dark:text-gray-100">
          {{ isEditing ? 'Editar Conta' : 'Nova Conta' }}
        </h2>

        <!-- formulário -->
        <form @submit.prevent="submit">
          <div class="mb-4">
            <label class="block mb-1 text-gray-700 dark:text-gray-300">
              Nome
            </label>
            <input
              ref="nameInput"
              v-model="form.name"
              type="text"
              class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded p-2 focus:ring-2 focus:ring-blue-400"
            />
            <!-- mensagens de validação -->
            <p v-if="isDuplicateName" class="text-red-600 text-sm mt-1">
              Já existe uma conta com este nome neste nível.
            </p>
            <p v-else-if="isSameName" class="text-gray-500 text-sm mt-1">
              Este é o nome atual da conta.
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
              class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
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
