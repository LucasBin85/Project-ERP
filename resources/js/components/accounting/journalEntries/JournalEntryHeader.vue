<script setup>
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const props = defineProps({
    entry: { type: Object, required: true },
})
</script>

<template>
    <section class="rounded-2xl border border-white/10 bg-gradient-to-br from-[#10213a] to-[#0b1220] p-6 shadow">
        <div class="mb-6">
            <Link
                :href="route('journal-entries.index')"
                class="text-sm font-semibold text-blue-300 hover:text-blue-200"
            >
                ← Voltar para lançamentos
            </Link>
        </div>

        <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-300">
                    Lançamento contábil
                </p>

                <h1 class="mt-2 text-3xl font-bold text-white">
                    Lançamento #{{ props.entry.id }}
                </h1>

                <p class="mt-3 max-w-3xl text-sm text-gray-400">
                    {{ props.entry.description || 'Sem descrição' }}
                </p>
            </div>

            <slot name="badges" />
        </div>
    </section>
</template>
