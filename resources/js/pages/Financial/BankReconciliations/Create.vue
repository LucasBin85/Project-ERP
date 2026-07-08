<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBankReconciliationCreate } from '@/composables/financial/useBankReconciliationCreate';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    filters: Record<string, string>;
    preview: Record<string, any>;
}>();

const reconciliation = useBankReconciliationCreate(props.filters, props.preview.lines ?? []);

const reconciledBalanceCents = computed(() => {
    return Number(props.preview.opening_balance_cents ?? 0) + reconciliation.selectedMovementCents.value;
});

const differenceCents = computed(() => {
    return reconciledBalanceCents.value - Number(reconciliation.form.statement_balance_cents ?? 0);
});

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
                            Escolha a conta e o período para carregar as movimentações postadas.
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

                    <div class="lg:col-span-4 flex justify-end gap-3">
                        <Link
                            :href="route('bank-reconciliations.index')"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                        >
                            Cancelar
                        </Link>

                        <button
                            type="button"
                            :disabled="!reconciliation.canPreview.value"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="reconciliation.applyPreview"
                        >
                            Carregar movimentações
                        </button>
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
                    label="Saldo contábil"
                    :value="formatCurrency(preview.book_balance_cents)"
                    tone="neutral"
                />

                <ReportSummaryCard
                    label="Selecionado"
                    :value="formatCurrency(reconciliation.selectedMovementCents.value)"
                    tone="green"
                />

                <ReportSummaryCard
                    label="Saldo conciliado"
                    :value="formatCurrency(reconciledBalanceCents)"
                    tone="neutral"
                />

                <ReportSummaryCard
                    label="Diferença"
                    :value="formatCurrency(differenceCents)"
                    :tone="differenceCents === 0 ? 'green' : 'yellow'"
                />
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">
                                Movimentações do período
                            </h2>

                            <p class="text-sm text-gray-400">
                                Marque as movimentações que aparecem no extrato bancário.
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                                @click="reconciliation.selectAll"
                            >
                                Selecionar tudo
                            </button>

                            <button
                                type="button"
                                class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                                @click="reconciliation.clearSelection"
                            >
                                Limpar seleção
                            </button>
                        </div>
                    </div>
                </template>

                <ReportTable
                    :empty="(preview.lines ?? []).length === 0"
                    empty-message="Nenhuma movimentação postada encontrada para a conta e período."
                    :empty-colspan="5"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conciliar</th>
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
                        <td class="px-4 py-3 text-sm">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-gray-600 bg-gray-900"
                                :checked="reconciliation.form.journal_line_ids.includes(Number(line.id))"
                                @change="reconciliation.toggleLine(line.id, $event.target.checked)"
                            />
                        </td>

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
                            Informe o saldo final do banco para calcular a diferença da conciliação.
                        </p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Saldo final informado pelo banco</label>
                        <input
                            :value="reconciliation.form.statement_balance"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            placeholder="R$ 0,00"
                            inputmode="numeric"
                            @input="reconciliation.updateStatementBalance"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ reconciliation.form.errors.statement_balance_cents }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label>
                        <input
                            v-model="reconciliation.form.notes"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            placeholder="Opcional"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ reconciliation.form.errors.notes }}</p>
                    </div>

                    <div class="lg:col-span-2 flex justify-end">
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
