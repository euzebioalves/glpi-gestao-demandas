# Gestão de Demandas 0.29.1 — consultas automáticas autorizadas

O JavaScript global consultava o feed de notificações e a fase pública mesmo para perfis sem os direitos correspondentes. O GLPI recusava essas requisições e preenchia o log de acessos inválidos. Agora a interface consulta apenas as capacidades da própria sessão antes de buscar dados. A fase pública também fica restrita à página de chamado, evitando consultas em cadastros com outro parâmetro `id`.

Nenhuma permissão é concedida ou removida. Os endpoints continuam exigindo autenticação, direito específico e, para a fase, leitura nativa do chamado. O novo endpoint informa somente booleanos, sem dados de chamados, WPs ou usuários. A resposta não pode ser armazenada em cache. Em falhas de comunicação/autorização, a interface não tenta carregar os recursos protegidos.

Não há alteração de esquema, saldos, fases, vínculos, webhook ou integração com o OpenProject. O alerta do plugin Moreticket é independente e não é tratado nesta alteração. Esta release inclui também as funcionalidades da 0.29.0: documentação visual/Markdown com código, imagens e anexos; relações opcionais entre WPs; conciliação com proteção contra cliques repetidos e justificativa somente nos vínculos adicionais. Consulte [RELEASE_0.29.0.md](RELEASE_0.29.0.md) para detalhes e limites.

## Validação

`tests/ui-permissions.cjs` usa usuários fictícios no Docker isolado: administrativo, interno, cliente e sem os dois direitos. Confere requisições automáticas, ausência do sino para perfis não autorizados, bloqueios diretos no backend, ausência de dados técnicos na resposta pública, páginas com IDs não relacionados a chamados, sessão anônima e respostas 401/403/503 na descoberta das permissões. A casca HTML de chamado é controlada pelo teste para exercitar explicitamente o fallback AJAX nas duas interfaces; endpoints e sessões são reais.

## Homologação local

Validação executada: sintaxe PHP/JavaScript, `git diff --check`, quatro perfis na suíte de navegador, instalação limpa em banco isolado e duas atualizações consecutivas com as 17 tabelas preservadas. Na homologação compartilhada, a versão 0.29.1 foi instalada e ativada; hashes dos dados do plugin e de todos os direitos de perfis permaneceram iguais antes/depois, e a página inicial respondeu HTTP 200. Nenhuma consulta ao OpenProject é necessária para esta correção. Homologação local aprovada pelo responsável pelo projeto antes da publicação.

Atualize sem desinstalar e recarregue as páginas abertas com **Ctrl+F5** para substituir o JavaScript antigo. Teste com os perfis atuais: navegar no GLPI, abrir chamados e verificar a fase pública e o sino somente onde já eram permitidos. A navegação automática não deve gerar os avisos de falta de `demandas_view_public`/`demandas_view_technical`. Acesso direto a uma URL protegida continua corretamente bloqueado e pode gerar registro de segurança.
