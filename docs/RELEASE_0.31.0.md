# Gestão de Demandas 0.31.0 — em homologação

## Escopo e preservação

Nova visão operacional na aba **Bugs e Melhorias**, sem substituir a **Visão geral**. Integra o trabalho local dessa aba com a main 0.30.0, preservando documentação/relações de WPs, Backlog e correções de permissões. Não altera ponto, banco de horas, entradas de tempo, fases públicas nem sincronização.

- **Precisam de ação**: motivos clicáveis e fila de até dez chamados, com acesso ao detalhamento completo.
- Entregas totais/parciais com chamado aberto; solucionados/fechados com alguma WP não entregue, sob a mesma permissão de conferência.
- Envelhecimento observado por etapa, dados insuficientes explícitos e maiores permanências.
- Técnicos GLPI separados de responsáveis/atribuídos OpenProject; filtros de responsáveis, etapa e motivo.
- Todas as WPs de cada chamado são preservadas na grade após um filtro de WP.
- Alerta técnico por chamado e opção individual de aba padrão.
- Indicadores secundários recolhidos na aba operacional. A visão geral permanece disponível.

## Segurança e atualização

Atualizar sem desinstalar, com backup prévio. A migração idempotente cria `glpi_plugin_demandas_dashboard_preferences`, com unicidade por usuário. Não remove vínculos nem concede direitos novos.

Conceder explicitamente: **Acessar a aba Bugs e Melhorias**, **Conferir entregas com chamado aberto**, **Visualizar alerta de WPs no chamado** e **Definir minha visão gerencial padrão**, conforme necessidade. A aba exige acesso gerencial e dados técnicos; o alerta exige leitura técnica, leitura nativa do chamado e entidade ativa. Exportação exige seu direito adicional. O filtro direto de entrega/divergência sem permissão é negado, não apenas ocultado.

A preferência é POST com CSRF nativo do GLPI e usuário da sessão, sem aceitar usuário-alvo. Acesso e preferência são revalidados por perfil. Enriquecimento de responsáveis/histórico ocorre somente depois da autorização dos chamados. Não há novas chamadas externas nem ações de fechamento.

## Regras de cálculo

- Classificação segue a configuração existente: bugs e melhorias, excluindo sugestões/coletores.
- Abertos por padrão; selecionar Todos e os status desejados permite conferência histórica.
- Apenas **Entregue** confirma entrega. Desenvolvido, build publicada, PR ou status desconhecido não confirmam.
- Totais por chamado único; motivos, etapas e responsáveis podem se sobrepor.
- Eventos bem-sucedidos de sincronização/conciliação sustentam a sequência da etapa, limitada pela última sincronização. Mudança de status dentro do grupo preserva o início; sair/retornar reinicia. Status não mapeados mantêm relógios próprios.
- Sem evidência compatível, apresentar histórico insuficiente; não estimar com updatedAt, data de vínculo ou comentário. Dados futuros/inválidos não sustentam duração.
- Não é a data exata de transição no OpenProject: alterações entre consultas podem passar despercebidas.
- Prazo de atenção ajustável na consulta: 1–365 dias, padrão 14. Frescor: sete dias. Não altera parâmetros da Central de Pendências.
- Dados de nomes vêm do snapshot; “não informado” não comprova falta de responsável no sistema externo.
- História consultada em lotes de 300 chamados, com um estado acumulado por vínculo; medir duração e volume real antes de produção.

Tendências históricas de entrada/saída, SLA e previsões de capacidade não foram incluídas nesta etapa. Exigem evidência histórica e regras específicas, evitando indicadores estimados.

## Testes locais executados

- `php tests/action-dashboard-unit.php`: testes isolados das regras de idade, retorno de etapa, dados ausentes/futuros, deduplicação, preservação de WPs, filtros, responsáveis e verificação de direitos no serviço com adaptadores simulados.
- Lint PHP dos arquivos envolvidos e sintaxe JavaScript da suíte.
- `git diff --check`.

O PHP disponível para esses testes locais é 8.0.30; ele não é o runtime-alvo do GLPI 11. Esses testes não substituem SQL/ACL/CSRF nativos, migrações e renderização no GLPI 11.0.9.

## Validação prática pendente — não publicar ainda

O Docker Desktop apresentou erro ao inicializar **Inference manager**, sem disponibilizar o engine. Não foi feito reset nem alteração de volumes compartilhados. Não houve execução Playwright desta nova versão, instalação/atualização no GLPI ou captura nova para o manual. Resultados de testes anteriores não validam esta alteração.

Depois de recuperar o Docker, usar exclusivamente a base fictícia `demandas-dashboard029-glpi` (8192), ou uma nova `demandas-dashboard031-glpi` (8193), GLPI 11.0.9, banco `glpi_e2e` no host `db`. A cópia atual do plugin deve estar instalada nessa base. Nunca executar fixtures em produção, homologação compartilhada ou cópia de produção.

Copiar `tests/dashboard-bugs-fixture.php` e `tests/legacy-reconciliation-fixture.php` para `/tmp` da instância descartável. Executar `node tests/dashboard-bugs.cjs` com Playwright disponível (ou `PLAYWRIGHT_MODULE`). Para a nova instância, definir `DASHBOARD_TEST_URL=http://127.0.0.1:8193` e `DASHBOARD_TEST_CONTAINER=demandas-dashboard031-glpi`. Os parâmetros só aceitam os destinos descartáveis previstos.

A suíte cobre perfis, entidade, CSRF, preferência individual/revogação, entregas, filtros, responsáveis, idade, PDF/XLSX, hashes de leitura, atualização repetida e rejeição de webhook inválido. Complementar com instalação limpa e cenários de integração previamente existentes. Validar claro/escuro em desktop/celular; inspecionar as capturas de `docs/assets/manual/dashboard-bugs-*.png` antes de atualizar o manual. As imagens atuais estão identificadas como anteriores à fila nova.
