<script setup>
import ReportTable from '@/components/reports/ReportTable.vue'
import { formatAccount, formatCurrency } from '@/lib/formatters'

defineProps({
    bankAccounts: {
        type: Array,
        default: () => [],
    },
    formatType: {
        type: Function,
        required: true,
    },
})
</script>

<template>
    <ReportTable
        :empty="bankAccounts.length === 0"
        empty-message="Nenhuma conta bancária cadastrada."
        :empty-colspan="6"
    >
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Nome</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Banco</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Tipo</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conta Contábil</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo Inicial</th>
                <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Status</th>
            </tr>
        </template>

        <tr
            v-for="account in bankAccounts"
            :key="account.id"
            class="hover:bg-gray-800/50"
        >
            <td class="px-4 py-3 text-sm font-semibold text-white">
                {{ account.name }}
            </td>

            <td class="px-4 py-3 text-sm text-gray-300">
                {{ account.bank_name || '-' }}
            </td>

            <td class="px-4 py-3 text-sm text-gray-300">
                {{ formatType(account.account_type) }}
            </td>

            <td class="px-4 py-3 text-sm text-gray-300">
                {{ formatAccount(account.chart_of_account?.code, account.chart_of_account?.name) }}
            </td>

            <td class="px-4 py-3 text-right text-sm font-semibold text-gray-100">
                {{ formatCurrency(account.opening_balance_cents) }}
            </td>

            <td class="px-4 py-3 text-center text-sm">
                <span
                    class="rounded px-2 py-1 text-xs font-semibold"
                    :class="account.is_active
                        ? 'bg-green-950 text-green-300'
                        : 'bg-gray-800 text-gray-400'"
                >
                    {{ account.is_active ? 'Ativa' : 'Inativa' }}
                </span>
            </td>
        </tr>
    </ReportTable>
</template>
