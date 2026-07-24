<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useCreditCardCreate } from '@/composables/financial/useCreditCardCreate';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { ref } from 'vue';

const props = defineProps<{
    wallet: Record<string, any>;
    parentCards: Array<Record<string, any>>;
    bankAccounts: Array<Record<string, any>>;
    selectedBankAccountId?: number | null;
}>();

const creditCard = useCreditCardCreate(props.selectedBankAccountId ?? null);
const setupMessage = ref('');
const setupLoading = ref(false);

async function fillFromStatement(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    setupLoading.value = true;
    setupMessage.value = '';
    const body = new FormData();
    body.append('statement_file', file);
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(route('credit-cards.setup-file.preview'), {
        method: 'POST',
        body,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
    });
    const data = await response.json();
    setupLoading.value = false;
    if (!response.ok) {
        setupMessage.value = data.message ?? 'Não foi possível identificar dados seguros neste arquivo.';
        return;
    }
    if (data.institution) creditCard.form.issuer_name = data.institution;
    if (data.last_four) creditCard.form.last_four = data.last_four;
    if (data.holder_name) creditCard.form.holder_name = data.holder_name;
    if (data.due_day) creditCard.form.due_day = data.due_day;
    setupMessage.value = data.warning ?? 'Dados seguros identificados. Revise os campos antes de salvar.';
}

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
                            O cartão principal representa a fatura. Cartões virtuais e adicionais ficam dentro dele e compartilham limite, fechamento e vencimento.
                        </p>
                    </div>
                </template>

                <div class="border-b border-gray-700 p-6">
                    <label class="mb-1 block text-sm font-semibold text-gray-300">Preencher com arquivo da fatura</label>
                    <input type="file" accept=".ofx,.csv,.pdf" class="text-sm text-gray-300" @change="fillFromStatement">
                    <p class="mt-1 text-xs text-gray-500">Somente metadados seguros serão preenchidos. Este passo não cria cartão, fatura ou compras.</p>
                    <p v-if="setupLoading" class="mt-1 text-xs text-indigo-300">Analisando arquivo...</p>
                    <p v-else-if="setupMessage" class="mt-1 text-xs text-amber-300">{{ setupMessage }}</p>
                </div>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Nome do cartão</label>
                        <input v-model="creditCard.form.name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Nubank Principal" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Instituição emissora</label>
                        <input v-model="creditCard.form.issuer_name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Nubank" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.issuer_name }}</p>
                        <p class="mt-1 text-xs text-gray-500">Instituição responsável pelo cartão, como Nubank, Itaú, Inter ou Banco do Brasil.</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Tipo</label>
                        <select v-model="creditCard.form.card_type" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="main">Principal / fatura</option>
                            <option value="additional">Adicional</option>
                            <option value="virtual">Virtual</option>
                        </select>
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

                    <div v-if="creditCard.form.card_type === 'main'" class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta padrão para pagamento da fatura</label>
                        <select v-model="creditCard.form.bank_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Sem conta padrão</option>
                            <option v-for="account in bankAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Usada apenas como sugestão ao pagar a fatura. O pagamento ainda pode sair de outra conta.</p>
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.bank_account_id }}</p>
                    </div>

                    <div v-if="creditCard.form.card_type !== 'main'" class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Cartão principal / fatura</label>
                        <select v-model="creditCard.form.parent_card_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione a fatura principal</option>
                            <option v-for="card in parentCards" :key="card.id" :value="card.id">{{ card.label }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Este cartão herdará limite, vencimento, fechamento, melhor data e conta bancária do cartão principal.</p>
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
                            <p class="mt-1 text-xs text-gray-500">Sugerida automaticamente como o dia após o fechamento.</p>
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
