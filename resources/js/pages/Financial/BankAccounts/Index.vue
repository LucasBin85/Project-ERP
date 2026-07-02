<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { formatCurrency, formatAccount } from '@/lib/formatters'

defineProps({
    wallet: Object,
    bankAccounts: Array,
})

function formatType(type) {
    const types = {
        checking: 'Conta Corrente',
        savings: 'Poupança',
        investment: 'Investimento',
        cash: 'Caixa',
        other: 'Outra',
    }

    return types[type] ?? type
}
</script>

<template>
    <AppLayout title="Contas Bancárias">
        <ReportPage
            title="Contas Bancárias"
            :subtitle="wallet.name"
        >
            <div class="flex justify-end">
                <Link
                    :href="route('bank-accounts.create')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Nova conta bancária
                </Link>
            </div>

            <ReportSection>
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
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>