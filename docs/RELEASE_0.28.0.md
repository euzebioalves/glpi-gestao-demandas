# 0.28.0 — Publicações SGE e clareza na evolução das demandas

## Alterações

- Dashboard: legenda de cobertura abaixo do gráfico; ranking mostra o segundo nível da entidade sem mudar contagens ou valores do drill-down.
- Evolução: autor do vínculo na grade e usuário GLPI no histórico/modal; resolução dos IDs legados de conciliação sem reescrever eventos.
- Botão **TASKs e versões**: consulta manual das subtarefas imediatas e relações diretas da WP, seus status e publicações identificadas nos comentários. Tipos relacionados são exibidos explicitamente, sem IDs fixos de tipos.
- Builds de homologação e produção separadas; referência a PR não equivale a publicação. As versões são evidências por tarefa, não confirmação de entrega da WP inteira.
- Resultado completo datado na grade e em evento de auditoria. Eventos antigos não recebem dados de publicações novas; sincronizações seguintes podem registrar a consulta já conhecida, mantendo sua data.

## Operação e segurança

A consulta usa token pessoal e somente GET no OpenProject. Não requer acesso/chave do GitHub. Consultar exige dados técnicos, sincronização manual, entidade ativa, READ/UPDATE nativos e o par chamado/WP existente. Leitura do cache exige dados técnicos e READ na entidade. Histórico exige também seu direito específico. Não há concessão automática de permissões.

O trabalho é mantido no servidor, vinculado à sessão, usuário, token, instância e par; cada etapa passa pela autorização e CSRF nativos. Metadados vindos do cliente não são aceitos como resultado. A gravação usa transação e comparação do cache original para evitar sobrescrita por consultas concorrentes. O texto completo dos comentários não é armazenado. Conteúdo é escapado e URLs são construídas pela configuração, nunca executadas a partir de comentários.

Cada etapa faz no máximo uma chamada externa; a UI mostra descoberta e tarefas concluídas. Coleções paginadas avançam por página, detectam repetição/incompletude e falham explicitamente nos limites de 500 tarefas, 1.000 páginas ou 1.000 evidências por tarefa. Sessão da consulta expira após 15 minutos. Em erro, a última consulta completa permanece disponível.

## Atualização

Faça backup e atualize sem desinstalar. A migração adiciona apenas a coluna nullable `release_details_json` aos vínculos; preserva dados, índices da 0.27.0, permissões e históricos. Não modifica fases públicas, ponto, entradas de tempo ou WPs externas.

## Validação

Instalação limpa e atualização do esquema 0.27.0 verificadas em GLPI 11.0.9: 17 tabelas preservadas, duas reexecuções idempotentes e assinatura do webhook validada. Sintaxe dos 76 arquivos PHP e do JavaScript validada.

Administração e equipe interna consultam quando autorizadas; visualizador lê somente o cache; cliente e outra entidade são bloqueados. Repetir a conclusão não duplica o evento. Sincronização manual repetida mantém a data/evidências da consulta e identifica o operador, sem duplicar acompanhamentos.

Suíte sintética em GLPI 11.0.9/MariaDB isolados: `tests/publications.cjs`, `tests/publications-fixture.php`, `tests/publications-api.php`, usando a fixture de usuários de `tests/legacy-reconciliation-fixture.php`. Configurar `PLAYWRIGHT_MODULE` se o Playwright não estiver em `node_modules`. O teste exige o container `demandas-sge028-glpi`, banco `glpi_e2e` no host `db`, porta local 8191 e API fictícia 8395. Nunca apontar as fixtures para homologação compartilhada/produção.

Cobertura: interpretação de comentários, páginas posteriores, múltiplas tarefas/builds, PR sem publicação, nomes de eventos legados, escaping, perfis, CSRF, preservação em 401/403/timeout/identidade inválida/paginação repetida, layout desktop/mobile e capturas sanitizadas do manual. Roteiro de operação na seção 6.2 do manual.

## Limites

A consulta é explícita pelo botão; webhooks e sincronização de status não varrem TASKs. Lê relações diretas, não a árvore recursiva. A API só devolve objetos visíveis ao token; comentários fora desse acesso não são inferidos. O padrão de publicação é baseado nos exemplos fornecidos e precisa ser atualizado se a integração SGE mudar seu texto. Nenhuma build é verificada diretamente no GitHub ou no ambiente SGE. Não foram utilizadas credenciais nem dados de produção nos testes.

Contrato de leitura: [atividades de Work Packages](https://www.openproject.org/docs/api/endpoints/work-packages/) e [relações](https://www.openproject.org/docs/api/endpoints/relations/) da API oficial OpenProject.
