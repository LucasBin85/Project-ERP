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

const props = defineProps<{ routeName: string; titleId: number; requiresReversal?: boolean }>();
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
        <DialogTrigger as-child
            ><button type="button" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600">
                Cancelar título
            </button></DialogTrigger
        >
        <DialogContent class="border-gray-700 bg-gray-950 text-white">
            <DialogHeader>
                <DialogTitle>Cancelar este título?</DialogTitle>
                <DialogDescription class="text-gray-400"
                    >O cancelamento é permanente para este título. Se a provisão já tiver sido contabilizada, será necessário um fluxo de reversão e
                    esta operação será recusada.</DialogDescription
                >
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <p v-if="requiresReversal" class="rounded-lg border border-amber-700 bg-amber-950/40 p-3 text-sm text-amber-200">
                    A provisão deste título já foi contabilizada. O cancelamento criará um lançamento contábil compensatório na data informada. O
                    lançamento original será preservado.
                </p>
                <div>
                    <label for="cancellation-reason" class="mb-1 block text-sm font-semibold text-gray-300">Motivo do cancelamento</label>
                    <textarea
                        id="cancellation-reason"
                        v-model="form.reason"
                        required
                        maxlength="1000"
                        rows="4"
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                    />
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-red-400">{{ form.errors.reason }}</p>
                </div>
                <div v-if="requiresReversal">
                    <label for="reversal-date" class="mb-1 block text-sm font-semibold text-gray-300">Data contábil do estorno</label>
                    <input
                        id="reversal-date"
                        v-model="form.reversal_date"
                        type="date"
                        required
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                    />
                    <p v-if="form.errors.reversal_date" class="mt-1 text-sm text-red-400">{{ form.errors.reversal_date }}</p>
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child
                        ><button type="button" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300">
                            Voltar
                        </button></DialogClose
                    >
                    <button
                        type="submit"
                        :disabled="form.processing || !form.reason.trim() || (requiresReversal && !form.reversal_date)"
                        class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Confirmar cancelamento
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
