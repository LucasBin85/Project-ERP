<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useOfxImport } from '@/composables/financial/useOfxImport';
import type { BankStatementAccount } from '@/types/financial/bankStatement';
import type { OfxAccountDetails, OfxImportPreview, OfxImportSituation, OfxPreviewRow } from '@/types/financial/ofxImport';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    bankAccount: BankStatementAccount;
    initialPreview?: OfxImportPreview | null;
}>();

const open = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const completedMessage = ref<string | null>(null);
const accountDetailsOpen = ref(false);
const ofxImport = useOfxImport(props.bankAccount.id);
let closeTimer: number | null = null;

const situationLabels: Record<OfxImportSituation, string> = {
    new: 'Novo lançamento',
    already_imported: 'Já importado',
    possible_match: 'Possível vínculo encontrado',
    ambiguous_match: 'Vínculo ambíguo',
    ignored: 'Ignorado',
    error: 'Erro',
};

const accountStatusLabels: Record<OfxImportPreview['account_validation']['status'], string> = {
    validated: 'Conta validada',
    unverified: 'Conta não validada',
    mismatched: 'Conta divergente',
};

const accountStatusClasses: Record<OfxImportPreview['account_validation']['status'], string> = {
    validated: 'border-green-500/30 bg-green-950/30 text-green-200',
    unverified: 'border-amber-500/30 bg-amber-950/30 text-amber-200',
    mismatched: 'border-red-500/30 bg-red-950/40 text-red-200',
};

const accountFields = [
    { key: 'bank_name', label: 'Banco' },
    { key: 'bank_code', label: 'Código do banco' },
    { key: 'ispb', label: 'ISPB / roteamento' },
    { key: 'agency', label: 'Agência' },
    { key: 'account_number', label: 'Conta' },
    { key: 'account_type', label: 'Tipo' },
] satisfies Array<{ key: keyof OfxAccountDetails; label: string }>;

const situationClasses: Record<OfxImportSituation, string> = {
    new: 'border-blue-500/30 bg-blue-950/40 text-blue-300',
    already_imported: 'border-gray-600 bg-gray-800 text-gray-300',
    possible_match: 'border-green-500/30 bg-green-950/40 text-green-300',
    ambiguous_match: 'border-amber-500/30 bg-amber-950/40 text-amber-300',
    ignored: 'border-gray-600 bg-gray-900 text-gray-400',
    error: 'border-red-500/30 bg-red-950/40 text-red-300',
};

const displayRows = computed(() => {
    if (!ofxImport.preview.value) return [];

    return ofxImport.preview.value.rows.flatMap((row) => {
        const decision = ofxImport.decisions.value.find((item) => item.row_key === row.row_key);

        return decision ? [{ row, decision }] : [];
    });
});

function formatDate(date: string | null): string {
    if (!date) return '—';

    return new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));
}

function formatValue(row: OfxPreviewRow): string {
    if (row.amount_cents === null) return '—';

    const absoluteValue = Math.abs(row.amount_cents) / 100;
    const formatted = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(absoluteValue);

    return row.direction === 'out' ? `-${formatted}` : formatted;
}

function accountValue(account: OfxAccountDetails, key: keyof OfxAccountDetails): string {
    const value = account[key];

    return value === null || value === undefined || value === '' ? 'Não informado' : String(value);
}

function summaryCount(situation: OfxImportSituation): number {
    return ofxImport.preview.value?.summary[situation] ?? 0;
}

function resetDialog() {
    if (closeTimer !== null) {
        window.clearTimeout(closeTimer);
        closeTimer = null;
    }

    completedMessage.value = null;
    accountDetailsOpen.value = false;
    ofxImport.reset();

    if (fileInput.value) fileInput.value.value = '';
}

function handleOpenChange(value: boolean) {
    if (!value && ofxImport.processing.value) return;

    open.value = value;
    if (!value) resetDialog();
}

function backToUpload() {
    resetDialog();
}

function confirmImport() {
    ofxImport.confirm((message) => {
        completedMessage.value = message ?? 'Importação do extrato concluída com sucesso.';
        closeTimer = window.setTimeout(() => {
            open.value = false;
            resetDialog();
        }, 1200);
    });
}

watch(
    () => props.initialPreview,
    (preview) => {
        if (!preview) return;

        ofxImport.setPreview(preview);
        open.value = true;
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogTrigger as-child>
            <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Importar extrato
            </button>
        </DialogTrigger>

        <DialogContent class="h-[94vh] w-[96vw] max-w-[96vw] overflow-hidden border-gray-700 bg-gray-950 text-white sm:max-w-[96vw] xl:max-w-[1500px]">
            <div class="flex h-[calc(94vh-3rem)] min-h-0 flex-col gap-4">
                <DialogHeader class="space-y-3">
                    <DialogTitle>Importar extrato</DialogTitle>
                    <DialogDescription class="text-gray-400">
                        Revise as transações de {{ bankAccount.name }} antes de criar ou vincular lançamentos.
                    </DialogDescription>
                </DialogHeader>

                <ol class="grid grid-cols-2 gap-2 text-xs font-semibold sm:grid-cols-4 sm:text-sm">
                    <li
                        class="rounded-lg border px-3 py-2"
                        :class="
                            !ofxImport.preview.value && !completedMessage
                                ? 'border-indigo-500 bg-indigo-950/40 text-indigo-300'
                                : 'border-gray-700 text-gray-500'
                        "
                    >
                        1. Arquivo
                    </li>
                    <li
                        class="rounded-lg border px-3 py-2"
                        :class="
                            ofxImport.preview.value && !ofxImport.confirmationForm.processing && !completedMessage
                                ? 'border-indigo-500 bg-indigo-950/40 text-indigo-300'
                                : 'border-gray-700 text-gray-500'
                        "
                    >
                        2. Pré-visualização
                    </li>
                    <li
                        class="rounded-lg border px-3 py-2"
                        :class="
                            ofxImport.confirmationForm.processing
                                ? 'border-indigo-500 bg-indigo-950/40 text-indigo-300'
                                : 'border-gray-700 text-gray-500'
                        "
                    >
                        3. Confirmação
                    </li>
                    <li
                        class="rounded-lg border px-3 py-2"
                        :class="completedMessage ? 'border-green-500 bg-green-950/40 text-green-300' : 'border-gray-700 text-gray-500'"
                    >
                        4. Resultado
                    </li>
                </ol>

                <div
                    v-if="ofxImport.errorMessage.value"
                    role="alert"
                    class="rounded-lg border border-red-500/30 bg-red-950/40 px-4 py-3 text-sm text-red-300"
                >
                    {{ ofxImport.errorMessage.value }}
                </div>

                <div
                    v-if="completedMessage"
                    role="status"
                    class="rounded-xl border border-green-500/30 bg-green-950/30 px-5 py-8 text-center text-green-200"
                >
                    <p class="text-base font-bold">Importação concluída</p>
                    <p class="mt-2 text-sm">{{ completedMessage }}</p>
                    <p class="mt-3 text-xs text-green-300/80">O Extrato foi atualizado.</p>
                </div>

                <form v-else-if="!ofxImport.preview.value" class="space-y-6" @submit.prevent="ofxImport.loadPreview()">
                    <div class="space-y-2">
                        <label for="bank-statement-ofx-file" class="block text-sm font-semibold text-gray-300">Arquivo do extrato</label>
                        <input
                            id="bank-statement-ofx-file"
                            ref="fileInput"
                            type="file"
                            accept=".ofx,.OFX,.csv,.CSV,.pdf,.PDF"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-sm text-white file:mr-4 file:rounded file:border-0 file:bg-gray-800 file:px-3 file:py-1 file:text-gray-200"
                            @change="ofxImport.selectFile"
                        />
                        <p class="text-xs text-gray-500">Formatos aceitos: OFX, CSV e PDF textual. Nenhum lançamento será criado durante a pré-visualização.</p>
                        <InputError :message="ofxImport.previewForm.errors.ofx_file" />
                        <InputError :message="ofxImport.previewForm.errors.bank_account_id" />
                    </div>

                    <DialogFooter class="gap-2">
                        <button
                            type="button"
                            :disabled="ofxImport.processing.value"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-50"
                            @click="handleOpenChange(false)"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="!ofxImport.canPreview.value || ofxImport.processing.value"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ ofxImport.previewForm.processing ? 'Gerando pré-visualização...' : 'Pré-visualizar' }}
                        </button>
                    </DialogFooter>
                </form>

                <template v-else>
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-800 bg-gray-900/50 px-4 py-3 text-sm">
                        <div>
                            <span class="font-semibold text-white">{{ ofxImport.preview.value.file_name }}</span>
                            <span class="ml-2 rounded bg-gray-800 px-2 py-1 text-xs font-semibold text-gray-300">{{ ofxImport.preview.value.origin }}</span>
                            <span class="ml-2 text-gray-400">{{ ofxImport.preview.value.rows.length }} transações</span>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs text-gray-300">
                            <span class="rounded-full bg-blue-950/50 px-2.5 py-1">{{ summaryCount('new') }} novas</span>
                            <span class="rounded-full bg-green-950/50 px-2.5 py-1"> {{ summaryCount('possible_match') }} possíveis vínculos </span>
                            <span class="rounded-full bg-gray-800 px-2.5 py-1"> {{ summaryCount('already_imported') }} já importadas </span>
                        </div>
                    </div>

                    <section class="rounded-xl border px-4 py-3" :class="accountStatusClasses[ofxImport.preview.value.account_validation.status]">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold tracking-wide uppercase opacity-70">Validação bancária</p>
                                <p class="text-sm font-bold">
                                    {{ accountStatusLabels[ofxImport.preview.value.account_validation.status] }}
                                </p>
                                <p class="mt-1 text-sm opacity-90">{{ ofxImport.preview.value.account_validation.message }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span v-if="ofxImport.preview.value.account_validation.blocking" class="rounded-full border border-red-400/40 px-2.5 py-1 text-xs font-bold">Importação bloqueada</span>
                                <button type="button" class="rounded border border-current/30 px-2.5 py-1 text-xs font-semibold hover:bg-black/10" @click="accountDetailsOpen = !accountDetailsOpen">
                                    {{ accountDetailsOpen ? 'Ocultar detalhes' : 'Ver detalhes' }}
                                </button>
                            </div>
                        </div>

                        <div v-if="accountDetailsOpen" class="mt-4 grid gap-4 text-xs lg:grid-cols-2">
                            <div class="rounded-lg bg-black/20 p-3">
                                <p class="mb-2 font-bold tracking-wide uppercase">Conta atual</p>
                                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                                    <template v-for="field in accountFields" :key="`current-${field.key}`">
                                        <dt class="opacity-70">{{ field.label }}</dt>
                                        <dd class="font-semibold">
                                            {{ accountValue(ofxImport.preview.value.account_validation.current_account, field.key) }}
                                        </dd>
                                    </template>
                                </dl>
                            </div>
                            <div class="rounded-lg bg-black/20 p-3">
                                <p class="mb-2 font-bold tracking-wide uppercase">Conta identificada no extrato</p>
                                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                                    <template v-for="field in accountFields" :key="`ofx-${field.key}`">
                                        <dt class="opacity-70">{{ field.label }}</dt>
                                        <dd class="font-semibold">
                                            {{ accountValue(ofxImport.preview.value.account_validation.ofx_account, field.key) }}
                                        </dd>
                                    </template>
                                </dl>
                            </div>
                        </div>

                        <ul
                            v-if="accountDetailsOpen && ofxImport.preview.value.account_validation.warnings.length"
                            class="mt-3 list-disc space-y-1 pl-5 text-xs opacity-80"
                        >
                            <li v-for="warning in ofxImport.preview.value.account_validation.warnings" :key="warning">{{ warning }}</li>
                        </ul>
                    </section>

                    <div class="min-h-[18rem] max-h-[52vh] flex-1 overflow-auto rounded-lg border border-gray-700">
                        <table class="w-full min-w-[1000px] table-fixed divide-y divide-gray-700 text-left text-sm">
                            <thead class="sticky top-0 z-10 bg-gray-900 text-xs tracking-wide text-gray-400 uppercase">
                                <tr>
                                    <th class="w-32 px-4 py-3">Data</th>
                                    <th class="w-[42%] min-w-96 px-4 py-3">Descrição</th>
                                    <th class="w-40 px-4 py-3 text-right">Valor</th>
                                    <th class="w-28 px-4 py-3">Direção</th>
                                    <th class="w-72 px-4 py-3">Situação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-800 bg-gray-950">
                                <tr v-for="item in displayRows" :key="item.row.row_key" class="align-top">
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-300">{{ formatDate(item.row.date) }}</td>
                                    <td class="min-w-96 px-4 py-3">
                                        <span class="block whitespace-normal font-medium text-white" :title="item.row.description">{{
                                            item.row.description
                                        }}</span>
                                        <span v-if="item.row.situation === 'error'" class="mt-1 block text-xs text-red-300">
                                            {{ item.row.suggestion.label }}
                                        </span>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-semibold whitespace-nowrap"
                                        :class="
                                            item.row.direction === 'in'
                                                ? 'text-green-400'
                                                : item.row.direction === 'out'
                                                  ? 'text-red-400'
                                                  : 'text-gray-400'
                                        "
                                    >
                                        {{ formatValue(item.row) }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-300">
                                        {{ item.row.direction === 'in' ? 'Entrada' : item.row.direction === 'out' ? 'Saída' : '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold"
                                            :class="situationClasses[item.row.situation]"
                                        >
                                            {{ situationLabels[item.row.situation] }}
                                        </span>
                                        <p v-if="item.row.suggestion.label" class="mt-1 text-xs leading-5 text-gray-400">
                                            {{ item.row.suggestion.label }}
                                        </p>
                                        <select
                                            v-if="item.row.situation === 'ambiguous_match'"
                                            v-model="item.decision.action"
                                            class="mt-2 rounded-lg border border-gray-700 bg-black px-2 py-1.5 text-xs text-white"
                                        >
                                            <option value="ignore">Ignorar</option>
                                            <option value="create">Criar novo</option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="ofxImport.hasPreviewErrors.value" class="text-sm text-red-300">
                        Corrija o arquivo e gere uma nova pré-visualização. Nenhum lançamento será criado enquanto houver linhas com erro.
                    </p>

                    <DialogFooter class="gap-2">
                        <button
                            type="button"
                            :disabled="ofxImport.processing.value"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-50"
                            @click="backToUpload"
                        >
                            Trocar arquivo
                        </button>
                        <button
                            type="button"
                            :disabled="!ofxImport.canConfirm.value || ofxImport.processing.value"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="confirmImport"
                        >
                            {{ ofxImport.confirmationForm.processing ? 'Confirmando importação...' : 'Confirmar importação' }}
                        </button>
                    </DialogFooter>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
