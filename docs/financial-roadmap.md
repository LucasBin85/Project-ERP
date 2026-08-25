# Roadmap financeiro

## Parcelamento e contas recorrentes esperadas

Parcelamento financeiro e recorrência representam necessidades diferentes e
não devem compartilhar o mesmo comportamento.

### Parcelamento financeiro

Um parcelamento representa uma única obrigação ou receita já existente, cujo
valor total é conhecido no momento do cadastro.

- O valor total é dividido entre várias parcelas financeiras.
- Existe uma única provisão contábil pelo valor total.
- Cada parcela possui valor, vencimento e status próprios.
- Pagamentos e recebimentos são baixados individualmente por parcela.
- A baixa de uma parcela não reconhece novamente a despesa ou receita.

O parcelamento atual de Contas a Pagar e Contas a Receber segue esse conceito e
não deve ser usado para representar energia elétrica, internet, aluguel,
condomínio, assinaturas ou mensalidades.

### Contas recorrentes esperadas — implementado

Uma recorrência representa uma obrigação ou receita esperada
periodicamente. Ela poderá ter valor fixo ou variável e funcionará como
controle operacional para identificar se a conta esperada de cada período foi
cadastrada.

O cadastro deverá considerar:

- tipo: pagar ou receber;
- fornecedor ou cliente;
- descrição;
- frequência;
- dia de vencimento;
- valor fixo ou variável;
- valor previsto opcional;
- conta padrão de despesa ou receita;
- status ativo ou inativo.

Mensalmente, o módulo deverá verificar e apresentar se:

- o título do mês já foi cadastrado;
- o título está pendente;
- o título está vencido;
- o título foi pago ou recebido.

### Confirmação e contabilidade

O cadastro de uma conta recorrente esperada não deverá criar automaticamente
um título financeiro, uma provisão ou qualquer outro lançamento contábil.

A contabilidade nascerá somente quando o usuário criar ou confirmar o título
mensal. A partir dessa confirmação, o fluxo será o fluxo normal do produto:

1. criação de uma Conta a Pagar ou Conta a Receber;
2. criação da provisão contábil em `draft`;
3. pagamento ou recebimento, inclusive por vínculo com o extrato bancário.

Isso permite que valores variáveis sejam confirmados antes do reconhecimento e
evita títulos e lançamentos automáticos sem validação do usuário.

### Integrações atuais

O módulo está integrado a:

- Dashboard, destacando contas esperadas ainda não cadastradas;
- Fechamento Mensal, como revisão gerencial informativa e não bloqueante;
- Contas a Pagar e Contas a Receber, no fluxo de criação ou confirmação mensal;
- alertas de pendências, incluindo contas não cadastradas, pendentes e vencidas.

### Invariantes atuais

- não gerar ou materializar recorrências infinitas;
- não criar títulos mensais automaticamente;
- não criar provisões contábeis automaticamente;
- não alterar o comportamento do parcelamento financeiro existente.

Versionamento, previsão configurável, integração com extrato, performance e
backtest estão documentados em [Recorrências financeiras](recurring-financial-expectations.md).

## Conciliação bancária formal — implementada

O fluxo formal de conciliação bancária está disponível a partir do extrato e do histórico de conciliações. Ele inclui:

- preview read-only antes da persistência;
- criação como draft ou completed conforme diferenças e pendências;
- saldo final informado pelo banco como valor oficial autoritativo;
- revisão e descarte de drafts;
- transição automática de draft para completed quando reconciliado;
- completed imutável como snapshot de auditoria;
- proteção contra períodos sobrepostos, journal lines e transações importadas reutilizadas;
- suporte a conciliação após o fechamento mensal, sem reabrir o mês ou alterar contabilidade.

Detalhes do contrato estão em [Conciliação bancária formal](bank-reconciliation.md).
