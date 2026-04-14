<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

defineProps({
    wallet: {
        type: Object,
        required: true,
    },
    entries: {
        type: Object,
        required: true,
    },
})

const formatCurrency = (cents) => {
    const value = (Number(cents || 0) / 100)
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(value)
}

const formatDate = (date) => {
    if (!date) return '-'

    return new Intl.DateTimeFormat('pt-BR').format(new Date(date))
}

const entryTotal = (entry) => {
    const debitLine = entry.lines?.find(line => line.type === 'debit')
    return debitLine ? debitLine.amount_cents : 0
}

const formatPaginationLabel = (label) => {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g, '&')
}
</script>

<template>
    <AppLayout title="Lançamentos">
        <Head title="Lançamentos" />

        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        Lançamentos
                    </h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Carteira: {{ wallet.name }}
                    </p>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Data
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Descrição
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Origem
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Status
                                </th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Valor
                                </th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Ações
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr
                                v-for="entry in entries.data"
                                :key="entry.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700/40"
                            >
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                    {{ formatDate(entry.entry_date) }}
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                    <div class="font-medium">
                                        {{ entry.description || 'Sem descrição' }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        #{{ entry.id }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                    {{ entry.source }}
                                </td>

                                <td class="px-4 py-3 text-sm">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="entry.status === 'posted'
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                                            : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'"
                                    >
                                        {{ entry.status }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-right text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ formatCurrency(entryTotal(entry)) }}
                                </td>

                                <td class="px-4 py-3 text-right text-sm">
                                    <Link
                                        :href="route('journal-entries.show', [entry.id])"
                                        class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                    >
                                        Ver
                                    </Link>
                                </td>
                            </tr>

                            <tr v-if="entries.data.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Nenhum lançamento encontrado.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="entries.links?.length > 3"
                    class="flex flex-wrap items-center justify-center gap-2 border-t border-gray-200 px-4 py-4 dark:border-gray-700"
                >
                    <template v-for="link in entries.links" :key="link.label">

                        <!-- Link desativado -->
                        <span
                            v-if="!link.url"
                            class="rounded-md px-3 py-1.5 text-sm text-gray-400"
                        >
                            {{ formatPaginationLabel(link.label) }}
                        </span>

                        <!-- Link ativo -->
                        <Link
                            v-else
                            :href="link.url"
                            class="rounded-md px-3 py-1.5 text-sm transition"
                            :class="link.active
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'"
                        >
                            {{ formatPaginationLabel(link.label) }}
                        </Link>

                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>