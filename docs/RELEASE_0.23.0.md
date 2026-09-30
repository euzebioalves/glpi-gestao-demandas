# 0.23.0 — Chamados abertos e conciliação de WPs existentes

## Comportamento

A Visão Gerencial de Demandas passa a usar **Abertos** por padrão: exclui os status nativos solucionado e fechado do GLPI. **Filtros estratégicos > Escopo dos chamados > Todos** inclui o histórico completo. Cards, gráficos, drill-down, ordenação, listagem e PDF/Excel recebem o mesmo escopo. A consulta confirma a leitura nativa de cada chamado, além das entidades ativas. O resumo exportado identifica o escopo escolhido.

A cobertura conta vínculos persistidos no plugin. Para incorporar os vínculos antigos, use **Conciliar Work Packages existentes** no painel ou **Conciliar WP existente de Atividade DevOps** na área técnica do chamado. O processo:

1. descobre campos textuais **Atividade DevOps** do plugin Fields;
2. interpreta URLs completas da mesma instância da URL externa configurada do OpenProject (incluindo caminhos por projeto e subpastas);
3. apresenta chamados/WPs para conferência, com abertos como escopo inicial;
4. valida as WPs selecionadas com GET na API configurada, usando o token pessoal;
5. grava o vínculo local e o evento de auditoria em transação.

Não são criadas nem alteradas WPs no OpenProject. O campo original é preservado. A conciliação não publica acompanhamentos ou fases públicas; a fase fica vazia até a sincronização normal. Os vínculos passam a ser reconhecidos na Evolução da Demanda, no painel e nos fluxos de sincronização existentes. Não há novas tabelas ou alterações de esquema.

Também foram corrigidas a descoberta de colunas Fields com nome simples/prefixo legado e a correspondência do filtro **Status WP** no painel.

## Operação e permissões

- Requer **Visualizar dados técnicos do OpenProject** e **Criar e vincular Work Packages**, além de leitura/alteração nativas do chamado na entidade ativa. Para entrar pelo painel, é necessário também **Acessar a visão gerencial**; para entrar pelo chamado, o acesso normal à Evolução da Demanda.
- Configure o token pessoal com acesso de leitura às WPs e confira a URL externa de navegação do OpenProject. Somente o endpoint fixo da API interna é consultado; a URL encontrada no campo não é acessada pelo servidor.
- Confirme até cinco referências por operação. Cada referência é independente: uma falha não desfaz os vínculos confirmados anteriormente no lote. A prévia tem paginação de 25 itens.
- Repetir uma operação preserva o vínculo existente, sem duplicar vínculo ou evento.
- Referências da mesma WP em chamados diferentes e WPs já vinculadas a outro chamado ficam bloqueadas para revisão. A restrição de uma WP por chamado de origem já existente no banco é preservada; um chamado continua podendo ter várias WPs.
- URLs de outra instância, somente números, referências inválidas, falta de acesso e falhas da API não geram vínculos. A importação não faz correspondência por título ou suposição.
- O POST usa CSRF nativo e repete as verificações de permissão, origem e conflito no servidor.

## Atualização e conferência

1. Faça backup do banco e da pasta do plugin.
2. Substitua `plugins/demandas` e execute **Atualizar**, sem desinstalar.
3. Confira o escopo **Abertos** no painel; alterne para **Todos** e compare os totais e as exportações.
4. Revise URL externa, token pessoal e permissões do operador.
5. Concilie primeiro um pequeno lote conferido no OpenProject. Verifique as WPs na Evolução da Demanda e o aumento da cobertura.
6. Repita a operação para confirmar a preservação dos vínculos. Prossiga com os demais lotes e revise os conflitos separadamente, sem criar WPs substitutas.
7. Confira a sincronização normal conforme os mapeamentos já aprovados para a comunicação pública.

## Validação realizada

Ambiente Docker isolado com GLPI 11.0.9/MariaDB, dados sintéticos e API simulada compatível com a resposta de WP do OpenProject:

- sintaxe PHP de todo o plugin e sintaxe do JavaScript;
- instalação do esquema do plugin em base separada, seguida de duas reexecuções idempotentes;
- atualização/ativação no GLPI e preservação dos dados nas 16 tabelas do plugin após duas reexecuções;
- testes HTTP com administrador, equipe interna, perfil sem UPDATE e cliente, incluindo entidade fora do escopo, acesso anônimo e CSRF ausente;
- padrões de URL válidos e inválidos, conflito de WP, API 401/403/404, resposta divergente e timeout;
- conciliação e repetição, com auditoria, sem mudança de fase e sem acompanhamentos; log da API confirmou somente GET de WPs;
- PDF/Excel, abertos versus todos, leitura por entidade e filtro de status de WP;
- assinatura válida/inválida do webhook, usando evento ignorado sem efeitos externos;
- inspeção visual Playwright das páginas gerencial e conciliação em desktop/mobile e temas claro/escuro.

Scripts: `tests/legacy-reconciliation-fixture.php`, `tests/legacy-reconciliation-api.php`, `tests/legacy-reconciliation-http.py` e `tests/legacy-reconciliation-visual.cjs`. O fixture recusa bancos diferentes de `demandas-test-021-db`. Copie os dois arquivos PHP para `/tmp` de `demandas-test-021-glpi`, execute a API simulada com `php -S 127.0.0.1:8393 /tmp/legacy-reconciliation-api.php` e rode `python tests/legacy-reconciliation-http.py` com o GLPI na porta local 8386. O teste redefine apenas seus dois vínculos sintéticos antes de executar. Os modos `upgrade` e `webhook` recebem JSON pela entrada padrão do fixture; `clean-install` requer a base vazia `glpi_reconciliation_clean_023`, com acesso concedido ao usuário do banco de teste. O visual usa o Playwright já disponível e Edge headless; `PLAYWRIGHT_MODULE` permite informar o caminho do módulo. Capturas ficam na pasta temporária, sem dados reais.

## Limitações

- Nenhuma conciliação foi executada em produção. É necessário validar a descoberta do campo Fields e um lote real após a instalação.
- A prévia examina as referências Fields locais para identificar conflitos; bases muito grandes podem exigir uma futura rotina de processamento em segundo plano. Não há importação automática nem criação remota em lote.
- Só a instância configurada é aceita. Migrações de domínio ou URLs antigas precisam de revisão administrativa, sem inferir equivalências.
- A validação da API nesta entrega foi simulada; a sincronização completa com o OpenProject oficial e os mapeamentos públicos deve ser conferida na homologação do ambiente de destino.

O pacote de instalação é publicado nos anexos da release `v0.23.0` no GitHub. A instalação e a conciliação em produção são realizadas pelo administrador do ambiente, seguindo o roteiro acima.
