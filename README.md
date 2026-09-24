# MVP de Gestão de Demandas — GLPI + OpenProject

## Plugin 0.20.0

A versão 0.20.0 permite preparar, localmente, um contexto selecionável de chamado e Work Package para copiar em uma IA externa. Inclui extração local de texto de PDF, documentos Office e OCR de imagens, com consentimento explícito e sem envio automático de dados. Veja `docs/RELEASE_0.20.0.md`.

## Plugin 0.19.6

A versão 0.19.6 corrige o painel flutuante de alertas: ele fica ancorado abaixo do sino, exibe uma prévia compacta e direciona ao histórico completo. Veja `docs/RELEASE_0.19.6.md`.

## Plugin 0.19.5

A versão 0.19.5 disponibiliza o acesso direto ao consolidado geral de Work Packages na tela de monitoramento pessoal para perfis com acesso à visão gerencial. Veja `docs/RELEASE_0.19.5.md`.

## Plugin 0.19.4

A versão 0.19.4 adiciona filtros por status, chamado, cliente e responsável ao monitoramento de Work Packages, torna os indicadores de status clicáveis e corrige a leitura de clientes que o OpenProject retorna como lista. Veja `docs/RELEASE_0.19.4.md`.

## Plugin 0.19.3

A versão 0.19.3 corrige o avanço da paginação do OpenProject: o offset representa a página e agora a consulta percorre corretamente todos os blocos. Veja `docs/RELEASE_0.19.3.md`.

## Plugin 0.19.2

A versão 0.19.2 pagina a consulta de Work Packages em blocos menores para impedir que respostas extensas da instância oficial do OpenProject excedam o tempo de comunicação configurado. Veja `docs/RELEASE_0.19.2.md`.

## Plugin 0.19.1

A versão 0.19.1 torna visível a consulta de Work Packages com indicador indeterminado de progresso e corrige o alinhamento do sino de alertas ao lado direito da busca no cabeçalho. Veja `docs/RELEASE_0.19.1.md`.

## Plugin 0.19.0

A versão 0.19.0 adiciona o monitoramento de Work Packages abertas por token pessoal: o usuário consulta suas User Stories, Épicos e Bugs sob sua responsabilidade, visualiza indicadores por status, vínculos com chamados e alertas no cabeçalho. A visão gerencial consolida as consultas já realizadas e filtra por responsável. Consulte `docs/RELEASE_0.19.0.md`.

## Plugin 0.18.5

A versão 0.18.5 corrige o salvamento da configuração: os botões ficam disponíveis nas três abas administrativas, e alternar entre abas preserva o preenchimento sem recarregar a página. Mantém a autorização por permissões da 0.18.4. Veja `docs/RELEASE_0.18.5.md` e `plugins/demandas/README.md`.

Ambiente local de homologação para validar a integração entre tickets do GLPI e Work Packages do OpenProject.

## Componentes

| Componente | Versão | Endereço local |
|---|---:|---|
| GLPI | 11.0.9 | <http://localhost:8180> |
| MariaDB | 11.4 | Somente na rede Docker |
| OpenProject | 17.7.2 | <http://localhost:8280> |

As versões das aplicações estão fixadas para que uma atualização não altere o ambiente inesperadamente.

## Pré-requisitos no Windows

- Windows 11 com virtualização habilitada;
- Docker Desktop atualizado;
- backend WSL 2 habilitado no Docker Desktop;
- pelo menos 6 GB de memória disponíveis para o Docker;
- portas `8180` e `8280` livres;
- PowerShell 7 recomendado. O Windows PowerShell 5.1 também deve funcionar.

Se as portas estiverem ocupadas, altere `GLPI_HTTP_PORT`, `OPENPROJECT_HTTP_PORT` e `OPENPROJECT_HOST_NAME` no `.env` antes da primeira inicialização.

## Inicialização

Abra o PowerShell nesta pasta e execute:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\start.ps1
```

O script:

1. verifica se o Docker está disponível;
2. cria o `.env` com segredos aleatórios;
3. valida o Compose;
4. baixa as imagens externas;
5. monta localmente a imagem do GLPI com ferramentas de extração de anexos;
6. inicia todos os serviços.

Na primeira inicialização, o OpenProject pode levar alguns minutos para preparar seu banco.

Consulte o estado com:

```powershell
.\status.ps1
```

## Primeiro acesso

### GLPI

O contêiner realiza a instalação automaticamente. Acesse:

```text
http://localhost:8180
```

As credenciais padrão criadas pelo GLPI são normalmente:

```text
Usuário: glpi
Senha:   glpi
```

Altere imediatamente as senhas dos usuários padrão no ambiente de homologação.

### OpenProject

Acesse:

```text
http://localhost:8280
```

Use o login `admin` e a senha exibida por `start.ps1` na criação do `.env`. Caso seja necessário recuperá-la localmente:

```powershell
Select-String -Path .env -Pattern '^OPENPROJECT_ADMIN_PASSWORD='
```

## Comunicação entre os contêineres

Dentro da rede Docker, utilize estes endereços:

| Origem | Destino | URL interna |
|---|---|---|
| Plugin GLPI | API OpenProject | `http://openproject/api/v3` |
| Webhook OpenProject | Plugin Gestão de Demandas | `http://glpi/plugins/demandas/webhook.php` |

Não configure webhooks com `localhost`: dentro de um contêiner, `localhost` aponta para o próprio contêiner.

O GLPI utiliza o endereço fixo `172.31.251.10` nessa rede. O OpenProject permite
somente esse endereço em sua proteção contra SSRF, para que o webhook alcance o
GLPI sem liberar indiscriminadamente outras redes privadas.
O Compose autoriza `openproject` como hostname interno adicional, mantendo `localhost:8280` como hostname público da homologação.

## Preparação do OpenProject para o MVP

A preparação é executada por `configure-mvp.ps1`. Além do projeto, usuário
técnico e token da integração, o script sincroniza um modelo corporativo de
homologação definido em `bootstrap/openproject_homologation.json`.

O catálogo declarativo configura:

- tipos **User Story** e **Bug** com os mesmos campos;
- prioridades **Baixa**, **Normal**, **Alta** e **Imediata**, sendo Normal o padrão;
- campo obrigatório **Cliente** e uma lista representativa de clientes;
- campos ServiceDesk, DevOps, QA, entrega, versão de homologação e pontos de história;
- seis usuários funcionais fictícios;
- papel atribuível com os direitos necessários para trabalhar com WPs;
- associação dos usuários de teste a todos os projetos ativos.

Os valores fictícios podem ser ajustados diretamente no JSON. Uma nova execução
sincroniza as mudanças sem duplicar projetos, usuários, campos, opções, papéis ou
associações.

Não utilize a conta `admin` como identidade permanente da integração.

## Preparação do GLPI para o MVP

1. Altere as senhas dos usuários padrão.
2. Execute o bootstrap para criar a massa de homologação semelhante à estrutura operacional.
3. Valide os perfis interno, técnico e de cliente e suas visões do plugin.
4. Habilite a API e os webhooks necessários somente quando o plugin começar a utilizá-los.

### Massa de homologação do GLPI

O comando `./configure-mvp.ps1` cria, de forma idempotente e sem utilizar dados
reais de produção:

- entidade raiz **PONTO ID**;
- entidades de clientes: SEDUC-TO, GOIÂNIA-GO, SANTARÉM-PA,
  ANGRA-DOS-REIS-RJ e JOÃO-PESSOA-PB;
- duas unidades subordinadas para cada cliente, permitindo validar herança e
  restrição por entidade;
- grupos internos equivalentes aos principais papéis operacionais;
- perfis de requisitos, suporte, QA e cliente;
- usuários fictícios vinculados às entidades corretas;
- chamados de melhoria, erro, integração e suporte distribuídos entre os
  clientes, com diferentes estados e prioridades.

A massa é representativa: ela reproduz relações e cenários relevantes sem copiar
nomes, e-mails, descrições ou demais informações pessoais da produção.

Depois do bootstrap, valide versão e quantidades mínimas com:

```powershell
.\validate-homologacao.ps1
```

O resultado esperado contém `"ready": true`, pelo menos 16 entidades (raiz,
clientes e unidades), nove grupos e dez chamados fictícios.

O diretório `plugins` já está montado no local esperado pelo GLPI. A primeira versão do plugin está em `plugins/demandas`.

## Parar sem perder os dados

```powershell
.\stop.ps1
```

## Reiniciar

```powershell
docker compose up -d
```

## Logs

Todos os serviços:

```powershell
docker compose logs --follow
```

Somente GLPI ou OpenProject:

```powershell
docker compose logs --follow glpi
docker compose logs --follow openproject
```

## Apagar completamente a homologação

```powershell
.\reset-homologacao.ps1
```

O script exige a confirmação textual `APAGAR` e remove os volumes com os bancos e anexos deste projeto. O `.env` é preservado.

## Persistência

Os dados ficam em volumes nomeados do Docker:

- `demandas-hml1109_glpi_db_data`;
- `demandas-hml1109_glpi_data`;
- `demandas-hml1109_openproject_pgdata`;
- `demandas-hml1109_openproject_assets`.

Isso evita problemas comuns de permissão e desempenho ao montar bancos diretamente em pastas do Windows.

### Isolamento de ambientes anteriores

Esta homologação usa o projeto Docker `demandas-hml1109`, containers com o mesmo
prefixo, rede `demandas-hml1109-network` e volumes exclusivos. Isso impede que
um `.env` recém-gerado seja utilizado contra um banco persistido por versões
anteriores do pacote.

Se uma versão anterior estiver em execução, pare-a **antes** de atualizar os
arquivos, sem remover seus volumes:

```powershell
docker compose down
git pull origin main
.\start.ps1
```

Os volumes antigos permanecem preservados no Docker e não são montados pela
homologação 11.0.9.

## Limites deste pacote

Este ambiente foi desenhado para homologação local. Antes de qualquer uso externo ou produção, será necessário acrescentar, no mínimo:

- HTTPS e nomes DNS reais;
- proxy reverso;
- backups testados;
- gestão externa de segredos;
- restrições de rede;
- monitoramento;
- política de atualização e restauração;
- revisão de segurança do plugin e dos endpoints de webhook.

## Plugin Gestão de Demandas

O plugin inclui, entre outros recursos:

1. página de configuração da conexão;
2. teste de comunicação com a API do OpenProject;
3. aba `Evolução da Demanda` no ticket;
4. ação `Criar Work Package`;
5. persistência do vínculo ticket ↔ WP;
6. separação da visão técnica interna e da visão pública conforme as permissões efetivas no chamado;
7. matriz configurável de status técnico para fase pública;
8. sincronização manual e histórico técnico;
9. sincronização automática por webhook assinado.
10. seleção dinâmica do projeto e do tipo da WP;
11. fases públicas administráveis e acompanhamentos automáticos configuráveis;
12. fase pública no formulário padrão do chamado para cliente e equipe interna.
13. matriz de permissões por perfil, com validação no backend para cada ação.
14. visão gerencial no menu **Gerência**, com KPIs, filtros e drill-down;
15. distribuição por idade, status, fase pública, projeto e tipo de WP;
16. exportação do resumo e dos chamados correspondentes em PDF e Excel.
17. nomenclaturas configuráveis para fase pública, aba de evolução e visão gerencial;
18. ícone próprio no menu e no cabeçalho gerencial;
19. política configurável de classificação do chamado para tipos de WP permitidos;
20. filtragem dos tipos por classificação e validação equivalente no backend.

Para ativar a sincronização automática, cadastre no OpenProject um webhook para o evento **Work package atualizada**, limitado ao projeto de homologação, usando a URL interna e o segredo exibidos na configuração do plugin.

Se estiver atualizando um ambiente criado por uma versão anterior deste pacote,
recrie a rede e os contêineres sem remover os volumes:

```powershell
docker compose down
docker compose up -d
```

O comando `down` acima remove os contêineres e a rede antiga, mas preserva os
volumes e os bancos. Não acrescente a opção `-v`.

Para instalar, acesse **Configuração > Plugins** no GLPI, localize **Gestão de Demandas**, clique em **Instalar** e depois em **Ativar**. Com a permissão **Administrar as configurações do plugin** concedida ao perfil ativo em **Administração > Perfis > Gestão de Demandas**, abra a configuração do plugin e informe as URLs e o token do usuário técnico presente no `integration.env`; ele é usado exclusivamente nas sincronizações automáticas. Cada usuário que criar ou sincronizar Work Packages manualmente deve abrir a aba **Meu acesso ao OpenProject** e registrar seu próprio token. Projetos, tipos e campos de origem são identificados automaticamente.

## Configuração automatizada para o plugin

Depois que GLPI e OpenProject estiverem saudáveis, execute:

```powershell
.\configure-mvp.ps1
```

O script configura o projeto, tipos, prioridades, formulários, campos personalizados,
usuários funcionais, permissões, associações, usuário técnico e chave de API no
OpenProject. No GLPI, cria os perfis e usuários fictícios das visões interna e
cliente, além de um ticket de teste.

Os resultados ficam somente no computador local:

- `integration.env`: credenciais e identificadores que serão consumidos pelo plugin;
- `bootstrap-result.json`: relatório técnico do que foi criado ou reutilizado.

`integration.env` também registra `OPENPROJECT_TEST_USER_PASSWORD`, a senha local
compartilhada pelos seis usuários fictícios. Ela é preservada entre reexecuções.

O processo é idempotente: executá-lo novamente reutiliza os registros existentes. Não compartilhe o `integration.env` e não o inclua em controle de versão.
