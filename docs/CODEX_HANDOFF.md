# Guia de continuidade — Plugin Gestão de Demandas

## 1. Finalidade

O plugin **Gestão de Demandas** complementa o GLPI com recursos para acompanhar demandas recebidas como chamados e tratadas tecnicamente no OpenProject. O GLPI continua sendo a interface de atendimento e visibilidade do cliente; o OpenProject concentra a gestão interna das Work Packages.

Este documento apresenta o estado funcional e técnico da versão `0.20.1` e deve ser usado como contexto inicial por qualquer agente Codex que continue o desenvolvimento.

## 2. Ambiente de referência

| Componente | Versão de homologação | Endereço padrão |
|---|---:|---|
| GLPI | 11.0.9 | `http://localhost:8180` |
| OpenProject | 17.7.2 | `http://localhost:8280` |
| MariaDB | 11.4 | rede Docker interna |
| Plugin Gestão de Demandas | 0.20.1 | `plugins/demandas` |

O ambiente é voltado exclusivamente à homologação local. Não deve ser publicado sem HTTPS, gestão externa de segredos, backup, monitoramento e revisão de segurança.

## 3. Domínios funcionais

### 3.1 Gestão de demandas

- vínculo de um chamado do GLPI com uma ou mais Work Packages;
- criação de User Stories e Bugs a partir do chamado;
- seleção dinâmica de projeto, tipo, prioridade, atribuído, responsável e cliente;
- templates Markdown configuráveis por tipo de Work Package;
- fases públicas desacopladas dos status técnicos do OpenProject;
- acompanhamentos automáticos conforme mudança de fase;
- histórico de sincronizações e visão técnica restrita;
- sincronização manual e automática por webhook assinado;
- painel gerencial com filtros, indicadores, drill-down e exportações.

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

O menu **Gerência > Monitoramento de Work Packages** usa exclusivamente o token pessoal do usuário logado. A consulta identifica User Stories, Épicos e Bugs abertos nos quais aquele token é o Responsável; não cria nem altera WPs. O consolidado exige `VIEW_DASHBOARD` e mostra somente resultados efetivamente coletados por usuários. Alertas são gravados por usuário e versão observada da WP, evitando repetição da mesma mensagem em consultas subsequentes.

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
