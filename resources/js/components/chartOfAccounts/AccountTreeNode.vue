<script setup lang="ts">
import { computed } from 'vue'
import { Plus, Pencil, Trash } from 'lucide-vue-next'
import type { TreeNode } from '@/types/types'

const props = defineProps<{
  node: TreeNode
}>()

const emit = defineEmits<{
  (e: 'create-child', node: TreeNode): void
  (e: 'create-bank-account'): void
  (e: 'edit', node: TreeNode): void
  (e: 'delete', node: TreeNode): void
}>()

const isRootStructure = computed(() =>
  (props.node.code === '1' && props.node.name === 'Ativo') ||
  (props.node.code === '2' && props.node.name === 'Passivo') ||
  (props.node.code === '3' && props.node.name === 'Patrimônio Líquido') ||
  (props.node.code === '4' && props.node.name === 'Receitas') ||
  (props.node.code === '5' && props.node.name === 'Despesas')
)

const isBanksGroup = computed(() => props.node.code === '1.1.2')

const isBankAccountChild = computed(() =>
  props.node.code?.startsWith('1.1.2.')
)

const canCreate = computed(() => {
  if (isBanksGroup.value) return true
  if (isBankAccountChild.value) return false

  return !isRootStructure.value
})

const canEdit = computed(() => !props.node.is_system)
const canDelete = computed(() => !props.node.is_system)

function handleCreate() {
  if (isBanksGroup.value) {
    emit('create-bank-account')
    return
  }

  emit('create-child', props.node)
}
</script>

<template>
  <li>
    <div
      class="group flex items-center justify-between rounded px-2 py-1 hover:bg-gray-50 dark:hover:bg-gray-800"
    >
      <div class="flex items-center space-x-2">
        <span class="font-mono text-sm">{{ node.code }}</span>
        <span>{{ node.name }}</span>
      </div>

      <div class="flex items-center space-x-1">
        <button
          v-if="canCreate"
          @click.stop="handleCreate"
          class="text-green-500 hover:text-green-700"
          :title="isBanksGroup ? 'Nova conta bancária' : 'Criar subconta'"
        >
          <Plus class="h-4 w-4" />
        </button>

        <button
          v-if="canEdit"
          @click.stop="emit('edit', node)"
          class="text-blue-500 hover:text-blue-700"
          title="Editar conta"
        >
          <Pencil class="h-4 w-4" />
        </button>

        <button
          v-if="canDelete"
          @click.stop="emit('delete', node)"
          class="text-red-500 hover:text-red-700"
          title="Excluir conta"
        >
          <Trash class="h-4 w-4" />
        </button>
      </div>
    </div>

    <ul v-if="node.children?.length" class="pl-4">
      <AccountTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        @create-child="$emit('create-child', $event)"
        @create-bank-account="$emit('create-bank-account')"
        @edit="$emit('edit', $event)"
        @delete="$emit('delete', $event)"
      />
    </ul>
  </li>
</template>