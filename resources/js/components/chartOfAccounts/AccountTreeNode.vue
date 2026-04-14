<!-- components/chartOfAccounts/AccountTreeNode.vue -->
<script setup lang="ts">
import { computed } from 'vue'
import { Plus, Pencil, Trash } from 'lucide-vue-next'
import type { TreeNode } from '@/types/types'

const props = defineProps<{
  node: TreeNode
}>()

const emit = defineEmits<{
  (e: 'create-child', node: TreeNode): void
  (e: 'edit', node: TreeNode): void
  (e: 'delete', node: TreeNode): void
}>()

/* --------------------------------------------------------------
  REGRAS DE NEGÓCIO
----------------------------------------------------------------*/

// 1) Não-circulante → não pode ter filhos criados pelo usuário
//    1.2.*  ou  2.2.*
const isNonCirculante = computed(() =>
  props.node.code.startsWith('1.2') ||
  props.node.code.startsWith('2.2')
)

// 2) “Banho frio” no usuário criar coisa diretamente em Ativo (1) ou Passivo (2)
//    Esses grupos são apenas estruturais
const isRootStructure = computed(() =>
  props.node.code.startsWith('1.1.1.2.') ||
  (props.node.code === '1' && props.node.name === 'Ativo') ||
  (props.node.code === '2' && props.node.name === 'Passivo')
)

// 3) Conta proibida para criação de filhos
const isBlockedForCreation = computed(() =>
  isNonCirculante.value || isRootStructure.value
)

// 4) Pode criar subconta?
const canCreate = computed(() => !isBlockedForCreation.value)

// 5) Pode editar/excluir? Somente se NÃO for protegido
const canEditOrDelete = computed(() => !props.node.is_protected)
</script>

<template>
  <li>
    <div
      class="group flex items-center justify-between px-2 py-1 rounded
             hover:bg-gray-50 dark:hover:bg-gray-800"
    >
      <!-- CÓDIGO + NOME -->
      <div class="flex items-center space-x-2">
        <span class="font-mono text-sm">{{ node.code }}</span>
        <span>{{ node.name }}</span>
      </div>

      <!-- AÇÕES -->
      <div
        class="flex items-center space-x-1 opacity-0
               group-hover:opacity-100 transition-opacity duration-200"
      >
        <!-- [+] criar sub-conta -->
        <button
          v-if="canCreate"
          @click.stop="emit('create-child', node)"
          class="text-green-500 hover:text-green-700"
          title="Criar sub-conta"
        >
          <Plus class="w-4 h-4" />
        </button>

        <!-- [✏️] editar -->
        <button
          v-if="canEditOrDelete"
          @click.stop="emit('edit', node)"
          class="text-blue-500 hover:text-blue-700"
          title="Editar conta"
        >
          <Pencil class="w-4 h-4" />
        </button>

        <!-- [🗑️] excluir -->
        <button
          v-if="canEditOrDelete"
          @click.stop="emit('delete', node)"
          class="text-red-500 hover:text-red-700"
          title="Excluir conta"
        >
          <Trash class="w-4 h-4" />
        </button>
      </div>
    </div>

    <!-- FILHOS (recursivo) -->
    <ul v-if="node.children?.length" class="pl-4">
      <AccountTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        @create-child="$emit('create-child', $event)"
        @edit="$emit('edit', $event)"
        @delete="$emit('delete', $event)"
      />
    </ul>
  </li>
</template>
