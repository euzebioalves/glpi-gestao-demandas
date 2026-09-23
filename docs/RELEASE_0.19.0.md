# Release 0.19.0 — monitoramento de Work Packages abertas

## Escopo

- cria o menu **Gerência > Monitoramento de Work Packages**;
- usa o token pessoal do usuário logado para localizar User Stories, Épicos/Epics e Bugs abertos sob sua responsabilidade no OpenProject;
- apresenta cards por status e uma lista com WP, título, datas, responsável, cliente e chamados relacionados;
- abre as WPs no OpenProject e os chamados no GLPI em abas novas;
- identifica chamados a partir de vínculos registrados pelo plugin, do campo Fields **Atividade DevOps** e de URLs de Work Package em acompanhamentos;
- mantém um consolidado gerencial filtrável por responsável, condicionado ao direito `VIEW_DASHBOARD`;
- adiciona alertas deduplicados no sino do cabeçalho e uma página com o histórico de alertas;
- acrescenta duas tabelas idempotentes: `glpi_plugin_demandas_work_package_monitors` e `glpi_plugin_demandas_work_package_notifications`.

## Atualização

Faça backup do banco e da pasta do plugin. Substitua somente `plugins/demandas`, abra **Configuração > Plugins** e execute **Atualizar**. Não desinstale: a desinstalação remove dados do plugin, incluindo os snapshots e alertas de monitoramento.

## Roteiro de homologação

1. Em **Minhas configurações > OpenProject**, salve e teste um token pessoal de um usuário que seja responsável por pelo menos uma User Story, Épico ou Bug aberto.
2. Acesse **Gerência > Monitoramento de Work Packages** e clique em **Consultar minhas WPs**.
3. Confirme que apenas WPs abertas e dos três tipos previstos são listadas; compare o responsável com o usuário associado ao token no OpenProject.
4. Confirme cards por status, cliente, datas e os links da WP e do chamado. Para validar vínculos legados, use uma WP no campo **Atividade DevOps** e outra URL em um acompanhamento do mesmo chamado.
5. Confirme que o sino próximo à busca mostra os alertas e que cada item encaminha para **Alertas de Work Packages**. Execute a consulta novamente sem alterar a WP e confirme que não duplica o alerta.
6. Com um perfil que possua **Acessar a visão gerencial**, abra **Consolidado de Work Packages**, aplique o filtro de responsável e confirme a deduplicação por identificador da WP.
7. Desative temporariamente o OpenProject ou use token inválido: a consulta deve informar falha e manter o último resultado já salvo.

## Limitações conhecidas

- o consolidado é alimentado por consultas dos próprios usuários; não é um varredor global e não usa o token automático;
- a detecção em acompanhamentos exige uma URL com o padrão `/work_packages/{id}`;
- o campo **Atividade DevOps** precisa ser um campo textual/URL do plugin Fields aplicado a chamados e ter esse rótulo.
