<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useAccountReceivableCreate } from '@/composables/financial/useAccountReceivableCreate';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    revenueAccounts: Array<Record<string, any>>;
    receivableAccounts: Array<Record<string, any>>;
}>();

const accountReceivable = useAccountReceivableCreate();

function submit() {
    if (!accountReceivable.canSubmit.value) return;
    accountReceivable.form.post(route('accounts-receivable.store'));
}
</script>

<template>
    <AppLayout title="Novo título a receber">
        <ReportPage title="Novo título a receber" :subtitle="props.wallet?.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Dados do título</h2>
                        <p class="mt-1 text-sm text-gray-400">O cadastro cria uma provisão contábil em rascunho para posterior postagem.</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta de controle do cliente</label>
                        <select v-model="accountReceivable.form.receivable_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma conta de controle</option>
                            <option v-for="account in receivableAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.receivable_account_id }}</p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Cliente</label>
                        <input v-model="accountReceivable.form.customer_name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Cliente ABC" />
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.customer_name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta de receita</label>
                        <select v-model="accountReceivable.form.revenue_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma receita</option>
                            <option v-for="account in revenueAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.revenue_account_id }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Descrição</label>
                        <input v-model="accountReceivable.form.description" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Prestação de serviços julho" />
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.description }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Vencimento</label>
                        <input v-model="accountReceivable.form.due_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" />
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.due_date }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Valor</label>
                        <input :value="accountReceivable.form.amount" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="accountReceivable.updateAmount" />
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.amount_cents }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label>
                        <textarea v-model="accountReceivable.form.notes" rows="3" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Opcional" />
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.notes }}</p>
                    </div>

                    <div class="md:col-span-2 flex justify-end gap-3">
                        <Link :href="route('accounts-receivable.index')" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">Cancelar</Link>
                        <button type="submit" :disabled="!accountReceivable.canSubmit.value || accountReceivable.form.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Salvar título a receber
                        </button>
                    </div>
                </form>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
