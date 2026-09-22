# Guia de continuidade — Plugin Gestão de Demandas

## 1. Finalidade

O plugin **Gestão de Demandas** complementa o GLPI com recursos para acompanhar demandas recebidas como chamados e tratadas tecnicamente no OpenProject. O GLPI continua sendo a interface de atendimento e visibilidade do cliente; o OpenProject concentra a gestão interna das Work Packages.

Este documento apresenta o estado funcional e técnico da versão `0.18.2` e deve ser usado como contexto inicial por qualquer agente Codex que continue o desenvolvimento.

## 2. Ambiente de referência

| Componente | Versão de homologação | Endereço padrão |
|---|---:|---|
| GLPI | 11.0.9 | `http://localhost:8180` |
| OpenProject | 17.7.2 | `http://localhost:8280` |
| MariaDB | 11.4 | rede Docker interna |
| Plugin Gestão de Demandas | 0.18.2 | `plugins/demandas` |

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
- feriados, compensações e dias não úteis configurados apenas pelo perfil ativo Super-Admin;
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

Use usuário técnico dedicado e com o menor conjunto de permissões necessário. Nunca use a conta `admin` permanentemente. O token automático desse usuário é configurado somente pelo perfil ativo **Super-Admin**, é reservado a webhooks e outras sincronizações automáticas e o código do plugin o bloqueia na criação de Work Packages. Cada usuário que execute criação, sincronização manual ou lançamento manual de tempo deve registrar seu token pessoal em **Minhas configurações > OpenProject**. Tokens devem permanecer no ambiente/configuração do GLPI e fora do Git.

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
