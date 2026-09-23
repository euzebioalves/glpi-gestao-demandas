# Release 0.18.5 — salvamento e navegação das configurações

## Problema corrigido

Na 0.18.4, os botões de salvar estavam dentro do painel Templates e as abas eram links que recarregavam a página, descartando o preenchimento ainda não salvo. O formulário também era fechado antes dos contêineres internos do último painel.

Agora **Salvar todas as configurações administrativas** e **Salvar e testar conexão** ficam no início do formulário, disponíveis em **Integração e automação**, **Classificação** e **Templates**. As três abas são salvas juntas. A navegação entre abas ocorre na própria página e mantém os campos preenchidos; o parâmetro da URL acompanha a aba selecionada, inclusive no retorno após salvar. O token pessoal permanece em formulário separado.

A validação abre a aba que contém o campo obrigatório pendente. O navegador é avisado quando há alteração de campos antes de sair/recarregar. A troca de abas não salva automaticamente e não grava rascunhos ou tokens em localStorage/sessionStorage. Salve antes de fechar a página.

Não há alteração no banco, nas permissões MANAGE_CONFIG, no CSRF, nas regras de integração ou no histórico de dados. Tutorial integrado, README e guia de continuidade atualizados.

## Validação

Ambientes Docker isolados com GLPI 11.0.9 e MariaDB 11.4:

- Reprodução no navegador da 0.18.4: ausência de salvar na integração e perda da URL não salva ao navegar para Templates e voltar.
- Na 0.18.5: preenchimento das URLs externa do OpenProject e do GLPI, navegação por Classificação/Templates e retorno sem perda, salvamento e teste da conexão pela própria Integração com confirmação de sucesso.
- Salvamento a partir de Classificação e Templates com confirmação, preservando a aba selecionada.
- Passagem por Tutorial e Meu acesso ao OpenProject sem perder a URL preenchida; botões administrativos ausentes dessas duas abas.
- Campo obrigatório vazio de Classificação: ao salvar pela Integração, a interface retorna à Classificação e foca o campo inválido.
- Suíte `tests/profile-permissions-http.py` aprovada: perfis personalizados, administrativo/interno/cliente, negativas HTTP 403, troca/revogação de perfil, CSRF, tokens pessoais, automação e respostas simuladas 401/403.
- Atualização 0.18.4 → 0.18.5 e reexecução sem desinstalação; comparação dos registros confirma preservação das 11 tabelas, configurações, tokens e direitos.
- Instalação limpa e ativação em segundo ambiente; integridade do esquema aprovada.
- Sintaxe dos 44 arquivos PHP e JavaScript da configuração/plugin aprovada; `git diff --check` sem problemas.

A API de conexão usada nos testes é simulada. Criação de WP, webhook, sincronização completa com OpenProject real e inspeção visual sistemática dos temas claro/escuro e dispositivos móveis não foram reexecutados nesta correção de interface.

## Roteiro reproduzível de interface

1. Selecione um perfil com **Administrar as configurações do plugin**.
2. Abra Integração e automação e confirme os dois botões acima dos campos.
3. Altere as URLs sem salvar; passe por Classificação, Templates, Tutorial e Meu acesso ao OpenProject. Retorne e confira o preenchimento.
4. Salve pela Integração; recarregue e confirme os valores persistidos.
5. Altere novamente e salve por Classificação e por Templates. Confira os valores e a aba selecionada após o POST.
6. Em Classificação, selecione campo da tabela principal e deixe a regra obrigatória vazia. Volte à Integração e salve: a aba Classificação deve abrir para a correção.
7. Sem MANAGE_CONFIG, confira somente o token pessoal; URLs e POST administrativos continuam negados.

Para a regressão HTTP e a verificação de dados, utilize o ambiente descartável e as instruções de `docs/RELEASE_0.18.4.md`, iniciando com a tag 0.18.4, executando `prepare` sem `--baseline`, substituindo pela 0.18.5 e executando `verify`. Não aponte fixtures para o ambiente oficial.

## Atualização

Faça backup, confira o SHA-256, substitua `plugins/demandas` pela pasta `demandas/` do ZIP e execute **Atualizar** em **Configuração > Plugins**. **Não desinstale**. Recarregue a página de configuração após atualizar para carregar o JavaScript corrigido; salve qualquer preenchimento pendente antes de recarregar.
