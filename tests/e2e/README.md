# Testes práticos com Playwright

## Permissões e documentação (0.32.0)

`node tests/permission-guide.cjs` usa exclusivamente `demandas-test-021-glpi` na porta 8386. Copie `permission-guide-fixture.php` e `legacy-reconciliation-fixture.php` para `/tmp` nesse container descartável. Confere grupos, ajudas, persistência, perfis, CSRF e responsividade. `PLAYWRIGHT_MODULE` permite reutilizar Playwright já instalado. Capturas sintéticas ficam na pasta temporária do sistema.

`node tests/documentation.cjs` usa o mesmo ambiente e API simulada `documentation-api.php` na porta interna 8397. Inclui editor oculto antes da seleção, falha/recuperação do template e preservação de texto ao trocar projeto/tipo. Não executar contra a homologação compartilhada ou produção.

Esta suíte valida o plugin por meio da interface do GLPI, em vez de chamar diretamente classes PHP. Ela existe para detectar regressões de rota, autorização, renderização e ações comuns depois de cada atualização do plugin.

## Segurança obrigatória

Execute somente contra um GLPI **descartável**, com dados, usuários, tokens e WPs fictícios. Não aponte para produção, para a homologação compartilhada ou para uma cópia de produção com dados reais.

Os testes de leitura não gravam dados. Os testes práticos podem criar ou atualizar ocorrências locais da Central de Pendências. Por isso, eles exigem simultaneamente `E2E_ISOLATED_FIXTURE=1` e `E2E_ALLOW_MUTATIONS=1`.

As capturas usadas no manual só podem ser atualizadas quando `E2E_ISOLATED_FIXTURE=1`. Isso impede que nomes de clientes, chamados ou outros dados reais sejam copiados para a documentação.

## Preparação única

1. Suba uma instância isolada do GLPI com o plugin atualizado.
2. Crie quatro usuários fictícios (administrador, operador, visualizador e restrito) e aplique os perfis descritos em `.env.example`. O administrador e o operador podem ser a mesma conta fictícia quando ela possuir todos os direitos necessários; o visualizador e o restrito precisam ser contas distintas, pois validam a segregação de permissões.
3. Crie contas que possam efetuar login pela **interface central** do GLPI. Contas ou perfis exclusivos para API, como `post-only`, não criam uma sessão navegável e não servem para esta suíte. Na pasta raiz do repositório, execute o configurador único e informe as credenciais fictícias quando solicitado:

   ```powershell
   .\run-e2e-tests.ps1 -Configure
   ```

   Ele cria `tests/e2e/.env`, instala a dependência e o navegador Playwright e executa o modo somente leitura. Como medida de segurança, o configurador mantém `E2E_ISOLATED_FIXTURE=0` e `E2E_ALLOW_ARTIFACTS=0`: só altere esses valores manualmente após confirmar que a instância é descartável e contém exclusivamente dados fictícios.

4. Crie pelo menos uma ocorrência local de cada cenário: chamado aberto elegível sem WP, WP em status inicial e WP sem sincronização recente. Os dados devem ser fictícios.

### Base pronta para Playwright

Este repositório também fornece uma base descartável, isolada da homologação principal e sem OpenProject externo. Ela usa GLPI 11.0.9, banco e volumes próprios, instala a cópia local do plugin e cria quatro contas fictícias, perfis segregados e uma pendência local para o cenário prático.

```powershell
.\start-e2e.ps1
.\run-e2e-tests.ps1 -EnvironmentFile tests/e2e/.env.e2e
```

A base fica em `http://localhost:8190`. Para executar o cenário mutável, use `-Mode Practical`; para atualizar capturas fictícias do manual, use `-Mode Manual`. Para pará-la sem apagar dados, execute `./stop-e2e.ps1`. Os arquivos `.env.e2e` e `tests/e2e/.env.e2e` são locais e ignorados pelo Git.

## Comandos por versão

Depois de instalar ou atualizar o plugin na instância isolada, execute:

```powershell
# Rotas, tela, filtros e permissões. Não executa a verificação.
.\run-e2e-tests.ps1

# Inclui o botão Executar verificação e confirma que a tratativa permanece visível.
.\run-e2e-tests.ps1 -Mode Practical

# Atualiza as imagens sanitizadas consumidas pelo manual.
.\run-e2e-tests.ps1 -Mode Manual
```

O relatório HTML fica em `playwright-report/`. Trace, vídeo e screenshot de falhas somente são gerados quando `E2E_ISOLATED_FIXTURE=1` **e** `E2E_ALLOW_ARTIFACTS=1`, pois podem capturar dados visíveis no GLPI. Esses diretórios não são versionados. As capturas aprovadas do manual ficam em `docs/assets/manual/` e devem ser revisadas antes do commit.

## Matriz atual

| Caso | Tipo | Validação |
|---|---|---|
| Configuração administrativa | leitura | aba de integração, parâmetros da central e captura sanitizada. |
| Monitoramento pessoal | leitura | página e captura sanitizada da listagem de WPs. |
| Central para operador | leitura | título, filtros, cards, tabela e botão de verificação. |
| Perfil visualizador | autorização | vê a lista, mas não o botão de executar ou tratar. |
| Perfil restrito | autorização | não recebe conteúdo técnico e a URL direta é negada. |
| Rodada operacional | mutável | executa a verificação e preserva a ação de reconhecimento. |
| Capturas do manual | documentação | gera imagens apenas no ambiente fictício confirmado. |

Ao adicionar um recurso, inclua neste arquivo o cenário, uma conta/perfil necessário e uma captura se ele mudar o processo de configuração ou utilização.

### Visão operacional — Bugs e Melhorias (0.31.0)

Execute primeiro `php tests/action-dashboard-unit.php` para as regras isoladas, sem banco nem API. Para validar o GLPI real, a suíte complementar `node tests/dashboard-bugs.cjs` usa somente a instância descartável `demandas-dashboard029-glpi` em `http://127.0.0.1:8192`, com o código atual instalado. Alternativamente, uma nova instância `demandas-dashboard031-glpi` na porta 8193 pode ser indicada por `DASHBOARD_TEST_CONTAINER` e `DASHBOARD_TEST_URL`; outros destinos são rejeitados. O banco precisa ser `glpi_e2e` no host `db`. Não use a homologação compartilhada da porta 8180.

Copie `tests/dashboard-bugs-fixture.php` e `tests/legacy-reconciliation-fixture.php` para `/tmp` do contêiner. A suíte cria dados fictícios: entrega total/parcial, ausência de WP/status/histórico, encerrado com WP não entregue, observações de etapa e responsáveis. Verifica filtros combinados, preservação de todas as WPs, perfis, acesso direto, entidade, CSRF, preferências, exportação, hashes de leitura e atualização repetida. `PLAYWRIGHT_MODULE` permite reutilizar uma instalação local da dependência.

As capturas claro/escuro, desktop/celular atualizam `docs/assets/manual/dashboard-bugs-*.png`; revise antes de publicar. A validação desta versão está pendente porque o engine Docker não iniciou. Veja [release 0.31.0](../../docs/RELEASE_0.31.0.md); não usar o sucesso de versões anteriores como evidência desta implementação.

### Publicações SGE (0.28.0)

A suíte complementar `node tests/publications.cjs` usa exclusivamente `demandas-sge028-glpi` em `http://127.0.0.1:8191`, com banco descartável `glpi_e2e` no host `db`. Copie as fixtures `legacy-reconciliation-fixture.php`, `publications-fixture.php` e `publications-api.php` para `/tmp` do container e inicie `php -S 127.0.0.1:8395 /tmp/publications-api.php`. O script cria usuários fictícios, valida leitura/atualização por perfil e testa builds, paginação, CSRF, falhas e layout. As capturas vão para `docs/assets/manual/sge-publications-*.png` e devem ser inspecionadas antes de publicar. Não execute no GLPI compartilhado da porta 8180.

## Segurança — etapa 1 (0.32.1)

`tests/security-stage1.cjs` usa somente `demandas-test-021-glpi`, porta 8386, e a fixture `security-stage1-fixture.php` copiada para `/tmp` desse container. Execute com `node tests/security-stage1.cjs`; `PLAYWRIGHT_MODULE` permite reutilizar Playwright já instalado. A opção `--baseline` reproduz as falhas exclusivamente com o código anterior à correção; `--ui` confere as duas telas de exceções e o salvamento sem mudanças.

Testa administrador, operador com leitura parcial de chamados, consulta de equipe, cliente e usuário sem portal; acesso direto, entidade, CSRF, exceções válidas/inválidas, auditoria e preservação de pendências em avaliação incompleta. Os dados são sintéticos e a fixture rejeita outros bancos. `security-preservation.php` é somente leitura e retorna hashes, sem expor registros ou tokens, para comparar a homologação antes/depois da atualização.
