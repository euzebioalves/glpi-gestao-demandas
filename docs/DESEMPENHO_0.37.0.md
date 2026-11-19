# Desempenho — preparação da 0.37.0

## Diagnóstico e mudanças

Na 0.36.0, abrir Entradas de Tempo podia consultar todos os projetos, cada WP sem chamado e seus projetos antes de devolver a página. Isso também atrasava o retorno após salvar. A latência se acumulava conforme o número de WPs distintas. O diagnóstico veio do código; não houve acesso ou medição direta em produção.

- A listagem agora usa somente dados locais: contagem e soma no banco, 50 linhas por página e ordenação estável. O total continua abrangendo todo o intervalo. A seleção para sincronização abrange a página atual, com aviso explícito.
- Identificadores de projeto já conhecidos continuam produzindo links diretos. Quando faltam, clicar no ID sincronizado abre uma rota autenticada que consulta somente aquela WP e seu projeto. Falhas oferecem nova tentativa sem impedir a consulta das entradas locais; não há escrita automática no vínculo.
- Pesquisa de WPs e atividades liberam o bloqueio de sessão após conferir os mesmos direitos anteriores. Uma resposta lenta não precisa bloquear outras páginas do usuário.
- A edição abre o modal antes de aguardar atividades. Mudanças de WP cancelam a consulta anterior no navegador e descartam respostas antigas. Cancelar o fetch não garante interromper um trabalho PHP já iniciado.
- A leitura nativa de um chamado é reutilizada durante a mesma renderização quando há várias WPs dele. Não há cache de permissão entre requisições.
- Incluída a ajuda solicitada nos quatro indicadores de ponto: modal acessível, conceito, período, exemplos e distinção entre marcações incompletas e encerramento do expediente.

Não há nova dependência, migração de schema, mudança de cálculo, fase pública ou permissão. Token pessoal, CSRF, autorização no backend, locks, transações e estados de recuperação continuam obrigatórios. Não foram adicionadas tentativas automáticas de POST remoto.

## Homologação

Na homologação local, conferir Gerência > Entradas de Tempo:

1. Abrir um período com mais de 50 entradas, conferir navegação e total do intervalo.
2. Abrir Nova entrada e Editar; testar WPs vinculadas e externas, atividades, salvar, finalizar e sincronizar.
3. Abrir o ID de uma entrada sincronizada: deve levar ao relatório filtrado no OpenProject. Se faltar identificador local, haverá consulta apenas nesse clique.
4. Com API lenta/indisponível, a listagem deve permanecer acessível. Pesquisa, atividades e gravações que dependem da API continuam sujeitas à sua disponibilidade.
5. Confirmar bloqueio para perfil sem direito e impossibilidade de acessar relatório de entrada de outro usuário.
6. Em Horas e Ponto, abrir as quatro ajudas por mouse/teclado e conferir responsividade e tema escuro. Os valores não devem mudar.

Testes automatizados: `python scripts/quality/run.py --integration` e `node tests/time-performance.cjs` (fixtures PHP e API sintética em `/tmp`, container descartável `demandas-test-021-glpi`, porta 8386). Nunca executar fixtures mutáveis na homologação compartilhada 8180. Evidências finais são registradas após a execução.

### Evidências locais — 19/11/2026

- Suíte integrada aprovada: instalação limpa/repetida, preservação, 66 regras de dashboard, 36 verificações de indicadores, conteúdo/credenciais, perfis/CSRF, falhas e repetição de operações, webhook e pacote.
- Teste específico aprovado: 55 entradas em duas páginas, total de 55 horas em ambas e **zero chamadas remotas** para carregar as páginas durante indisponibilidade simulada.
- Relatório sob demanda confirmado; acessos de outro proprietário e perfil cliente recusados. Respostas 401/403/422/503 tratadas sem liberar dados.
- Pesquisa lenta não bloqueou outra requisição da mesma sessão. Resposta antiga de atividades não substituiu a WP atual.
- Quatro ajudas aprovadas por teclado, com retorno de foco, em larguras 390/1366 e temas claro/escuro. O retorno de foco foi corrigido durante essa validação.
- PHP final e JavaScript dos dois formulários sem erro de sintaxe; varredura e pacote local conferidos.
- Homologação 8180 instalada e ativa em 0.37.0. Os **22 hashes** de tabelas/configuração/direitos coincidiram antes, depois da instalação e após os testes. Backups privados: `dist/before-performance.sql` e `dist/before-performance-config.tar.gz`.

Logs locais: `dist/performance-quality.log` e `dist/time-performance-tests.log`. A suíte específica foi executada separadamente após a suíte integrada e incorporada ao runner para as próximas execuções. Nenhuma chamada foi feita à produção; ganho percentual em produção permanece não medido. Aguardando validação humana.

## Limites e próximos passos

Não há promessa de percentual de ganho em produção. A latência real da API, proxy e servidor deve ser comparada antes/depois com o mesmo usuário e período. Salvar uma WP externa ainda confirma seu acesso na API; sincronizar/editar/excluir tempo remoto ainda aguarda a confirmação do OpenProject. Não se troca segurança por rapidez.

O seletor de vínculos mantém o limite anterior de 500 vínculos recentes. Uma busca local paginada para esse seletor e métricas operacionais por endpoint podem ser uma próxima etapa. Backlog, monitoramento e dashboard não foram refatorados nesta entrega.

Atualização local sem desinstalar, preservando volumes, banco e `glpicrypt.key`. Backups privados e hashes de preservação ficam em `dist/`, fora do Git. Esta versão está em preparação para validação; não é uma publicação em produção.
