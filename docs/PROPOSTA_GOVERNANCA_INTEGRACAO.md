# Proposta técnica — governança da integração GLPI ↔ OpenProject

> **Status:** a primeira entrega foi implementada na versão 0.22.0. As seções posteriores continuam como proposta para priorização. A central atual não altera dados de chamados ou Work Packages.

## 1. Decisão recomendada

O próximo incremento deve ser a **Central de pendências operacionais da integração**. Ela estende o monitoramento já existente e transforma informações espalhadas em uma fila de conferência: chamados sem Work Package, Work Packages que permanecem em etapas iniciais, vínculos potencialmente divergentes, falhas de sincronização e informações desatualizadas.

É uma evolução diretamente ligada ao propósito do plugin: assegurar que a demanda iniciada no GLPI tenha acompanhamento técnico confiável no OpenProject, sem substituir nenhum dos dois sistemas.

Não é recomendado, neste momento, ampliar o plugin para CRM, gestão financeira, mensageria genérica, IA automática ou funcionalidades que não dependam do ciclo chamado ↔ Work Package.

## 2. Objetivos de negócio

- Dar visibilidade antecipada a demandas que ficaram sem tratamento técnico ou sem vínculo confiável.
- Priorizar Work Packages abertas em **Novo** ou **Em especificação** por tempo acima do aceitável.
- Separar claramente dado atualizado, dado defasado e falha de consulta; nunca apresentar um snapshot antigo como se fosse atual.
- Permitir que um gestor confira a evidência e aja no sistema de origem, sem o plugin alterar status ou criar vínculos automaticamente por padrão.
- Reduzir consultas manuais, duplicadas e lentas ao OpenProject, preservando auditabilidade.

## 3. Escopo da primeira entrega: Central de pendências operacionais

### 3.1 Regras iniciais da versão 0.22.0

Cada regra gera uma ocorrência com severidade, evidência, primeira detecção, última verificação e situação da tratativa.

| Código | Pendência identificada | Severidade padrão | Fonte da evidência | Situação |
|---|---|---|---|---|
| `ticket_without_wp` | Chamado aberto, classificado para demandar WP, sem vínculo | Alta | chamado e política de classificação | Entregue |
| `wp_initial_status_overdue` | WP em **Novo**, **Em especificação** ou outro status inicial configurado acima do prazo | Alta | vínculo e último estado local | Entregue |
| `wp_sync_stale` | WP vinculada sem atualização confirmada há mais do que o prazo definido | Média | `last_synced_at` e histórico local | Entregue |
| `sync_error_open` | Última sincronização retornou erro ainda não resolvido | Alta | log de integração | Planejado |
| `link_candidate` | Há forte indício de vínculo em Atividade DevOps, acompanhamento ou campo corporativo, mas ele não está confirmado | Média | detector de vínculos já utilizado pelo monitoramento | Planejado |
| `mapping_gap` | Status técnico sem mapeamento de fase pública ou regra de comunicação | Média | configuração de status e último snapshot | Planejado |

Os nomes de status e os prazos não devem ser fixados no código. A administração deve definir quais etapas contam como iniciais e quantos dias são aceitáveis para cada regra.

### 3.2 Experiência de uso

Em **Gerência > Pendências de Integração**, a versão 0.22.0 apresenta:

- cards de drill-down por severidade e tipo de pendência;
- filtros por regra, severidade e situação da tratativa;
- links para chamado e Work Package em novas abas, quando o perfil puder ver os dados técnicos;
- data/hora da última detecção e da última tratativa;
- ações locais de **reconhecer**, **ignorar por sete dias** e **reabrir**; nenhuma dessas ações muda a WP ou o chamado;
- ação opcional de abrir o chamado ou a WP para que o usuário autorizado faça a correção no sistema de origem.

O histórico da ocorrência deve responder: “qual regra apontou o problema, desde quando, com quais dados e quem tratou?”.

### 3.3 Arquitetura proposta

O recurso reutiliza os serviços existentes (`SynchronizationService`, `WorkPackageMonitoringService`, links locais e logs), introduzindo uma camada própria de avaliação:

```text
GLPI tickets + links locais + logs          snapshots de WPs/OpenProject
                 \                              /
                  \                            /
                   -> OperationalHealthService -> ocorrências persistidas
                                                    |
                                                    v
                              Gerência > Pendências de Integração
```

Componentes implementados:

| Componente | Responsabilidade |
|---|---|
| `OperationalHealthService` | Avaliar as regras a partir de dados locais e snapshots de WP. |
| tabelas `operational_*` | Persistir a rodada, a ocorrência e a tratativa auditável. |
| `OperationalHealthHub` | Menu, autorização e páginas da fila gerencial. |
| tarefa cron do GLPI | Futuro: executar a avaliação programada, sem depender de uma página aberta no navegador. |

Tabelas novas, sempre criadas e atualizadas de modo idempotente:

| Tabela | Conteúdo mínimo |
|---|---|
| `glpi_plugin_demandas_operational_runs` | início/fim, origem, resultado, contagens, erro sanitizado e versão da regra executada. |
| `glpi_plugin_demandas_operational_findings` | impressão digital única, regra, severidade, ticket/WP, primeira/última detecção, situação, evidência mínima e prazo de ignorar. |
| `glpi_plugin_demandas_operational_actions` | usuário, ação, data e observação da tratativa. |

A impressão digital deve ser derivada de `rule_code + tickets_id + openproject_work_package_id`, impedindo duplicação da mesma pendência a cada execução. Uma ocorrência deixa de ficar aberta quando a regra não é mais verdadeira; o histórico é preservado.

### 3.4 Atualização de dados e desempenho

A primeira versão pode avaliar apenas dados locais e os snapshots já obtidos pelo monitoramento. Isso reduz risco e oferece valor imediato.

Numa segunda etapa, o `OperationalHealthRunner` poderá atualizar WPs por lotes pequenos usando o token técnico de automação, exclusivamente em leitura. Deve reutilizar paginação, limites de tempo e tratamento de erro já consolidados na consulta ao OpenProject.

Regras obrigatórias de transparência:

- a tela mostra a data/hora de atualização de cada rodada e o estado **em andamento**, **concluída** ou **com erro**;
- uma falha nunca apaga ocorrências anteriores nem faz um dado antigo parecer atual;
- dados baseados em snapshot mostram a respectiva idade;
- não executar duas rodadas concorrentes para o mesmo escopo;
- a atualização manual deve criar uma rodada auditável, e não uma consulta oculta no carregamento da página.

Essa abordagem responde à preocupação de desempenho sem “mascarar” o dashboard: os indicadores permanecem reproduzíveis a partir de uma rodada identificada, com sua data e resultado visíveis.

## 4. Segurança e privacidade

### 4.1 Permissões

Foram adicionados direitos específicos na matriz do plugin:

- **Visualizar pendências operacionais da integração**: lista e detalhes restritos ao escopo de entidades e à leitura efetiva do chamado.
- **Executar e tratar pendências operacionais da integração**: executar a rodada manual, reconhecer, ignorar temporariamente e reabrir.
- a alteração de prazos e status iniciais usa a permissão existente **Administrar as configurações do plugin**.

Qualquer detalhe de WP exige também **Visualizar dados técnicos do OpenProject**. Perfis que só podem ver a evolução pública não recebem ID, título, status, link, responsável nem dados da ocorrência que revelem informações técnicas.

Cada endpoint deve validar esses direitos no backend e respeitar entidades ativas e leitura efetiva do chamado. Ocultar menu, botão ou coluna não substitui essa validação.

### 4.2 Dados armazenados

- Não gravar token, cabeçalho de autenticação, payload bruto da API, descrição integral do chamado, acompanhamentos ou anexos nas tabelas de saúde.
- Armazenar somente metadados necessários para explicar a ocorrência: IDs, nomes de status, datas, regra, severidade e uma evidência curta escapada.
- Sanitizar mensagens de exceção antes de exibição ou persistência; logs técnicos detalhados permanecem no mecanismo de log protegido do GLPI.
- Aplicar CSRF a toda ação de tratativa e escapar toda saída HTML/CSV/XLSX/PDF.
- A execução automática usa somente o token técnico configurado para automação, com menor privilégio possível e sem capacidade de criar WP.

## 5. Evoluções posteriores, dependentes da primeira entrega

### 5.1 Conciliação assistida de vínculos GLPI ↔ WP

**Objetivo:** reduzir vínculos ausentes sem criar relações incorretas.

O serviço reúne evidências já reconhecidas pelo plugin: tabela de vínculos, campo **Atividade DevOps**, URLs em acompanhamentos e campos corporativos da WP. Ele calcula um nível de confiança e apresenta candidatos para confirmação humana.

Regras de segurança:

- vínculo só é criado após confirmação explícita de perfil com **Criar e vincular Work Packages**;
- nenhum vínculo é criado por correspondência textual fraca;
- conflitos (uma WP apontando para vários chamados) ficam sempre em revisão humana;
- a confirmação cria histórico local com origem, evidências utilizadas e usuário responsável.

**Viabilidade:** média. O detector básico já existe no monitoramento; o novo trabalho é persistir candidatos, definir critérios de confiança, criar revisão e testes contra falsos positivos.

### 5.2 Atualização assíncrona observável de snapshots

**Objetivo:** evitar que dashboards e listagens bloqueiem o navegador em consultas extensas ao OpenProject.

O cron atualiza snapshots em blocos, e a interface lê o último resultado concluído. A tela exibe a data de corte, a duração, falhas e um comando explícito de atualização; não há atualização silenciosa nem mistura de dados de rodadas diferentes.

**Viabilidade:** média/alta. Depende de definir frequência, limites de API, lock distribuído, política de retentativas e monitoramento de falhas. Deve ser desenvolvida após a Central de pendências, que já entrega o modelo de rodada e transparência de atualização.

### 5.3 Regras de prontidão para desenvolvimento

**Objetivo:** tornar visível a WP que está vinculada, mas não foi liberada para desenvolvimento.

Além do tempo em status inicial, regras configuráveis podem identificar ausência de responsável, cliente, projeto, tipo, estimativa ou outra informação que a organização considere obrigatória. A regra somente alerta; a mudança de status continua sendo feita no OpenProject por um usuário autorizado.

**Viabilidade:** alta depois da Central de pendências. Usa o mesmo motor de regras, filtros, notificações e trilha de auditoria.

## 6. Esforço e sequência recomendada

| Incremento | Valor | Complexidade | Dependências |
|---|---|---|---|
| Central de pendências com regras locais | Alto | Médio | definição dos prazos e regras iniciais |
| Conciliação assistida | Alto | Médio/alto | primeira entrega e critérios de confiança aprovados |
| Atualização assíncrona observável | Alto em volume | Médio/alto | cron, token técnico de leitura e política de execução |
| Regras adicionais de prontidão | Médio/alto | Baixo/médio | motor de regras da primeira entrega |

A entrega inicial foi dividida nos seguintes incrementos verificáveis:

1. **Entregue na 0.22.0:** modelo de dados, permissões, regras locais, cards, filtros e tratativa auditável;
2. paginação, exportação, observação da tratativa e notificações dedicadas;
3. cron, locks, estado de atualização e atualização opcional por OpenProject;
4. conciliação assistida e regras organizacionais adicionais.

## 7. Decisões necessárias antes de iniciar

1. Quais classificações de chamado exigem WP?
2. Quais status do OpenProject são considerados “iniciais” e qual é o prazo por severidade?
3. Quem pode ignorar uma pendência, por quanto tempo e com qual justificativa?
4. A atualização automática poderá consultar todas as WPs abertas com o token técnico, e em qual frequência?
5. Qual perfil ou grupo será responsável por tratar a fila?
6. Quais campos devem ser obrigatórios para considerar uma WP pronta para desenvolvimento?

## 8. Critérios de aceite da primeira entrega

- Uma regra gera no máximo uma ocorrência aberta por chamado/WP, mesmo após múltiplas rodadas.
- Corrigir a condição fecha a ocorrência sem apagar o histórico.
- Perfis sem os novos direitos recebem HTTP 403 nos endpoints e não veem menu ou dados técnicos.
- O escopo por entidade e leitura do chamado é preservado.
- Uma falha de OpenProject mantém a última rodada e aparece explicitamente como falha; não zera indicadores nem oculta pendências.
- A tela permite filtrar, ordenar, paginar e exportar sem alterar chamado ou WP.
- Execuções concorrentes são impedidas e todas as ações de tratativa são auditadas.
- Atualizações de banco são idempotentes e não alteram vínculos, tokens ou histórico existentes.
