# Gestão de Demandas 0.33.0 — operações recuperáveis

Validação operacional local aprovada pelo usuário em 26/10/2026. Publicação na main e geração da release autorizadas.

## Escopo da etapa 2

- Operações simultâneas de horas e ponto são serializadas por usuário na conexão primária do banco. Sincronização de WP é serializada por vínculo; o estado é relido após obter o lock.
- O formulário do dia grava marcações, ausência, metadados dos anexos e auditoria em uma transação. Erro em qualquer registro desfaz o conjunto. Upload novo é removido no rollback; arquivo anterior só é removido após commit. Repetir o formulário não duplica horários já gravados.
- Antes de criar uma entrada no OpenProject, o plugin persiste o estado `uncertain`. Timeout, resposta inválida ou interrupção não permitem um segundo POST automático. Rejeições HTTP definitivas (incluindo 401, 403 e 422) permitem correção e nova tentativa.
- A ação **Conferir envio** associa uma entrada remota existente após validar ID, usuário, WP, atividade, data, duração e comentário. Alternativamente, o operador pode confirmar explicitamente que conferiu e que o lançamento não existe, liberando nova tentativa. A decisão fica na auditoria.
- Atualizações e exclusões remotas preservam a intenção em `update_pending`/`delete_pending`. **Sincronizar** retoma a mesma operação no mesmo ID, sem criar outra entrada. O dado local anterior permanece até confirmação; 404 na repetição de exclusão é tratado como ausência remota.
- Mudança de status e enfileiramento do acompanhamento são atômicos. Publicação da mensagem e conclusão do job também são atômicas no GLPI. Falhas deixam o job pendente para a próxima sincronização, mesmo que o status não tenha mudado. Sair e retornar a um status continua gerando uma nova transição legítima.

Não altera cálculos de saldo, jornada, ausências, fases públicas, audiência das mensagens, direitos dos perfis ou exceções individuais. Marcações de ponto nunca são enviadas ao OpenProject.

## Como validar localmente

1. Acesse `http://localhost:8180` e recarregue com Ctrl+F5.
2. Em **Horas e Ponto**, salve um dia com quatro marcações e, se necessário, ausência/anexo. Reabra e confira os dados e o saldo.
3. Em **Entradas de Tempo**, crie e sincronize uma entrada de teste; repita a sincronização e confirme que o ID remoto permanece o mesmo. Edite e exclua uma entrada de teste já sincronizada.
4. Se houver **Conferir envio**, confira o lançamento no OpenProject antes de escolher uma ação. Informe o ID da entrada de tempo, não o da WP. Não declare ausência apenas para desbloquear o botão.
5. Em **Evolução da Demanda**, sincronize uma WP de teste com mensagem automática configurada; repetir sem mudança de status não deve criar outro acompanhamento.
6. Confira administrador, equipe interna e cliente: as permissões anteriores devem continuar valendo.

As falhas de rede e de banco foram simuladas somente no ambiente descartável. Não é necessário provocar indisponibilidade na homologação compartilhada para validar a interface.

## Migração e preservação

Adiciona `glpi_plugin_demandas_followup_jobs` e a coluna anulável `pending_operation_json` nas entradas de tempo. A migração é idempotente e não recria vínculos nem históricos. Estados pendentes de edição/exclusão sobrevivem à atualização.

Homologação compartilhada atualizada pelo instalador nativo do GLPI e ativada em **0.33.0**. Backup anterior em `dist/before-stage2.sql`, ignorado pelo Git. Os hashes de todos os campos anteriores das 18 tabelas, direitos dos perfis, associações de usuários e configuração (21 grupos) permaneceram idênticos. A nova tabela não importa eventos históricos.

## Evidências técnicas

- Falha de gravação parcial reproduzida com o serviço da 0.32.1 no Docker descartável: uma primeira marcação era alterada antes de rejeitar um horário inválido posterior.
- `tests/operations.py`: workers PHP independentes, POST aceito com resposta perdida/timeout, repetição, recuperação por ID, rejeições 401/403/422, edição/exclusão com resposta perdida, rollback de ponto/ausência/anexo/auditoria, falha após criar acompanhamento, recuperação com status inalterado e retorno ao status anterior.
- `tests/operations-http.cjs`: upload multipart real; erro no segundo anexo remove o primeiro arquivo novo e preserva o banco; falha no dia mantém o arquivo anterior. Conferência de horas com CSRF, usuário diferente e cliente bloqueados; formulário acessível em 390 e 1366 pixels, claro/escuro.
- `tests/security-stage1.cjs`: regressões de escopo, perfis, exceções, auditoria e pendências. A asserção de marcações foi delimitada ao dia testado, permitindo coexistência com outros testes sintéticos.
- `tests/time-entry-http.py`: criação, sincronização, edição e exclusão normais com API sintética; autenticação, resposta divergente de WP e CSRF.
- `tests/timeclock-modal.cjs`: administrador/equipe, nove resoluções, claro/escuro e quatro marcações salvas/reabertas; perfil cliente sem direito bloqueado e ausência de erros JavaScript.
- Webhook HTTP stateless com assinatura válida e repetição, assinatura inválida sem gravações; atualização repetida com edição remota pendente preserva intenção e direitos.
- Instalação limpa em schema exclusivo e atualização repetida: 19 tabelas do plugin e duas tabelas de permissões preservadas. Nenhum volume removido.
- Validação sintática dos 88 arquivos PHP; validação JavaScript e `git diff --check`.

O contrato de consulta, atualização e exclusão usa a [API oficial de entradas de tempo do OpenProject](https://www.openproject.org/docs/api/endpoints/time-entries/). Nenhum cabeçalho de idempotência remoto não documentado foi presumido.

## Limites operacionais

- GLPI e OpenProject não compartilham uma transação. Resultado incerto de criação exige conferência; declarar incorretamente que uma entrada não existe pode causar duplicação. Não existe conciliação automática por semelhança de conteúdo.
- A conferência verifica o registro remoto atual. Se alguém alterou o lançamento diretamente no OpenProject, diferenças precisam ser investigadas antes da associação. Erros históricos anteriores à atualização não são reclassificados automaticamente.
- Alterações remotas pendentes podem exigir restauração da permissão/token antes de serem concluídas. Evite editar diretamente no OpenProject uma entrada cuja atualização ainda está pendente no GLPI, pois a repetição aplica a intenção preservada.
- A fila de acompanhamentos é retomada por sincronização manual ou webhook; não foi criado um agendador periódico. A fila preserva mensagem e privacidade da regra da transição original. Entrega de notificações externas continua sob responsabilidade do GLPI.
- Interrupção abrupta durante upload pode deixar arquivo órfão sem referência no banco. Ele não fica disponível pelo endpoint de anexos. Remoção física posterior ao commit pode falhar e requer manutenção de arquivos órfãos; arquivos ainda referenciados não são apagados antes do commit.
- Locks e transações foram validados na base MariaDB 11.4 deste projeto. A consulta `@@session.in_transaction` é específica de MariaDB; outra base requer adaptação antes de usar esta versão.
- A etapa cobre horas, ponto/ausências e acompanhamentos da sincronização. Não reescreve o fluxo de criação/documentação de WPs nem reconstitui operações antigas. Criptografia, revisão ampliada de uploads e demais itens do plano permanecem para as próximas etapas.

## Instalação em produção

1. Faça backup do banco e da pasta atual do plugin.
2. Confirme que a instância utiliza MariaDB, conforme a compatibilidade descrita acima.
3. Baixe `demandas-0.33.0.zip` e substitua `plugins/demandas` pela pasta `demandas` do ZIP, preservando proprietário e permissões do servidor.
4. Em **Configuração > Plugins**, execute **Atualizar** e ative se necessário. **Não desinstale**.
5. Recarregue com **Ctrl+F5** e confira Horas e Ponto, Entradas de Tempo e Evolução da Demanda. Não é necessário recriar permissões.

Use o ZIP do plugin anexado à release, não os arquivos automáticos de código-fonte. O checksum SHA256 acompanha o pacote.
