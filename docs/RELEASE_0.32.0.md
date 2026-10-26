# Gestão de Demandas 0.32.0 — permissões por módulo

## Mudanças

A aba do plugin em Administração > Perfis recebe ícone de escudo e sete grupos: Chamados e Work Packages, Visão gerencial, Backlog, Monitoramento e integração, Entradas de tempo, Ponto e ausências e Configuração do plugin. Cada um dos 28 direitos tem ajuda clicável com efeito, localização e dependências. Atalhos de navegação e abertura da ajuda preservam seleções ainda não salvas. A ajuda funciona por teclado e sem JavaScript.

Na criação de WP, a documentação deixa de exibir editor e anexos vazios antes de selecionar projeto e tipo. Os campos precisam ser carregados e o template confirmado antes de revelar a edição. Em falha de template, a mensagem fica visível e permite repetir o carregamento. Ao trocar projeto/tipo, o texto é preservado e os controles ficam ocultos até os novos campos estarem prontos; respostas obsoletas da consulta de opções são descartadas. Para WP já vinculada, Documentar mostra a edição após carregar a documentação.

## Segurança e atualização

Não há alteração de schema, IDs de direitos, concessões automáticas, regras de saldo ou fases públicas. As mesmas verificações de perfil, entidades, leitura/alteração nativas, token pessoal e CSRF permanecem no servidor. As ajudas não concedem dependências automaticamente. O endpoint de salvamento de permissões continua exigindo alteração de perfis no GLPI; quem tem somente leitura vê os controles desabilitados.

Faça backup e atualize o plugin sem desinstalar. Recarregue com Ctrl+F5. As melhorias desta etapa foram incorporadas à release 0.32.1, validada localmente e autorizada para publicação.

## Validação

- PHP: sintaxe dos 86 arquivos do plugin; JavaScript do editor e scripts embutidos de criação; `git diff --check`.
- `tests/permission-guide.cjs`: quatro perfis, 28 ajudas, sete grupos, persistência das seleções, salvamento sem alterar direitos, concessão/revogação explícita, leitura sem edição, negativas por acesso direto e CSRF.
- `tests/documentation.cjs`: editor/anexos ocultos sem seleção e em falha do template, recuperação, preservação de texto ao trocar seleção, chamado sem vínculo, edição, Markdown, código, imagens, anexos, relações com repetição sem duplicar WP, erros de integração, conflito de edição e perfis restritos.
- Instalação limpa em banco separado e execução repetida do instalador com as 18 tabelas do plugin preservadas; webhook válido/assinatura inválida verificados em ambiente descartável.
- Homologação compartilhada atualizada e ativada em 0.32.0, com backup local, comparação dos hashes das 18 tabelas e dos direitos anteriores sem diferenças; acesso inicial HTTP 200.
- Capturas sintéticas das permissões em desktop/celular, claro/escuro. Integração de documentação testada com API simulada, sem envio a instâncias reais.

A validação operacional pelo usuário foi aprovada junto à versão 0.32.1. A suíte antiga `action-dashboard-unit.php` tem uma expectativa desatualizada de renderização já identificada na 0.31.1; não foi alterada por esta melhoria.
