<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useAccountReceivableCreate } from '@/composables/financial/useAccountReceivableCreate';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed } from 'vue';

const props = defineProps<{
    wallet: Record<string, any>;
    customers: Array<Record<string, any>>;
}>();

const accountReceivable = useAccountReceivableCreate();
const selectedCustomer = computed(() => props.customers.find((customer) => customer.id === Number(accountReceivable.form.customer_id)));

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

                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Cliente</label>
                        <select v-model="accountReceivable.form.customer_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="">Selecione o cliente</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option></select>
                        <div v-if="selectedCustomer" class="mt-2 space-y-1 rounded-lg border border-gray-700 bg-gray-950 p-3 text-sm text-gray-300">
                            <p>Conta de controle: {{ selectedCustomer.receivable_account.code }} - {{ selectedCustomer.receivable_account.name }}</p>
                            <p>Receita padrÃ£o: {{ selectedCustomer.default_revenue_account.code }} - {{ selectedCustomer.default_revenue_account.name }}</p>
                        </div>
                        <p class="mt-1 text-sm text-red-400">{{ accountReceivable.form.errors.customer_id }}</p>
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
