# Release 0.18.3 — correção de instalação

## Escopo

Base: v0.18.2 (`a553555`). Elimina os avisos de DATETIME do instalador de
**demandas** e corrige a aspa ausente em `TimeManagementService::bankLedger()`
que causava erro de sintaxe PHP na versão anterior. Não altera regras de saldo,
jornada, ausência, fases públicas ou visibilidade de clientes.

Os erros dos prints que citam `plugin_propostas_index_exists()` e
`plugin_version_propostas()` pertencem a outro plugin. O repositório não contém
`plugins/propostas`; esta release não corrige nem substitui aquele componente.
Em demandas, o instalador já utiliza `request()` e `doQuery()`, não `query()`.

## Migração e atualização

1. Faça backup do banco e dos arquivos e programe a atualização sem lançamentos concorrentes.
2. Extraia o ZIP: a pasta raiz deve ser `demandas`, instalada em `plugins/demandas`.
3. Substitua os arquivos e execute **Atualizar** em **Configuração > Plugins**.
4. Ative o plugin se necessário e confira marcações, auditoria, vínculos e configurações.

Não desinstale para atualizar: a desinstalação remove os dados do plugin.
Mantenha a configuração de fuso horário do GLPI durante a migração. A conversão
usa o fuso da conexão, sem deslocamento manual adicional. Valores nulos são
preservados. DATE/TIME não são convertidos. `punch_at` continua obrigatório e
não recebe atualização automática quando outro campo da marcação é editado.

A migração verifica todas as colunas legadas antes de alterá-las. Datas não
representáveis por TIMESTAMP no servidor interrompem a atualização com uma
mensagem que identifica a coluna, sem substituir datas por zero ou NULL. Após
a revisão administrativa, é possível repetir a atualização. A conversão só
é aplicada a colunas ainda DATETIME; não recria tabelas ou registros.

## Validação executada

Ambiente Docker descartável: GLPI 11.0.9, MariaDB 11.4, PHP da imagem oficial,
banco instalado com `--strict-configuration`. Rede `demandas-release-0183-net`,
contêineres `demandas-release-0183-glpi` e `demandas-release-0183-db`, sem montar
os volumes da homologação existente. Endpoint HTTP local: porta 8383.

- Reprodução na 0.18.2: dez avisos DATETIME na instalação.
- Atualização para 0.18.3 com dados fictícios em 11 tabelas: comparação integral
  dos registros antes/depois, incluindo vínculos, eventos, ponto, ausências,
  metadados de anexos, entradas de tempo, configurações individuais e token fictício.
- Repetição da atualização com os mesmos registros e sem avisos DATETIME/consultas diretas.
- Preservação de NULL, campos DATE/TIME e horários na sessão `-03:00`.
- Edição de observação sem alteração automática do horário da marcação.
- Datas 1960 e 9999 rejeitadas antes do DDL, preservadas para revisão;
  retomada da migração após correção dos dados fictícios.
- Instalação limpa e repetição aprovadas; instalação/ativação pela CLI nativa.
- Sintaxe dos 44 arquivos PHP do plugin e do JavaScript externo validada.
- Auditoria de falta justificada com motivo e saldo neutro aprovada.
- Ponto, auditoria, ausências, configuração e painel: HTTP 200 para administrador
  e técnico com direitos explicitamente habilitados no banco de testes.
- Cliente sem os direitos: HTTP 403 em ponto, auditoria, ausências e painel.
  A configuração pessoal continua acessível conforme o comportamento existente.
- POST sem CSRF rejeitado com HTTP 403 nos três perfis.
- Webhook: saúde HTTP 200, POST sem assinatura HTTP 401; assinatura HMAC válida
  aceita para evento ignorado e assinatura inválida rejeitada pelo handler.

## Reprodução do teste de migração

`tests/install-timestamps.php` requer o ambiente isolado acima e o código montado
em `/var/www/glpi/plugins/demandas`. Copie o teste para `/tmp` no contêiner.

1. Instale a 0.18.2 no banco descartável e execute o teste no modo `seed`.
2. Substitua os arquivos pela 0.18.3 e execute `upgrade`.
3. Execute `clean-fixtures` em outro processo, seguido de `fresh`.
4. O modo opcional `profiles` habilita direitos dos perfis fictícios usados nos testes HTTP.

**Não execute em produção:** `clean-fixtures` remove as tabelas do plugin no
banco de teste. O script recusa execução fora do hostname Docker específico.

## Limites

Não houve acesso ao GLPI oficial nem ao código de propostas. Não foram
reexecutadas operações reais no OpenProject (criação, sincronização, timeout,
401/403 e repetição), nem validação visual responsiva em temas claro/escuro.
Não foi certificada compatibilidade com GLPI 10 ou outros bancos/versões.
Os testes HTTP verificam resposta e autorização, não substituem a homologação
funcional da infraestrutura oficial nem uma revisão completa de segurança.
