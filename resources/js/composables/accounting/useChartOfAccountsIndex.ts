import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useToast } from 'vue-toastification'
import type { TreeNode } from '@/types/types'

export function useChartOfAccountsIndex(props) {
    const showModal = ref(false)
    const showSupplierDialog = ref(false)
    const showCustomerDialog = ref(false)
    const isEditing = ref(false)
    const editingId = ref<number | null>(null)

    const toast = useToast()

    const form = useForm<{
        name: string
        parent_id: number | null
        allows_posting: boolean
        financial_group: string | null
    }>({
        name: '',
        parent_id: null,
        allows_posting: true,
        financial_group: null,
    })

    const allNodes = computed(() => {
        const result: TreeNode[] = []

        function traverse(nodes: TreeNode[]) {
            for (const node of nodes) {
                result.push(node)

                if (node.children && node.children.length > 0) {
                    traverse(node.children)
                }
            }
        }

        traverse(props.tree)
        return result
    })

    const isDuplicateName = computed(() => {
        const name = form.name.trim().toLowerCase()
        if (!name) return false

        return allNodes.value.some(node =>
            node.parent_id === form.parent_id &&
            node.name.trim().toLowerCase() === name &&
            node.id !== editingId.value,
        )
    })

    const isSameName = computed(() => {
        if (!isEditing.value || editingId.value === null) return false

        const current = allNodes.value.find(node => node.id === editingId.value)

        return Boolean(
            current &&
            current.name.trim().toLowerCase() === form.name.trim().toLowerCase(),
        )
    })

    const canSubmit = computed(() => {
        return form.name.trim().length > 0 &&
            !isDuplicateName.value &&
            !isSameName.value
    })

    function resetForm() {
        form.reset()
        form.clearErrors()
        form.name = ''
        form.parent_id = null
        form.allows_posting = true
        form.financial_group = null
    }

    function closeModal() {
        showModal.value = false
        resetForm()
    }

    function openCreate(node: TreeNode | null) {
        if (node?.code === '2.1' || node?.code?.startsWith('2.1.')) {
            showSupplierDialog.value = true
            return
        }

        if (node?.code === '1.2' || node?.code?.startsWith('1.2.')) {
            showCustomerDialog.value = true
            return
        }

        isEditing.value = false
        editingId.value = null
        resetForm()

        form.parent_id = node ? node.id : null
        form.allows_posting = true
        form.financial_group = null

        showModal.value = true
    }

    function openCreateBankAccount() {
        router.visit(route('bank-accounts.create'))
    }

    function counterpartyCreated() {
        showSupplierDialog.value = false
        showCustomerDialog.value = false
        toast.success('Cadastro e contas vinculadas criados!')
        router.reload({ only: ['tree', 'payableControlAccounts', 'expenseAccounts', 'receivableControlAccounts', 'revenueAccounts'] })
    }

    function openEdit(node: TreeNode) {
        isEditing.value = true
        editingId.value = node.id
        resetForm()

        form.name = node.name
        form.parent_id = node.parent_id
        form.allows_posting = Boolean(node.allows_posting)
        form.financial_group = node.financial_group ?? null

        showModal.value = true
    }

    watch(
        () => form.allows_posting,
        value => {
            if (value) {
                form.financial_group = null
            }
        },
    )

    function submit() {
        if (isEditing.value) {
            form.put(route('chart-of-accounts.update', { chart_of_account: editingId.value }), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Conta atualizada!')
                    closeModal()
                    router.reload({ only: ['tree', 'financialGroups'] })
                },
                onError: () => {
                    toast.error('Não foi possível atualizar a conta.')
                },
            })

            return
        }

        form.post(route('chart-of-accounts.store'), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Conta criada!')
                closeModal()
                router.reload({ only: ['tree', 'financialGroups'] })
            },
            onError: () => {
                toast.error('Não foi possível criar a conta.')
            },
        })
    }

    function deleteNode(node: TreeNode) {
        if (!confirm(`Excluir a conta "${node.code} - ${node.name}"?`)) return

        router.delete(route('chart-of-accounts.destroy', { chart_of_account: node.id }), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Conta removida!')
                router.reload({ only: ['tree', 'financialGroups'] })
            },
            onError: () => {
                toast.error('Não foi possível excluir a conta.')
            },
        })
    }

    function handleKeyDown(event: KeyboardEvent) {
        if (event.key === 'Escape') {
            closeModal()
        }
    }

    watch(showModal, visible => {
        if (visible) {
            document.addEventListener('keydown', handleKeyDown)
        } else {
            document.removeEventListener('keydown', handleKeyDown)
        }
    })

    return {
        showModal,
        showSupplierDialog,
        showCustomerDialog,
        isEditing,
        editingId,
        form,
        isDuplicateName,
        isSameName,
        canSubmit,
        openCreate,
        openCreateBankAccount,
        counterpartyCreated,
        openEdit,
        closeModal,
        submit,
        deleteNode,
    }
}
