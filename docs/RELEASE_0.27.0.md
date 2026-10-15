# 0.27.0 — Conciliação com múltiplos vínculos

## Comportamento

- Ao iniciar a conciliação pela **Evolução da Demanda**, a confirmação retorna à mesma aba do chamado de origem. O contexto acompanha filtros e paginação. Operações iniciadas na visão gerencial continuam retornando à listagem de conciliação.
- Uma WP pode ser vinculada a vários chamados e um chamado pode ter várias WPs. Não é necessário remover uma referência válida do campo Atividade DevOps para confirmar outra.
- As situações com múltiplas referências ou vínculos continuam destacadas para análise. Para confirmá-las, o perfil precisa também de **Resolver conflitos de conciliação de Work Packages**. Selecione as referências corretas, informe uma justificativa de **10 a 2.000 caracteres por referência**, marque a conferência e confirme até cinco por operação.
- O servidor valida justificativa, permissões, entidade ativa, leitura/alteração do chamado e acesso à WP usando o token pessoal. URLs inválidas, outra instância e falta de acesso continuam bloqueadas.
- A operação adiciona apenas os pares selecionados. Os vínculos anteriores, IDs, fases, históricos e entradas de tempo são preservados. Repetir o mesmo par não cria outra linha nem outro evento de conciliação.
- A justificativa, o usuário e os motivos da revisão ficam na auditoria do novo vínculo; a justificativa pode ser consultada nos detalhes do evento.
- A transferência local anterior permanece disponível somente para um único vínculo seguro, conforme os bloqueios existentes. Adicionar um vínculo justificado não transfere o anterior.

Nenhuma WP é criada ou alterada no OpenProject pela conciliação. Não há publicação imediata de fase ou acompanhamento. Nas sincronizações seguintes, o webhook processa cada chamado vinculado de forma independente, aplicando as regras de fase e privacidade já configuradas. O tempo permanece no chamado em que foi registrado, sem cópia entre vínculos.

## Atualização

Faça backup e atualize sem desinstalar. A migração substitui a unicidade global de WP pela unicidade do par `(tickets_id, openproject_work_package_id)`, adicionando um índice não único por WP. Não regrava dados nem altera IDs. Não concede permissões automaticamente. Não restaure somente o código antigo após gravar compartilhamentos; versões anteriores pressupõem uma WP por chamado de origem e não suportam esse modelo.

`findByWorkPackage($wp, $ticket)` resolve um par específico; sem chamado, só retorna resultado quando não há ambiguidade. `findAllByWorkPackage()` é usado para sincronizar todos os vínculos. Histórico e cache usam o par, evitando selecionar ou atualizar outro chamado.

## Validação e limites

- Instalação limpa em GLPI 11.0.9/MariaDB isolados; migração de índice antigo com vínculo e histórico sentinela, sem mudança nos hashes; duas reexecuções idempotentes.
- Atualização repetida preservando as 17 tabelas, inclusive pares compartilhados.
- Navegador: retorno real à aba, conciliação de uma WP com vários chamados e de duas WPs com um chamado, justificativa obrigatória, preservação de fase/mensagem/tempo anteriores, repetição sem duplicação, CSRF e perfis administrativo, interno, somente leitura e cliente.
- Webhook assinado: atualização de três chamados da mesma WP, repetição sem duplicar acompanhamentos e assinatura inválida rejeitada.
- Regressão da coluna nativa de WP: múltiplos links, filtros vazio/não vazio/igualdade/negação, entidades, perfis e CSV.
- Regressão da conciliação anterior: URLs, 401/403/404, timeout, identidade inválida da WP, acesso por entidade, transferências seguras/bloqueadas, PDF/Excel e ausência de escrita externa.
- Sintaxe PHP e JavaScript e inspeção visual em telas de 1366px/390px, temas claro/escuro.

As consultas do teste usam uma API sintética no Docker isolado. A confirmação é individual por par: um lote pode produzir sucessos e falhas, informados separadamente, e pode ser repetido com segurança. Não foi acrescentada edição manual de URLs ou de vínculos fora das referências reconhecidas em Atividade DevOps.

Reprodução: no ambiente `demandas-test-021-glpi`, copiar `tests/legacy-reconciliation-fixture.php`, `tests/legacy-reconciliation-api.php` e `tests/reconciliation-many-fixture.php` para `/tmp`, iniciar a API com `php -S 127.0.0.1:8393 /tmp/legacy-reconciliation-api.php` e executar `node tests/reconciliation-many.cjs` com `PLAYWRIGHT_MODULE` configurado. As fixtures recusam o banco compartilhado.
