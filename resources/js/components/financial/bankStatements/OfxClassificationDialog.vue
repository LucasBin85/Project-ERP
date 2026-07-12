<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { BankStatementAccount, BankStatementClassificationAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    transaction: BankStatementTransaction;
    bankAccount: BankStatementAccount;
    classificationAccounts: BankStatementClassificationAccount[];
}>();

type OfxClassificationForm = {
    chart_of_account_id: string;
    should_post: boolean;
};

const open = ref(false);
const form = useForm<OfxClassificationForm>({
    chart_of_account_id: '',
    should_post: false,
});

function resetForm() {
    form.reset();
    form.clearErrors();
}

function submit(shouldPost: boolean) {
    if (!form.chart_of_account_id || !props.transaction.journal_entry_id) return;

    form.should_post = shouldPost;
    form.post(route('bank-accounts.statement.classify', [props.bankAccount.id, props.transaction.journal_entry_id]), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            resetForm();
        },
    });
}

watch(open, (isOpen) => {
    if (!isOpen && !form.processing) {
        resetForm();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button type="button" class="mt-2 text-xs font-semibold text-indigo-300 hover:text-indigo-200">Classificar</button>
        </DialogTrigger>

        <DialogContent class="border-gray-700 bg-gray-950 text-white sm:max-w-xl">
            <form class="space-y-6" @submit.prevent="submit(false)">
                <DialogHeader class="space-y-3">
                    <DialogTitle>Classificar lançamento OFX</DialogTitle>
                    <DialogDescription class="text-gray-400">
                        Substitua a conta A classificar por uma conta contábil definitiva. A linha da conta bancária não será alterada.
                    </DialogDescription>
                </DialogHeader>

                <dl class="grid grid-cols-1 gap-4 rounded-xl border border-gray-800 bg-black/30 p-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Data</dt>
                        <dd class="mt-1 font-semibold text-gray-100">{{ formatDate(transaction.date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Direção</dt>
                        <dd class="mt-1 font-semibold text-gray-100">{{ transaction.type === 'inflow' ? 'Entrada' : 'Saída' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Descrição</dt>
                        <dd class="mt-1 font-semibold text-gray-100">{{ transaction.description || 'Sem descrição' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Valor</dt>
                        <dd class="mt-1 font-semibold text-gray-100">{{ formatCurrency(Math.abs(transaction.amount_cents)) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Conta bancária</dt>
                        <dd class="mt-1 font-semibold text-gray-100">{{ bankAccount.name }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Conta atual</dt>
                        <dd class="mt-1 font-semibold text-yellow-300">A classificar</dd>
                    </div>
                </dl>

                <div class="space-y-2">
                    <label :for="`ofx-classification-account-${transaction.id}`" class="block text-sm font-semibold text-gray-300">
                        Conta contábil de destino
                    </label>
                    <select
                        :id="`ofx-classification-account-${transaction.id}`"
                        v-model="form.chart_of_account_id"
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                    >
                        <option value="">Selecione uma conta</option>
                        <option v-for="account in classificationAccounts" :key="account.id" :value="String(account.id)">
                            {{ account.code }} - {{ account.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.chart_of_account_id" />
                </div>

                <DialogFooter class="gap-2 sm:flex-wrap">
                    <DialogClose as-child>
                        <button
                            type="button"
                            :disabled="form.processing"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-50"
                        >
                            Cancelar
                        </button>
                    </DialogClose>

                    <button
                        type="submit"
                        :disabled="!form.chart_of_account_id || form.processing"
                        class="rounded-lg border border-indigo-500 px-4 py-2 text-sm font-semibold text-indigo-200 hover:bg-indigo-950 disabled:opacity-50"
                    >
                        Classificar
                    </button>

                    <button
                        type="button"
                        :disabled="!form.chart_of_account_id || form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        @click="submit(true)"
                    >
                        Classificar e postar
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
