<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
const props=defineProps<{rules:Array<Record<string,any>>}>();
function update(rule:Record<string,any>, changes:Record<string,any>) { router.put(route('bank-statement-classification-rules.update', rule.id), {...rule,...changes}, {preserveScroll:true}); }
function remove(id:number) { if(confirm('Excluir esta regra?')) router.delete(route('bank-statement-classification-rules.destroy',id),{preserveScroll:true}); }
</script>
<template>
 <details class="rounded-xl border border-gray-700 bg-gray-950 p-4">
  <summary class="cursor-pointer font-bold text-white">Regras de classificação ({{ rules.length }})</summary>
  <p v-if="!rules.length" class="mt-3 text-sm text-gray-400">Crie uma regra a partir de um lançamento do Extrato.</p>
  <div v-else class="mt-3 divide-y divide-gray-800">
   <div v-for="rule in rules" :key="rule.id" class="flex flex-wrap items-center gap-3 py-3 text-sm">
    <div class="min-w-48 flex-1"><b class="text-white">{{ rule.name }}</b><p class="text-gray-400">{{ rule.match_mode }} “{{ rule.match_text }}” · {{ rule.operation_type }} → {{ rule.target_label }}</p></div>
    <label class="text-gray-400">Prioridade <input :value="rule.priority" type="number" class="w-20 rounded border border-gray-700 bg-black p-1 text-white" @change="update(rule,{priority:Number(($event.target as HTMLInputElement).value)})"></label>
    <button class="rounded border border-gray-600 px-2 py-1" @click="update(rule,{active:!rule.active})">{{ rule.active?'Desativar':'Ativar' }}</button>
    <button class="text-red-300 hover:underline" @click="remove(rule.id)">Excluir</button>
   </div>
  </div>
 </details>
</template>
