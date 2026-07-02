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
    <div
        v-if="canReclassify"
        class="rounded-xl border border-gray-700 bg-[#111827] p-6"
    >
        <h2 class="mb-4 text-xl font-bold text-white">Reclassificar lançamento</h2>

        <form class="grid gap-4 md:grid-cols-[1fr_180px_1fr_auto]" @submit.prevent="emit('submit')">
            <select
                v-model="selectedAccountId"
                class="rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
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
                class="rounded-lg border border-gray-700 bg-black px-3 py-2 text-right text-white"
            >

            <input
                v-model="selectedMemo"
                type="text"
                placeholder="Memo"
                class="rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
            >

            <button
                type="submit"
                class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-500 disabled:opacity-50"
                :disabled="formProcessing || !selectedAccountId || !selectedAmount"
            >
                Salvar
            </button>
        </form>
    </div>
</template>
