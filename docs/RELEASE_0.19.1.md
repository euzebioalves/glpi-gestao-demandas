# Release 0.19.1 — feedback de consulta e alertas no cabeçalho

## Escopo

- apresenta um painel de carregamento quando o usuário inicia a consulta de Work Packages;
- bloqueia o botão durante a requisição e informa que o progresso é indeterminado, pois o total é devolvido pelo OpenProject somente durante a própria consulta paginada;
- corrige o sino de alertas para ocupar a mesma linha, à direita, do formulário de busca padrão do GLPI 11.

## Validação

1. Em **Gerência > Monitoramento de Work Packages**, clique em **Consultar minhas WPs** e confirme a exibição imediata do painel, do spinner e da barra animada até o retorno da página.
2. Durante a consulta, confirme que o botão permanece desabilitado e não permite reenvio.
3. No cabeçalho em tela larga, confirme que o sino aparece imediatamente à direita do campo de busca, sem ocupar linha abaixo.
4. Clique no sino, confirme a abertura do painel flutuante e teste o encaminhamento para **Alertas de Work Packages**.
