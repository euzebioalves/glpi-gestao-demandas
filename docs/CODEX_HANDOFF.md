# Guia de continuidade — Plugin Gestão de Demandas

## Atualização 0.36.0 — revisão, etapa 5

0.35.0 confirmada em produção pelo usuário. Etapa 5 na branch `codex/security-continuous-validation`: `scripts/quality` reúne política estática por tokens, segredos conhecidos, lockfile, regressão Docker, inventário PHP e pacote determinístico. Workflow sem segredos de produção, token de leitura e runner efêmero. Não muda regras, banco ou direitos. Não adicionar analisadores/dependências sem decisão conforme AGENTS.md.

Testes mutáveis são exclusivos de `demandas-test-021-glpi` / `demandas-test-021-db` (8386); nunca executar contra 8180. O fixture de operações usa o usuário sintético security-admin e grava credenciais pelo CredentialStore. Relatórios/pacotes ficam em dist/quality; backups privados em dist nunca são publicados. Cobertura, limitações e comandos em `docs/VALIDACAO_CONTINUA.md` e `docs/RELEASE_0.36.0.md`. Execução remota acompanhada no PR #21; merge e release exigem conferir o resultado do workflow do commit publicado.

Suíte integrada local aprovada: instalação limpa/repetida, 66 regras de dashboard, 36 indicadores, credenciais/conteúdo, rede, perfis/CSRF, operações e webhook. Cinco controles negativos e pacote determinístico conferidos. Fixture de indicadores fixa o instante de referência; anexos são conferidos pelo GLPI_DOC_DIR real e por hashes/metadados. Homologação 8180 ativa em 0.36.0, com os 22 hashes de dados/configuração/direitos idênticos antes/depois. Validação humana aprovada em 27/10/2026; publicação da main e release autorizadas pelo usuário.

## Atualização 0.35.0 — revisão, etapa 4

`StageObservation` compartilha a sequência local entre dashboard e central; `IndicatorPolicy` valida datas, frescor, nomes iniciais configurados, entrega exata e categoria segura de falha. A leitura de eventos conserva a última falha até uma observação bem-sucedida posterior. Não inferir indisponibilidade/permissão por texto de exceção. Novos eventos de sincronização guardam apenas categoria allowlist, sem regravar históricos. Fases públicas e filtros remotos abertos permanecem independentes.

`OperationalHealthService` usa conexão primária, lock global curto por execução/tratativa e transação. Falhas revertem alterações da rodada; qualquer vínculo com dado ausente/desatualizado/última falha torna o resultado parcial e bloqueia TODAS as resoluções dessa rodada. Só uma rodada completa resolve ocorrências dos chamados efetivamente avaliados, com auditoria de execução e evidência anterior. Migração adiciona colunas nulas owner_users_id/due_date/treatment_note e metrics_json, sem reescrever dados. Direitos permanecem os mesmos. Assumir responsável usa exclusivamente o ator da sessão; prazo validado, adiamento exige motivo. O histórico acessível é limitado a 100 eventos mais recentes, sem exclusão. Não usar essas tratativas para fechar chamados ou alterar WPs.

Versão 0.35.0 instalada e ativa na homologação http://localhost:8180, Validada pelo usuário, com publicação na main e release 0.35.0 autorizadas. Os 22 hashes semânticos de dados/configuração/direitos coincidiram antes/depois; novas colunas ficaram nulas, sem tratativas automáticas. Testes: 36 casos de indicadores/tratativas/rollback, 66 de dashboard, HTTP/CSRF/perfis/responsividade, regressão de operações e instalação limpa/repetida aprovados. Backups privados de banco e configuração/chave em dist/; não publicar esses arquivos. Roteiro e evidências em `docs/RELEASE_0.35.0.md`.

## Atualização 0.34.0 — segurança, etapa 3

Produção confirmou MariaDB e funcionamento da 0.33.0. A etapa 3 protege credenciais e conteúdo sem modificar direitos, vínculos, saldos ou fases públicas. `CredentialStore` migra tokens pessoais, automático/legado e segredo de webhook em transação, com marcador `credentials_encrypted_0340`. Usa ciphertext nativo de `GLPIKey`, registrado em `SECURED_CONFIGS`/`SECURED_FIELDS` para rotação. Não adicionar prefixos ao ciphertext ou gravar valores diretamente por SQL. `Config::get` decifra apenas o segredo solicitado; `Config::all` entrega valores decifrados para os fluxos internos autorizados.

O instalador grava somente configurações ausentes, sem recriptografar valores atuais. Backup inclui `glpicrypt.key`; rollback exige o conjunto banco/configuração compatível. Chave ausente/inválida falha sem substituição automática dos segredos.

`IntegrationSecurity` valida URLs e caminhos e apresenta erros seguros mantendo a exceção anterior para a classificação de operações incertas da etapa 2. Não remover essa cadeia: respostas perdidas não podem liberar outro POST. Log novo contém apenas referência/categoria/status, sem payloads. Históricos anteriores são preservados.

`AttachmentSecurity` verifica bytes/tamanho/MIME e limites de imagens/arquivos compactados. Não extrai ZIP nem promete antivírus. Webhook: 2 MB/64 níveis, assinatura sobre bytes originais. API: sem redirects, até 16 MB/64 níveis. Extração: até cinco anexos, `timeout`/`prlimit`, drenagem não bloqueante de stdout/stderr e descarte de conversões falhas. Ausência das ferramentas desabilita conversão, sem instalar dependências automaticamente.

Roteiro, riscos residuais e testes em `docs/RELEASE_0.34.0.md`. Implementação local na branch `codex/security-credentials-content`. Homologação atualizada/ativa em 0.34.0; hashes semânticos antes/depois coincidiram, incluindo tokens e permissões. Imagem reconstruída pelo Dockerfile existente com PDF/Office/OCR, sem trocar as versões-base ou volumes. Conexão automática local confirmada. Backups de banco/configuração em `dist/` ignorado; Validação humana local aprovada e publicação da 0.34.0 na main autorizada pelo usuário.

## Atualização 0.33.0 — segurança, etapa 2

`OperationGuard` usa locks de conexão no banco primário para serializar entradas de tempo/ponto por usuário e sincronizações por vínculo, além de transações nativas do GLPI. O ambiente-base é MariaDB 11.4; a verificação de transação externa usa `@@session.in_transaction` (não transportar sem revisão para outro SGBD). Locks liberam ao encerrar a conexão; o estado de envio incerto persiste antes do POST remoto.

`TimeManagementService::saveDay` substitui as duas gravações independentes de `punch.form.php`: ponto, ausência, metadados e auditoria são atômicos. Uploads novos são removidos em rollback; arquivos antigos só são removidos após commit. Uma interrupção abrupta do processo pode deixar arquivo órfão sem referência, mas não apagar um anexo ainda registrado. Não alterar as regras de saldo/jornada nesta camada.

Entradas de tempo usam `uncertain` antes de POST; somente rejeições HTTP definitivas permitem nova tentativa automática. A conferência humana usa o token pessoal e valida ID, usuário, WP, atividade, data, duração e comentário. A opção de declarar ausência é explícita e auditada: uma declaração incorreta ainda pode causar duplicidade. `update_pending`/`delete_pending` preservam a operação no mesmo ID remoto; a nova coluna `pending_operation_json` guarda a intenção de edição. Não limpar esses estados em migrações. As autorizações da etapa 1 permanecem intactas.

`SynchronizationService` relê o vínculo após obter o lock, persiste transição e job no mesmo commit e publica cada acompanhamento junto com a conclusão do job em outra transação. `glpi_plugin_demandas_followup_jobs` guarda o snapshot da regra/privacidade da transição. Falhas são reprocessadas pela sincronização seguinte (manual ou webhook); não existe worker periódico novo. Não reenfileira eventos históricos anteriores à atualização. Uma saída e retorno ao status gera nova transição legítima.

Roteiro, testes e limites: `docs/RELEASE_0.33.0.md`, `tests/operations.py`, `tests/operations-http.cjs`. Os testes de falhas usam exclusivamente o Docker descartável, sem POSTs ao OpenProject real. Validação operacional local aprovada pelo usuário em 26/10/2026, com autorização para merge na main e publicação da release.

## Atualização 0.32.1 — segurança, etapa 1

`TimeAccess` compartilha autorização entre telas, endpoints e serviços de horas/ponto. Preserva `AccessPolicy` e seus valores cadastrados; não transforma exceções em direitos de outros módulos. Alvos devem existir, não estar excluídos e compartilhar entidade ativa via `Profile_User::getUserEntities()` (incluindo associação recursiva), ou ser o próprio usuário autenticado. As permissões do módulo continuam delegando a ação; a navegação pelo cadastro de usuários também mantém o controle nativo do GLPI. Não acrescentar automaticamente direitos aos perfis.

Exceções editáveis são as oito de horas/ponto já presentes em `UserTimeProfile`, inclusive `MANAGE_TIME_ACCESS`, agora compartilhadas com Administração do ponto. Somente -1/0/1 são aceitos (Herdar/Negar/Permitir). O lote inteiro é validado antes de escrever, com transação e auditoria anterior/novo por mudança efetiva. Registros legados fora desse catálogo não são apagados nem alterados automaticamente. Regras de saldo/jornada e comportamento de `AccessPolicy::has()` permanecem inalterados.

`OperationalHealthService::candidates()` devolve ocorrências e IDs de todos os chamados efetivamente avaliados, inclusive sem ocorrências. `resolveMissing()` limita a resolução a esses IDs, entidades ativas e leitura nativa, preservando inacessíveis/excluídos. Falha de classificação não equivale a ausência de problema: interrompe a avaliação antes das gravações de ocorrências e resoluções. `run`/`act` repetem autorização no serviço e exigem ator da sessão. Não corrige retrospectivamente estados antigos sem revisão humana.

Roteiro, evidências e limites em `docs/RELEASE_0.32.1.md`; regressões sintéticas em `tests/security-stage1.cjs` e fixture exclusiva do banco descartável. A versão 0.32.1 inclui as melhorias de interface da 0.32.0. Validação operacional local aprovada pelo usuário em 26/10/2026, com autorização para merge na main e publicação da release.

## Atualização 0.32.0 — organização das permissões (26/10/2026)

O usuário solicitou incluir na próxima melhoria a reorganização da tela **Administração > Perfis > Gestão de Demandas**. A lista única de permissões cresceu e está dificultando a administração.

- Agrupar as permissões por módulo/grupo funcional, com seções claramente identificadas.
- Disponibilizar ajuda para cada permissão, descrevendo exatamente quais ações e dados libera e em quais menus, páginas, abas ou componentes seu efeito aparece.
- Explicar os pré-requisitos e as dependências entre permissões, quando houver, distinguindo acesso próprio e acesso a outros usuários.
- Preservar os direitos já concedidos, os identificadores das permissões e as verificações no backend; a reorganização não deve conceder nem revogar acessos automaticamente.

Implementado localmente na 0.32.0. `PermissionGuide` contém somente metadados de apresentação: sete grupos e ajuda para cada direito. `Profile::definitions()`, IDs e endpoint de gravação permanecem como fonte de autorização. A tela usa um único formulário, controles associados a labels e ajuda nativa `details/summary`, sem depender de JavaScript para consultar ou salvar direitos. Não conceder dependências automaticamente.

`WorkPackageDocumentationForm` começa com a área de edição/anexos oculta. `TicketDemand` sinaliza `creationReady` apenas após carregar os campos de projeto/tipo, invalida respostas antigas por geração e emite eventos de reset/prontidão. `documentation.js` adia a inicialização até essa prontidão e revela o editor após carregar o template; mudanças de seleção preservam o texto e recolhem os controles até os novos campos estarem prontos. Para WPs existentes, revela após carregar a documentação. As verificações do backend permanecem obrigatórias e inalteradas.

## Atualização 0.31.1 — apresentação para tomada de decisão

Revisão visual exclusiva de Bugs e Melhorias. `ActionDashboardPanel` traduz motivos em próximos passos, recolhe zeros e detalhes de WPs, resume a fila e mostra barras de quantidade por etapa com duração separada. `public/css/action-dashboard.css` é carregado somente nessa aba, com cores próprias para contraste em claro/escuro. Filtros e Opções do painel começam recolhidos. Nenhuma alteração em ActionDashboard, DeliveryReview, serviço, ACL, exportadores ou schema.

Docker recuperado. Suíte prática executada no GLPI descartável 11.0.9 da porta 8192, com dados sintéticos: ACL, entidades, CSRF, preferências, filtros, PDF/XLSX, preservação de registros, upgrade repetido e webhook inválido. Contraste dos novos textos/controles >= 4,5:1; capturas desktop/celular nos dois temas incorporadas ao manual. Recarregar após mudar o viewport nos testes, pois o layout nativo do GLPI adapta a barra lateral na navegação. Homologação humana concluída; merge na main e publicação da release 0.31.1 autorizados pelo usuário.

## Atualização 0.31.0 — Bugs e Melhorias e conferência de entregas

`DashboardView` autoriza a aba focada com VIEW_DASHBOARD + VIEW_TECHNICAL + VIEW_BUGS_DASHBOARD. Conferência exige adicionalmente VIEW_DELIVERY_REVIEW. Preferência exige CONFIGURE_DASHBOARD, POST/CSRF e usa exclusivamente o usuário autenticado. `glpi_plugin_demandas_dashboard_preferences` tem unicidade por usuário; a preferência não concede direitos e retorna à visão geral quando o perfil ativo deixa de permitir a aba. Não há concessão automática de novos direitos.

`ManagementDashboardService` reutiliza a classificação existente (bugs e melhorias, excluindo sugestões/coletores), começa com chamados abertos e aplica os status selecionados. O escopo Todos permite conferir solucionados/fechados. Filtros de WP selecionam chamados; em seguida todos os vínculos desses chamados são restaurados. A grade focada e os totais deduplicam por chamado. Indicadores consideram o escopo dos filtros; drill-down e conferência detalham esse escopo na grade e exportação. A visão geral não consulta o novo histórico nem carrega o JSON técnico adicional.

`ActionDashboard` enriquece exclusivamente chamados já autorizados, buscando técnicos GLPI e eventos bem-sucedidos por lotes de 300 chamados. Mantém somente um estado de observação por vínculo em memória. Eventos de sincronização/conciliação formam a sequência local da mesma etapa; troca de status dentro do grupo não reinicia o tempo, saída/retorno reinicia. Status não reconhecidos usam sequência própria, embora apareçam no grupo Outras situações. O status final deve coincidir com o snapshot. O limite superior é a última sincronização, nunca o horário atual; data futura/inválida ou histórico insuficiente não produz duração. Não usar updatedAt, comentário, vínculo ou build como data de entrada na etapa. Mudanças não observadas entre consultas continuam desconhecidas.

`ActionDashboardPanel` apresenta motivos com drill-down, fila de até dez chamados, envelhecimento e responsáveis. Técnicos GLPI não são confundidos com responsável/atribuído OpenProject. Sem nome no snapshot significa não informado, não ausência comprovada. Prazos: atenção ajustável por filtro (1–365, padrão 14 dias), frescor sete dias. Os motivos partial/all_delivered/closed_pending, filtros diretos e exportações exigem VIEW_DELIVERY_REVIEW. Não há novos acessos concedidos. A revisão 0.31.1 recolhe também os filtros; a Visão geral e preferências existentes são preservadas.

O bloqueio inicial do Docker foi resolvido; testes práticos e capturas atuais estão registrados acima, na revisão 0.31.1. A branch integra a main 0.30.0 preservando documentação de WP, relações, Backlog de colegas e correções de permissões. A aprovação humana foi recebida para publicar o conjunto na versão 0.31.1.

`DeliveryReview` reconhece somente o nome Entregue (ignorando caixa/espaços externos), nunca IDs fixos ou isClosed. Status desconhecidos não contam como entrega; com outra WP entregue, o caso é parcial. Não encerrar chamados. O hook `post_itil_info_section` apresenta o alerta apenas com VIEW_TICKET_WP_ALERT + VIEW_TECHNICAL + leitura nativa/entidade. Somente dados locais; mostrar última sincronização e alerta após sete dias/sem data. Não alterar fase pública nem enviar acompanhamentos. `tests/dashboard-bugs.cjs`/fixture usam Docker descartável 8192 ou 8193 com dados fictícios. Veja release/manual para homologação.

## Atualização 0.30.0 — Backlog

O carregamento de cards não espera `time_entries`; `action=times` consulta lotes de até 25 IDs, sempre usando a identidade do próprio token. O frontend descarta respostas de quadros anteriores e não confunde horas pendentes/indisponíveis com zero. GETs liberam o bloqueio da sessão antes das consultas remotas. `summarizeWorkPackage(..., true)` usa schema GET por projeto/tipo com cache de falhas durante a requisição, sem expandir usuários/valores de formulários de criação.

`VIEW_COLLEAGUE_BACKLOG` controla listagem de pessoas e consultas a outro responsável, inclusive detalhe/imagem. Usa `principals` visíveis ao token, não `users` (que exige administrar usuários no OpenProject 17). As preferências continuam pertencendo ao usuário GLPI logado. `BacklogDetails` confirma WP e responsável pela API, saneia HTML ou converte Markdown com bibliotecas do GLPI e limita imagens aos anexos da própria WP. O proxy revalida acesso e pertencimento, aceita raster até 15 MB e não segue redirects. Links de chamados vêm somente de vínculos confirmados, com entidade ativa, não excluído e READ nativo. Nunca usar a descoberta de chamados por varredura irrestrita de acompanhamentos para preencher esse quadro. Consulte `RELEASE_0.30.0.md`.

## Atualização 0.29.1 — consultas automáticas e permissões

`front/ui-permissions.php` exige sessão autenticada e retorna somente dois booleanos dos direitos efetivos do perfil ativo (`VIEW_PUBLIC` e `VIEW_TECHNICAL`), com `private, no-store`. `demandas.js` compartilha essa consulta durante a página, sem armazenamento persistente. A fase é consultada somente em `front/ticket.form.php` com ID e direito público; o feed exige direito técnico. Falhas fecham o acesso na interface. Os endpoints de dados mantêm integralmente a autorização existente, inclusive leitura nativa do chamado. Não conceder direitos para silenciar logs; acessos diretos indevidos continuam sendo registrados. Veja `RELEASE_0.29.1.md` e `tests/ui-permissions.cjs`.

## Atualização 0.28.0 — autores e publicações SGE

`IntegrationActor` resolve o nome atual do GLPI pelo ID estruturado do evento (ou pelo padrão legado de conciliação), com fallback para o nome capturado. Não regrava auditoria antiga nem atribui eventos sem identidade ao usuário atual. Novas ações manuais e de conciliação capturam ID/nome; webhook é identificado como automação.

`WorkPackagePublicationService` consulta somente GETs do OpenProject com token pessoal, em etapas POST autenticadas/CSRF. O servidor descobre filhos diretos e relações `involved` da WP vinculada, valida identidades, percorre atividades e extrai declarações `Incluída na build vX.Y.Z`. PRs não são evidência. Não assume que a WP inteira foi publicada quando uma TASK tem build. A sessão mantém um único trabalho com prazo de 15 minutos, proprietário, par chamado/WP, identidade do token e instância. Cada etapa revalida permissão técnica, sincronização, entidade, READ/UPDATE e vínculo. Limites: 500 tarefas, 1.000 páginas por coleção, 1.000 evidências por tarefa; ultrapassagens falham sem gravar resultado parcial.

A migração adiciona `release_details_json` em links. A gravação completa compara o cache original e cria evento em transação. Não gravar comentários brutos nem URLs extraídas. Não sobrescrever esse cache nas sincronizações normais. Eventos de sincronização incluem a última consulta conhecida com sua data original. O botão **Consultar agora** atualiza publicações; a abertura da aba e webhooks não disparam essa varredura. O GET do modal usa somente o cache e exige leitura técnica e nativa. Eventos antigos não recebem retrospectivamente versões atuais. Renderização usa escaping/textContent e links construídos pela URL configurada.

`publications.js` percorre etapas e renova o token CSRF, mostrando descoberta/quantidade de tarefas. Novos endpoints não fazem chamadas ao GitHub. Consulte `RELEASE_0.28.0.md`, `tests/publications.cjs` e fixtures exclusivamente sintéticas. O ranking do dashboard encurta somente o rótulo ao segundo nível da entidade, sem reagrupar valores nem alterar drill-down.

## Atualização 0.27.0 — conciliação de múltiplos vínculos

A tabela de links agora usa `unicity_ticket_work_package(tickets_id, openproject_work_package_id)` e índice não único por WP. A migração adiciona os índices e remove `unicity_work_package` em um ALTER, sem regravar linhas. `TicketDemand::saveLink()` atualiza somente o par. `findByWorkPackage($wp, $ticket)` resolve um par; sem ticket, retorna null quando houver ambiguidade. Use `findAllByWorkPackage()` para percorrer todos os vínculos. O webhook sincroniza todos; histórico/cache e sincronização manual continuam separados por chamado. Não copiar fases, históricos ou tempo de um vínculo para outro.

`LegacyReconciliationService` mantém avisos de compartilhamento e de múltiplas WPs, mas permite confirmação com `MANAGE_RECONCILIATION_CONFLICTS` e justificativa de 10–2.000 caracteres, além dos direitos anteriores. O evento do novo par registra usuário, justificativa e motivos. Pares já existentes não são regravados. A transferência antiga continua excepcional, somente quando há um único vínculo seguro; a adição justificada preserva todos os anteriores. Referências inacessíveis não expõem IDs ou títulos.

O parâmetro numérico `origin_ticket` acompanha filtros, paginação e POST. O retorno é construído internamente com `forcetab=GlpiPlugin\\Demandas\\TicketDemand$1` após checar leitura e entidade; não recebe URL arbitrária. Sem origem, o fluxo permanece na listagem geral. Consulte `RELEASE_0.27.0.md` e `tests/reconciliation-many.cjs`.

## Atualização 0.26.0 — Backlog pessoal

`BacklogHub` exige `VIEW_TECHNICAL` e `VIEW_BACKLOG`; salvar também exige `CONFIGURE_BACKLOG`, POST e CSRF nativo. `BacklogService` usa exclusivamente a identidade do token pessoal para filtrar responsável, sem restringir tipos de WP nem depender dos vínculos locais. O endpoint não aceita um usuário-alvo. Preferências em `glpi_plugin_demandas_backlog_preferences` são únicas por usuário GLPI e só são gravadas pelo POST explícito. IDs de status removidos são preservados até a pessoa desmarcá-los. Sem preferência salva, são apresentados os status abertos do catálogo, priorizando Novo, Em Especificação e Em Dúvida. Nenhum direito novo é concedido automaticamente.

O cliente pagina WPs e entradas de tempo, valida usuário/entidade ao somar e não utiliza `spentTime` da WP, que inclui outras pessoas. Uma falha na leitura de horas produz valor indisponível. Dados da WP não são persistidos nem publicados em chamados; detalhes usam texto, não HTML da API. A interface não movimenta cards entre status. Consulte `RELEASE_0.26.0.md` e `tests/backlog.cjs`.

## Atualização 0.25.0 — coluna nativa de Work Package

`TicketSearch` adiciona a opção estável `925001` ao itemtype `Ticket` pelos hooks automáticos `getAddSearchOptionsNew`, `addWhere` e `giveItem`. Não mudar esse ID: preferências e buscas salvas o persistem. A opção e o renderizador exigem `VIEW_TECHNICAL`; a renderização confirma também `Ticket::can(READ)`. A consulta principal mantém as restrições nativas de entidade/chamado. Filtros usam subconsultas sobre os vínculos, sem restringir o join de exibição: buscar uma WP mostra todas as WPs do chamado e filtros negativos excluem o chamado inteiro se qualquer WP corresponder. Não aplicar filtros ao agregado que contém IDs internos dos vínculos.

O renderizador usa os valores agrupados fornecidos pelo GLPI, sem API OpenProject. Resultado vazio retorna um espaço porque string vazia acionaria o formatador padrão do GLPI. URLs aceitam somente HTTP(S) externo válido; sem URL válida, o ID fica em texto. Exportações nativas usam texto com pipe. Não há mudança de esquema, migração de vínculos ou concessão de direitos. Veja `RELEASE_0.25.0.md` e `tests/ticket-search.cjs`.

## Atualização 0.24.1 — modal de ponto responsivo

Em `front/timeclock.php`, o próprio formulário é o `.modal-content` diretamente dentro de `.modal-dialog-scrollable`. Preserve essa estrutura: um formulário intermediário sem limite de altura impede a rolagem nativa e corta o rodapé. A grade das marcações usa colunas flexíveis e as opções de ausência podem quebrar linha. Backend, permissões, CSRF e regras de saldo permanecem iguais. Veja `RELEASE_0.24.1.md` e `tests/timeclock-modal.cjs`.

## Atualização 0.24.0 — conciliação assistida e release controlada

Na versão `0.24.0`, `LegacyReconciliationService` entrega detalhes estruturados de conflito à página `front/legacy-reconciliation.php`. A transferência é local e excepcional: exige `MANAGE_RECONCILIATION_CONFLICTS`, leitura/UPDATE nativos nos dois chamados e um conflito exclusivamente `linked_elsewhere`, sem entradas de tempo, `public_phase` ou `public_message`. Ela atualiza somente o vínculo existente em transação e registra eventos nos dois chamados. Não use `TicketDemand::saveLink()` nesse fluxo, pois o upsert pode mover um vínculo fora desses bloqueios. Referências múltiplas continuam sem transferência automática.

`PluginUpdateService` é uma tarefa cron diária sem escrita de código. Ele consulta a API fixa da release oficial, armazena versão/links/estado em `plugin:demandas` e não faz download. O arquivo raiz `update-plugin-release.ps1` é deliberadamente externo ao pacote do plugin: valida ZIP/versão de release, preserva um backup e substitui a pasta somente com `-Apply`, em janela de manutenção. Nunca transformar esse recurso em instalação pelo navegador, execução de shell pelo GLPI ou atualização de banco fora do fluxo nativo **Configuração > Plugins > Atualizar**.

A listagem de entradas de tempo agora consulta a leitura nativa do chamado antes de renderizar o link. Preserve esse comportamento se a grade for refatorada.

Entradas de tempo também admitem `tickets_id=0`, sem criar vínculo. `front/time-entry-work-packages.php` exige `VIEW_TIME_PORTAL` e `LOG_OWN_TIME`, pesquisa somente com token pessoal e limita resultados. O serviço valida a identidade da WP na API; entradas vinculadas exigem leitura nativa do chamado no backend. A grade resolve projeto/identifier para o relatório de custos das WPs externas. Não converter esses apontamentos em marcações de ponto.

## 1. Finalidade

O plugin **Gestão de Demandas** complementa o GLPI com recursos para acompanhar demandas recebidas como chamados e tratadas tecnicamente no OpenProject. O GLPI continua sendo a interface de atendimento e visibilidade do cliente; o OpenProject concentra a gestão interna das Work Packages.

Este documento apresenta o estado funcional e técnico da versão `0.31.0` e deve ser usado como contexto inicial por qualquer agente Codex que continue o desenvolvimento.

## 2. Ambiente de referência

| Componente | Versão de homologação | Endereço padrão |
|---|---:|---|
| GLPI | 11.0.9 | `http://localhost:8180` |
| OpenProject | 17.7.2 | `http://localhost:8280` |
| MariaDB | 11.4 | rede Docker interna |
| Plugin Gestão de Demandas | 0.31.0 | `plugins/demandas` |

O ambiente é voltado exclusivamente à homologação local. Não deve ser publicado sem HTTPS, gestão externa de segredos, backup, monitoramento e revisão de segurança.

## 3. Domínios funcionais

### 3.1 Gestão de demandas

Na 0.24.0, `ManagementDashboardService` aplica `ticket_scope=open` por padrão, excluindo `Ticket::SOLVED` e `Ticket::CLOSED` e confirmando leitura nativa por chamado. `ticket_scope=all` mantém o histórico completo; filtros, drill-down e exportações compartilham o escopo. `LegacyReconciliationService` descobre URLs completas em **Atividade DevOps** pelo Fields, aceita somente a instância externa configurada e exige conferência humana. A gravação usa transação e unicidade de WP; a exceção controlada de transferência não usa o upsert de `TicketDemand::saveLink`. Os endpoints exigem direitos técnicos/criar-vincular, o direito adicional de transferência quando aplicável, leitura/UPDATE nativos, entidade ativa e CSRF. A conciliação consulta somente GET de WP com token pessoal; não chama o sincronizador para publicar fase/acompanhamento. Não há nova tabela ou migração nesta versão. Consulte `RELEASE_0.24.0.md` para testes e limites.

- vínculo de um chamado do GLPI com uma ou mais Work Packages;
- criação de User Stories e Bugs a partir do chamado;
- seleção dinâmica de projeto, tipo, prioridade, atribuído, responsável e cliente;
- templates Markdown configuráveis por tipo de Work Package;
- fases públicas desacopladas dos status técnicos do OpenProject;
- acompanhamentos automáticos conforme mudança de fase;
- histórico de sincronizações e visão técnica restrita;
- sincronização manual e automática por webhook assinado;
- painel gerencial com filtros, indicadores, drill-down e exportações.
- central de pendências operacionais baseada nos dados locais, com verificação manual, filtros e auditoria de tratativas.

### 3.2 Segurança e perfis

O plugin possui direitos próprios para separar:

- visão pública;
- dados técnicos;
- histórico/log;
- criação de Work Package;
- sincronização;
- configuração;
- controle de ponto;
- lançamento de entradas de tempo;
- administração de jornadas, ausências e feriados.
- visualização e operação da central de pendências de integração.

As verificações devem ocorrer no backend, inclusive em endpoints de formulários e consultas AJAX. A visão do cliente nunca deve revelar dados internos do OpenProject sem autorização explícita.

### 3.3 Controle de ponto

- jornada individual com entrada, saída, intervalo, tolerância e localização;
- múltiplas marcações por dia, tipo, NSR opcional e observação;
- preenchimento sequencial dos quatro tipos principais em novas marcações;
- calendário mensal no padrão brasileiro (domingo a sábado), feriados e compensações;
- indicador visual do intervalo de almoço, calculado exclusivamente entre as marcações de saída da manhã e entrada da tarde;
- saldo histórico opcional, positivo ou negativo, acumulado com as marcações a partir da data-base informada;
- extrato auditável do banco, com saldo histórico, cada crédito ou débito que incidiu no acumulado e faltas justificadas neutras para conferência;
- faltas integrais ou parciais, justificadas ou não;
- anexos de justificativa em formatos usuais, limitados a 15 MB por arquivo;
- página de ausências por usuário, com filtro de justificativa e download autorizado dos comprovantes;
- feriados, compensações e dias não úteis configurados com a permissão `MANAGE_HOLIDAYS` (respeitando a política de exceções de horas);
- auditoria das alterações;
- cálculo de jornada e banco de horas.

Regra fundamental: **dia sem marcação e sem ausência registrada é neutro**. Apenas fatos efetivamente registrados podem alterar o saldo. Faltas justificadas são neutras; faltas não justificadas debitam o período correspondente. Feriados, compensações e dias não úteis cadastrados também são neutros, mesmo que tenham marcação ou ausência.

### 3.4 Entradas de tempo

Entradas de tempo são independentes das marcações de ponto.

- lançamento local vinculado a uma Work Package;
- início obrigatório e término opcional;
- encerramento posterior pela grid ou pelo modal de edição;
- atividade obrigatória carregada dinamicamente do OpenProject;
- comentário opcional enviado com prefixo `- `;
- sincronização manual individual ou em lote;
- estados em andamento, pendente, sincronizada e erro;
- edição e exclusão imediatas no OpenProject quando a entrada já estiver sincronizada;
- link da WP para a página da Work Package;
- ID da entrada sincronizada apontando para o relatório de custos filtrado pela WP.

Somente essas entradas são enviadas ao OpenProject. Horas de ponto nunca devem ser convertidas automaticamente em Time Entries.

## 4. Componentes técnicos principais

| Arquivo/classe | Responsabilidade |
|---|---|
| `Config.php` | configurações e prontidão da integração |
| `OpenProjectClient.php` | comunicação com a API do OpenProject |
| `SynchronizationService.php` | sincronização dos vínculos e histórico |
| `WebhookHandler.php` | validação e processamento dos webhooks |
| `AccessPolicy.php` / `Profile.php` | autorização e direitos do plugin |
| `TicketDemand.php` | apresentação da integração no chamado |
| `ManagementDashboardService.php` | consultas e indicadores gerenciais |
| `WorkPackageMonitoringService.php` | consulta pessoal de WPs abertas, snapshots, vínculos e alertas |
| `WorkPackageMonitoringHub.php` | menu e acesso às páginas de monitoramento e alertas |
| `WorkPackageAnalysisService.php` | prepara localmente contexto selecionável de WP, chamado, acompanhamentos públicos e anexos para cópia em IA externa |
| `TimeManagementService.php` | ponto, jornadas, ausências e entradas de tempo |
| `WorkPackageTemplate.php` | templates e variáveis permitidas |
| `hook.php` | instalação, atualização e hooks do GLPI |

## 5. Integração com OpenProject

### Comunicação

- API interna: `http://openproject/api/v3`;
- URL pública padrão: `http://localhost:8280`;
- webhook interno: `http://glpi/plugins/demandas/webhook.php`.

O Compose da homologação 11.0.9 usa o projeto Docker `demandas-hml1109`, rede
e volumes exclusivos. Não reutilize nomes de projeto ou volumes de homologações
anteriores, pois as credenciais gravadas pelo MariaDB na primeira inicialização
não são alteradas automaticamente quando o arquivo `.env` muda.

Dentro do Docker, `localhost` não deve ser usado entre contêineres.

### Identidade e token

Use usuário técnico dedicado e com o menor conjunto de permissões necessário. Nunca use a conta `admin` permanentemente. O token automático desse usuário é configurado somente por perfil ativo com **Administrar as configurações do plugin** (`MANAGE_CONFIG`), é reservado a webhooks e outras sincronizações automáticas e o código do plugin o bloqueia na criação de Work Packages. Cada usuário que execute criação, sincronização manual ou lançamento manual de tempo deve registrar seu token pessoal em **Minhas configurações > OpenProject**. Tokens devem permanecer no ambiente/configuração do GLPI e fora do Git.

O menu **Gerência > Monitoramento de Work Packages** exige `VIEW_TECHNICAL` e usa exclusivamente o token pessoal do usuário logado. A consulta identifica User Stories, Épicos e Bugs abertos nos quais aquele token é o Responsável; não cria nem altera WPs. O consolidado exige também `VIEW_DASHBOARD` e mostra somente resultados efetivamente coletados por usuários. Alertas, exportação pessoal e preparo de IA exigem `VIEW_TECHNICAL` no backend. Alertas são gravados por usuário e versão observada da WP, evitando repetição da mesma mensagem em consultas subsequentes.

Na listagem pessoal, o botão **Preparar IA** exige o direito `PREPARE_AI_CONTEXT` e só opera sobre uma WP do próprio snapshot do usuário. Se houver chamado efetivamente vinculado, o serviço também permite selecionar os dados desse chamado. Sem chamado identificado, o contexto é formado apenas pelos dados da WP e seus links. O serviço monta o contexto em memória, sem persistir texto ou anexos e sem comunicar-se com qualquer provedor de IA. A pessoa escolhe cada seção e anexo, confirma que está autorizada a compartilhar o resultado e copia o prompt para a ferramenta de sua preferência. Apenas acompanhamentos públicos e documentos com leitura permitida entram na seleção. A imagem local do GLPI instala Poppler, LibreOffice e Tesseract (`por` e `eng`) para extração local; limites de tamanho e caracteres reduzem risco de exposição e saturação de recursos.

Para entradas de tempo, o plugin também admite o vínculo do usuário do GLPI com o usuário correspondente do OpenProject. Revise sempre os direitos de visualizar e registrar horas no projeto.

### Webhook

O webhook precisa ter segredo compartilhado e escopo restrito. O processamento deve ser idempotente, validar assinatura antes de interpretar a carga e não confiar em dados da requisição sem validação.

## 6. Instalação e execução local

No Windows, com Docker Desktop e WSL 2:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\start.ps1
```

Depois que os serviços estiverem saudáveis, siga as instruções do `README.md` para configurar a homologação e instale/ative o plugin em **Configuração > Plugins** no GLPI.

Nunca confirme `APAGAR` no script de reset sem entender que os volumes e dados de homologação serão removidos.

## 7. Estratégia de versionamento

### Documentação e relações — 0.29.0

`WorkPackageDocumentation` e `front/work-package-documentation.php` controlam edição e criação com token pessoal, autorização técnica/nativa, CSRF e sessões temporárias vinculadas à identidade. O editor usa bibliotecas já distribuídas no GLPI. Anexos sem contêiner são associados ao salvar; descrições existentes usam `lockVersion`. Nunca repita a criação de uma WP ao tratar falha posterior de relação: a sessão guarda o ID confirmado e retoma somente as etapas pendentes. Resultado desconhecido exige conferência humana. Consulte `RELEASE_0.29.0.md` para limites e testes.

A conciliação exige justificativa apenas quando existe outro vínculo local do chamado ou WP. Possibilidades no Fields continuam sendo mostradas em detalhes, mas não obrigam justificativa para a primeira associação. No lote, a validação no backend ocorre a cada vínculo e considera as associações gravadas anteriormente no mesmo envio.

- versão declarada em `plugins/demandas/setup.php`;
- histórico funcional em `plugins/demandas/README.md`;
- o roteiro exibido na aba **Tutorial** deve ser atualizado quando uma novidade alterar a configuração, os tokens, o webhook ou o fluxo administrativo;
- alterações incompatíveis ou com migração devem elevar a versão;
- atualizações precisam ser idempotentes e compatíveis com bancos originados em versões anteriores;
- prefira branches curtas e Pull Requests pequenos, com descrição do cenário, regra, testes e risco.

Sugestão de branches:

- `feature/<assunto>`;
- `fix/<assunto>`;
- `docs/<assunto>`;
- `chore/<assunto>`.

## 8. Roteiro de validação

### Instalação e atualização

- instalar em banco limpo;
- atualizar uma instalação anterior preservando vínculos e históricos;
- repetir atualização para confirmar idempotência;
- abrir cada página principal e conferir logs PHP/GLPI.

### Perfis

- administrador;
- equipe interna de requisitos;
- cliente com visão pública;
- usuário sem cada direito específico;
- exceção individual de permissão quando aplicável.

### OpenProject

- sucesso de consulta/criação/edição/exclusão;
- token inválido;
- usuário sem permissão;
- projeto sem módulo de custos;
- WP sem atividade disponível;
- indisponibilidade e timeout;
- reexecução sem duplicidade.

### Ponto e entradas de tempo

- dia sem registro permanece neutro;
- saldo histórico positivo, negativo e sem saldo-base, inclusive no extrato auditável;
- pares completos e marcação incompleta;
- falta integral/parcial, justificada/não justificada, filtros e download autorizado dos anexos;
- feriado, compensação e dia não útil sem qualquer incidência no banco;
- entrada de tempo sem fim, encerramento pela grid e edição;
- sincronização individual e em lote;
- atualização/exclusão de entrada já sincronizada;
- links da WP e do relatório de custos.

## 9. Pendências técnicas recomendadas

Antes de produção, priorizar:

1. suíte automatizada de testes unitários e de integração;
2. análise estática e padronização de código PHP;
3. CI para lint, testes, varredura de segredos e geração de pacote;
4. política formal de retenção de anexos e auditoria;
5. tratamento centralizado de erros e observabilidade;
6. documentação de backup, restauração e atualização;
7. revisão de segurança dos endpoints públicos e uploads;
8. matriz formal de compatibilidade com novas versões de GLPI/OpenProject.

## 10. Como solicitar trabalho ao Codex

Uma solicitação deve informar:

- cenário atual e resultado esperado;
- perfil/usuário afetado;
- tela, endpoint ou integração relacionada;
- regra de negócio e exceções;
- evidências, mensagens de erro e passos de reprodução;
- critérios objetivos de aceite;
- se a tarefa autoriza somente diagnóstico ou também implementação.

Peça ao Codex para ler `AGENTS.md` e este documento antes de agir, preservar alterações não relacionadas, executar as validações possíveis e declarar claramente qualquer teste que não pôde ser executado.


## 11. Correção de autorização — 0.18.4

`Config::canManageConfiguration()` consulta `Profile::has(Profile::MANAGE_CONFIG)` / `Session::haveRight(..., READ)`. Nenhuma autorização em tempo de execução depende de nome, idioma ou ID fixo de perfil. A configuração global ignora exceções individuais: usa exclusivamente os direitos do perfil ativo. Menus e operações de feriados usam `MANAGE_HOLIDAYS`; gestão de exceções usa `MANAGE_TIME_ACCESS`, com lista de direitos de horas permitidos no backend. Alterar perfis continua exigindo `profile/UPDATE` nativo.

URLs das abas administrativas e POST global sem permissão retornam HTTP 403 antes de qualquer escrita. Token pessoal usa sempre o usuário autenticado e não concede acesso global. Erros no teste pessoal retornam à própria aba. O tutorial explica como conceder a permissão a perfis personalizados. Não há alteração do esquema, de fases públicas, de saldos ou de vínculos nesta release.

Ocorrências de nomes em `hook.php` são concessões iniciais/migrações históricas protegidas por marcadores; não constituem autorização dos endpoints. Não alterar esses marcadores para atualizar. Histórico das versões anteriores foi mantido no README do plugin.

Consulte `docs/RELEASE_0.18.4.md` para testes em GLPI 11.0.9, preservação de dados, segurança, roteiro de homologação e limitações.


## 12. Navegação e salvamento da configuração — 0.18.5

A página `front/config.form.php` mantém as três abas administrativas em um único formulário HTML válido. Os botões ficam fora dos painéis, no início desse formulário, disponíveis em Integração e automação, Classificação e Templates. Não colocar os botões dentro de um painel nem fechar o formulário antes dos contêineres internos.

O JavaScript alterna os painéis sem navegação HTTP e atualiza somente o parâmetro `tab` da URL com `history.replaceState`. Os campos permanecem no DOM até salvar; não são persistidos em localStorage/sessionStorage. Campos obrigatórios inválidos abrem a aba correspondente. Alterações de campos geram aviso antes de sair/recarregar; enviar o formulário válido segue o fluxo normal de POST e redirecionamento. O formulário pessoal tem destino explícito `?tab=my-access`, independente da aba administrativa anterior.

Autorização MANAGE_CONFIG, CSRF, regras de salvamento e banco não foram alterados. Roteiro de regressão e limitações em `docs/RELEASE_0.18.5.md`.

## 13. Monitoramento de Work Packages — 0.19.0

O monitoramento usa `GET /api/v3/users/me` para identificar o dono do token e consulta `GET /api/v3/work_packages` com filtros de tipo, responsável e status aberto. Não substituir o filtro de status aberto por uma lista de nomes: a API do OpenProject usa o operador `o`, que preserva as configurações de status da instância.

Os snapshots são por usuário e WP; uma consulta bem-sucedida marca como fora do escopo apenas WPs antes abertas naquele mesmo snapshot que não retornaram. Uma falha de API não limpa dados anteriores. A identificação de chamados reúne a tabela `glpi_plugin_demandas_links`, URLs `/work_packages/{id}` nos acompanhamentos e o campo Fields com rótulo “Atividade DevOps”. Alterações nessa heurística precisam preservar os três caminhos.

As notificações possuem uma impressão digital de WP, status e atualização. Não remover a chave única: ela impede spam a cada clique de consulta. O feed do sino é um endpoint autenticado e nunca deve expor token, payload bruto do OpenProject ou notificações de outro usuário. Veja `docs/RELEASE_0.19.0.md`.

## 14. Feedback de consulta — 0.19.1

A consulta de WPs faz um POST tradicional para manter a proteção de sessão e CSRF do GLPI. Antes de enviar, a página mostra uma barra de progresso **indeterminada** e bloqueia o botão. Não simular porcentagem: o total disponível no OpenProject só chega junto da resposta paginada e não há um trabalho assíncrono para informar avanço real ao navegador.

O sino deve ser anexado ao formulário do `#global-search`, que no GLPI 11 é inicialmente bloco. O script transforma apenas esse formulário em linha flexível e permite que o grupo de pesquisa encolha; isso evita que o sino seja renderizado abaixo da barra. Veja `docs/RELEASE_0.19.1.md`.

## 15. Paginação do monitoramento — 0.19.2

Não eleve o timeout como resposta inicial a uma coleção de WPs lenta. O cliente busca blocos de 25, preservando o filtro e a ordenação, e usa o total retornado pela coleção quando estiver disponível. Essa abordagem limita cada resposta HTTP e evita uma requisição adicional vazia ao final. Veja `docs/RELEASE_0.19.2.md`.

## 16. Offset do OpenProject — 0.19.3

No endpoint `/api/v3/work_packages`, `offset` é a página da coleção e começa em 1. Para uma página de 25 itens, o avanço correto é `1`, `2`, `3`; nunca some a quantidade de elementos ao offset. A instância oficial confirmou `total=128`, `count=25`, `pageSize=25` e `offset=1`, validando esse contrato. Veja `docs/RELEASE_0.19.3.md`.

## 17. Filtros e cliente no monitoramento — 0.19.4

As visões pessoal e consolidada compartilham filtros de status, chamado GLPI, cliente e responsável. Os cards são links de drill-down por status e preservam os demais filtros. O OpenProject pode representar o campo `Cliente` como uma lista de links HAL (`customFieldN`); o cliente deve combinar os títulos desses itens em vez de assumir um único objeto. Algumas instâncias não expõem `/api/v3/custom_fields` para tokens pessoais: nesse caso, descubra o campo pelo schema de formulário da WP e mantenha o resultado em cache por projeto e tipo. Veja `docs/RELEASE_0.19.4.md`.

## 18. Acesso ao consolidado — 0.19.5

A página consolidada é `/plugins/demandas/front/work-package-consolidated.php`, protegida pelo direito `demandas_view_dashboard`. Além da opção no menu de monitoramento, a página pessoal apresenta o botão **Consolidado geral** para quem possuir esse direito, evitando depender do submenu do GLPI. Veja `docs/RELEASE_0.19.5.md`.

## 19. Prévia de alertas no cabeçalho — 0.19.6

O sino apresenta apenas as três notificações não lidas mais recentes. O painel deve ter `top-100`, altura máxima e rolagem interna para nunca cobrir ou extrapolar o cabeçalho; títulos e mensagens longos devem ser truncados visualmente. A página `work-package-notifications.php` continua sendo a fonte do histórico completo. Veja `docs/RELEASE_0.19.6.md`.

## 20. Contexto local para IA externa — 0.20.0

O recurso não é uma integração com IA. Ele entrega ao usuário um prompt revisável e copiável, mantendo a decisão e a responsabilidade pelo compartilhamento fora do plugin. Não registrar o prompt, o conteúdo extraído ou o consentimento evita converter dados de chamados em uma nova base de dados sensível. O OCR e a conversão de documentos devem permanecer estritamente locais à imagem do GLPI. Todo novo formato de anexo deve ser liberado de forma explícita, com limite de recurso e teste contra documentos malformados. Veja `docs/RELEASE_0.20.0.md`.

## 21. Análise de WP sem chamado identificado — 0.20.1

O preparo de contexto deve continuar disponível quando a correlação da WP com o GLPI ainda não existir. Nesse cenário, não tentar inferir ou expor dados de chamados: esconder as opções dependentes de ticket e montar o prompt exclusivamente com a WP selecionada. Veja `docs/RELEASE_0.20.1.md`.

## 22. Paginação e exportação do monitoramento — 0.21.0

`WorkPackageMonitoringList` normaliza filtros e pagina os resultados após a filtragem. O padrão é 25, com opções 50/100/200; páginas fora do intervalo são ajustadas. Cards e navegação preservam os filtros e o tamanho, e aplicar filtros volta à primeira página. A paginação visual não altera a consulta paginada da API nem os snapshots completos.

`front/work-package-export.php` exporta a lista filtrada inteira usando `WorkPackageMonitoringExport`, GLPIPDF e PhpSpreadsheet já presentes no GLPI. A exportação pessoal usa exclusivamente `Session::getLoginUserID()`. O consolidado exige `VIEW_DASHBOARD` e `EXPORT_DASHBOARD`; não aceitar IDs de usuários fornecidos por GET. Texto da API é gravado no Excel como string explícita para evitar fórmulas e escapado no PDF. Exportações não incluem descrições, anexos, tokens nem dados adicionais de chamados. Não há migração nova. Veja `docs/RELEASE_0.21.0.md`.

## 23. Isolamento de dados técnicos de Work Packages — 0.21.1

`VIEW_PUBLIC` é exclusivamente a visão da fase e das mensagens públicas. Não usar esse direito como autorização para identificador, URL, título, status ou qualquer snapshot de Work Package. A aba de evolução e o hook público também devem confirmar a leitura efetiva do chamado antes de renderizar conteúdo.

`WorkPackageMonitoringHub` e todos os seus endpoints exigem `VIEW_TECHNICAL`; isso inclui consulta, alertas, exportação pessoal e preparo de IA. O consolidado acumula `VIEW_TECHNICAL` e `VIEW_DASHBOARD`; a exportação consolidada acrescenta `EXPORT_DASHBOARD`. Não reintroduzir acesso somente por usuário autenticado, token pessoal ou ocultação de menu: a checagem precisa permanecer no backend. Veja `docs/RELEASE_0.21.1.md`.

## 24. Filtros e ordenação da visão gerencial — 0.21.2

O painel de filtros da Visão Gerencial permanece recolhido por padrão. Os onze filtros são distribuídos em seis colunas na primeira linha e cinco na segunda em telas largas, com adaptação responsiva para larguras menores. A contagem de filtros ativos continua visível mesmo com o painel fechado.

A ordenação da grade é feita no `ManagementDashboardService`, depois dos filtros de base e do drill-down. Aceite somente as chaves fechadas em `SORT_FIELDS`; não use parâmetros de URL como nomes de colunas. O parâmetro `sort_direction` aceita apenas `asc` e `desc`. URLs de cabeçalho, paginação e exportação preservam essa ordenação; valores indisponíveis de data permanecem no fim. Veja `docs/RELEASE_0.21.2.md`.

## 25. Central de pendências de integração — 0.22.0

`OperationalHealthService` avalia somente dados já persistidos no GLPI, sem abrir uma consulta ao OpenProject. As três regras iniciais são `ticket_without_wp`, `wp_initial_status_overdue` e `wp_sync_stale`. O serviço cria uma rodada em `operational_runs`, atualiza a ocorrência por fingerprint em `operational_findings` e registra reconhecer, ignorar por sete dias ou reabrir em `operational_actions`.

O menu e a página usam `OperationalHealthHub`. Exigem cumulativamente `VIEW_TECHNICAL` e `VIEW_OPERATIONAL_HEALTH`; o endpoint mutável também exige `MANAGE_OPERATIONAL_HEALTH`. Não conceda esses direitos por migração a todos os perfis: a matriz de perfis deve ser a única fonte de autorização. Antes de listar ou agir sobre uma ocorrência, o serviço confirma que o ticket está na entidade ativa e que `Ticket::can(READ)` continua verdadeiro.

Os prazos e os status iniciais ficam em `operational_health_initial_statuses`, `operational_health_initial_days` e `operational_health_stale_days`, administrados apenas com `MANAGE_CONFIG`. A instalação cria as tabelas e os valores padrão de modo idempotente. Consulte `docs/RELEASE_0.22.0.md` antes de ampliar as regras, automatizar rodadas ou executar ações corretivas.
