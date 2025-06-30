<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import AccountTreeNode from '@/components/chartOfAccounts/AccountTreeNode.vue';
import { Head, usePage, router, useForm } from '@inertiajs/vue3';
import { ref, nextTick, watch, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import { useToast } from 'vue-toastification';
import { X as XIcon } from 'lucide-vue-next'
import type { TreeNode } from '@/types/types';


// acessa os props reativos
const page = usePage<{
  tree: TreeNode[];
  activeWallet: number;
}>();

// 1. Recebe a árvore completa do Inertia
const tree = computed(() => page.props.tree as TreeNode[])

// 2. Função auxiliar pra encontrar o nó "1.1.1.02"
function findByCode(nodes: TreeNode[], code: string): number | null {
  for (const n of nodes) {
    if (n.code === code) return n.id
    const child = findByCode(n.children || [], code)
    if (child !== null) return child
  }
  return null
}
// 3. Descobre o ID do nó “Banco Conta Movimento” (code = '1.1.1.02')
const bankMovementId = ref<number|null>(null)
onMounted(() => {
  bankMovementId.value = findByCode(tree.value, '1.1.1.02')
})


// Computed que retorna todos os nós em um array plano
const allNodes = computed(() => {
  const result: TreeNode[] = [];
  function traverse(nodes: TreeNode[]) {
    for (const node of nodes) {
      result.push(node);
      if (node.children && node.children.length > 0) {
        traverse(node.children);
      }
    }
  }
  traverse(tree.value);
  return result;
})
// Computed que detecta duplicata ENTRE IRMÃOS
const isDuplicateName = computed(() => {
  const nome = form.name.trim().toLowerCase()
  if (!nome) return false

  return allNodes.value.some(node =>
    node.parent_id === form.parent_id &&            // mesmo pai
    node.name.trim().toLowerCase() === nome &&      // mesmo nome
    node.id !== editingId.value                     // e não for o registro que estamos editando
  )
})
// Computed que detecta que o usuário não mudou o nome (no modo editar)
const isSameName = computed(() => {
  if (!isEditing.value || editingId.value === null) return false
  const current = allNodes.value.find(n => n.id === editingId.value)
  return !!(current && current.name.trim().toLowerCase() === form.name.trim().toLowerCase())
})

// Agora o canSubmit só será true se:
// 1) tiver algum texto,
// 2) não for duplicado,
// 3) não for o mesmo nome no editar
const canSubmit = computed(() => {
  return form.name.trim().length > 0 && 
         !isDuplicateName.value && 
         !isSameName.value
})
// estado do modal
const showModal    = ref(false);
const isEditing    = ref(false);
const editingId    = ref<number|null>(null);
const form         = useForm<{ 
  name: string; 
  parent_id: number|null;
}>({
  name: '',
  parent_id: null
})

function closeModal() {
  showModal.value = false
  form.reset()
}

const toast = useToast();
// ref para foco
const nameInput = ref<HTMLInputElement|null>(null);

// abre modal de criação (parentId = id do nó ou null para nível raiz)
function openCreate(node: TreeNode | null) {
  isEditing.value = false
  editingId.value = null
  form.reset()
  form.name = ''
  form.parent_id = node ? node.id : null
  showModal.value = true
  nextTick(() => nameInput.value?.focus())
}

// abre modal de edição
function openEdit(node: TreeNode) {
  isEditing.value = true;
  editingId.value = node.id;
  form.reset();
  form.name = node.name;
  form.parent_id = node.parent_id; // mantém o parent_id
  showModal.value = true;
  nextTick(() => nameInput.value?.focus());
}

// salvar (create or update)
function submit() {
  if (isEditing.value) {
    form.put(
      route('chart-of-accounts.update', { chart_of_account: editingId.value }),
      {
        preserveState: true,
        onSuccess: () => {
          toast.success('Conta atualizada!');
          showModal.value = false;
          router.reload({ only: ['tree'] });
        },
      }
    );
  } else {
    form.post(route('chart-of-accounts.store'), {
      preserveState: true,
      onSuccess: () => {
        toast.success('Conta criada!');
        showModal.value = false;
        router.reload({ only: ['tree'] });
      },
    });
  }
}

// excluir nó
function deleteNode(node: TreeNode) {
  if (!confirm('Excluir esta conta?')) return;
  router.delete(route('chart-of-accounts.destroy', { chart_of_account: node.id }), {
    preserveState: true,
    onSuccess: () => {
      toast.success('Conta removida!');
      router.reload({ only: ['tree'] });
    },
  });
}

const breadcrumbs: BreadcrumbItem[] = [
  { 
    title: 'Plano de Contas', 
    href: '/chart-of-accounts' 
  },
]

// fecha o modal ao clicar em Esc
function handleKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    closeModal()
  }
}
watch(showModal, (visible) => {
  if (visible) {
    document.addEventListener('keydown', handleKeyDown)
  } else {
    document.removeEventListener('keydown', handleKeyDown)
  }
})
</script>

<template>
<Head title="Plano de Contas" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="p-5">
      <div class="mb-4 p-1 bg-blue-50 border-l-4 border-blue-400 text-blue-700 rounded">
        🛈 <strong>Observação:</strong>
        Crie suas contas apenas em <em>Ativo Circulante</em> (1.1) ou <em>Passivo Circulante</em> (2.1).  
        As contas de longo prazo (não-circulante) serão apresentadas automaticamente no relatório de balanço.
      </div>
      <h1 class="text-2xl font-bold mb-4">Plano de Contas</h1>
      <ul>
        <AccountTreeNode
          v-for="node in tree"
          :key="node.id"
          :node="node"
          :bank-movement-id="bankMovementId"
          @create-child="openCreate"
          @edit="openEdit"
          @delete="deleteNode"
        />
      </ul>
    </div>
  </AppLayout>

  <!-- Modal de Create/Edit -->
  <Teleport to="body">
    <div
      v-if="showModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
      @click.self="closeModal"
    >
      <div class="bg-white dark:bg-gray-900 rounded-lg p-6 w-80 shadow-lg relative">
        <!-- botão fechar -->
        <button
          @click="closeModal"
          class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 cursor-pointer"
          aria-label="Fechar"
        >
          <XIcon class="w-4 h-4" />
        </button>
        <!-- título dinâmico -->
        <h2 class="text-lg font-medium mb-4 text-gray-900 dark:text-gray-100">
          {{ isEditing ? 'Editar Conta' : 'Nova Conta' }}
        </h2>

        <!-- formulário -->
        <form @submit.prevent="submit">
          <div class="mb-4">
            <label class="block mb-1 text-gray-700 dark:text-gray-300">Nome</label>
            <input
              ref="nameInput"
              v-model="form.name"
              type="text"
              class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded p-2 focus:ring-2 focus:ring-blue-400"
            />
            <!-- exibe mensagens de validação abaixo do input -->
            <p v-if="isDuplicateName" class="text-red-600 text-sm mt-1">
              Já existe uma conta com este nome neste nível.
            </p>
            <p v-else-if="isSameName" class="text-gray-500 text-sm mt-1">
              Este é o nome atual da conta.
            </p>
            <p v-else-if="form.errors.name" class="text-red-600 text-sm mt-1">
              {{ form.errors.name }}
            </p>
          </div>

          <div class="flex justify-end space-x-2 mt-6">
            <button
              type="button"
              @click="closeModal"
              class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded text-gray-700 dark:text-gray-300"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
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
