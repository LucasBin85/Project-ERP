<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useCreditCardCreate } from '@/composables/financial/useCreditCardCreate';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    parentCards: Array<Record<string, any>>;
}>();

const creditCard = useCreditCardCreate();

function submit() {
    if (!creditCard.canSubmit.value) return;
    creditCard.form.post(route('credit-cards.store'));
}
</script>

<template>
    <AppLayout title="Novo Cartão de Crédito">
        <ReportPage title="Novo Cartão de Crédito" :subtitle="props.wallet?.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Dados do cartão</h2>
                        <p class="mt-1 text-sm text-gray-400">
                            Cartões adicionais e virtuais ficam vinculados ao cartão principal e compartilham a mesma conta passiva da fatura.
                        </p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Nome do cartão</label>
                        <input v-model="creditCard.form.name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Nubank Ultravioleta" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Operadora/Banco</label>
                        <input v-model="creditCard.form.issuer_name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Nubank" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.issuer_name }}</p>
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

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Tipo</label>
                        <select v-model="creditCard.form.card_type" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="main">Principal</option>
                            <option value="additional">Adicional</option>
                            <option value="virtual">Virtual</option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.card_type }}</p>
                    </div>

                    <div v-if="creditCard.form.card_type !== 'main'" class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Cartão principal</label>
                        <select v-model="creditCard.form.parent_card_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione o cartão principal</option>
                            <option v-for="card in parentCards" :key="card.id" :value="card.id">{{ card.label }}</option>
                        </select>
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
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Limite</label>
                        <input :value="creditCard.form.credit_limit" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="creditCard.updateLimit" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.credit_limit_cents }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label>
                        <textarea v-model="creditCard.form.notes" rows="3" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Opcional" />
                        <p class="mt-1 text-sm text-red-400">{{ creditCard.form.errors.notes }}</p>
                    </div>

                    <div class="md:col-span-2 flex justify-end gap-3">
                        <Link :href="route('credit-cards.index')" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">Cancelar</Link>
                        <button type="submit" :disabled="!creditCard.canSubmit.value || creditCard.form.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Salvar cartão
                        </button>
                    </div>
                </form>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
