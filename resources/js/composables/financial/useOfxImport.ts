import type { OfxImportDecision, OfxImportPreview, OfxPreviewRow } from '@/types/financial/ofxImport';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

interface OfxImportDecisionPayload {
    [key: string]: string | number | null;
    row_key: string;
    action: OfxImportDecision['action'];
}

function defaultAction(row: OfxPreviewRow): OfxImportDecision['action'] {
    return row.default_action;
}

function decisionsFromPreview(preview: OfxImportPreview): OfxImportDecision[] {
    return preview.rows.map((row) => ({
        row_key: row.row_key,
        action: defaultAction(row),
    }));
}

export function useOfxImport(defaultBankAccountId: number | string) {
    const preview = ref<OfxImportPreview | null>(null);
    const decisions = ref<OfxImportDecision[]>([]);

    const previewForm = useForm({
        bank_account_id: String(defaultBankAccountId),
        ofx_file: null as File | null,
    });

    const confirmationForm = useForm({
        preview_token: '',
        rows: [] as OfxImportDecisionPayload[],
    });

    const canPreview = computed(() => Boolean(previewForm.bank_account_id && previewForm.ofx_file));
    const hasPreviewErrors = computed(() => preview.value?.rows.some((row) => row.situation === 'error') ?? false);
    const canConfirm = computed(() =>
        Boolean(preview.value?.token && decisions.value.length && !hasPreviewErrors.value && !preview.value.account_validation.blocking),
    );
    const processing = computed(() => previewForm.processing || confirmationForm.processing);
    const errorMessage = computed(() => {
        const errors = { ...previewForm.errors, ...confirmationForm.errors };

        return Object.values(errors).find((error) => Boolean(error)) ?? null;
    });

    function setPreview(value: OfxImportPreview | null | undefined) {
        preview.value = value ?? null;
        decisions.value = value ? decisionsFromPreview(value) : [];
    }

    function selectFile(event: Event) {
        const target = event.target as HTMLInputElement;
        previewForm.ofx_file = target.files?.[0] ?? null;
        previewForm.clearErrors();
        confirmationForm.clearErrors();
        setPreview(null);
    }

    function loadPreview() {
        if (!canPreview.value) return;

        previewForm.post(route('ofx-imports.preview'), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                setPreview((page.props.ofxPreview as OfxImportPreview | null | undefined) ?? null);
            },
        });
    }

    function confirm(onSuccess?: (message: string | null) => void) {
        if (!canConfirm.value || !preview.value) return;

        confirmationForm.preview_token = preview.value.token;
        confirmationForm.rows = decisions.value.map((decision) => ({ ...decision }));
        confirmationForm.post(route('ofx-imports.confirm'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { success?: string | null } | undefined;

                onSuccess?.(flash?.success ?? null);
            },
        });
    }

    function reset() {
        previewForm.reset('ofx_file');
        previewForm.clearErrors();
        confirmationForm.reset();
        confirmationForm.clearErrors();
        setPreview(null);
    }

    return {
        preview,
        decisions,
        previewForm,
        confirmationForm,
        canPreview,
        canConfirm,
        hasPreviewErrors,
        processing,
        errorMessage,
        setPreview,
        selectFile,
        loadPreview,
        confirm,
        reset,
    };
}
