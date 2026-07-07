<script setup lang="ts">
import { X as XIcon } from 'lucide-vue-next'

defineProps({
    show: Boolean,
    isEditing: Boolean,
    form: Object,
    financialGroups: {
        type: Array,
        default: () => [],
    },
    isDuplicateName: Boolean,
    isSameName: Boolean,
    canSubmit: Boolean,
})

const emit = defineEmits([
    'close',
    'submit',
    'update-name',
    'update-allows-posting',
    'update-financial-group',
])
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="emit('close')"
        >
            <div class="relative w-[26rem] rounded-lg bg-white p-6 shadow-lg dark:bg-gray-900">
                <button
                    class="absolute top-3 right-3 cursor-pointer text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
                    aria-label="Fechar"
                    @click="emit('close')"
                >
                    <XIcon class="h-4 w-4" />
                </button>

                <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ isEditing ? 'Editar Conta' : 'Nova Conta' }}
                </h2>

                <form @submit.prevent="emit('submit')">
                    <div class="mb-4">
                        <label
                            for="account-name"
                            class="mb-1 block text-gray-700 dark:text-gray-300"
                        >
                            Nome
                        </label>

                        <input
                            id="account-name"
                            :value="form.name"
                            name="name"
                            type="text"
                            autofocus
                            class="w-full rounded border border-gray-300 bg-white p-2 text-gray-900 focus:ring-2 focus:ring-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            @input="emit('update-name', $event.target.value)"
                        />

                        <p v-if="isDuplicateName" class="mt-1 text-sm text-red-600">
                            Já existe uma conta com este nome neste nível.
                        </p>
                        <p v-else-if="isSameName" class="mt-1 text-sm text-gray-500">
                            Este é o nome atual da conta.
                        </p>
                        <p v-else-if="form.errors.name" class="mt-1 text-sm text-red-600">
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="mb-4">
                        <label
                            for="allows-posting"
                            class="mb-1 block text-gray-700 dark:text-gray-300"
                        >
                            Tipo da conta
                        </label>

                        <select
                            id="allows-posting"
                            :value="form.allows_posting"
                            name="allows_posting"
                            class="w-full rounded border border-gray-300 bg-white p-2 text-gray-900 focus:ring-2 focus:ring-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            @change="emit('update-allows-posting', $event.target.value === 'true')"
                        >
                            <option :value="true">Analítica (permite lançamentos)</option>
                            <option :value="false">Sintética (não permite lançamentos)</option>
                        </select>

                        <p v-if="form.errors.allows_posting" class="mt-1 text-sm text-red-600">
                            {{ form.errors.allows_posting }}
                        </p>
                    </div>

                    <div class="mb-4">
                        <label
                            for="financial-group"
                            class="mb-1 block text-gray-700 dark:text-gray-300"
                        >
                            Grupo financeiro
                        </label>

                        <select
                            id="financial-group"
                            :value="form.financial_group"
                            name="financial_group"
                            :disabled="form.allows_posting"
                            class="w-full rounded border border-gray-300 bg-white p-2 text-gray-900 focus:ring-2 focus:ring-blue-400 disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-700"
                            @change="emit('update-financial-group', $event.target.value || null)"
                        >
                            <option :value="null">Nenhum</option>
                            <option
                                v-for="group in financialGroups"
                                :key="group"
                                :value="group"
                            >
                                {{ group }}
                            </option>
                        </select>

                        <p class="mt-1 text-xs text-gray-500">
                            Disponível apenas para contas sintéticas.
                        </p>

                        <p v-if="form.errors.financial_group" class="mt-1 text-sm text-red-600">
                            {{ form.errors.financial_group }}
                        </p>
                    </div>

                    <div class="mt-6 flex justify-end space-x-2">
                        <button
                            type="button"
                            class="rounded border border-gray-300 px-4 py-2 text-gray-700 dark:border-gray-600 dark:text-gray-300"
                            @click="emit('close')"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            class="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400"
                            :disabled="!canSubmit || form.processing"
                        >
                            {{ isEditing ? 'Atualizar' : 'Criar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
