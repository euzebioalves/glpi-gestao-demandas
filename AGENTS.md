# Instruções para agentes de desenvolvimento

## Objetivo do repositório

Este repositório contém o ambiente de homologação e o plugin **Gestão de Demandas** para GLPI. O plugin integra chamados do GLPI a Work Packages do OpenProject e também contém módulos separados de controle de ponto e entradas de tempo.

Antes de alterar o código, leia integralmente:

1. `README.md`;
2. `docs/CODEX_HANDOFF.md`;
3. `plugins/demandas/README.md`;
4. os arquivos diretamente envolvidos na mudança.

## Escopo e estrutura

- `plugins/demandas/`: código-fonte do plugin GLPI.
- `plugins/demandas/setup.php`: versão, requisitos, instalação e atualização do plugin.
- `plugins/demandas/hook.php`: hooks e migrações de banco.
- `plugins/demandas/src/`: regras de negócio, segurança e integração.
- `plugins/demandas/front/`: páginas e endpoints autenticados.
- `plugins/demandas/public/`: recursos públicos e endpoint de webhook.
- `bootstrap/`: preparação automatizada dos ambientes GLPI/OpenProject.
- `docker-compose.yml` e scripts PowerShell: ambiente local de homologação.

## Regras obrigatórias para mudanças

- Preserve compatibilidade com GLPI 11 e OpenProject 17.x.
- Nunca grave tokens, senhas, cookies, chaves ou conteúdo real de clientes no repositório.
- Mantenha toda autorização também no backend; ocultar botão não substitui verificação de permissão.
- Respeite entidades, perfis e escopo de acesso do GLPI.
- Use consultas parametrizadas/construtor do GLPI e escape toda saída HTML.
- Valide CSRF em ações mutáveis conforme o mecanismo nativo do GLPI.
- Não envie marcações de ponto ao OpenProject. Somente entradas de tempo vinculadas a Work Packages podem ser sincronizadas.
- Dias sem marcação de ponto e sem ausência registrada são neutros e não geram saldo negativo.
- Mudanças de banco devem ser idempotentes e preservar dados de instalações anteriores.
- Não altere IDs do OpenProject de forma fixa; descubra projetos, tipos, status, atividades e campos pela API sempre que possível.
- Mantenha mensagens voltadas ao usuário em português do Brasil e sem expor detalhes sensíveis de exceções.
- Não remova funcionalidades existentes sem solicitação explícita.

## Fluxo esperado de desenvolvimento

1. Reproduza o problema no ambiente Docker.
2. Identifique a regra afetada e os pontos de autorização.
3. Faça a menor alteração coerente com a arquitetura existente.
4. Valide sintaxe PHP e JavaScript.
5. Execute testes de instalação limpa e atualização com dados preservados.
6. Teste com perfil administrativo, equipe interna e perfil de cliente.
7. Quando houver integração, teste sucesso, indisponibilidade, 401/403, validação e repetição da operação.
8. Atualize `plugins/demandas/README.md`, o número em `setup.php` e a documentação afetada.

## Critérios mínimos antes de entregar

- instalação e ativação sem erro HTTP 500;
- atualização idempotente;
- páginas acessíveis somente com as permissões adequadas;
- ausência de segredos no diff;
- nenhuma regressão nos vínculos GLPI ↔ OpenProject;
- webhook assinado validado;
- sincronização manual repetível sem duplicação;
- comportamento responsivo nos temas claro e escuro do GLPI;
- instruções de homologação e limitações registradas.

## Decisões que exigem confirmação

Pare e solicite decisão antes de:

- mudar regras de saldo, jornada, ausência ou banco de horas;
- alterar o significado das fases públicas;
- modificar visibilidade de informações para clientes;
- apagar ou reconstruir vínculos e históricos;
- trocar versões-base do GLPI, OpenProject ou banco;
- incluir dependência externa, serviço pago ou tratamento de dados pessoais;
- realizar ação destrutiva em volumes ou ambientes compartilhados.

