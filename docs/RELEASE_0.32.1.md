# Gestão de Demandas 0.32.1 — segurança de acesso e pendências

## Escopo

Etapa 1 da revisão de segurança. Corrige autorização e resolução de pendências, preservando registros existentes, direitos dos perfis e exceções individuais. Inclui as melhorias de interface da 0.32.0. Não altera banco de horas, fases públicas, vínculos ou integração remota. Não há nova tabela ou migração de dados.

## Comportamento

- Verificação operacional resolve somente pendências dos chamados efetivamente avaliados e legíveis, inclusive chamados avaliados que não geraram ocorrências. Inacessíveis, excluídos e fora do escopo permanecem intactos. Erro na classificação interrompe a avaliação sem resolver ocorrências.
- Jornada/ponto próprios exigem portal e direito de visualizar/registrar o próprio ponto. Alterações em outro usuário exigem portal, administração de jornadas e usuário-alvo no escopo. Consulta de ponto de colegas mantém portal, ponto próprio e consulta de equipe. Entradas de tempo exigem portal e o direito próprio/de terceiros correspondente.
- O escopo de terceiros usa associações de perfil/entidade do GLPI, incluindo recursividade, cruzadas com as entidades ativas. Usuário inexistente ou excluído é rejeitado. Registros de tempo continuam pessoais, não são repartidos por entidade.
- O cadastro nativo de usuários mantém sua autorização própria. Administração do ponto permite a gestão delegada pelos direitos do módulo, dentro do escopo.
- As duas telas de exceções passam a compartilhar os oito direitos de horas/ponto já existentes no cadastro de usuários. Herdar remove apenas a exceção escolhida; Permitir/Negar mantêm a precedência atual. Lotes inválidos não gravam parcialmente. Auditoria registra quem alterou, usuário-alvo, direito e valores anterior/novo.
- Direitos legados fora do catálogo não são apagados nem migrados. Não há concessão ou revogação automática durante atualização.

## Homologação

1. Acesse Gerência > Horas e Ponto, confira jornada, marcações, ausências e saldos existentes.
2. Com perfil de consulta de equipe, confira os colegas disponíveis nas entidades ativas; tente outra entidade sem acesso e confirme o bloqueio.
3. Como administrador autorizado, altere uma exceção de um usuário de teste e confira Herdar/Permitir/Negar. Restaure a escolha anterior após a validação.
4. Na Central de Pendências, execute a verificação com acesso parcial aos chamados: ocorrências de chamados não consultáveis não devem ser resolvidas.
5. Confirme a organização de permissões e o editor contextual da 0.32.0. Recarregue com Ctrl+F5; se necessário, encerre e reabra a sessão do GLPI.

## Limites

Não reabre automaticamente pendências que uma versão anterior possa ter resolvido indevidamente. Os demais itens da revisão (criptografia, idempotência de integrações, uploads e evolução dos indicadores) ficam para as etapas seguintes. Nenhum dado é enviado ao OpenProject para esta correção. A publicação desta versão foi autorizada após validação local pelo usuário.

## Verificação técnica

- Falhas reproduzidas antes da correção no Docker descartável: resolução de pendência inacessível e POST de configurações aceito para cliente sem acesso ao módulo.
- `tests/security-stage1.cjs`: administrador, operador com leitura parcial, consulta de equipe, cliente e usuário sem portal; POSTs diretos, entidades, usuário inexistente, leitura de comprovantes fora de escopo, CSRF e preservação integral em negativas. Exceções malformadas/lotes mistos rejeitados antes da escrita; precedência individual Permitir/Negar sobre o perfil; autor/antes/depois auditados; registros legados preservados.
- Verificação operacional com chamado visível, oculto na mesma entidade, excluído e em outra entidade; ação indevida bloqueada; falha de classificação preserva ocorrências; repetição mantém a tratativa Ignorada e não duplica a ocorrência.
- `tests/security-stage1.cjs --ui`: cadastro do usuário e Administração do ponto exibem os mesmos oito direitos; salvar sem alterar as seleções preserva os valores existentes.
- `tests/timeclock-modal.cjs`: administrador/equipe em nove resoluções, claro/escuro, salvamento e reabertura de quatro marcações e cliente sem direito bloqueado. Sem erros JavaScript.
- `tests/time-entry-http.py`: consulta, criação, sincronização, edição e exclusão de entradas com API sintética local; CSRF, 401/403, outro usuário, resposta de WP divergente e acesso anônimo. Nenhum envio ao OpenProject real.
- Instalação limpa em schema separado e atualização repetida: 18 tabelas do plugin e duas tabelas de permissões preservadas. Nenhum volume removido.
- Sintaxe dos 87 arquivos PHP do plugin, JavaScript e `git diff --check`. Webhook com assinatura válida/evento ignorado e assinatura inválida verificados no banco descartável; sem chamadas a instâncias remotas.
- Homologação compartilhada atualizada/ativada em **0.32.1** pelos comandos nativos do GLPI, com backup local ignorado pelo Git. Os hashes das 18 tabelas, direitos dos perfis, associações de usuários e configuração (21 grupos) permaneceram idênticos. Endereço local responde HTTP 200.

A validação operacional pelo usuário foi concluída e aprovada em 26/10/2026. A suíte antiga `action-dashboard-unit.php` possui a expectativa de renderização desatualizada já registrada na 0.32.0; não foi alterada nem usada como evidência desta etapa. Os avisos nativos de mudança de versão durante a atualização são esperados; o plugin foi reativado ao final.

## Instalação em produção

1. Faça backup do banco e da pasta atual do plugin.
2. Baixe `demandas-0.32.1.zip` da release e substitua a pasta `plugins/demandas` pela pasta `demandas` do ZIP, preservando proprietário e permissões do servidor.
3. Em **Configuração > Plugins**, execute **Atualizar** e ative se necessário. **Não desinstale**.
4. Recarregue com Ctrl+F5; se necessário, saia e entre novamente no GLPI.
5. Confira as permissões, Horas e Ponto e a Central de Pendências. Os direitos existentes não precisam ser recriados.

Use o ZIP do plugin anexado à release, não o pacote automático de código-fonte do repositório. Compatibilidade: GLPI 11 e OpenProject 17.x.
