# Release 0.20.1 — análise sem chamado GLPI identificado

## Correção

O botão **Preparar IA** passa a ser exibido em todas as Work Packages da consulta pessoal. Antes, ele era ocultado quando a correlação com um chamado do GLPI ainda não havia sido identificada.

Ao abrir uma WP sem chamado, a página informa claramente essa condição e disponibiliza somente os campos próprios da WP e seus links. Dados de chamado, acompanhamentos e anexos não são apresentados nem incluídos no contexto. Quando existir vínculo, o fluxo completo permanece disponível.

## Validação

1. Consulte WPs sem chamado vinculado e confirme o botão **Preparar IA** na coluna Ações.
2. Gere o contexto e confirme que o prompt contém somente dados selecionados da WP.
3. Em uma WP com chamado vinculado, confirme a seleção de dados do chamado, acompanhamentos públicos e anexos.
