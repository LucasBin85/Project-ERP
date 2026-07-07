<script setup>
defineProps({
    form: Object,
    accounts: Array,
    statuses: Array,
})
</script>

<template>
    <div class="grid w-full grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-4">
        <div>
            <label
                for="chart_of_account_id"
                class="mb-1 block text-sm font-semibold text-gray-300"
            >
                Conta
            </label>

            <select
                id="chart_of_account_id"
                v-model="form.chart_of_account_id"
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
            >
                <option value="">Selecione</option>

                <option
                    v-for="account in accounts"
                    :key="account.id"
                    :value="String(account.id)"
                >
                    {{ account.label }}
                </option>
            </select>
        </div>

        <div>
            <label
                for="status"
                class="mb-1 block text-sm font-semibold text-gray-300"
            >
                Status
            </label>

            <select
                id="status"
                v-model="form.status"
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
            >
                <option value="">Todos</option>

                <option
                    v-for="status in statuses"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </option>
            </select>
        </div>
    </div>
</template>
