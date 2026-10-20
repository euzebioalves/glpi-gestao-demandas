# Gestão de Demandas 0.30.0 — Backlog

## Alterações

- Busca imediata nos cards por número, assunto, projeto, cliente, responsável ou chamado, sem chamadas adicionais ao OpenProject.
- Colunas com cabeçalhos fixos e rolagem vertical independente; quadro com rolagem horizontal.
- Cards exibidos antes das horas, consultadas depois em lotes de 25 WPs. Não se exibe zero enquanto a consulta está pendente ou indisponível.
- Descoberta do campo Cliente por schema GET de projeto/tipo, sem carregar formulários de criação e seus valores permitidos.
- Descrição no painel lateral com HTML saneado, Markdown, tabelas e imagens de anexos. A descrição é buscada somente ao abrir o card.
- Chamados com vínculo confirmado pelo plugin e leitura permitida no GLPI aparecem no card e no painel como links em nova aba. Múltiplos chamados são preservados. URLs apenas no Fields precisam ser conciliadas antes.
- Consulta de colegas com o próprio token, limitada aos usuários e WPs acessíveis no OpenProject. Nenhum token de colega é utilizado. Colunas e horas continuam sendo do usuário logado.

## Configuração e limites

Atualize sem desinstalar e recarregue com Ctrl+F5. Para consultar colegas, conceda **Consultar Backlog de colegas com o próprio token** em **Administração > Perfis > Gestão de Demandas**, além dos direitos técnicos e de Backlog já necessários. Na página, clique **Consultar colegas** e selecione o responsável. Volte para **Meu backlog** a qualquer momento. Nenhum direito existente é ampliado automaticamente.

A busca filtra as WPs dos status selecionados. Escolha outras colunas para incluir outros status. Preferências só são gravadas pelo botão **Salvar colunas**. Consultar colegas não modifica as preferências deles.

As imagens são obtidas com autorização por WP/anexo; somente PNG, JPEG, GIF e WebP até 15 MB. Referências externas e imagens que exigem redirecionamento para armazenamento externo podem exigir abrir a WP no OpenProject. Não há envio do token a URLs da descrição. Não há nova dependência, tabela, alteração de saldo, fase, webhook ou escrita no OpenProject.

## Homologação

Validar perfis administrativo, interno, somente leitura e cliente; bloqueio direto de consultas a colegas sem direito; busca e limpeza; links de múltiplos chamados e entidades inacessíveis; descrição com tabelas, imagens e conteúdo malicioso; mudança de responsável durante consulta de horas; falhas 401/403/500 e repetição; preferências persistentes; temas claro/escuro e telas estreitas.

Os testes usam API e usuários fictícios em Docker isolado. O ganho em produção depende da latência da instância e do volume de WPs/entradas; não foi medida a instância oficial.

Validação executada em GLPI 11.0.9:

- `tests/backlog.cjs`: quatro perfis, cards disponíveis com consulta de horas suspensa, rolagem interna com cabeçalho fixo, busca/limpeza, colegas, autorização direta, persistência de colunas, soma pessoal paginada, imagens raster autenticadas, HTML saneado, links de chamados e ocultação de entidade inacessível, parâmetros inválidos, falhas 401/403/500 e fallback de Cliente por schema GET;
- sintaxe PHP e JavaScript e `git diff --check`;
- instalação limpa em banco separado e atualizações consecutivas com as 17 tabelas preservadas;
- atualização/ativação na homologação compartilhada com hash dos dados do plugin e dos direitos anteriores preservado, página inicial HTTP 200;
- revisão visual em 1366 px e 390 px, temas claro e escuro. A primeira execução foi interrompida por falta de espaço ao salvar capturas; a suíte final passou sem gravar novas imagens.

Publicação da versão 0.30.0 autorizada pelo responsável após os testes automatizados descritos acima. A validação operacional pelo responsável não foi realizada em homologação e será realizada em produção.
