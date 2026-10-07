# 0.25.0 — Work Package na listagem padrão de chamados

## Uso

Em **Assistência > Chamados**, abra a configuração de colunas (**Selecionar itens a mostrar**) e adicione **Plugins > Work Package** à visão pessoal ou padrão. O GLPI controla o direito de alterar cada visão. A opção fica disponível para perfis com **Visualizar dados técnicos do OpenProject**.

Cada WP é um link para a URL externa configurada do OpenProject, aberto em nova aba. Havendo múltiplas WPs, a célula apresenta, por exemplo, `123 | 456 | 789`, com um link em cada número, em ordem numérica e sem repetir o chamado na listagem. Sem URL externa HTTP(S) válida, os números aparecem como texto.

Chamados sem vínculo têm célula vazia. Nos filtros nativos, use **Work Package — está vazio**; negue esse critério para localizar chamados com WP. Também estão disponíveis igualdade, desigualdade, contém e não contém. Buscar uma WP mantém todas as WPs do chamado na coluna. Na negação, o chamado é excluído se qualquer uma de suas WPs corresponder ao número pesquisado.

A fonte é a tabela de vínculos do Gestão de Demandas, incluindo vínculos criados e conciliados. Uma URL existente apenas em **Atividade DevOps** do More Fields não preenche esta coluna: utilize o fluxo de conciliação para associá-la previamente. Nenhuma WP é criada por esta funcionalidade e a listagem não consulta a API do OpenProject.

Exportações nativas apresentam os números como texto separado por pipe. Esta entrega não altera automaticamente as colunas escolhidas por usuários nem concede permissões a perfis de cliente.

## Implementação e atualização

`TicketSearch` registra a opção estável `925001` pelos hooks automáticos do GLPI. O join de exibição agrupa os vínculos, enquanto subconsultas independentes aplicam os filtros, preservando todas as WPs na célula. As consultas usam o construtor nativo e o escape de pesquisa do GLPI. Referência: [extensão das opções de busca do GLPI](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tutorial.html#using-other-objects).

Não há migração de esquema. Substitua os arquivos do plugin e use **Atualizar** na administração de plugins, sem desinstalar. A versão e as permissões devem ser recarregadas na sessão; se necessário, saia e entre novamente. A aba **Tutorial** explica a configuração da coluna.

## Homologação

Em GLPI 11.0.9/MariaDB, exclusivamente no Docker isolado:

- opção disponível na configuração nativa de colunas, inclusão na visão pessoal e persistência ao reabrir a listagem;
- zero, uma e múltiplas WPs, links individuais, separador pipe e uma linha por chamado;
- chamado com somente URL legada continua vazio;
- está vazio/não vazio, `NULL`, igualdade/desigualdade, contém/não contém, preservação das demais WPs ao filtrar uma delas e entradas malformadas;
- perfis administrativo e interno autorizados; perfil de cliente sem direito técnico não recebe a opção e não expõe WPs ao forçar a coluna ou o filtro pela URL;
- chamado de outra entidade não aparece e seu número de WP não é exportado;
- exportação CSV nativa com múltiplos números em texto, sem HTML;
- temas claro/escuro, sintaxe PHP/JavaScript e diff;
- instalação limpa em banco separado; repetição do instalador duas vezes; atualização com dados preservados nas 16 tabelas do plugin.

## Repetir testes

Com o plugin atualizado e ativado no GLPI isolado `http://127.0.0.1:8386`, contêineres `demandas-test-021-glpi` e `demandas-test-021-db`:

```powershell
docker cp tests/ticket-search-fixture.php demandas-test-021-glpi:/tmp/ticket-search-fixture.php
node tests/ticket-search.cjs
```

Requer Edge e Playwright (ou `PLAYWRIGHT_MODULE` indicando instalação existente). A fixture bloqueia outros bancos, cria usuários/chamados/WPs fictícios e configura uma URL externa fictícia; não executar contra produção ou homologação compartilhada. Capturas ficam no diretório temporário `demandas-ticket-search`.

Limitações: não houve teste em produção, em outras versões de GLPI 11, com o plugin More Fields ativo ou em outras famílias de navegador. A fixture simula uma tabela Fields sem registrar a coluna daquele plugin. A exportação de referência testada foi CSV; PDF/Excel usam o mesmo formatador de texto, mas não foram inspecionados separadamente. Não foram repetidas chamadas reais à API/webhook, pois este recurso somente lê vínculos locais. Validação de desempenho em volumes de produção permanece recomendada.
