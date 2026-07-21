import type { BankAccountCreateFormData, BankAccountOfxPreview } from '@/types/financial/bankAccount';
import type { InertiaForm } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

type FilledBankAccountField = 'bank_id' | 'name' | 'agency' | 'account_number' | 'account_type';

export function useBankAccountOfxPreview(bankAccountForm: InertiaForm<BankAccountCreateFormData>) {
    const preview = ref<BankAccountOfxPreview | null>(null);
    const selectedFileName = ref<string | null>(null);
    const conflictWarnings = ref<string[]>([]);
    const previewForm = useForm({
        ofx_file: null as File | null,
    });

    const processing = computed(() => previewForm.processing);
    const message = computed(() => preview.value?.message ?? null);
    const warnings = computed(() => [...(preview.value?.warnings ?? []), ...conflictWarnings.value]);
    const errorMessage = computed(() => {
        return Object.values(previewForm.errors).find((error) => Boolean(error)) ?? null;
    });

    function applyPreview(value: BankAccountOfxPreview) {
        preview.value = value;
        selectedFileName.value = value.file_name;
        conflictWarnings.value = [];

        const changedFields: FilledBankAccountField[] = [];
        const suggestion = value.suggested;

        if (value.matched_bank && suggestion.bank_id !== null && bankAccountForm.bank_id === null) {
            bankAccountForm.bank_id = suggestion.bank_id;
            changedFields.push('bank_id');
        } else if (suggestion.bank_id !== null && bankAccountForm.bank_id !== null && bankAccountForm.bank_id !== suggestion.bank_id) {
            conflictWarnings.value.push('O banco já preenchido foi mantido porque diverge do arquivo do extrato.');
        }

        if (suggestion.agency !== null && !bankAccountForm.agency.trim()) {
            bankAccountForm.agency = suggestion.agency;
            changedFields.push('agency');
        } else if (suggestion.agency !== null && bankAccountForm.agency.trim() !== suggestion.agency) {
            conflictWarnings.value.push('A agência já preenchida foi mantida porque diverge do arquivo do extrato.');
        }

        if (suggestion.account_number !== null && !bankAccountForm.account_number.trim()) {
            bankAccountForm.account_number = suggestion.account_number;
            changedFields.push('account_number');
        } else if (suggestion.account_number !== null && bankAccountForm.account_number.trim() !== suggestion.account_number) {
            conflictWarnings.value.push('O número da conta já preenchido foi mantido porque diverge do arquivo do extrato.');
        }

        if (suggestion.account_type !== null && bankAccountForm.account_type === 'checking') {
            bankAccountForm.account_type = suggestion.account_type;
            changedFields.push('account_type');
        }

        if (!bankAccountForm.name.trim() && suggestion.name?.trim()) {
            bankAccountForm.name = suggestion.name;
            changedFields.push('name');
        }

        if (changedFields.length > 0) {
            const clearChangedFieldErrors = bankAccountForm.clearErrors.bind(bankAccountForm) as (...fields: FilledBankAccountField[]) => void;

            clearChangedFieldErrors(...changedFields);
        }
    }

    function selectFile(event: Event) {
        const target = event.target as HTMLInputElement;
        const file = target.files?.[0] ?? null;

        if (!file || previewForm.processing) return;

        preview.value = null;
        selectedFileName.value = file.name;
        previewForm.ofx_file = file;
        previewForm.clearErrors();

        previewForm.post(route('bank-accounts.ofx-preview'), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const result = page.props.bankAccountOfxPreview as BankAccountOfxPreview | null | undefined;

                if (!result) {
                    previewForm.setError('ofx_file', 'Não foi possível obter os dados da conta neste arquivo do extrato.');

                    return;
                }

                applyPreview(result);
            },
            onFinish: () => {
                previewForm.ofx_file = null;
            },
        });

        target.value = '';
    }

    return {
        preview,
        previewForm,
        selectedFileName,
        processing,
        message,
        warnings,
        errorMessage,
        applyPreview,
        selectFile,
    };
}
