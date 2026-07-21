<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { BankAccountCreateFormData, BankAccountType, BankOption } from '@/types/financial/bankAccount';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from 'ziggy-js';

type BankAccountCreateFormField =
    | 'bank_id'
    | 'name'
    | 'agency'
    | 'account_number'
    | 'account_type'
    | 'opening_balance'
    | 'opening_balance_cents'
    | 'opening_balance_date';

type BankAccountCreateFormView = Pick<BankAccountCreateFormData, BankAccountCreateFormField> & {
    errors: Partial<Record<BankAccountCreateFormField, string>>;
};

const props = withDefaults(
    defineProps<{
        form: BankAccountCreateFormView;
        banks?: BankOption[];
        isDuplicateName?: boolean;
        isDuplicateBankAccount?: boolean;
        canSubmit?: boolean;
        ofxProcessing?: boolean;
        ofxSelectedFileName?: string | null;
        ofxMessage?: string | null;
        ofxWarnings?: string[];
        ofxError?: string | null;
    }>(),
    {
        banks: () => [],
        isDuplicateName: false,
        isDuplicateBankAccount: false,
        canSubmit: false,
        ofxProcessing: false,
        ofxSelectedFileName: null,
        ofxMessage: null,
        ofxWarnings: () => [],
        ofxError: null,
    },
);

const emit = defineEmits<{
    submit: [];
    'select-ofx-file': [event: Event];
    'update-bank-id': [bankId: number];
    'update-name': [name: string];
    'update-account-type': [accountType: BankAccountType];
    'update-opening-balance-date': [date: string];
    'update-only-numbers': [field: 'agency' | 'account_number', event: Event];
    'update-opening-balance': [event: Event];
}>();

const ofxFileInput = ref<HTMLInputElement | null>(null);

function inputValue(event: Event): string {
    return (event.target as HTMLInputElement | HTMLSelectElement).value;
}

function accountTypeValue(event: Event): BankAccountType {
    return inputValue(event) as BankAccountType;
}

function chooseOfxFile() {
    if (props.ofxProcessing) return;

    ofxFileInput.value?.click();
}
</script>

<template>
    <form class="space-y-6 p-6" @submit.prevent="emit('submit')">
        <section class="rounded-xl border border-indigo-500/30 bg-indigo-950/20 p-4">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-sm font-bold text-indigo-100">Preencher dados usando um arquivo do extrato</h3>
                    <p id="bank-account-ofx-help" class="mt-1 max-w-3xl text-sm leading-6 text-indigo-200/80">
                        O arquivo será usado apenas para preencher os dados da conta. Nenhuma transação ou lançamento contábil será importado, e a
                        conta bancária não será criada automaticamente.
                    </p>
                </div>

                <input
                    ref="ofxFileInput"
                    type="file"
                    accept=".ofx,.OFX,.csv,.CSV,.pdf,.PDF"
                    class="sr-only"
                    :disabled="ofxProcessing"
                    aria-describedby="bank-account-ofx-help"
                    @change="emit('select-ofx-file', $event)"
                />

                <button
                    type="button"
                    :disabled="ofxProcessing"
                    class="shrink-0 rounded-lg border border-indigo-400/50 bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="chooseOfxFile"
                >
                    {{ ofxProcessing ? 'Lendo extrato...' : 'Preencher com extrato' }}
                </button>
            </div>

            <p class="mt-3 text-xs text-indigo-200/70">Arquivo do extrato · Formatos aceitos: OFX, CSV e PDF textual/OCR.</p>
            <p v-if="ofxSelectedFileName" class="mt-1 text-xs text-indigo-200/70">Arquivo selecionado: {{ ofxSelectedFileName }}</p>
            <InputError class="mt-2" :message="ofxError ?? undefined" />
        </section>

        <div v-if="ofxMessage" role="status" class="rounded-lg border border-green-500/30 bg-green-950/30 px-4 py-3 text-sm text-green-200">
            {{ ofxMessage }}
        </div>

        <div v-if="ofxWarnings.length" role="alert" class="rounded-lg border border-amber-500/30 bg-amber-950/30 px-4 py-3 text-sm text-amber-200">
            <p class="font-semibold">Revise os dados preenchidos</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li v-for="warning in ofxWarnings" :key="warning">{{ warning }}</li>
            </ul>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Banco</label>
                <select
                    :value="form.bank_id ?? ''"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    @change="emit('update-bank-id', Number(inputValue($event)))"
                >
                    <option value="" disabled>Selecione um banco...</option>
                    <option v-for="bank in banks" :key="bank.id" :value="bank.id">{{ bank.code }} - {{ bank.short_name }}</option>
                </select>
                <p class="mt-1 text-sm text-red-400">{{ form.errors.bank_id }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Nome da conta</label>
                <input
                    :value="form.name"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    :class="isDuplicateName ? 'border-red-500' : ''"
                    placeholder="Ex: Conta principal"
                    @input="emit('update-name', inputValue($event))"
                />
                <p v-if="isDuplicateName" class="mt-1 text-sm text-red-400">Já existe uma conta bancária com esse nome.</p>
                <p class="mt-1 text-sm text-red-400">{{ form.errors.name }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Agência</label>
                <input
                    :value="form.agency"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    placeholder="Ex: 0001"
                    inputmode="numeric"
                    @input="emit('update-only-numbers', 'agency', $event)"
                />
                <p class="mt-1 text-sm text-red-400">{{ form.errors.agency }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Número da conta</label>
                <input
                    :value="form.account_number"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    :class="isDuplicateBankAccount ? 'border-red-500' : ''"
                    placeholder="Ex: 123456"
                    inputmode="numeric"
                    @input="emit('update-only-numbers', 'account_number', $event)"
                />
                <p v-if="isDuplicateBankAccount" class="mt-1 text-sm text-red-400">
                    Já existe uma conta bancária deste banco com a mesma agência e número.
                </p>
                <p class="mt-1 text-sm text-red-400">{{ form.errors.account_number }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Tipo</label>
                <select
                    :value="form.account_type"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    @change="emit('update-account-type', accountTypeValue($event))"
                >
                    <option value="checking">Conta corrente</option>
                    <option value="savings">Poupança</option>
                    <option value="investment">Investimento</option>
                    <option value="cash">Caixa</option>
                    <option value="other">Outra</option>
                </select>
                <p class="mt-1 text-sm text-red-400">{{ form.errors.account_type }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Saldo inicial</label>
                <input
                    :value="form.opening_balance"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    @input="emit('update-opening-balance', $event)"
                />
                <p class="mt-1 text-sm text-red-400">{{ form.errors.opening_balance_cents }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Data do saldo inicial</label>
                <input
                    :value="form.opening_balance_date"
                    type="date"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    @input="emit('update-opening-balance-date', inputValue($event))"
                />
                <p class="mt-1 text-sm text-red-400">{{ form.errors.opening_balance_date }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-blue-900 bg-blue-950/30 p-4 text-sm text-blue-200">
            A conta contábil será criada automaticamente como filha de:
            <strong>1.1.2 Bancos</strong>.
        </div>

        <div class="flex justify-end gap-3">
            <Link
                :href="route('bank-accounts.index')"
                class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
            >
                Cancelar
            </Link>

            <button
                type="submit"
                :disabled="!canSubmit"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Salvar
            </button>
        </div>
    </form>
</template>
