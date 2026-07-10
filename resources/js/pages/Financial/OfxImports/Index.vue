<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useOfxImport } from '@/composables/financial/useOfxImport';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    imports: Array<Record<string, any>>;
}>();

const ofxImport = useOfxImport();
</script>

<template>
    <AppLayout title="Importação OFX">
        <ReportPage title="Importação OFX" :subtitle="props.wallet?.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Importar extrato bancário</h2>
                        <p class="mt-1 text-sm text-gray-400">
                            O arquivo OFX gera lançamentos em rascunho usando a conta bancária e a conta A classificar.
                        </p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-[1fr_1fr_auto]" @submit.prevent="ofxImport.submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
                        <select v-model="ofxImport.form.bank_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma conta</option>
                            <option v-for="account in bankAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ ofxImport.form.errors.bank_account_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Arquivo OFX</label>
                        <input type="file" accept=".ofx,.OFX" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white file:mr-4 file:rounded file:border-0 file:bg-gray-800 file:px-3 file:py-1 file:text-gray-200" @change="ofxImport.selectFile" />
                        <p class="mt-1 text-sm text-red-400">{{ ofxImport.form.errors.ofx_file }}</p>
                    </div>

                    <div class="flex items-end">
                        <button type="submit" :disabled="!ofxImport.canSubmit.value || ofxImport.form.processing" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Importar OFX
                        </button>
                    </div>
                </form>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Histórico de importações</h2>
                        <p class="text-sm text-gray-400">Últimos arquivos OFX importados para a carteira ativa.</p>
                    </div>
                </template>

                <div v-if="imports.length === 0" class="p-6 text-sm text-gray-400">
                    Nenhuma importação OFX encontrada.
                </div>

                <div v-else class="space-y-6 p-6">
                    <div v-for="importItem in imports" :key="importItem.id" class="overflow-hidden rounded-xl border border-gray-700 bg-gray-900/40">
                        <div class="grid grid-cols-1 gap-4 border-b border-gray-700 p-4 xl:grid-cols-[1.5fr_1fr_auto]">
                            <div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="text-base font-bold text-white">{{ importItem.original_filename }}</h3>
                                    <StatusBadge :status="importItem.status" />
                                </div>
                                <p class="mt-1 text-sm text-gray-400">
                                    {{ importItem.bank_account?.name ?? '-' }} · importado em {{ formatDateTime(importItem.created_at) }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Período do arquivo: {{ importItem.statement_started_at ? formatDate(importItem.statement_started_at) : '-' }} até {{ importItem.statement_ended_at ? formatDate(importItem.statement_ended_at) : '-' }}
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-2">
                                <ReportSummaryCard label="Transações" :value="String(importItem.total_transactions)" tone="neutral" />
                                <ReportSummaryCard label="Importadas" :value="String(importItem.imported_transactions)" tone="green" />
                                <ReportSummaryCard label="Duplicadas" :value="String(importItem.skipped_duplicates)" tone="yellow" />
                                <ReportSummaryCard label="Entradas" :value="formatCurrency(importItem.total_in_cents)" tone="green" />
                            </div>

                            <div class="flex items-center justify-end">
                                <ReportSummaryCard label="Saídas" :value="formatCurrency(importItem.total_out_cents)" tone="red" />
                            </div>
                        </div>

                        <ReportTable :empty="importItem.transactions.length === 0" empty-message="Nenhuma transação registrada para esta importação." :empty-colspan="7">
                            <template #head>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">FITID</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Lançamento</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ação</th>
                                </tr>
                            </template>

                            <tr v-for="transaction in importItem.transactions" :key="transaction.id" class="hover:bg-gray-800/50">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(transaction.posted_at) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-200">{{ transaction.description }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ transaction.fit_id }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold" :class="transaction.direction === 'in' ? 'text-green-300' : 'text-red-300'">
                                    {{ transaction.direction === 'in' ? '+' : '-' }} {{ formatCurrency(transaction.amount_cents) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm"><StatusBadge :status="transaction.status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    <span v-if="transaction.journal_entry">JE-{{ String(transaction.journal_entry.id).padStart(6, '0') }}</span>
                                    <span v-else>-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                    <Link v-if="transaction.journal_entry" :href="route('journal-entries.show', [transaction.journal_entry.id])" class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700">
                                        Ver
                                    </Link>
                                </td>
                            </tr>
                        </ReportTable>
                    </div>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
