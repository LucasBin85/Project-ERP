# Recorrências financeiras

## Conceito e ciclo de vida

Uma recorrência representa uma obrigação ou receita esperada periodicamente. Ela é diferente de um título único, que existe uma vez, e de um parcelamento, que divide uma obrigação ou receita já conhecida. A recorrência registra uma regra; não materializa indefinidamente títulos futuros.

O ciclo é `expectation -> competência virtual -> confirmed | skipped`. Uma criação recorrente feita pelas telas de AP/AR confirma somente a competência inicial informada. As demais permanecem virtuais até uma decisão explícita.

`RecurringFinancialExpectation` contém a configuração. `RecurringFinancialOccurrence` registra a resolução imutável de uma competência. A chave única `(expectation, period_date)` garante no máximo uma resolução por versão e competência.

## Confirmação, skip e contabilidade

Uma confirmação cria uma occurrence `confirmed`, o AP ou AR correspondente e uma provisão contábil em draft. O snapshot `expected_amount_cents` guarda a previsão produzida naquele momento; `actual_amount_cents` guarda o valor confirmado. Revisões, novos valores reais e analytics nunca recalculam esse snapshot.

O skip cria apenas uma occurrence `skipped`. Não cria AP, AR ou lançamento contábil. Confirmed e skipped removem a competência das projeções virtuais.

Na provisão de AP, a despesa é debitada e o controle de AP é creditado. Na provisão de AR, o controle de AR é debitado e a receita é creditada. A baixa posterior usa o fluxo normal de pagamento ou recebimento.

## Versionamento e agenda

Alterações são versionadas em uma cadeia V1 -> V2 -> V3. A versão anterior recebe `ends_on`; a sucessora recebe `starts_on` e `replaces_expectation_id`. Somente a versão terminal pode ser revisada ou encerrada. Occurrences, títulos e lançamentos permanecem associados à versão original.

`schedule_anchor_date` preserva a fase da agenda. Alterações de descrição, contraparte, conta, valor, modo, vencimento, strategy ou observações não reiniciam o anchor. Uma mudança de frequência cria um novo anchor na competência efetiva.

## Valores e previsão

Regras fixed têm valor contratual em `expected_amount_cents` e `forecast_strategy = null`. Regras variable podem manter uma previsão-base como fallback e usam uma strategy válida:

- `mean_last_3`: média HALF_UP de até três actuals anteriores;
- `last_actual`: actual anterior mais recente por competência financeira;
- `median_last_3`: mediana de até três actuals anteriores, usando média HALF_UP quando há dois.

Um registro legado variable com strategy nula resolve defensivamente para `mean_last_3`. Sem actual anterior, todas as strategies usam o fallback da versão atual; sem fallback, a previsão é desconhecida (`null`).

O estimator atravessa predecessores e considera somente occurrences confirmed, com actual não nulo e `period_date` anterior ao alvo. Skipped, o próprio alvo e períodos futuros são ignorados. A ordenação é por competência, nunca por ID, criação ou confirmação.

## Extrato bancário

A busca de candidatos de recorrência no extrato é read-only e respeita carteira, tipo, janela de vencimento e estado da competência. Confirmar e vincular é uma transação única: cria occurrence, título e provisão, depois reutiliza o lançamento do extrato para a baixa. Se o vínculo falhar, occurrence, título e provisão são revertidos.

## Consumidores gerenciais

Range deriva competências virtuais sem persistir. Cash Flow inclui a projeção virtual antes da resolução e passa a usar o AP/AR materializado depois, sem dupla contagem. Forecast desconhecido permanece nulo e torna o saldo projetado parcial.

Dashboard usa o mesmo Cash Flow canônico. Monthly Closing apresenta recorrências virtuais como revisão informativa; elas não são blockers, não viram pendência contábil e não impedem o fechamento formal. Confirmed e skipped deixam a revisão do mês.

Performance mede a previsão histórica real usando os snapshots gravados. Backtest compara algoritmos hipotéticos usando apenas actuals anteriores ao alvo, sem look-ahead. A recomendação é analítica: empate favorece a strategy atual, mas nenhuma leitura ou recomendação altera a regra.

AP index, AR index, overview, range, Cash Flow, Dashboard, Monthly Closing, performance, backtest e busca de candidatos bancários são consumidores read-only.

## Invariantes principais

- nenhuma competência futura é materializada automaticamente;
- uma única resolução existe por versão e competência;
- histórico confirmado e snapshots são imutáveis;
- carteira e tipo são validados em todos os endpoints contextuais;
- somente a versão terminal é gerenciável;
- fixed e variable mantêm configuração coerente;
- confirmação e vínculo bancário são atômicos;
- projeção virtual e título materializado não coexistem para a mesma competência;
- analytics não escrevem e performance não se confunde com backtest.

## Extensões futuras seguras

Novas strategies, como média ponderada ou sazonalidade, devem ser centralizadas no domínio, versionadas e adicionadas ao backtest sem alterar snapshots. Recomendação assistida deve exigir decisão explícita e revisão versionada. Importações PDF/XML, APIs bancárias/Open Finance e eventual baixa parcial devem reutilizar os serviços canônicos de confirmação, vínculo e contabilidade, preservando atomicidade e isolamento.
