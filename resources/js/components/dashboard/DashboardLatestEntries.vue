<script setup>
import { Link } from '@inertiajs/vue3'
import { formatDate } from '@/lib/formatters'

defineProps({
    entries: Array,
})

const emit = defineEmits(['go-to-entry'])

function sourceLabel(source) {
    const map = {
        manual: 'Manual',
        ofx: 'OFX',
        open_finance: 'Open Finance',
    }

    return map[source] || source || '—'
}

function statusLabel(status) {
    return status === 'posted' ? 'Postado' : 'Rascunho'
}
</script>

<template>
    <section class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-white">
                    Últimos lançamentos
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Movimentações mais recentes da carteira ativa.
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="border-b border-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                        <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Lançamento</th>
                        <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                        <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                        <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="cursor-pointer border-b border-gray-800 hover:bg-gray-800/50"
                        @click="emit('go-to-entry', entry.entry_show_url)"
                    >
                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ formatDate(entry.date) }}
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <Link
                                :href="entry.entry_show_url"
                                class="font-semibold text-blue-300 hover:text-blue-200"
                                @click.stop
                            >
                                {{ entry.entry_label }}
                            </Link>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ entry.description || '—' }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ sourceLabel(entry.source) }}
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <span
                                class="rounded px-2 py-1 text-xs font-bold"
                                :class="entry.status === 'posted'
                                    ? 'bg-green-950/60 text-green-300'
                                    : 'bg-yellow-950/60 text-yellow-300'"
                            >
                                {{ statusLabel(entry.status) }}
                            </span>
                        </td>
                    </tr>

                    <tr v-if="!entries.length">
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                            Nenhum lançamento encontrado no período.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
