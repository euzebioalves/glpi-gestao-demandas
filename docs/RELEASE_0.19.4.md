# Release 0.19.4 — filtros e cliente no monitoramento

## Novidades

- Os cards de status da listagem de Work Packages são links de drill-down. Ao selecionar um card, a listagem mantém somente as WPs daquele status.
- A visão pessoal e a visão consolidada possuem filtros por status da WP, chamado GLPI vinculado, cliente do OpenProject e responsável da WP.
- O campo Cliente passa a reconhecer valores de seleção única e listas de valores retornadas pelo OpenProject.
- A descoberta do schema de campos é reutilizada por projeto e tipo durante a consulta, reduzindo chamadas repetidas à API.

## Validação

1. Execute a consulta de WPs e confirme que a coluna Cliente é preenchida quando o campo estiver informado no OpenProject.
2. Clique em um card de status e confira que apenas as WPs daquele status permanecem na tabela.
3. Combine os filtros de status, chamado, cliente e responsável e confira que a tabela apresenta somente a interseção selecionada.
4. Na visão consolidada, confirme os mesmos filtros e o drill-down dos cards.
