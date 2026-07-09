<script setup lang="ts">
import { formatStatus } from '@/lib/formatters'

const props = defineProps<{
    status: string
}>()

const classes: Record<string, string> = {
    posted: 'bg-green-950 text-green-300',
    completed: 'bg-green-950 text-green-300',
    reconciled: 'bg-green-950 text-green-300',
    paid: 'bg-green-950 text-green-300',
    received: 'bg-green-950 text-green-300',
    draft: 'bg-yellow-950 text-yellow-300',
    pending: 'bg-blue-950 text-blue-300',
    cancelled: 'bg-red-950 text-red-300',
    reversed: 'bg-purple-950 text-purple-300',
}
</script>

<template>
    <span
        class="rounded px-2 py-1 text-xs font-semibold"
        :class="classes[props.status] ?? 'bg-gray-800 text-gray-300'"
    >
        {{ formatStatus(props.status) }}
    </span>
</template>
