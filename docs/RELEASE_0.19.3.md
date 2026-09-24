# Release 0.19.3 — avanço correto da paginação OpenProject

## Correção

O parâmetro `offset` da coleção de Work Packages do OpenProject identifica a página, começando em `1`. A versão anterior incrementava esse valor pela quantidade de itens recebidos e, após a primeira página de 25 itens, solicitava incorretamente a página `26`.

A consulta agora solicita as páginas `1`, `2`, `3` e assim por diante, até atingir o total retornado pela API ou receber uma página incompleta. Assim, o bloco de 25 itens limita apenas cada resposta HTTP; não limita a quantidade final exibida.

## Validação

1. Execute a consulta em uma conta com mais de 25 WPs abertas.
2. Confirme que a quantidade exibida no card corresponde ao total esperado no OpenProject.
3. Confira itens conhecidos que estavam além da primeira página, ordenada por última atualização.
