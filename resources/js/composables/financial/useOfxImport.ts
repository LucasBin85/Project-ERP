import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

export function useOfxImport(defaultBankAccountId: number | string | null = null) {
    const form = useForm({
        bank_account_id: defaultBankAccountId ? String(defaultBankAccountId) : '',
        ofx_file: null as File | null,
    });

    const canSubmit = computed(() => Boolean(form.bank_account_id && form.ofx_file));

    function selectFile(event: Event) {
        const target = event.target as HTMLInputElement;
        form.ofx_file = target.files?.[0] ?? null;
    }

    function submit() {
        if (!canSubmit.value) return;

        form.post(route('ofx-imports.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('ofx_file');
            },
        });
    }

    return {
        form,
        canSubmit,
        selectFile,
        submit,
    };
}
