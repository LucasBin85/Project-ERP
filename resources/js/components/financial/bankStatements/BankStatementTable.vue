<script setup lang="ts">
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { BankStatementAccount, BankStatementClassificationAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import BankStatementSuggestion from './BankStatementSuggestion.vue';
import ClassificationRuleDialog from './ClassificationRuleDialog.vue';
import type { FinancialOperationTypeOption } from '@/types/financial/operationType';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import InlineOfxClassification from './InlineOfxClassification.vue';
import InlineOfxMatchResolution from './InlineOfxMatchResolution.vue';
import InlineOfxOperationType from './InlineOfxOperationType.vue';
import InlineOfxTransferMatch from './InlineOfxTransferMatch.vue';
import InlinePayableSettlement from './InlinePayableSettlement.vue';
import InlineReceivableSettlement from './InlineReceivableSettlement.vue';

const props = defineProps<{
    transactions: BankStatementTransaction[];
    bankAccount: BankStatementAccount;
    classificationAccounts: BankStatementClassificationAccount[];
    operationTypes: FinancialOperationTypeOption[];
    settlementParties: { suppliers: Array<{ id: number; name: string }>; customers: Array<{ id: number; name: string }> };
}>();

function operationTypeLabel(transaction: BankStatementTransaction): string {
    if (transaction.operation_type === 'investment') {
        return transaction.type === 'inflow' ? 'Resgate de investimento' : 'Investimento / aplicação';
    }

    if (transaction.operation_type === 'other' && transaction.type === 'inflow') {
        return 'Reembolso, estorno ou outro';
    }

    return props.operationTypes.find((option) => option.code === transaction.operation_type)?.label ?? '—';
}
</script>

<template>
    <ReportTable :empty="transactions.length === 0" empty-message="Nenhuma movimentação encontrada para os filtros informados." :empty-colspan="9">
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Data</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Descrição</th>
                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Valor</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Origem</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Situação</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Validação bancária</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Tipo de operação</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Classificação</th>
                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Lançamento</th>
            </tr>
        </template>

        <tr v-for="transaction in transactions" :key="transaction.id" class="hover:bg-gray-800/50">
            <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">
                {{ formatDate(transaction.date) }}
            </td>

            <td class="px-4 py-3 text-sm">
                <BankStatementSuggestion :transaction="transaction" :bank-account="bankAccount" />
                <div class="font-semibold text-white">
                    {{ transaction.description || 'Sem descrição' }}
                </div>

                <div class="mt-1 flex items-center gap-2 text-xs text-gray-500">
                    <span>#{{ transaction.id }}</span>
                </div>
                <ClassificationRuleDialog v-if="['ofx','csv','pdf'].includes(transaction.source ?? '')" :transaction="transaction" :bank-account="bankAccount" :accounts="classificationAccounts" :suppliers="settlementParties.suppliers" :customers="settlementParties.customers" />
            </td>

            <td
                class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap"
                :class="transaction.amount_cents > 0 ? 'text-green-300' : transaction.amount_cents < 0 ? 'text-red-300' : 'text-gray-300'"
            >
                {{ formatCurrency(transaction.amount_cents) }}
            </td>

            <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">
                {{ transaction.source_label || transaction.source || 'Manual' }}
            </td>

            <td class="px-4 py-3 text-sm whitespace-nowrap">
                <StatusBadge :status="transaction.workflow_status" />
            </td>

            <td class="px-4 py-3 text-sm whitespace-nowrap">
                <StatusBadge :status="transaction.reconciliation_status" />
            </td>

            <td class="px-4 py-3 text-sm">
                <InlineOfxOperationType
                    v-if="['ofx', 'csv', 'pdf'].includes(transaction.source ?? '') && transaction.accounting_status === 'draft'"
                    :transaction="transaction"
                    :bank-account="bankAccount"
                    :operation-types="operationTypes"
                />
                <span v-else class="text-gray-300">{{ operationTypeLabel(transaction) }}</span>
            </td>

            <td class="px-4 py-3 text-sm">
                <InlinePayableSettlement
                    v-if="transaction.linked_account_payable || transaction.can_link_account_payable"
                    :transaction="transaction"
                    :bank-account="bankAccount"
                    :suppliers="settlementParties.suppliers"
                />
                <InlineReceivableSettlement
                    v-else-if="transaction.linked_account_receivable || transaction.can_link_account_receivable"
                    :transaction="transaction"
                    :bank-account="bankAccount"
                    :customers="settlementParties.customers"
                />
                <InlineOfxMatchResolution v-else-if="transaction.match_status !== 'none'" :transaction="transaction" :bank-account="bankAccount" />
                <InlineOfxTransferMatch
                    v-else-if="transaction.transfer && transaction.transfer.match_status !== 'none'"
                    :transaction="transaction"
                    :bank-account="bankAccount"
                />
                <div v-else-if="transaction.transfer" class="space-y-1 text-xs">
                    <span class="inline-flex rounded bg-blue-950 px-2 py-1 font-semibold text-blue-300">Transferência</span>
                    <p><Link :href="transaction.transfer.counterpart_statement_url" class="text-indigo-300 hover:underline">{{ transaction.transfer.counterpart_name }}</Link></p>
                    <p :class="transaction.transfer.status === 'fully_validated' ? 'text-green-300' : 'text-amber-300'">{{ transaction.transfer.status === 'fully_validated' ? 'Validada nas duas contas' : 'Aguardando OFX da contraparte' }}</p>
                </div>
                <InlineOfxClassification
                    v-else-if="['ofx', 'csv', 'pdf'].includes(transaction.source ?? '') && transaction.accounting_status === 'draft'"
                    :transaction="transaction"
                    :bank-account="bankAccount"
                    :classification-accounts="classificationAccounts"
                />
                <span
                    v-else
                    class="inline-flex rounded px-2 py-1 text-xs font-semibold"
                    :class="transaction.classification_status === 'unclassified' ? 'bg-yellow-950 text-yellow-300' : 'bg-green-950 text-green-300'"
                >
                    {{ transaction.classification_label }}
                </span>
            </td>

            <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
                <Link
                    v-if="transaction.journal_entry_id"
                    :href="route('journal-entries.show', [transaction.journal_entry_id])"
                    class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                >
                    JE-{{ String(transaction.journal_entry_id).padStart(6, '0') }}
                </Link>
                <span v-else class="text-gray-500">-</span>
            </td>
        </tr>
    </ReportTable>
</template>
