# 0.24.0 — Conciliação assistida, chamado nas entradas e atualização controlada

## Conciliação de WPs existentes

Em **Conciliar Work Packages existentes**, uma referência em conflito passa a exibir o botão **Detalhes**. O modal apresenta os motivos detectados, referências de chamados visíveis ao perfil ativo, a existência do vínculo local atual e os bloqueios aplicáveis. O identificador em **WP indicada** abre a WP correspondente na URL externa configurada do OpenProject, em nova aba.

O novo direito **Resolver conflitos de conciliação de Work Packages** não é concedido automaticamente. Com ele, somente um conflito de vínculo local simples pode ser transferido após confirmação explícita, desde que o operador consiga alterar os dois chamados, não existam entradas de tempo no vínculo e não haja fase pública ou acompanhamento público. A operação:

- move apenas `tickets_id` no vínculo local já existente;
- grava eventos de auditoria nos dois chamados;
- não cria, altera ou consulta por escrita uma WP no OpenProject;
- não modifica o campo Fields, fases públicas, acompanhamentos ou entradas de tempo.

Conflitos por múltiplas referências, dados publicados, histórico de tempo, acesso insuficiente ou chamados fora do escopo continuam bloqueados e devem ser resolvidos por procedimento administrativo. Isso evita alterar a origem de dados ou reatribuir histórico de forma implícita.

## Entradas de tempo

A grade de **Gerência > Entradas de Tempo** recebeu a coluna **Chamado GLPI**. O número abre o chamado em nova aba somente se a pessoa ainda tiver a permissão nativa de leitura; caso contrário, a coluna permanece neutra.

O botão **Nova entrada**, à esquerda de **Sincronizar selecionadas**, oferece duas origens: **Relacionada ao GLPI** e **Outra WP do OpenProject**. A segunda pesquisa por ID ou por título (mínimo de três caracteres, até 20 resultados), usando exclusivamente o token pessoal. O backend valida a WP na API antes de salvar e mantém a entrada sem chamado. Nenhum vínculo de conciliação é criado. As atividades continuam vindo do formulário oficial da WP. O link do ID sincronizado resolve o projeto na API para abrir o relatório de Tempo e Custos correspondente. Nenhuma regra de ponto foi alterada.

Tokens pertencem à instância onde foram gerados. No Docker local, use API `http://openproject/api/v3` e navegação `http://localhost:8280`; um token local não autentica na instância oficial.

## Monitoramento de releases

O plugin registra uma tarefa diária do GLPI que consulta exclusivamente a API da release mais recente do repositório oficial. A tela **Integração e automação** permite habilitar/desabilitar a verificação, consultar manualmente e visualizar a versão instalada/disponível. A verificação usa HTTPS, valida o certificado e aceita somente URLs do GitHub oficial.

O GLPI **não baixa, extrai ou instala** código. Para aplicar uma release, `update-plugin-release.ps1` deve ser executado por uma conta/automação de infraestrutura durante janela de manutenção. Sem opções, o script consulta a release; com `-ValidatePackage`, baixa e valida o ZIP sem substituir arquivos. Com `-Apply`, valida o ZIP e a versão declarada, move a pasta anterior para um backup datado e instala a nova pasta; o administrador então executa **Atualizar** na página nativa de plugins do GLPI. O script não acessa o banco, não recebe tokens e preserva o backup para rollback.

A tarefa usa o modo externo do GLPI: a infraestrutura deve manter o cron nativo em execução para a verificação diária ocorrer. A consulta manual **Verificar agora** não depende desse agendamento. Em Linux, o script externo requer PowerShell (`pwsh`) e o parâmetro `-PluginDirectory` com o caminho real da instalação; não há caminho administrativo fixo embutido.

## Atualização e configuração

1. Atualize o plugin sem desinstalá-lo e clique em **Atualizar** no GLPI para registrar o direito e a tarefa agendada.
2. Em **Administração > Perfis > Gestão de Demandas**, conceda **Resolver conflitos de conciliação de Work Packages** apenas a operadores responsáveis por esse procedimento.
3. Em **Configuração do plugin > Integração e automação**, mantenha a verificação de release habilitada para administradores que precisam receber o aviso.
4. Antes da primeira atualização automatizada, execute o script externo em modo de verificação e valide o backup planejado no ambiente de homologação.

## Segurança e limites

- Nenhuma credencial, token ou dado de cliente é enviado ao GitHub pelo verificador.
- Falhas de rede geram apenas um estado administrativo genérico; o plugin instalado continua operando normalmente.
- A tarefa não cria notificações para perfis de cliente nem revela a existência de versões a usuários sem a permissão de configuração.
- Não houve mudança de esquema nem alteração de dados existentes durante a atualização.
- O fluxo deve ser homologado com administrador, equipe interna, perfil sem o novo direito e perfil cliente antes de produção.

## Validação executada

Homologação automatizada em GLPI 11.0.9 na base Docker isolada `glpi_e2e` (porta 8190), sem alteração do banco compartilhado nem escrita no OpenProject oficial:

- Sintaxe PHP de todo o plugin e verificação do diff.
- Instalação limpa, repetição idempotente, atualização com preservação de 11 tabelas, datas e fusos; auditoria neutra de falta justificada e webhook com assinatura válida/inválida.
- Conciliação HTTP: entidades, leitura/alteração nativas, cliente, perfil interno, somente leitura, CSRF, erros de API 401/403/404/timeout, identidade da WP, repetição sem duplicação, transferência auditada e bloqueios por tempo ou dados públicos. Exportações PDF/Excel respeitam escopo e entidades.
- Entradas de tempo HTTP: ID/título, token pessoal, perfil cliente/anônimo, CSRF, WP sem acesso/identidade inválida, propriedade da entrada, criação sem chamado, sincronização, edição, exclusão e link de Tempo e Custos. Consulta de releases negada sem direito administrativo.
- Playwright: seis cenários da Central de Pendências aprovados; conciliação, modal de conflito e modal de WP externa nos temas claro/escuro e em 390 px, sem erros JavaScript nem rolagem horizontal da página.
- Verificador de release: versões superior/igual/anterior, tarefa desabilitada, tarefa diária registrada, domínio/HTTPS e consulta real da API oficial do GitHub. Atualizador externo validado em modo de pacote e aplicado a uma cópia temporária com backup conferido, sem substituir uma instalação compartilhada.

As integrações de escrita foram exercitadas contra uma API local sintética. Isso valida os fluxos e bloqueios do plugin, mas não substitui a conferência das permissões e atividades reais de cada projeto no OpenProject de produção. Não foi aplicada atualização em produção.

### Repetir as regressões da release

Prepare a base com `./start-e2e.ps1` e execute `./run-e2e-tests.ps1 -EnvironmentFile tests/e2e/.env.e2e -Mode Manual`. Para a suíte adicional, copie `tests/legacy-reconciliation-fixture.php`, `tests/legacy-reconciliation-api.php` e `tests/time-entry-api.php` para `/tmp/` no contêiner `demandas-e2e1109-glpi`. Inicie as duas APIs com `php -S 127.0.0.1:8393 /tmp/legacy-reconciliation-api.php` e `php -S 127.0.0.1:8394 /tmp/time-entry-api.php` em processos separados. Defina `RECONCILIATION_BASE=http://localhost:8190` e `RECONCILIATION_CONTAINER=demandas-e2e1109-glpi` e execute, nesta ordem e sem concorrência entre fixtures:

```powershell
python tests/legacy-reconciliation-http.py
python tests/time-entry-http.py
node tests/legacy-reconciliation-visual.cjs
```

As fixtures verificam o banco isolado antes de qualquer escrita. As capturas adicionais são gravadas no diretório temporário `demandas-reconciliation-024`; revise-as antes de copiar ao manual. `tests/plugin-update.php`, executado dentro desse mesmo contêiner, testa o monitoramento (`--network` inclui a consulta pública ao GitHub).
