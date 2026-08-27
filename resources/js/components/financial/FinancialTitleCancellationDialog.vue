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

const props = defineProps<{ routeName: string; titleId: number }>();
const open = ref(false);
const form = useForm({ reason: '' });

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
                <DialogFooter class="gap-2">
                    <DialogClose as-child
                        ><button type="button" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300">
                            Voltar
                        </button></DialogClose
                    >
                    <button
                        type="submit"
                        :disabled="form.processing || !form.reason.trim()"
                        class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Confirmar cancelamento
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
