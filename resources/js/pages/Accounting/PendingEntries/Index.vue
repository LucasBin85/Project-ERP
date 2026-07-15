<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';

interface BankAccountSummary {
    id: number;
    name: string;
}

interface PendingEntry {
    id: number;
    entry_date: string;
    description: string;
    source: string;
    source_label: string;
    bank_accounts: BankAccountSummary[];
    amount_cents: number;
    status: 'ready_for_accounting';
    status_label: string;
    journal_entry_url: string;
}

interface PostingResult {
    posted: number;
    skipped: number;
    errors: number;
    message: string;
}

const props = defineProps<{
    wallet: { id: number; name: string };
    entries: PendingEntry[];
    summary: { ready_count: number; ready_amount_cents: number };
    postingResult?: PostingResult | null;
}>();

const page = usePage();
const selectedIds = ref<number[]>([]);
const processing = ref(false);
const requestError = ref<string | null>(null);

const allSelected = computed(() => props.entries.length > 0 && selectedIds.value.length === props.entries.length);
const flashSuccess = computed(() => (page.props.flash as { success?: string | null } | undefined)?.success ?? null);

watch(
    () => props.entries,
    (entries) => {
        const availableIds = new Set(entries.map((entry) => entry.id));
        selectedIds.value = selectedIds.value.filter((id) => availableIds.has(id));
    },
);

function formatMoney(cents: number): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(cents / 100);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`));
}

function bankAccountLabel(entry: PendingEntry): string {
    if (entry.bank_accounts.length === 0) {
        return '—';
    }

    return entry.bank_accounts.map((account) => account.name).join(', ');
}

function toggleAll(): void {
    selectedIds.value = allSelected.value ? [] : props.entries.map((entry) => entry.id);
}

function submit(url: string, data: Record<string, unknown> = {}): void {
    requestError.value = null;
    processing.value = true;

    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
        },
        onError: (errors) => {
            requestError.value = Object.values(errors)[0] ?? 'Não foi possível concluir a postagem em lote.';
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function postSelected(): void {
    if (selectedIds.value.length === 0) {
        return;
    }

    submit(route('accounting.pending-entries.post-selected'), {
        entry_ids: selectedIds.value,
    });
}

function postAll(): void {
    if (props.entries.length === 0) {
        return;
    }

    submit(route('accounting.pending-entries.post-all'));
}
</script>

<template>
    <Head title="Pendências contábeis" />

    <AppLayout>
        <ReportPage
            title="Pendências contábeis"
            :subtitle="`Lançamentos da carteira ${wallet.name} que já estão classificados e prontos para postagem.`"
        >
            <div
                v-if="flashSuccess"
                class="rounded-xl border border-emerald-500/30 bg-emerald-950/30 px-4 py-3 text-sm font-semibold text-emerald-300"
            >
                {{ flashSuccess }}
            </div>

            <div v-if="requestError" class="rounded-xl border border-red-500/30 bg-red-950/30 px-4 py-3 text-sm font-semibold text-red-300">
                {{ requestError }}
            </div>

            <div v-if="postingResult" class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-emerald-500/30 bg-emerald-950/20 p-4">
                    <p class="text-xs font-semibold text-emerald-400 uppercase">Postados</p>
                    <p class="mt-1 text-2xl font-bold text-white">
                        {{ postingResult.posted }}
                    </p>
                </div>
                <div class="rounded-xl border border-amber-500/30 bg-amber-950/20 p-4">
                    <p class="text-xs font-semibold text-amber-400 uppercase">Ignorados</p>
                    <p class="mt-1 text-2xl font-bold text-white">
                        {{ postingResult.skipped }}
                    </p>
                </div>
                <div class="rounded-xl border border-red-500/30 bg-red-950/20 p-4">
                    <p class="text-xs font-semibold text-red-400 uppercase">Falhas</p>
                    <p class="mt-1 text-2xl font-bold text-white">
                        {{ postingResult.errors }}
                    </p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-gray-700 bg-[#111827] p-5">
                    <p class="text-sm text-gray-400">Prontos para postagem</p>
                    <p class="mt-1 text-3xl font-bold text-white">
                        {{ summary.ready_count }}
                    </p>
                </div>
                <div class="rounded-xl border border-gray-700 bg-[#111827] p-5">
                    <p class="text-sm text-gray-400">Valor total</p>
                    <p class="mt-1 text-3xl font-bold text-white">
                        {{ formatMoney(summary.ready_amount_cents) }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3">
                <button
                    type="button"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-200 transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="processing || selectedIds.length === 0"
                    @click="postSelected"
                >
                    Postar selecionados
                    <span v-if="selectedIds.length > 0"> ({{ selectedIds.length }}) </span>
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="processing || entries.length === 0"
                    @click="postAll"
                >
                    {{ processing ? 'Postando...' : 'Postar todos prontos' }}
                </button>
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-white">Lançamentos prontos</h2>
                            <p class="mt-1 text-sm text-gray-400">Cada lançamento é revalidado e postado de forma independente.</p>
                        </div>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1050px] text-left text-sm">
                        <thead class="bg-gray-950/40 text-xs text-gray-400 uppercase">
                            <tr>
                                <th class="w-12 px-4 py-3">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-900"
                                        :checked="allSelected"
                                        :aria-label="allSelected ? 'Desmarcar todos' : 'Selecionar todos'"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th class="px-4 py-3">Data</th>
                                <th class="px-4 py-3">Descrição</th>
                                <th class="px-4 py-3">Origem</th>
                                <th class="px-4 py-3">Conta bancária</th>
                                <th class="px-4 py-3 text-right">Valor</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="entry in entries" :key="entry.id" class="text-gray-200 hover:bg-gray-800/40">
                                <td class="px-4 py-4">
                                    <input
                                        v-model="selectedIds"
                                        type="checkbox"
                                        class="rounded border-gray-600 bg-gray-900"
                                        :value="entry.id"
                                        :aria-label="`Selecionar lançamento ${entry.id}`"
                                    />
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    {{ formatDate(entry.entry_date) }}
                                </td>
                                <td class="max-w-sm px-4 py-4">
                                    <p class="font-medium text-white">
                                        {{ entry.description }}
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500">Lançamento #{{ entry.id }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    {{ entry.source_label }}
                                </td>
                                <td class="px-4 py-4 text-gray-300">
                                    {{ bankAccountLabel(entry) }}
                                </td>
                                <td class="px-4 py-4 text-right font-semibold whitespace-nowrap text-white">
                                    {{ formatMoney(entry.amount_cents) }}
                                </td>
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex rounded-full border border-blue-500/30 bg-blue-950/30 px-2.5 py-1 text-xs font-semibold text-blue-300"
                                    >
                                        {{ entry.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <Link :href="entry.journal_entry_url" class="font-semibold text-blue-400 hover:text-blue-300">
                                        Ver lançamento
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="entries.length === 0">
                                <td colspan="8" class="px-6 py-12 text-center">
                                    <p class="font-semibold text-gray-200">Nenhuma pendência pronta para postagem.</p>
                                    <p class="mt-1 text-sm text-gray-500">Lançamentos ainda sem classificação não aparecem nesta fila.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
