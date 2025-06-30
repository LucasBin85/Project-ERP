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
  (e: 'edit',       node: TreeNode): void
  (e: 'delete',     node: TreeNode): void
}>()

// 1) Detecta se é “Não-Circulante” (1.2* ou 2.2*)
const isNonCirculante = computed(() =>
  props.node.code.startsWith('1.2') ||
  props.node.code.startsWith('2.2')
)

// 2) Detecta “Banco Conta Movimento” e seus descendentes (1.1.1.02*)
const isBancoNoSub = computed(() =>
  //props.node.code === '1.1.1.02' ||
  props.node.code.startsWith('1.1.1.02.') ||
  props.node.code.startsWith('1') && props.node.name === 'Ativo' ||
  props.node.code.startsWith('2') && props.node.name === 'Passivo'
)

// 3) Pode criar sub-conta aqui?
//    → NÃO em Não-Circulante ou Banco Conta Movimento
const canCreate = computed(() =>
  !isNonCirculante.value && !isBancoNoSub.value
)

// 4) Pode editar/excluir?
//    → Só se NÃO for protegido
const canEditOrDelete = computed(() =>
  !props.node.is_protected
)
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

      <!-- AÇÕES (aparecem só no hover) -->
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
        <!-- <span
          v-else
          class="text-gray-300 cursor-not-allowed"
          :title=" isNonCirculante
                    ? 'Criação não permitida em Não-Circulante'
                    : 'Criação não permitida em Banco Conta Movimento' "
        >
          <Plus class="w-4 h-4" />
        </span> -->

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

    <!-- FILHOS RECURSIVAMENTE -->
    <ul v-if="node.children?.length" class="pl-4">
      <AccountTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        @create-child="$emit('create-child', $event)"
        @edit        ="$emit('edit',        $event)"
        @delete      ="$emit('delete',      $event)"
      />
    </ul>
  </li>
</template>
