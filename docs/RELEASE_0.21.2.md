# Versão 0.21.2 — filtros e ordenação da Visão Gerencial

## Novidades

- O painel **Filtros estratégicos** fica recolhido ao abrir a página, mantendo a quantidade de filtros ativos visível.
- Em telas largas, os filtros são organizados em duas linhas, com seis campos na primeira e cinco na segunda para ampliar os controles.
- Todos os títulos da tabela de chamados são clicáveis e alternam a ordenação crescente/decrescente.
- A ordenação é aplicada no servidor somente após filtros e drill-down, portanto permanece correta na paginação e é reutilizada nas exportações PDF e Excel.

## Atualização

1. Substitua os arquivos do plugin pela versão 0.21.2.
2. Em **Configuração > Plugins**, execute **Atualizar**. Não desinstale o plugin.
3. Abra **Gerência > Visão Gerencial de Demandas**.

## Validação recomendada

- Confirme que os filtros estão inicialmente recolhidos e podem ser expandidos pelo cabeçalho.
- Aplique dois ou mais filtros e confirme que o contador permanece correto com o painel fechado.
- Ordene cada coluna da tabela e confirme que um segundo clique inverte a ordem, sem remover filtros ou drill-down.
- Navegue entre páginas e exporte PDF ou Excel: a mesma combinação de filtros e a ordenação selecionada devem ser mantidas.
