<script setup lang="ts">
import BankStatementDateRangeFilter from '@/components/financial/bankStatements/BankStatementDateRangeFilter.vue';
import BankStatementFilters from '@/components/financial/bankStatements/BankStatementFilters.vue';
import BankStatementSummary from '@/components/financial/bankStatements/BankStatementSummary.vue';
import BankStatementTable from '@/components/financial/bankStatements/BankStatementTable.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useBankStatementIndex } from '@/composables/financial/useBankStatementIndex';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    filters: Record<string, string>;
    statementReady: boolean;
    selectedBankAccount: Record<string, any> | null;
    summary: Record<string, number>;
    transactions: Array<Record<string, any>>;
}>();

const bankStatement = useBankStatementIndex(props.filters as any);
</script>

<template>
    <AppLayout title="Extrato Bancário">
        <ReportPage title="Extrato Bancário" :subtitle="props.wallet?.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Filtros do extrato
                        </h2>

                        <p class="mt-1 text-sm text-gray-400">
                            A partir do extrato você importa OFX, confere movimentos e inicia a conciliação da conta.
                        </p>
                    </div>
                </template>

                <BankStatementFilters
                    v-model:bank-account-id="bankStatement.form.bank_account_id"
                    v-model:search="bankStatement.form.search"
                    :bank-accounts="bankAccounts"
                    @clear="bankStatement.clearFilters"
                />
            </ReportSection>

            <BankStatementDateRangeFilter
                v-model:start="bankStatement.form.start_date"
                v-model:end="bankStatement.form.end_date"
            />

            <div
                v-if="!statementReady"
                class="rounded-xl border border-dashed border-gray-700 bg-gray-900/50 p-8 text-center"
            >
                <h2 class="text-lg font-bold text-white">
                    Selecione uma conta bancária para gerar o extrato
                </h2>

                <p class="mt-2 text-sm text-gray-400">
                    O período já vem preenchido com o mês atual. Depois de selecionar a conta, os filtros passam a atualizar a tela de forma dinâmica.
                </p>
            </div>

            <template v-else>
                <BankStatementSummary :summary="summary" />

                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">
                                    {{ selectedBankAccount?.name }}
                                </h2>

                                <p class="text-sm text-gray-400">
                                    Movimentações financeiras em formato de internet banking.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    v-if="selectedBankAccount?.id"
                                    :href="route('bank-accounts.show', [selectedBankAccount.id])"
                                    class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                                >
                                    Resumo da conta
                                </Link>

                                <Link
                                    v-if="selectedBankAccount?.id"
                                    :href="route('ofx-imports.index', { bank_account_id: selectedBankAccount.id })"
                                    class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                                >
                                    Importar OFX
                                </Link>

                                <Link
                                    v-if="selectedBankAccount?.id"
                                    :href="route('bank-reconciliations.create', {
                                        bank_account_id: selectedBankAccount.id,
                                        period_start: bankStatement.form.start_date,
                                        period_end: bankStatement.form.end_date,
                                    })"
                                    class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500"
                                >
                                    Conciliar período
                                </Link>

                                <div class="text-sm text-gray-400">
                                    {{ transactions.length }} movimentação(ões)
                                </div>
                            </div>
                        </div>
                    </template>

                    <BankStatementTable :transactions="transactions" />
                </ReportSection>
            </template>
        </ReportPage>
    </AppLayout>
</template>
