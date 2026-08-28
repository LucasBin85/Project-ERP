<script setup lang="ts">
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
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    routeName: string;
    titleId: number;
    settlementStatus: 'draft' | 'posted';
    actionLabel: string;
    bankSettlement?: boolean;
}>();
const open = ref(false);
const form = useForm({ reason: '', reversal_date: '' });

function submit() {
    form.post(route(props.routeName, [props.titleId]), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button type="button" class="rounded-lg border border-red-700 px-4 py-2 text-sm font-semibold text-red-300 hover:bg-red-950/40">
                {{ actionLabel }}
            </button>
        </DialogTrigger>
        <DialogContent class="border-gray-700 bg-gray-950 text-white">
            <DialogHeader>
                <DialogTitle>{{ actionLabel }}</DialogTitle>
                <DialogDescription v-if="bankSettlement && settlementStatus === 'draft'" class="text-gray-400">
                    A classificação bancária será desfeita e o movimento voltará a ficar pendente de classificação. O movimento importado será
                    preservado.
                </DialogDescription>
                <DialogDescription v-else-if="bankSettlement" class="text-gray-400">
                    O movimento bancário original será preservado. Será criado um ajuste contábil para reabrir o título sem apagar o fato bancário.
                </DialogDescription>
                <DialogDescription v-else-if="settlementStatus === 'draft'" class="text-gray-400">
                    Esta liquidação ainda não foi contabilizada. O lançamento em rascunho será removido e o título voltará a ficar pendente.
                </DialogDescription>
                <DialogDescription v-else class="text-gray-400">
                    A liquidação já foi contabilizada. A reversão criará um lançamento contábil compensatório na data informada. O lançamento original
                    será preservado e o título voltará a ficar pendente.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label for="settlement-reversal-reason" class="mb-1 block text-sm font-semibold text-gray-300">Motivo da reversão</label>
                    <textarea
                        id="settlement-reversal-reason"
                        v-model="form.reason"
                        required
                        maxlength="1000"
                        rows="4"
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                    />
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-red-400">{{ form.errors.reason }}</p>
                </div>
                <div v-if="settlementStatus === 'posted'">
                    <label for="settlement-reversal-date" class="mb-1 block text-sm font-semibold text-gray-300">Data contábil da reversão</label>
                    <input
                        id="settlement-reversal-date"
                        v-model="form.reversal_date"
                        type="date"
                        required
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                    />
                    <p v-if="form.errors.reversal_date" class="mt-1 text-sm text-red-400">{{ form.errors.reversal_date }}</p>
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child
                        ><button type="button" class="rounded-lg border border-gray-600 px-4 py-2 text-sm text-gray-300">Voltar</button></DialogClose
                    >
                    <button
                        type="submit"
                        :disabled="form.processing || !form.reason.trim() || (settlementStatus === 'posted' && !form.reversal_date)"
                        class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Confirmar reversão
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
