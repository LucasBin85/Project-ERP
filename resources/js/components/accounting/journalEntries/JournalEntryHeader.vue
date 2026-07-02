<script setup>
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const props = defineProps({
    entry: { type: Object, required: true },
})
</script>

<template>
    <div>
        <Link
            :href="route('journal-entries.index')"
            class="text-sm font-medium text-blue-400 hover:text-blue-300"
        >
            ← Voltar para lançamentos
        </Link>
    </div>

    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
        <div>
            <h1 class="text-3xl font-bold text-white">
                Lançamento #{{ props.entry.id }}
            </h1>

            <p class="mt-2 text-lg text-gray-400">
                {{ props.entry.description || 'Sem descrição' }}
            </p>
        </div>

        <slot name="badges" />
    </div>
</template>
