# Conciliação bancária formal

## Objetivo

A conciliação bancária registra um snapshot auditável que compara o saldo final informado pelo banco com movimentos bancários e lançamentos contábeis já existentes. Ela explica diferenças; não cria ou corrige contabilidade.

## Fronteiras do domínio

- **Extrato bancário:** visão operacional dos movimentos manuais, importados e, futuramente, Open Finance.
- **Posting:** criação ou postagem de `JournalEntry`; acontece fora da conciliação.
- **Conciliação:** vincula itens do extrato a linhas contábeis posted e persiste o diagnóstico do período.
- **Fechamento mensal:** formaliza o estado contábil do mês. Não é reaberto nem modificado pela conciliação.

## Lifecycle

```text
create → preview → draft ou completed
draft → review → preview → draft ou completed
draft → discard
completed → snapshot imutável
```

Conta bancária e período formam a identidade da conciliação e não podem ser alterados durante a revisão. Um draft com identidade incorreta deve ser descartado e recriado.

## Saldos e status

`statement_balance_cents` é o saldo final autoritativo informado pelo banco. Ele nunca é inferido a partir dos itens.

O saldo calculado é apenas diagnóstico:

```text
calculated statement balance = opening balance + soma dos statement items
statement items difference = calculated statement balance - saldo oficial
reconciliation difference = reconciled balance - saldo oficial
```

Opening balance, book balance e linhas elegíveis consideram somente `JournalEntry` em status `posted`. Drafts contábeis não participam.

A conciliação fica `completed` somente quando a diferença da conciliação é zero e nenhum statement item está pendente. Nos demais casos permanece `draft`.

## Integridade e isolamento

- períodos da mesma conta não podem se sobrepor; períodos adjacentes podem coexistir;
- contas diferentes podem usar o mesmo período;
- uma `journal_line_id` bancária não pode pertencer a duas conciliações;
- uma `bank_statement_import_transaction_id` não pode pertencer a duas conciliações;
- update pode reutilizar somente os itens da própria reconciliation;
- wallet, conta e período são validados em create, preview, store, edit, update e discard;
- o preview de edição ignora somente o overlap da própria reconciliation.

Create, update e discard são transacionais. Update e discard bloqueiam o header com `lockForUpdate`. O descarte remove apenas o draft e seus filhos; lançamentos contábeis, linhas, imports e movimentos bancários permanecem.

## Snapshot e imutabilidade

Uma reconciliation completed preserva saldos, diferença, notas, vínculos e `completed_at`. Operações normais do domínio impedem atualização ou exclusão do header e mutação de seus filhos. Não existe transição completed para draft.

GETs e preview são read-only. Histórico e show usam os snapshots persistidos, sem recalcular registros antigos. Conciliações podem ser criadas, revisadas, concluídas ou descartadas após o fechamento mensal porque não alteram JournalEntry nem o fechamento.

## Integrações atuais

- histórico de conciliações;
- extrato bancário com entrada contextual por conta e período;
- itens importados OFX e itens manuais complementares;
- linhas contábeis posted;
- Monthly Closing, com política explicitamente não bloqueante.

## Fora do escopo

A conciliação não cria `JournalEntry`, não corrige diferenças automaticamente, não reabre períodos e não edita completed. Supersession/correção histórica, audit log detalhado, RBAC, optimistic locking, Open Finance e matching por IA são decisões futuras.
