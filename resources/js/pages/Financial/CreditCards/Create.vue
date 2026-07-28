<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useCreditCardCreate } from '@/composables/financial/useCreditCardCreate';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { watch } from 'vue';

const props = defineProps<{
    wallet: Record<string, any>;
    parentCards: Array<Record<string, any>>;
    selectedBankAccountId?: number | null;
    issuerBanks: Array<Record<string, any>>;
    issuerContext?: { bank_account_id: number; bank_id: number; name: string } | null;
}>();

const creditCard = useCreditCardCreate(props.issuerContext?.bank_id ?? null, props.issuerContext?.bank_account_id ?? null);
watch(() => creditCard.form.card_type, (type) => {
    if (type === 'main') return;
    const eligible = props.parentCards.filter((card) =>
        !creditCard.form.bank_id || Number(card.issuer_bank_id) === Number(creditCard.form.bank_id),
    );
    if (eligible.length === 1) creditCard.form.parent_card_id = String(eligible[0].id);
});

function submit() {
    if (!creditCard.canSubmit.value) return;
    creditCard.form.post(route('credit-cards.store'));
}
</script>

<template>
    <AppLayout title="Novo Cartão de Crédito">
        <ReportPage title="Novo Cartão de Crédito" :subtitle="props.wallet?.name">
            <div v-if="selectedBankAccountId" class="flex justify-end">
                <Link
                    :href="route('bank-accounts.show', [selectedBankAccountId])"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar para a conta bancária
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Dados do cartão</h2>
                        <p class="mt-1 text-sm text-gray-400">
                            O cadastro é manual. A fatura principal representa a fatura consolidada e o passivo contábil do banco; arquivos são importados depois, no detalhe da fatura.
                        </p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Nome do cartão</label>
                        <input v-model="creditCard.form.name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Nubank Principal" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Instituição</label>
                        <p v-if="creditCard.form.card_type !== 'main'" class="rounded-lg border border-gray-700 bg-gray-900 px-3 py-2 text-gray-300">Herdada do cartão principal</p>
                        <p v-else-if="issuerContext" class="rounded-lg border border-gray-700 bg-gray-900 px-3 py-2 font-semibold text-white">{{ issuerContext.name }}</p>
                        <select v-else v-model="creditCard.form.bank_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione a instituição emissora</option>
                            <option v-for="bank in issuerBanks" :key="bank.id" :value="bank.id">{{ bank.short_name }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">{{ issuerContext ? 'Instituição herdada da conta bancária usada para criar o cartão.' : 'O cartão ficará vinculado à instituição selecionada.' }}</p>
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.bank_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Tipo</label>
                        <select v-model="creditCard.form.card_type" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="main">Fatura principal do banco</option>
                            <option value="physical">Cartão físico</option>
                            <option value="virtual">Cartão virtual</option>
                            <option value="additional">Cartão adicional</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">{{ creditCard.form.card_type === 'main' ? 'Representa a fatura consolidada e o passivo contábil do cartão.' : 'As compras deste cartão entram na fatura principal do banco.' }}</p>
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.card_type }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Bandeira</label>
                        <select v-model="creditCard.form.network" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="visa">Visa</option>
                            <option value="mastercard">Mastercard</option>
                            <option value="elo">Elo</option>
                            <option value="amex">Amex</option>
                            <option value="hipercard">Hipercard</option>
                            <option value="other">Outra</option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.network }}</p>
                    </div>

                    <div v-if="creditCard.form.card_type !== 'main'" class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Cartão principal / fatura</label>
                        <select v-model="creditCard.form.parent_card_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione a fatura principal</option>
                            <option v-for="card in parentCards" :key="card.id" :value="card.id">{{ card.label }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Este cartão compartilhará limite, datas e passivo com a fatura principal. Se ela ainda não existir, cadastre-a primeiro.</p>
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.parent_card_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Titular</label>
                        <input v-model="creditCard.form.holder_name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Opcional" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.holder_name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Final</label>
                        <input v-model="creditCard.form.last_four" maxlength="4" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="1234" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.last_four }}</p>
                    </div>

                    <template v-if="creditCard.form.card_type === 'main'">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">Data de fechamento</label>
                            <input v-model="creditCard.form.closing_day" type="number" min="1" max="31" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                            <p class="mt-1 text-xs text-gray-500">Use 31 para fechar no último dia de cada mês, inclusive fevereiro e meses com 30 dias.</p>
                            <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.closing_day }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">Data de vencimento</label>
                            <input v-model="creditCard.form.due_day" type="number" min="1" max="31" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                            <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.due_day }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">Melhor data de compra</label>
                            <input v-model="creditCard.form.best_purchase_day" type="number" min="1" max="31" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                            <p class="mt-1 text-xs text-gray-500">Calculada somente depois que você informar o fechamento; pode ser ajustada.</p>
                            <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.best_purchase_day }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">Limite compartilhado</label>
                            <input :value="creditCard.form.credit_limit" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="creditCard.updateLimit" />
                            <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.credit_limit_cents }}</p>
                        </div>
                    </template>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label>
                        <textarea v-model="creditCard.form.notes" rows="3" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Opcional" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.notes }}</p>
                    </div>

                    <div class="md:col-span-2 flex justify-end gap-3">
                        <Link :href="selectedBankAccountId ? route('bank-accounts.show', [selectedBankAccountId]) : route('credit-cards.index')" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">Cancelar</Link>
                        <button type="submit" :disabled="!creditCard.canSubmit.value || creditCard.form.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Salvar cartão
                        </button>
                    </div>
                </form>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
