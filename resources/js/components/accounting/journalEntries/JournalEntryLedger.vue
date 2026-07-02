<script setup>
import { formatCurrency } from '@/lib/formatters'

defineProps({
    debitLines: { type: Array, required: true },
    creditLines: { type: Array, required: true },
    debitTotal: { type: Number, required: true },
    creditTotal: { type: Number, required: true },
    difference: { type: Number, required: true },
    isBalanced: { type: Boolean, required: true },
    suspenseAccountId: { type: [Number, String, null], default: null },
})
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-gray-700 bg-[#111827]">
        <div class="border-b border-gray-700 px-6 py-5">
            <h2 class="text-xl font-bold text-white">
                Razonete do lançamento
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2">
            <div class="border-b border-gray-700 md:border-r md:border-b-0">
                <div class="bg-[#1f2937] px-5 py-4 text-center text-lg font-bold text-green-300">
                    DÉBITO
                </div>

                <div class="grid grid-cols-[1fr_140px] border-b border-gray-700 px-5 py-3 text-xs font-bold uppercase text-gray-400">
                    <div>Conta / Memo</div>
                    <div class="text-right">Valor</div>
                </div>

                <div class="min-h-[170px] space-y-3 p-5">
                    <div
                        v-for="line in debitLines"
                        :key="line.id"
                        class="grid grid-cols-[1fr_140px] gap-4 rounded-lg border border-gray-700 bg-[#0b1220] p-4"
                    >
                        <div>
                            <p class="font-bold text-white">
                                {{ line.chart_of_account?.code }} - {{ line.chart_of_account?.name }}
                            </p>

                            <p class="mt-2 text-sm text-gray-400">
                                {{ line.memo || 'Sem memo' }}
                            </p>
                        </div>

                        <div class="text-right font-bold text-white">
                            {{ formatCurrency(line.amount_cents) }}
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-700 px-5 py-6 text-center">
                    <p class="text-sm font-bold uppercase text-green-300">
                        Total Débito
                    </p>

                    <p class="mt-2 text-3xl font-bold text-green-300">
                        {{ formatCurrency(debitTotal) }}
                    </p>
                </div>
            </div>

            <div>
                <div class="bg-[#1f2937] px-5 py-4 text-center text-lg font-bold text-blue-300">
                    CRÉDITO
                </div>

                <div class="grid grid-cols-[1fr_140px] border-b border-gray-700 px-5 py-3 text-xs font-bold uppercase text-gray-400">
                    <div>Conta / Memo</div>
                    <div class="text-right">Valor</div>
                </div>

                <div class="min-h-[170px] space-y-3 p-5">
                    <div
                        v-for="line in creditLines"
                        :key="line.id"
                        class="grid grid-cols-[1fr_140px] gap-4 rounded-lg border border-gray-700 bg-[#0b1220] p-4"
                    >
                        <div>
                            <p class="font-bold text-white">
                                {{ line.chart_of_account?.code }} - {{ line.chart_of_account?.name }}
                            </p>

                            <p
                                v-if="Number(line.chart_of_account_id) === Number(suspenseAccountId)"
                                class="mt-1 text-xs font-semibold text-yellow-300"
                            >
                                Conta transitória / pendente de classificação
                            </p>

                            <p class="mt-2 text-sm text-gray-400">
                                {{ line.memo || 'Sem memo' }}
                            </p>
                        </div>

                        <div class="text-right font-bold text-white">
                            {{ formatCurrency(line.amount_cents) }}
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-700 px-5 py-6 text-center">
                    <p class="text-sm font-bold uppercase text-blue-300">
                        Total Crédito
                    </p>

                    <p class="mt-2 text-3xl font-bold text-blue-300">
                        {{ formatCurrency(creditTotal) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="border-t border-gray-700 p-5">
            <div
                class="rounded-lg px-5 py-4 text-sm font-bold"
                :class="isBalanced
                    ? 'bg-green-950/40 text-green-300'
                    : 'bg-red-950/40 text-red-300'"
            >
                Diferença: {{ formatCurrency(Math.abs(difference)) }}
                —
                {{ isBalanced ? 'lançamento balanceado' : 'lançamento desbalanceado' }}
            </div>
        </div>
    </div>
</template>
