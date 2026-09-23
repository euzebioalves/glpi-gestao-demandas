# Release 0.18.4 — autorização pelo perfil ativo

## Correção

A 0.18.3 exigia o nome literal Super-Admin e ignorava `MANAGE_CONFIG`. A 0.18.4 usa `Config::canManageConfiguration()` → `Profile::has(MANAGE_CONFIG)` → `Session::haveRight(..., READ)`. Master, Administrador Corporativo ou qualquer nome funcionam quando o perfil ativo possui o direito.

A página protege GET administrativo e POST global antes de qualquer escrita e fora do tratamento genérico de erros. `Config::save()` também exige o direito. Sem ele, permanecem somente a aba pessoal e os comandos de token do próprio usuário autenticado. Uma falha no teste pessoal volta à aba pessoal. O token automático e o segredo do webhook não são enviados à resposta pessoal.

Feriados, menus de ponto e o serviço de feriados deixam de comparar nomes e usam `MANAGE_HOLIDAYS`; exceções individuais continuam com `MANAGE_TIME_ACCESS` e a política `AccessPolicy` própria das horas. Esses direitos não concedem `MANAGE_CONFIG`. O backend limita as exceções aos direitos de horas oferecidos na tela e deixa de inserir a coluna inexistente `date_creation` nessa tabela. A edição dos perfis continua exigindo `profile/UPDATE` nativo do GLPI.

Não há nova migração, dependência externa ou mudança de regras de saldo, fases, entidades, vínculo com WP ou visibilidade pública. Nomes de perfis permanecem somente nas concessões iniciais e migrações históricas em `hook.php` e na documentação histórica. Os marcadores dessas migrações são preservados.

## Atualização

1. Faça backup do banco e dos arquivos.
2. Valide o ZIP com `SHA256SUMS.txt` e extraia a pasta `demandas/`.
3. Substitua os arquivos de `plugins/demandas` no servidor.
4. Em **Configuração > Plugins**, execute **Atualizar** e ative, se necessário.
5. Em **Administração > Perfis > Gestão de Demandas**, confira **Administrar as configurações do plugin** e selecione o perfil autorizado na sessão.

**Não desinstale para atualizar.** A desinstalação remove dados do plugin. Não renomeie perfis e não altere direitos diretamente por SQL: o GLPI mantém a sessão sincronizada pelo mecanismo nativo de atualização de permissões. A 0.18.4 preserva a migração de TIMESTAMP da 0.18.3; quem vem de versões anteriores deve seguir também suas orientações de datas e fuso.

## Validações executadas

Ambiente descartável: GLPI 11.0.9, PHP da imagem oficial e MariaDB 11.4; nenhum banco oficial nem volume compartilhado foi modificado.

- Defeito reproduzido na 0.18.3 pelo helper e por HTTP autenticado: Master com direito cadastrado não via abas administrativas.
- Atualização 0.18.3 → 0.18.4 e reexecução idempotente pelo comando nativo do GLPI, sem desinstalação. Comparação SHA-256 dos registros das 11 tabelas do plugin, configurações e `glpi_profilerights`: preservados, incluindo tokens e dados sintéticos previamente existentes.
- Instalação limpa e ativação em segundo GLPI descartável; verificação nativa de integridade do esquema aprovada.
- Sintaxe aprovada nos 44 arquivos PHP do plugin e em 14 scripts JavaScript (arquivo público e scripts da página renderizada); `git diff --check` sem problemas.
- Matriz HTTP com sessões e CSRF reais: Master e Administrador Corporativo autorizados; Super-Admin sem direito e perfil comum e perfil de cliente (interface helpdesk) sem direito bloqueados nas quatro URLs administrativas; POST global rejeitado com HTTP 403 e banco sem alterações.
- Troca de perfil pela rota nativa e revogação/concessão em sessão aberta refletidas na requisição seguinte. O teste respeita a precisão de segundos do marcador `last_rights_update` do GLPI.
- Token pessoal: salvar, testar, preservar valor quando vazio, ignorar `users_id` manipulado; sucesso e respostas 401/403 em API OpenProject simulada. Token automático preservado. Token pessoal não concede configuração global.
- Master salva a configuração e testa a automação; `MANAGE_CONFIG` não concede edição de perfis nem administração de ponto. Direitos de feriados e de exceções testados separadamente, incluindo POST indevido e restrição de exceções a horas.
- CSRF nativo: POST sem token rejeitado. Revisão do diff: sem credenciais reais, novas consultas diretas, remoção de dados ou bypass por nome de perfil.

## Reexecutar a regressão

Os testes usam somente Python padrão, Docker e o PHP já fornecido pela imagem GLPI; não instalam dependências. Os fixtures PHP recusam bancos com host diferente de `demandas-release-0183-db` ou `demandas-release-0184-db`. Nunca copie os testes para a pasta pública do plugin.

Para o roteiro automatizado, use um GLPI 11.0.9 descartável chamado `demandas-release-0183-glpi`, com banco `demandas-release-0183-db`, porta local `127.0.0.1:8383` e uma cópia do plugin montada em `/var/www/glpi/plugins/demandas`. Instale e ative inicialmente a 0.18.3 para validar atualização. Não use o compose do ambiente oficial nem desinstale o plugin.

```powershell
docker cp tests/profile-permissions-fixture.php demandas-release-0183-glpi:/tmp/profile-permissions-fixture.php
docker cp tests/openproject-fixture.php demandas-release-0183-glpi:/tmp/openproject-fixture.php
docker exec -d demandas-release-0183-glpi php -S 127.0.0.1:8390 /tmp/openproject-fixture.php
python tests/profile-permissions-http.py prepare --baseline
# Substitua a cópia montada do plugin pelos arquivos da 0.18.4.
docker exec demandas-release-0183-glpi php bin/console plugin:install demandas -u glpi -n --force
docker exec demandas-release-0183-glpi php bin/console plugin:activate demandas -n
python tests/profile-permissions-http.py verify
```

Para repetir com a 0.18.4 já instalada, omita `--baseline`. `prepare` cria/resetta somente fixtures sintéticas nesse ambiente descartável; `verify` altera seus direitos durante os cenários. A senha aleatória do usuário de teste fica no arquivo temporário local `demandas-0184-test-state.json`, nunca no Git. Preserve esse arquivo enquanto reutilizar o contêiner; descarte-o ao descartar o ambiente. Tokens com prefixo `fixture-` são valores fictícios aceitos apenas pela API simulada. O pacote publicado não contém testes.

## Roteiro manual para homologação oficial

1. No perfil Master, conceda a permissão de configuração e selecione-o. Confira as cinco abas e teste a automação.
2. Repita com Administrador Corporativo ou outro nome. Não renomeie os perfis reais.
3. Em perfil de teste chamado Super-Admin sem a permissão, confira somente a aba pessoal e HTTP 403 para `?tab=automation`, `classification`, `templates` e `tutorial`.
4. Repita com equipe interna e cliente; salve e teste seus próprios tokens. Confira que o token automático e os tokens de outros usuários continuam intactos.
5. Alterne perfis na mesma sessão, revogue o direito pela tela de perfis e recarregue. Tente POST administrativo com CSRF válido e confirme a negativa sem alteração do banco.
6. Valide separadamente feriados e exceções de horas. Não conceda configuração global apenas para administrar ponto.
7. Confira visualmente temas claro/escuro e telas estreitas. Teste webhook assinado, sincronização manual repetida sem duplicação e criação de WP com a API real.

## Limitações

A API usada na matriz automatizada é simulada: não representa toda a integração real com OpenProject 17.x. Esta entrega não reexecutou criação de WP, webhook assinado, sincronização completa sem duplicação, anexos nem inspeção visual responsiva nos dois temas. Esses fluxos não foram alterados e permanecem no roteiro manual; não são declarados aprovados nesta release. O ambiente oficial não foi acessado.
