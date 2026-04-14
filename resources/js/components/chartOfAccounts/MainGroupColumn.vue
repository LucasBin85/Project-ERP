<script setup lang="ts">
import { computed } from 'vue'
import type { PropType } from 'vue'
import type { TreeNode } from '@/types/types'
import AccountTreeNode from '@/components/chartOfAccounts/AccountTreeNode.vue'

const props = defineProps({
  groupNumber: {
    type: Number,
    required: true,
  },
  tree: {
    type: Array as PropType<TreeNode[]>,
    required: true,
  },
})

const emit = defineEmits<{
  (e: 'create-child', node: TreeNode): void
  (e: 'edit', node: TreeNode): void
  (e: 'delete', node: TreeNode): void
}>()

const filtered = computed(() =>
  props.tree.filter(node =>
    String(node.code).startsWith(String(props.groupNumber))
  )
)
</script>

<template>
  <div v-if="filtered.length" class="space-y-4">
    <div
      v-for="group in filtered"
      :key="group.id"
      class="h-full rounded-lg overflow-hidden shadow-sm
             bg-slate-900 text-slate-50
             dark:bg-slate-900 dark:text-slate-50
             hover:shadow-lg transition"
    >
      <!-- HEADER DO CARD -->
      <div
        class="px-4 py-2 bg-slate-800/80
               dark:bg-slate-800
               border-b border-slate-700
               font-semibold text-lg tracking-wide"
      >
        {{ group.code }} — {{ group.name }}
      </div>

      <!-- BODY DO CARD -->
      <div class="p-4">
        <ul v-if="group.children?.length" class="space-y-1">
          <AccountTreeNode
            v-for="child in group.children"
            :key="child.id"
            :node="child"
            @create-child="emit('create-child', $event)"
            @edit="emit('edit', $event)"
            @delete="emit('delete', $event)"
          />
        </ul>
      </div>
    </div>
  </div>
</template>
