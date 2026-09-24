# Release 0.19.2 — paginação resiliente da consulta de Work Packages

## Correção

A consulta de WPs abertas passou a solicitar 25 itens por página. A instância oficial pode devolver objetos de Work Package extensos; solicitar 100 por vez gerava respostas de vários megabytes e podia ultrapassar o timeout configurado antes de a primeira página terminar.

Quando o OpenProject informa o total da coleção, o cliente encerra a paginação ao atingir esse número. Caso a instância não informe esse valor, mantém a regra compatível de encerrar ao receber uma página menor que 25 itens.

## Validação

1. Execute **Consultar minhas WPs** com uma conta que possua mais de 25 WPs abertas.
2. Confirme que a lista continua mostrando todas as WPs, não apenas a primeira página.
3. Confirme que não ocorre erro cURL 28 para uma resposta extensa e que a barra de consulta permanece visível até o retorno.
