# Gestão de Demandas 0.31.1 — dashboard para tomada de decisão

Esta release reúne a nova aba **Bugs e Melhorias**, desenvolvida na etapa 0.31.0, e a revisão visual aprovada para publicação. Inclui fila **Precisam de ação**, conferência de entregas totais/parciais, envelhecimento observado por etapa, filtros por responsável, alerta técnico no chamado e escolha pessoal da visão inicial. A Visão geral continua disponível.

## Mudança

A aba Bugs e Melhorias passa a priorizar ações legíveis: cartões com quantidade e próximo passo, fila compacta, detalhes de WPs expansíveis, entregas para conferência e barras de quantidade por etapa. O maior tempo observado fica em coluna própria. Filtros, opções, critérios, distribuição por responsável e indicadores zerados ficam recolhidos.

Os dados não foram recalculados nem filtrados de outra forma. Todos os vínculos continuam disponíveis; somente sua apresentação inicial foi resumida. Cores, foco de teclado e contraste são específicos dessa aba, preservando a Visão geral e o tema do restante do GLPI.

## Segurança e atualização

A revisão visual não acrescenta mudanças de schema, ACL, tokens ou chamadas externas à etapa 0.31.0. Para quem atualiza da 0.30.0, a migração idempotente cria a tabela de preferências pessoais. O administrador deve conceder explicitamente os novos direitos **Acessar a aba Bugs e Melhorias**, **Conferir entregas com chamado aberto**, **Visualizar alerta de WPs no chamado** e **Definir minha visão gerencial padrão**, conforme cada perfil. Não há concessão automática. A leitura nativa, entidades e CSRF continuam obrigatórios.

Apenas o status **Entregue** confirma entrega; o painel não encerra chamados, sincroniza WPs ou muda prioridades automaticamente. Atualize sem desinstalar e preserve backup. Consulte o [manual](MANUAL_DO_PLUGIN.md) e as [regras da etapa 0.31.0](RELEASE_0.31.0.md).

## Validação

- Playwright em GLPI 11.0.9 isolado, porta 8192, banco sintético: perfis administrativo/interno/cliente, acesso direto, entidade, CSRF, preferência individual e revogação.
- Filtros, todas as WPs, expansão dos detalhes, cartões e exports PDF/XLSX. Ordenação existente preservada.
- Lint PHP completo no Docker e 64 verificações isoladas das regras de cálculo; sintaxe JavaScript e diff sem erros.
- Contraste de textos/controles novos de pelo menos 4,5:1 nos temas claro e escuro; desktop 1440 e celular 390, sem transbordamento da página. As tabelas têm rolagem própria.
- Capturas sintéticas atualizadas no manual; nenhum dado de cliente utilizado.
- Preservação dos chamados, vínculos e acompanhamentos durante consultas; atualização repetida e rejeição de webhook inválido.

Homologação humana concluída e publicação autorizada pelo usuário. O tempo por etapa continua sendo observação local até a última sincronização; não é a data exata de transição no OpenProject. Desempenho com volume de produção não foi reavaliado nesta revisão visual. A publicação da release não atualiza automaticamente o ambiente de produção.
