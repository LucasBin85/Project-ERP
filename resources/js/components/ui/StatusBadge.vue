<script setup lang="ts">
import { formatStatus } from '@/lib/formatters'

const props = defineProps<{
    status: string
}>()

const classes: Record<string, string> = {
    active: 'bg-green-950 text-green-300',
    posted: 'bg-green-950 text-green-300',
    completed: 'bg-green-950 text-green-300',
    imported: 'bg-green-950 text-green-300',
    reconciled: 'bg-green-950 text-green-300',
    reconciled_via_ofx: 'bg-green-950 text-green-300',
    classified: 'bg-green-950 text-green-300',
    paid: 'bg-green-950 text-green-300',
    received: 'bg-green-950 text-green-300',
    open: 'bg-blue-950 text-blue-300',
    closed: 'bg-yellow-950 text-yellow-300',
    partial: 'bg-purple-950 text-purple-300',
    overdue: 'bg-red-950 text-red-300',
    draft: 'bg-yellow-950 text-yellow-300',
    pending: 'bg-blue-950 text-blue-300',
    unclassified: 'bg-yellow-950 text-yellow-300',
    pending_classification: 'bg-yellow-950 text-yellow-300',
    awaiting_counterpart_ofx: 'bg-amber-950 text-amber-300',
    pending_link: 'bg-orange-950 text-orange-300',
    ready_for_accounting: 'bg-green-950 text-green-300',
    skipped_duplicate: 'bg-gray-800 text-gray-300',
    failed: 'bg-red-950 text-red-300',
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
