# 0.26.0 — Backlog pessoal

## Uso

Em Administração > Perfis > Gestão de Demandas, conceda **Visualizar dados técnicos do OpenProject** e **Visualizar o Backlog pessoal**. Para selecionar colunas, conceda também **Configurar as colunas do próprio Backlog**. Os novos direitos começam desmarcados. Saia e entre novamente se a sessão ainda usar as permissões anteriores.

Cada pessoa configura seu token em Minhas configurações > OpenProject e acessa Gerência > Backlog. O quadro consulta WPs sob sua responsabilidade no OpenProject, de qualquer tipo, incluindo WPs sem chamado GLPI. O escopo de projetos é o permitido pelo token pessoal. Não consulta chamados nem modifica a visibilidade pública de clientes.

Sem seleção salva, aparecem os status abertos, com Novo, Em Especificação e Em Dúvida no início quando existirem. Escolher colunas permite selecionar também status fechados e salvar uma seleção vazia. A escolha é individual, persistida no banco e independente das atualizações dos dados. Um status removido do OpenProject permanece identificado como indisponível até ser desmarcado.

Os cards mostram número, título, projeto, cliente, responsável e horas pessoais. O relógio soma todas as entradas daquele usuário do token nas WPs exibidas, sem recorte de período, incluindo lançamentos externos ao plugin. Horas de outras pessoas e marcações de ponto não entram na soma. Falha na consulta de tempo mostra **Indisponível**, sem converter falha em zero. A leitura depende de permissão para visualizar tempo gasto no OpenProject.

Clique no card para abrir detalhes laterais e o link para o OpenProject. Descrições são exibidas como texto para impedir execução de HTML remoto. O quadro é de consulta: não inclui arrastar cards, mudar status ou editar WPs.

## Atualização e arquitetura

Atualize o plugin sem desinstalar. A nova tabela `glpi_plugin_demandas_backlog_preferences` tem chave única por usuário e guarda apenas IDs de status e data de alteração. O instalador usa CREATE IF NOT EXISTS, preserva dados existentes e não concede automaticamente os novos direitos. O POST de preferências usa CSRF nativo do GLPI e verifica os três direitos no backend; usuário-alvo é sempre obtido da sessão.

As consultas usam token pessoal, paginação de 25 itens e IDs descobertos na API. Não usam o total compartilhado `spentTime` da WP. Consulte o [contrato oficial de entradas de tempo](https://www.openproject.org/docs/api/endpoints/time-entries/). A implementação não grava snapshots de WPs nem altera vínculos, fases públicas, ponto ou sincronização existente.

## Validação

- PHP e JavaScript com sintaxe validada.
- Instalação limpa em banco isolado GLPI 11.0.9 e duas reexecuções do instalador; 17 tabelas do plugin preservadas por hash.
- Atualização repetida com dados e preferências preservados; assinatura válida/inválida do webhook conferida.
- Teste de navegador `tests/backlog.cjs`: perfis administrativo, interno, somente leitura e cliente; paginação de WPs/horas; soma pessoal; HTML remoto tratado como texto; painel lateral; persistência e isolamento entre usuários; falhas 401/403/500; horas indisponíveis; POST sem CSRF rejeitado.
- Inspeção visual em 1366px e 390px, temas claro e escuro.

Os testes de integração do novo quadro usam API sintética no ambiente Docker isolado. Validar também com token pessoal na instância oficial antes de produção, principalmente a descoberta do campo Cliente e as permissões de tempo em cada projeto. O quadro não faz atualização automática: use Atualizar quadro. Consultas com muitos lançamentos podem demorar; existe um limite defensivo de 1.000 páginas por consulta e uma falha não retorna totais parciais como completos.

Para reproduzir: iniciar `demandas-test-021-db` e `demandas-test-021-glpi`, instalar/ativar o plugin, copiar `tests/backlog-fixture.php` e `tests/backlog-api.php` para `/tmp` no GLPI isolado, iniciar `php -S 127.0.0.1:8396 /tmp/backlog-api.php`, definir `PLAYWRIGHT_MODULE` para o Playwright local e executar `node tests/backlog.cjs`. A fixture recusa o banco compartilhado.
