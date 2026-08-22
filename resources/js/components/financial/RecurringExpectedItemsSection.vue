<script setup lang="ts">
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

type ExpectedItem = {
    expectation_id: number;
    period_date: string;
    due_date: string;
    description: string;
    counterparty: { id: number; name: string } | null;
    default_account: { id: number; code: string; name: string } | null;
    frequency: string;
    amount_mode: 'fixed' | 'variable';
    expected_amount_cents: number | null;
    is_overdue: boolean;
};

const props = defineProps<{
    items: ExpectedItem[];
    counterpartyLabel: string;
    accountLabel: string;
    confirmRoute: string;
    skipRoute: string;
}>();

const editingKey = ref<string | null>(null);
const amount = ref('');
const form = useForm({ period_date: '', actual_amount_cents: 0, due_date: '', notes: '' });
const actionError = computed(() => {
    const errors = form.errors as Record<string, string>;
    return errors.period_date ?? errors.expectation ?? errors.period ?? errors.amount_cents;
});

const keyFor = (item: ExpectedItem) => `${item.expectation_id}:${item.period_date}`;
const frequencyLabel = (frequency: string) =>
    ({ monthly: 'Mensal', quarterly: 'Trimestral', semiannual: 'Semestral', annual: 'Anual' })[frequency] ?? frequency;
const decimalAmount = (cents: number | null) => (cents === null ? '' : (cents / 100).toFixed(2).replace('.', ','));
const centsFrom = (value: string) => Math.round(Number(value.replace(/\./g, '').replace(',', '.')) * 100);

function edit(item: ExpectedItem) {
    form.clearErrors();
    editingKey.value = keyFor(item);
    amount.value = decimalAmount(item.expected_amount_cents);
    form.period_date = item.period_date;
    form.due_date = item.due_date;
    form.notes = '';
}

function confirm(item: ExpectedItem) {
    form.actual_amount_cents = centsFrom(amount.value);
    form.post(route(props.confirmRoute, item.expectation_id), {
        preserveScroll: true,
        onSuccess: () => {
            editingKey.value = null;
        },
    });
}

function skip(item: ExpectedItem) {
    if (!window.confirm('Ignorar esta competência recorrente? Esta ação ainda não pode ser desfeita.')) return;
    router.post(route(props.skipRoute, item.expectation_id), { period_date: item.period_date }, { preserveScroll: true });
}
</script>

<template>
    <ReportSection v-if="items.length > 0">
        <template #header>
            <div>
                <h2 class="text-lg font-bold text-white">Contas recorrentes previstas</h2>
                <p class="mt-1 text-sm text-gray-400">Contas esperadas no período que ainda não foram confirmadas.</p>
            </div>
        </template>
        <ReportTable :empty="false" :empty-colspan="7">
            <template #head
                ><tr>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Vencimento previsto</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">{{ counterpartyLabel }}</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Descrição</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">{{ accountLabel }}</th>
                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Previsão</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Comportamento</th>
                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Ações</th>
                </tr></template
            >
            <template v-for="item in items" :key="keyFor(item)">
                <tr class="border-b border-gray-800">
                    <td class="px-4 py-3 text-sm text-gray-300">
                        {{ formatDate(item.due_date)
                        }}<span v-if="item.is_overdue" class="ml-2 rounded bg-amber-950 px-2 py-0.5 text-xs text-amber-300">Atrasada</span>
                    </td>
                    <td class="px-4 py-3 text-sm font-semibold text-white">{{ item.counterparty?.name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-300">
                        {{ item.description }}<span class="ml-2 text-xs text-indigo-300">{{ frequencyLabel(item.frequency) }}</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-400">{{ formatAccount(item.default_account?.code, item.default_account?.name) }}</td>
                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-100">
                        {{
                            item.expected_amount_cents === null
                                ? 'Sem estimativa'
                                : `${item.amount_mode === 'variable' ? '~ ' : ''}${formatCurrency(item.expected_amount_cents)}`
                        }}
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-300">{{ item.amount_mode === 'fixed' ? 'Valor fixo' : 'Valor variável' }}</td>
                    <td class="px-4 py-3 text-right text-sm">
                        <div class="flex justify-end gap-2">
                            <button
                                class="rounded-lg bg-indigo-600 px-3 py-1.5 font-semibold text-white hover:bg-indigo-500"
                                type="button"
                                @click="edit(item)"
                            >
                                Confirmar</button
                            ><button
                                class="rounded-lg border border-gray-600 px-3 py-1.5 text-gray-300 hover:bg-gray-700"
                                type="button"
                                @click="skip(item)"
                            >
                                Ignorar competência
                            </button>
                        </div>
                    </td>
                </tr>
                <tr v-if="editingKey === keyFor(item)" class="bg-gray-950/60">
                    <td colspan="7" class="p-4">
                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-gray-300">Valor real</label
                                ><input
                                    v-model="amount"
                                    :readonly="item.amount_mode === 'fixed'"
                                    inputmode="decimal"
                                    class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white read-only:text-gray-400"
                                    placeholder="R$ 0,00"
                                />
                                <p class="mt-1 text-sm text-red-400">{{ form.errors.actual_amount_cents }}</p>
                                <p v-if="item.amount_mode === 'variable'" class="mt-1 text-xs text-gray-400">
                                    {{
                                        item.expected_amount_cents === null
                                            ? 'Sem histórico suficiente para estimativa.'
                                            : `Previsão baseada no histórico: ${formatCurrency(item.expected_amount_cents)}`
                                    }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-gray-300">Vencimento</label
                                ><input
                                    v-model="form.due_date"
                                    type="date"
                                    class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                                />
                                <p class="mt-1 text-sm text-red-400">{{ form.errors.due_date }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-gray-300">Observações (opcional)</label
                                ><input v-model="form.notes" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                                <p class="mt-1 text-sm text-red-400">{{ form.errors.notes }}</p>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-red-400">{{ actionError }}</p>
                        <div class="mt-4 flex gap-2">
                            <button
                                type="button"
                                :disabled="form.processing"
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                @click="confirm(item)"
                            >
                                Confirmar competência</button
                            ><button
                                type="button"
                                class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-300"
                                @click="editingKey = null"
                            >
                                Cancelar
                            </button>
                        </div>
                    </td>
                </tr>
            </template>
        </ReportTable>
    </ReportSection>
</template>
