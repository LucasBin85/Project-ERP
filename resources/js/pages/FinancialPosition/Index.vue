<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import FinancialPositionGroups from '@/components/financial/position/FinancialPositionGroups.vue'
import FinancialPositionHero from '@/components/financial/position/FinancialPositionHero.vue'
import FinancialPositionSummary from '@/components/financial/position/FinancialPositionSummary.vue'
import { useFinancialPosition } from '@/composables/financial/useFinancialPosition'

const props = defineProps({
    wallet: Object,
    position: Object,
})

const view = useFinancialPosition(props.position)
</script>

<template>
    <AppLayout title="Posição Financeira">
        <div class="space-y-6 p-6">
            <FinancialPositionHero
                :wallet="wallet"
                :position-date="view.positionDate"
            />

            <FinancialPositionSummary :cards="view.summaryCards.value" />

            <FinancialPositionGroups :groups="position.groups" />
        </div>
    </AppLayout>
</template>
