<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import ChartOfAccountModal from '@/components/chartOfAccounts/ChartOfAccountModal.vue'
import MainGroupColumn from '@/components/chartOfAccounts/MainGroupColumn.vue'
import { Head } from '@inertiajs/vue3'
import { type BreadcrumbItem } from '@/types'
import { useChartOfAccountsIndex } from '@/composables/accounting/useChartOfAccountsIndex'
import type { TreeNode } from '@/types/types'
import SupplierQuickCreateDialog from '@/components/financial/counterparties/SupplierQuickCreateDialog.vue'
import CustomerQuickCreateDialog from '@/components/financial/counterparties/CustomerQuickCreateDialog.vue'

const props = defineProps<{
  tree: TreeNode[]
  financialGroups?: string[]
  payableControlAccounts: any[]
  expenseAccounts: any[]
  receivableControlAccounts: any[]
  revenueAccounts: any[]
}>()

const chart = useChartOfAccountsIndex(props)

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Plano de Contas',
    href: '/chart-of-accounts',
  },
]
</script>

<template>
  <Head title="Plano de Contas" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="p-5">
      <div class="mb-4 rounded border-l-4 border-blue-400 bg-blue-50 p-2 text-blue-700">
        🛈 <strong>Observação:</strong>
        Crie suas contas apenas em <em>Ativo Circulante</em> (1.1) ou
        <em>Passivo Circulante</em> (2.1). As contas de longo prazo
        (não-circulante) serão apresentadas automaticamente no relatório de
        balanço.
      </div>

      <h1 class="mb-4 text-2xl font-bold">Plano de Contas</h1>

      <div class="mb-8 grid grid-cols-1 items-stretch gap-4 md:grid-cols-2">
        <MainGroupColumn
          v-for="n in 5"
          :key="n"
          :group-number="n"
          :tree="props.tree"
          @create-child="chart.openCreate"
          @create-bank-account="chart.openCreateBankAccount"
          @edit="chart.openEdit"
          @delete="chart.deleteNode"
        />
      </div>
    </div>
  </AppLayout>

  <ChartOfAccountModal
    :show="chart.showModal.value"
    :is-editing="chart.isEditing.value"
    :form="chart.form"
    :financial-groups="props.financialGroups ?? []"
    :is-duplicate-name="chart.isDuplicateName.value"
    :is-same-name="chart.isSameName.value"
    :can-submit="chart.canSubmit.value"
    @close="chart.closeModal"
    @submit="chart.submit"
    @update-name="chart.form.name = $event"
    @update-allows-posting="chart.form.allows_posting = $event"
    @update-financial-group="chart.form.financial_group = $event"
  />
  <SupplierQuickCreateDialog
    :show="chart.showSupplierDialog.value"
    :control-accounts="props.payableControlAccounts"
    :expense-accounts="props.expenseAccounts"
    @close="chart.showSupplierDialog.value = false"
    @created="chart.counterpartyCreated"
  />
  <CustomerQuickCreateDialog
    :show="chart.showCustomerDialog.value"
    :control-accounts="props.receivableControlAccounts"
    :revenue-accounts="props.revenueAccounts"
    @close="chart.showCustomerDialog.value = false"
    @created="chart.counterpartyCreated"
  />
</template>
