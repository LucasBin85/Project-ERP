<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBankReconciliationCreate } from '@/composables/financial/useBankReconciliationCreate';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    filters: Record<string, string>;
    preview: Record<string, any>;
}>();

const reconciliation = useBankReconciliationCreate(
    props.filters,
    props.preview.lines ?? [],
    Number(props.preview.opening_balance_cents ?? 0),
);

function lineLabel(line: Record<string, any>): string {
    return `${formatDate(line.date)} · ${formatCurrency(line.signed_amount_cents)} · ${line.description || 'Sem descrição'}`;
}

function submit() {
    if (!reconciliation.canSubmit.value) {
        return;
    }

    reconciliation.form.post(route('bank-reconciliations.store'));
}
</script>

<template>
    <AppLayout title="Nova Conciliação">
        <ReportPage title="Nova Conciliação Bancária" :subtitle="wallet.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Conta e período
                        </h2>

                        <p class="mt-1 text-sm text-gray-400">
                            Selecione a conta e o período. Os lançamentos do sistema são carregados automaticamente.
                        </p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
                        <select
                            v-model="reconciliation.form.bank_account_id"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        >
                            <option value="">Selecione uma conta</option>
                            <option
                                v-for="account in bankAccounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ reconciliation.form.errors.bank_account_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data inicial</label>
                        <input
                            v-model="reconciliation.form.period_start"
                            type="date"
                            :max="reconciliation.form.period_end"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ reconciliation.form.errors.period_start }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data final</label>
                        <input
                            v-model="reconciliation.form.period_end"
                            type="date"
                            :min="reconciliation.form.period_start"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ reconciliation.form.errors.period_end }}</p>
                    </div>
                </div>
            </ReportSection>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                <ReportSummaryCard
                    label="Saldo inicial"
                    :value="formatCurrency(preview.opening_balance_cents)"
                    tone="blue"
                />

                <ReportSummaryCard
                    label="Saldo extrato"
                    :value="formatCurrency(reconciliation.statementBalanceCents.value)"
                    tone="neutral"
                />

                <ReportSummaryCard
                    label="Saldo conciliado"
                    :value="formatCurrency(reconciliation.reconciledBalanceCents.value)"
                    tone="green"
                />

                <ReportSummaryCard
                    label="Diferença"
                    :value="formatCurrency(reconciliation.differenceCents.value)"
                    :tone="reconciliation.differenceCents.value === 0 ? 'green' : 'yellow'"
                />

                <ReportSummaryCard
                    label="Pendentes"
                    :value="String(reconciliation.pendingItemsCount.value)"
                    :tone="reconciliation.pendingItemsCount.value === 0 ? 'green' : 'yellow'"
                />
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">
                                Extrato do banco × lançamentos do sistema
                            </h2>

                            <p class="text-sm text-gray-400">
                                Cadastre as linhas do extrato bancário e vincule cada uma a um lançamento postado no ERP.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                                @click="reconciliation.applySuggestedStatementItems"
                            >
                                Sugerir pelo sistema
                            </button>

                            <button
                                type="button"
                                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500"
                                @click="reconciliation.addStatementItem"
                            >
                                Adicionar item do extrato
                            </button>
                        </div>
                    </div>
                </template>

                <ReportTable
                    :empty="reconciliation.form.statement_items.length === 0"
                    empty-message="Adicione itens do extrato bancário para iniciar a conciliação."
                    :empty-colspan="7"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data extrato</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição extrato</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Tipo</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor extrato</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Lançamento do sistema</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
                        </tr>
                    </template>

                    <tr
                        v-for="(item, index) in reconciliation.form.statement_items"
                        :key="index"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <StatusBadge :status="item.journal_line_id ? 'reconciled' : 'pending'" />
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <input
                                v-model="item.transaction_date"
                                type="date"
                                :min="reconciliation.form.period_start"
                                :max="reconciliation.form.period_end"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                            />
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <input
                                v-model="item.description"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                                placeholder="Descrição no extrato"
                            />
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <select
                                v-model="item.movement_type"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                                @change="reconciliation.updateStatementItemType(index, item.movement_type)"
                            >
                                <option value="inflow">Entrada</option>
                                <option value="outflow">Saída</option>
                            </select>
                        </td>

                        <td class="px-4 py-3 text-right text-sm">
                            <input
                                :value="item.amount"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-right text-white"
                                placeholder="R$ 0,00"
                                inputmode="numeric"
                                @input="reconciliation.updateStatementItemAmount(index, $event)"
                            />
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <select
                                v-model="item.journal_line_id"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            >
                                <option value="">A conciliar / pendente</option>
                                <option
                                    v-for="line in preview.lines"
                                    :key="line.id"
                                    :value="line.id"
                                >
                                    {{ lineLabel(line) }}
                                </option>
                            </select>
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <button
                                type="button"
                                class="rounded-lg border border-red-900 px-3 py-1.5 text-sm font-medium text-red-300 transition hover:bg-red-950"
                                @click="reconciliation.removeStatementItem(index)"
                            >
                                Remover
                            </button>
                        </td>
                    </tr>
                </ReportTable>

                <p class="px-6 pb-6 pt-2 text-sm text-red-400">
                    {{ reconciliation.form.errors.statement_items }}
                </p>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Lançamentos do sistema no período
                        </h2>

                        <p class="text-sm text-gray-400">
                            Referência dos lançamentos postados disponíveis para vínculo.
                        </p>
                    </div>
                </template>

                <ReportTable
                    :empty="(preview.lines ?? []).length === 0"
                    empty-message="Nenhum lançamento postado encontrado para a conta e período."
                    :empty-colspan="4"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
                        </tr>
                    </template>

                    <tr
                        v-for="line in preview.lines"
                        :key="line.id"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            {{ formatDate(line.date) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-200">
                            {{ line.description || 'Sem descrição' }}
                        </td>

                        <td
                            class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                            :class="Number(line.signed_amount_cents) >= 0 ? 'text-green-300' : 'text-red-300'"
                        >
                            {{ formatCurrency(line.signed_amount_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-400">
                            JE-{{ String(line.journal_entry_id).padStart(6, '0') }}
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Fechamento
                        </h2>

                        <p class="text-sm text-gray-400">
                            A diferença compara o saldo calculado pelo extrato bancário com o saldo dos lançamentos vinculados.
                        </p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label>
                        <input
                            v-model="reconciliation.form.notes"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            placeholder="Opcional"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ reconciliation.form.errors.notes }}</p>
                    </div>

                    <div class="flex items-end justify-end gap-3">
                        <Link
                            :href="route('bank-reconciliations.index')"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                        >
                            Cancelar
                        </Link>

                        <button
                            type="submit"
                            :disabled="!reconciliation.canSubmit.value || reconciliation.form.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Salvar conciliação
                        </button>
                    </div>
                </form>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
