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
import { useOfxImport } from '@/composables/financial/useOfxImport';
import type { BankStatementAccount } from '@/types/financial/bankStatement';
import { ref, watch } from 'vue';

const props = defineProps<{
    bankAccount: BankStatementAccount;
}>();

const open = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const ofxImport = useOfxImport(props.bankAccount.id);

function resetForm() {
    ofxImport.form.reset('ofx_file');
    ofxImport.form.clearErrors();

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

watch(open, (isOpen) => {
    if (!isOpen && !ofxImport.form.processing) {
        resetForm();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button type="button" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">
                Importar OFX
            </button>
        </DialogTrigger>

        <DialogContent class="border-gray-700 bg-gray-950 text-white sm:max-w-lg">
            <form class="space-y-6" @submit.prevent="ofxImport.submit()">
                <DialogHeader class="space-y-3">
                    <DialogTitle>Importar OFX</DialogTitle>
                    <DialogDescription class="text-gray-400">
                        Importe o arquivo de {{ bankAccount.name }}. Movimentos idênticos serão vinculados; os demais serão criados em rascunho como A
                        classificar.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-2">
                    <label for="bank-statement-ofx-file" class="block text-sm font-semibold text-gray-300">Arquivo OFX</label>
                    <input
                        id="bank-statement-ofx-file"
                        ref="fileInput"
                        type="file"
                        accept=".ofx,.OFX"
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-sm text-white file:mr-4 file:rounded file:border-0 file:bg-gray-800 file:px-3 file:py-1 file:text-gray-200"
                        @change="ofxImport.selectFile"
                    />
                    <InputError :message="ofxImport.form.errors.ofx_file" />
                    <InputError :message="ofxImport.form.errors.bank_account_id" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <button
                            type="button"
                            :disabled="ofxImport.form.processing"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-50"
                        >
                            Cancelar
                        </button>
                    </DialogClose>

                    <button
                        type="submit"
                        :disabled="!ofxImport.canSubmit.value || ofxImport.form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ ofxImport.form.processing ? 'Importando...' : 'Importar arquivo' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
