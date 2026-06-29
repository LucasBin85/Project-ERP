<script setup lang="ts">
defineProps<{
    empty?: boolean
    emptyMessage?: string
    emptyColspan?: number
}>()
</script>

<template>
    <div class="w-full overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700">
            <thead class="bg-[#1f2937]">
                <slot name="head" />
            </thead>

            <tbody class="divide-y divide-gray-800">
                <template v-if="!empty">
                    <slot />
                </template>

                <tr v-else>
                    <td
                        :colspan="emptyColspan ?? 1"
                        class="px-4 py-8 text-center text-sm text-gray-400"
                    >
                        <slot name="empty">
                            {{ emptyMessage ?? 'Nenhum registro encontrado.' }}
                        </slot>
                    </td>
                </tr>
            </tbody>

            <tfoot
                v-if="$slots.foot"
                class="bg-[#1f2937]"
            >
                <slot name="foot" />
            </tfoot>
        </table>
    </div>
</template>