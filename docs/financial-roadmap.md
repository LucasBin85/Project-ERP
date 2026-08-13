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

### Contas recorrentes esperadas

Uma recorrência representa uma obrigação ou receita esperada periodicamente.
Ela pode ter valor fixo ou variável e funciona como controle operacional para
identificar se a conta esperada de cada período já foi tratada.

O cadastro considera:

- tipo: pagar ou receber;
- fornecedor ou cliente;
- descrição;
- frequência;
- dia de vencimento;
- valor fixo ou variável;
- valor previsto opcional;
- conta padrão de despesa ou receita;
- início e fim opcional de vigência;
- status ativo ou inativo.

Mensalmente, o módulo apresenta se a expectativa:

- ainda precisa ser confirmada;
- já originou um título financeiro;
- foi explicitamente ignorada naquele período;
- está vencida sem confirmação;
- está fora da periodicidade ou inativa.

### Confirmação e contabilidade

O cadastro de uma conta recorrente esperada não cria automaticamente um título
financeiro, uma provisão ou qualquer outro lançamento contábil.

A contabilidade nasce somente quando o usuário confirma o título mensal. A
partir dessa confirmação, o fluxo reutiliza o comportamento normal do produto:

1. criação de uma Conta a Pagar ou Conta a Receber;
2. criação da provisão contábil em `draft`;
3. pagamento ou recebimento, inclusive por vínculo com o extrato bancário.

Isso permite que valores variáveis sejam confirmados antes do reconhecimento e
evita títulos e lançamentos automáticos sem validação do usuário.

### Integrações do V1

O V1 de contas recorrentes esperadas está integrado a:

- Dashboard, destacando expectativas do período ainda não resolvidas;
- Fechamento Mensal, tratando expectativas não confirmadas ou justificadas como
  pendência operacional;
- Contas a Pagar e Contas a Receber, reutilizando os serviços existentes na
  confirmação mensal;
- navegação do módulo Financeiro, com workspace mensal próprio.

### Escopo implementado no V1

O V1 mantém deliberadamente as seguintes regras:

- não gerar recorrências infinitas nem materializar meses futuros;
- não criar títulos mensais automaticamente;
- não criar provisões contábeis automaticamente no cadastro da recorrência;
- permitir confirmar ou ignorar explicitamente cada ocorrência mensal;
- impedir mais de uma ocorrência para a mesma recorrência e período;
- não alterar o comportamento do parcelamento financeiro existente.

### Evoluções posteriores

Podem ser avaliadas depois do uso do V1:

- edição completa da regra recorrente preservando o histórico já confirmado;
- filtros e visão anual das ocorrências;
- atalhos de confirmação a partir de Contas a Pagar e Contas a Receber;
- sugestões de vínculo entre uma expectativa recorrente e um lançamento de
  extrato antes da criação manual do título;
- regras adicionais para periodicidades não mensais e exceções de calendário.
