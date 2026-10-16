# Gestão de Demandas 0.29.0

## Escopo

Nova aba **Bugs e Melhorias**, conferência humana de entregas em chamados abertos, alerta técnico individual e escolha pessoal da visão inicial. Não há alteração de regras de ponto, tempo, fases públicas, vínculos ou sincronização.

## Segurança e instalação

Atualizar sem desinstalar, com backup prévio. A migração idempotente cria `glpi_plugin_demandas_dashboard_preferences`; não regrava vínculos nem concede direitos novos. Conceder explicitamente as quatro permissões na matriz de perfis. Aba focada exige também painel e leitura técnica; alerta exige leitura técnica/nativa e entidade ativa. Exportação e acesso direto usam as mesmas verificações. Preferência é POST com CSRF nativo e usuário autenticado, sem usuário-alvo fornecido pelo navegador.

## Regras e limitações

- Somente bugs e melhorias conforme a classificação existente; sugestões/coletores não entram.
- Seleção dos status nativos abertos do GLPI, sem conversão implícita de “Para desenvolvimento”. Solucionados/fechados nunca entram na aba focada.
- Apenas **Entregue** confirma entrega; desconhecido permanece pendente. Todas as WPs vinculadas aparecem mesmo quando o filtro corresponde a uma delas.
- Totais/grade focada por chamado único; distribuições por status WP podem se sobrepor.
- Dados da última sincronização local; sem chamada externa ao abrir o painel/alerta. Data e aviso de desatualização explícitos. Decisão do analista, sem fechamento automático.
- Preferência guarda somente a aba, não filtros. Mudança de perfil revalida o acesso.

## Homologação

Usar base descartável GLPI 11.0.9, banco `glpi_e2e`, container `demandas-dashboard029-glpi`, porta 8192. `tests/dashboard-bugs.cjs` usa Playwright/Edge e as fixtures `dashboard-bugs-fixture.php` e `legacy-reconciliation-fixture.php` copiadas para `/tmp` do container. A suíte cria usuários e chamados fictícios; nunca apontar para banco real. Dependência Playwright pode ser indicada por `PLAYWRIGHT_MODULE`.

Validar entrega total/parcial/sem status, exclusão de solucionados/fechados/outra entidade, filtro de uma WP preservando as demais, ausência de classificação, aba padrão e troca de perfil, acesso direto/CSRF, alertas por perfil, PDF/Excel, instalação/atualização repetida e temas claro/escuro em desktop/celular. Capturas ficam em `docs/assets/manual/dashboard-bugs-*.png`.

## Validações realizadas

Instalação limpa/ativação em GLPI 11.0.9 e MariaDB 11.4 descartáveis. Suíte Playwright aprovada para os cenários acima, com comparação de hashes confirmando que consultas não alteram chamados, links, eventos ou acompanhamentos. Preferência restrita ao usuário autenticado; CSRF ausente e gravações sem direito retornam 403. Revogar leitura técnica bloqueia a aba e o alerta e faz a preferência voltar à visão geral. Atualização repetida preserva as 18 tabelas existentes do plugin; webhook com assinatura inválida é rejeitado. Sintaxe PHP e JavaScript validada. Não houve teste com dados de produção nem migração em base compartilhada.
