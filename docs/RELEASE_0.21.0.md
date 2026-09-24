# Versão 0.21.0 — paginação e exportação de Work Packages

## Uso

Em **Gerência > Monitoramento de Work Packages**, as listagens pessoal e consolidada passam a exibir 25 itens por página, com opções de 50, 100 e 200. Use **Filtrar** depois de escolher os filtros e o tamanho. Os controles Primeira/Anterior/Próxima/Última mantêm os filtros; aplicar novos filtros ou clicar em um card volta à primeira página. O total informa o intervalo exibido e o número de resultados filtrados.

**PDF** e **Excel** exportam todos os resultados filtrados da última consulta armazenada, mesmo quando a tabela está em outra página. O PDF usa paisagem e repete o cabeçalho da tabela. O Excel é um `.xlsx` com a listagem e uma aba de filtros, cabeçalho congelado e autofiltro. Os arquivos identificam a data de geração e os filtros usados. Não executam nova consulta ao OpenProject.

## Acesso e atualização

- A exportação pessoal usa somente o usuário autenticado, sem aceitar IDs de terceiros na URL.
- A consolidada exige **Acessar a visão gerencial** e **Exportar a visão gerencial em PDF e Excel**. Os botões e o endpoint verificam os direitos.
- Nenhuma concessão automática de direito novo, alteração do escopo de visibilidade ou migração de banco foi adicionada. O escopo é o mesmo das listagens existentes.
- Dados externos são escapados no PDF e gravados como texto explícito no Excel, impedindo interpretação como fórmulas.
- Reutiliza GLPIPDF e PhpSpreadsheet distribuídos com GLPI 11; não exige instalar bibliotecas.
- Substitua os arquivos e execute **Atualizar** em **Configuração > Plugins**, sem desinstalar. A aba Tutorial explica a exportação e as permissões.

## Validação executada

Ambiente Docker isolado: GLPI 11.0.9 / MariaDB 11.4, com 122 WPs fictícias distribuídas entre dois usuários, mais um usuário de perfil cliente sem snapshot.

- Reprodução: a versão anterior renderizava as 51 linhas de uma coleção sem paginação.
- Sintaxe PHP de todo o plugin e JavaScript existente.
- Instalação limpa e ativação; atualização da versão declarada 0.20.1 para 0.21.0 e repetição do instalador, com hashes das tabelas do plugin preservados.
- Testes HTTP com sessão real: primeira/última página, tamanho 50, filtro antes da paginação, exportação inteira e filtrada, lista vazia, formatos/escopos inválidos e acesso anônimo.
- Perfis administrativo, interno sem exportação gerencial e cliente: bloqueios HTTP 403 e ocultação dos botões consolidados sem direito.
- XLSX aberto como pacote XML: contagens 61/31/122/0, IDs do usuário correto mesmo com tentativa de troca por GET, ausência de fórmulas.
- PDF real gerado com múltiplas páginas e conferência visual da primeira página; títulos, filtros, acentos e tabela legíveis.
- Navegação real no navegador: seleção de filtro e tamanho, avanço para a segunda página com seis resultados; conferência visual em desktop, largura móvel de 390 px e paleta escura do GLPI.
- As consultas e exportações preservaram os hashes das tabelas do plugin.

Os testes estão em `tests/work-package-list-fixture.php` e `tests/work-package-list-http.py`. O fixture recusa qualquer banco cujo host não seja `demandas-test-021-db`. Use somente um GLPI descartável publicado em `127.0.0.1:8386`, contêiner `demandas-test-021-glpi`, com o plugin instalado e ativo. Copie o fixture para `/tmp/work-package-list-fixture.php` e execute `python tests/work-package-list-http.py`. Os artefatos contêm somente dados fictícios e ficam no diretório temporário do sistema, em `demandas-wp-021-tests`.

## Limitações e conferência operacional

- A paginação limita as linhas HTML; a leitura e a filtragem do snapshot continuam em memória, como antes. Os arquivos completos também são gerados em memória: homologar volumes muito superiores aos 122 registros testados conforme os limites PHP do servidor.
- As exportações refletem a última consulta armazenada, não o estado ao vivo do OpenProject. Execute **Consultar minhas WPs** para atualizar os dados antes de exportar.
- Não foram repetidos nesta alteração os cenários de webhook, criação/sincronização de WPs e falhas da API, pois esses fluxos não foram modificados. Não houve acesso à instância oficial.
