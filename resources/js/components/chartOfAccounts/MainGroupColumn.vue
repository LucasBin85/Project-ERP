<script setup lang="ts">
import { computed } from 'vue'
import AccountTreeNode from '@/components/chartOfAccounts/AccountTreeNode.vue'
import type { TreeNode } from '@/types/types'

const props = defineProps<{
  tree: TreeNode[]
  groupNumber: number
}>()

const emit = defineEmits<{
  (e: 'create-child', node: TreeNode): void
  (e: 'edit', node: TreeNode): void
  (e: 'delete', node: TreeNode): void
}>()

const rootNode = computed(() =>
  props.tree.find(node => node.code === String(props.groupNumber))
)
</script>

<template>
  <div
    v-if="rootNode"
    class="rounded-lg border border-gray-700 bg-[#0b1a33]"
  >
    <div class="border-b border-gray-600 bg-[#1f2e45] px-4 py-2 font-semibold">
      {{ rootNode.code }} — {{ rootNode.name }}
    </div>

    <ul class="p-3">
      <AccountTreeNode
        v-for="child in rootNode.children"
        :key="child.id"
        :node="child"
        @create-child="$emit('create-child', $event)"
        @edit="$emit('edit', $event)"
        @delete="$emit('delete', $event)"
      />
    </ul>
  </div>
</template>