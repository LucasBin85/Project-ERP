<script setup>
defineProps({
    canReclassify: Boolean,
    classificationAccounts: Array,
    formProcessing: Boolean,
})

const selectedAccountId = defineModel('selectedAccountId')
const selectedAmount = defineModel('selectedAmount')
const selectedMemo = defineModel('selectedMemo')

const emit = defineEmits(['submit'])
</script>

<template>
    <section
        v-if="canReclassify"
        class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow"
    >
        <div class="mb-5">
            <h2 class="text-xl font-bold text-white">Reclassificar lançamento</h2>
            <p class="mt-1 text-sm text-gray-500">Substitua a conta transitória por uma conta definitiva.</p>
        </div>

        <form class="grid gap-4 md:grid-cols-[1fr_180px_1fr_auto]" @submit.prevent="emit('submit')">
            <select
                v-model="selectedAccountId"
                class="rounded-xl border border-white/10 bg-gray-950 px-3 py-2 text-white outline-none focus:border-blue-500"
            >
                <option value="">Selecione uma conta</option>
                <option
                    v-for="account in classificationAccounts"
                    :key="account.id"
                    :value="account.id"
                >
                    {{ account.code }} - {{ account.name }}
                </option>
            </select>

            <input
                v-model="selectedAmount"
                type="text"
                inputmode="decimal"
                placeholder="0,00"
                class="rounded-xl border border-white/10 bg-gray-950 px-3 py-2 text-right text-white outline-none focus:border-blue-500"
            >

            <input
                v-model="selectedMemo"
                type="text"
                placeholder="Memo"
                class="rounded-xl border border-white/10 bg-gray-950 px-3 py-2 text-white outline-none focus:border-blue-500"
            >

            <button
                type="submit"
                class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="formProcessing || !selectedAccountId || !selectedAmount"
            >
                Salvar
            </button>
        </form>
    </section>
</template>
