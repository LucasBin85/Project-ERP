<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useAccountPayableCreate } from '@/composables/financial/useAccountPayableCreate';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed } from 'vue';
import { ref } from 'vue';
import SupplierQuickCreateDialog from '@/components/financial/counterparties/SupplierQuickCreateDialog.vue';

const props = defineProps<{
    wallet: Record<string, any>;
    suppliers: Array<Record<string, any>>;
    payableControlAccounts: Array<Record<string, any>>;
    expenseAccounts: Array<Record<string, any>>;
    supplierNames: string[];
}>();

const accountPayable = useAccountPayableCreate();
const suppliers = ref([...props.suppliers]);
const supplierNames = ref([...props.supplierNames]);
const showSupplierDialog = ref(false);
const selectedSupplier = computed(() => suppliers.value.find((supplier) => supplier.id === Number(accountPayable.form.supplier_id)));

function supplierCreated(supplier: Record<string, any>) {
    suppliers.value.push(supplier);
    supplierNames.value.push(supplier.name);
    suppliers.value.sort((a, b) => a.name.localeCompare(b.name));
    accountPayable.form.supplier_id = String(supplier.id);
}

function submit() {
    if (!accountPayable.canSubmit.value) {
        return;
    }

    accountPayable.form.post(route('accounts-payable.store'));
}
</script>

<template>
    <AppLayout title="Novo título a pagar">
        <ReportPage title="Novo título a pagar" :subtitle="props.wallet?.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Dados do título
                        </h2>

                        <p class="mt-1 text-sm text-gray-400">
                            O cadastro cria uma provisão contábil em rascunho para posterior postagem.
                        </p>
                    </div>

                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Fornecedor / Beneficiário</label>
                        <div class="flex gap-2"><select v-model="accountPayable.form.supplier_id" class="min-w-0 flex-1 rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="">Selecione o fornecedor</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select><button type="button" class="rounded-lg border border-indigo-500 px-3 py-2 text-sm text-indigo-300" @click="showSupplierDialog = true">Cadastrar fornecedor</button></div>
                        <div v-if="selectedSupplier" class="mt-2 space-y-1 rounded-lg border border-gray-700 bg-gray-950 p-3 text-sm text-gray-300">
                            <p>Conta de controle: {{ selectedSupplier.payable_account.code }} - {{ selectedSupplier.payable_account.name }}</p>
                            <p>Despesa padrÃ£o: {{ selectedSupplier.default_expense_account.code }} - {{ selectedSupplier.default_expense_account.name }}</p>
                        </div>
                        <p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.supplier_id }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Descrição</label>
                        <input
                            v-model="accountPayable.form.description"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            placeholder="Ex: Conta de energia julho"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.description }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Vencimento</label>
                        <input
                            v-model="accountPayable.form.due_date"
                            type="date"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.due_date }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Valor</label>
                        <input
                            :value="accountPayable.form.amount"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            placeholder="R$ 0,00"
                            inputmode="numeric"
                            @input="accountPayable.updateAmount"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.amount_cents }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label>
                        <textarea
                            v-model="accountPayable.form.notes"
                            rows="3"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            placeholder="Opcional"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.notes }}</p>
                    </div>

                    <div class="md:col-span-2 flex justify-end gap-3">
                        <Link
                            :href="route('accounts-payable.index')"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                        >
                            Cancelar
                        </Link>

                        <button
                            type="submit"
                            :disabled="!accountPayable.canSubmit.value || accountPayable.form.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Salvar título a pagar
                        </button>
                    </div>
                </form>
            </ReportSection>
        </ReportPage>
        <SupplierQuickCreateDialog :show="showSupplierDialog" :control-accounts="payableControlAccounts" :expense-accounts="expenseAccounts" :existing-names="supplierNames" @close="showSupplierDialog = false" @created="supplierCreated" />
    </AppLayout>
</template>
