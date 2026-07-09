<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps<{
    wallet: Record<string, any>;
    cards: Array<Record<string, any>>;
}>();

const cardTypes: Record<string, string> = {
    main: 'Principal',
    additional: 'Adicional',
    virtual: 'Virtual',
};
</script>

<template>
    <AppLayout title="Cartões de Crédito">
        <ReportPage title="Cartões de Crédito" :subtitle="wallet.name">
            <div class="flex justify-end">
                <Link
                    :href="route('credit-cards.create')"
                    class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500"
                >
                    Novo cartão
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Faturas de cartão</h2>
                        <p class="text-sm text-gray-400">
                            A lista mostra somente cartões principais. Virtuais e adicionais aparecem dentro da mesma fatura.
                        </p>
                    </div>
                </template>

                <ReportTable :empty="cards.length === 0" empty-message="Nenhum cartão cadastrado." :empty-colspan="9">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Fatura</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conta vinculada</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Cartões</th>
                            <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Fechamento</th>
                            <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Vencimento</th>
                            <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Melhor compra</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Limite</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Fatura atual</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
                        </tr>
                    </template>

                    <tr v-for="card in cards" :key="card.id" class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-sm">
                            <div class="font-semibold text-white">{{ card.name }}</div>
                            <div class="text-xs text-gray-500">
                                {{ card.issuer_name }} · {{ card.network }} {{ card.last_four ? '•••• ' + card.last_four : '' }}
                            </div>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ card.bank_account?.name ?? '-' }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            <div>Principal</div>
                            <div v-for="child in card.child_cards" :key="child.id" class="text-xs text-gray-500">
                                {{ cardTypes[child.card_type] ?? child.card_type }} · {{ child.name }} {{ child.last_four ? '•••• ' + child.last_four : '' }}
                            </div>
                        </td>

                        <td class="px-4 py-3 text-center text-sm text-gray-300">dia {{ card.closing_day }}</td>
                        <td class="px-4 py-3 text-center text-sm text-gray-300">dia {{ card.due_day }}</td>
                        <td class="px-4 py-3 text-center text-sm text-green-300">dia {{ card.best_purchase_day }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">{{ formatCurrency(card.credit_limit_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-yellow-300">{{ formatCurrency(card.current_balance_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link
                                :href="route('credit-cards.show', [card.id])"
                                class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                            >
                                Ver fatura
                            </Link>
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
