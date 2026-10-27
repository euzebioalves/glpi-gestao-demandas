# 0.36.0 — Validação contínua de segurança

Etapa 5 da revisão. A versão 0.35.0 foi confirmada em produção pelo usuário. A 0.36.0 foi validada pelo usuário em homologação, com publicação na main e release autorizadas em 27/10/2026.

## Escopo

- Workflow de PR, main, execução manual e revisão semanal, com token somente de leitura, runner efêmero e sem segredos de produção. Não usa pull_request_target, runner próprio, actions de terceiros ou publicação automática.
- Comando único para verificações offline e regressões em Docker descartável, com recusa de outro host de banco e de volumes identificados como homologação. Falhas interrompem a execução; integração não executada não é apresentada como aprovada.
- Análise de tokens PHP: sintaxe, eval, desserialização PHP, execução de shell e consultas diretas via query. A única exceção proc_open permanece no conversor existente, coberto pelos testes de limites. Isso não substitui análise de tipos, fluxo de dados ou revisão humana de autorização/SQL/XSS.
- Verificação de formatos conhecidos de segredos sem imprimir o conteúdo encontrado. Backups, chaves, dumps, dependências e fixtures são recusados no pacote do plugin. A varredura não identifica todo formato possível de token nem pesquisa histórico Git antigo.
- Lockfile da dependência Playwright já existente, npm ci sem scripts de instalação e npm audit com bloqueio em severidade alta/crítica. Nenhuma nova biblioteca foi adicionada ao plugin.
- Inventário das bibliotecas PHP efetivamente distribuídas pela imagem GLPI, com versão/licença e hash dos metadados. Não é uma auditoria de CVEs das bibliotecas PHP ou do sistema operacional.
- ZIP determinístico para os mesmos bytes de origem, com SHA256, manifesto por arquivo e indicação de checkout alterado. Pacotes de homologação não são publicados automaticamente.

O código funcional do plugin, schema, IDs de direitos, concessões, dados, credenciais, saldos, fases públicas e visibilidade de clientes permanecem inalterados. A única mudança no código distribuído é o número da versão e a documentação. Não há novo fluxo administrativo; o Tutorial atual permanece aplicável.

## Execução

Ver [VALIDACAO_CONTINUA.md](VALIDACAO_CONTINUA.md). Scripts e fixtures ficam fora do ZIP e nunca devem ser colocados na pasta pública do servidor. O pipeline não usa OpenProject real; os testes de rede usam simuladores em loopback.

## Instalação e atualização em produção — roteiro consolidado desde 0.32.0

Revisão documental de 27/10/2026. Este roteiro reúne os cuidados das versões 0.32.0, 0.32.1, 0.33.0, 0.34.0, 0.35.0 e 0.36.0. O ZIP, a tag e o checksum da release 0.36.0 permanecem os mesmos; esta revisão não exige reinstalação em quem já atualizou com sucesso.

### 1. Identificar a origem e conferir os requisitos

Registre a versão instalada e os caminhos efetivos do GLPI, plugin, configuração, documentos e logs. Em Docker, identifique os volumes persistentes e a imagem utilizada. Os caminhos variam por instalação: não presuma que a configuração esteja dentro da pasta do plugin.

- Compatibilidade declarada pelo plugin: GLPI **11.0.x** (11.0.0 a 11.0.99) e OpenProject **17.x**. Matriz homologada: GLPI **11.0.9**, MariaDB **11.4** e OpenProject **17.7.2**.
- **MariaDB é necessário nesta implementação**: desde 0.33.0, o controle de transações usa `@@session.in_transaction`, específico desse banco. O uso de MariaDB em produção foi confirmado pela infraestrutura; isso não confirma que toda versão de MariaDB foi homologada. Outra base exige adaptação e validação antes de instalar.
- Mantenha as versões-base, o fuso horário e as configurações existentes durante esta atualização. Não é necessário atualizar GLPI, OpenProject ou MariaDB para aplicar o pacote do plugin na matriz compatível.
- Confirme espaço para backup/extração e acesso de leitura do processo PHP à chave e ao código, além das permissões de escrita já exigidas pelo GLPI nos diretórios de dados, temporários e logs. Preserve proprietário/grupo e política de permissões; não use `chmod 777` como correção.

| Versão de origem | Cuidados acumulados ao chegar à 0.36.0 |
|---|---|
| 0.32.0 / 0.32.1 | Preservar perfis/exceções; aplicar também as migrações de operações, credenciais e acompanhamento descritas abaixo. A 0.32.1 incorporou as melhorias da 0.32.0. |
| 0.33.0 | Preservar operações pendentes e fila; aplicar a proteção de credenciais da 0.34.0 e os campos da 0.35.0. |
| 0.34.0 | Preservar banco e chave correspondentes; aplicar os campos de acompanhamento da 0.35.0. |
| 0.35.0 | Não há nova migração de schema nem mudança funcional na 0.36.0; manter os mesmos backups e conferir a atualização nativa. |
| 0.36.0 já ativa | Apenas consultar este roteiro consolidado; nenhum novo pacote foi gerado. |

O instalador da 0.36.0 contém as migrações cumulativas e idempotentes; não exige instalar cada ZIP intermediário. Para saltos a partir de 0.32.x/0.33.x/0.34.x, ensaie primeiro em cópia isolada do banco, documentos e chave da origem. As evidências desta entrega cobrem instalação limpa, atualizações repetidas e evolução das etapas; não representam ensaio de toda combinação de base antiga real.

### 2. Backup e janela de manutenção

1. Combine uma janela com a equipe e impeça novas gravações durante a cópia e a atualização. Coordene acessos, automações e recebimento de webhooks para não processar operações com arquivos parcialmente substituídos; registre como retomá-los ao final.
2. Faça backup consistente do **banco completo do GLPI**, da **pasta atual do plugin**, dos **documentos/anexos**, do **diretório efetivo de configuração**, incluindo **`glpicrypt.key`**, e das configurações de implantação necessárias à restauração. Em Docker, preserve também a identificação da imagem e dos volumes. Não use reset nem remova volumes.
3. Confirme que o backup é legível e que existe um procedimento de restauração. Guarde a chave em local protegido, fora da pasta pública e separada dos arquivos de distribuição. Nunca envie chave, tokens, dumps ou configurações privadas para GitHub, chamados ou logs.
4. Registre as operações com resultado incerto e as edições/exclusões pendentes antes da manutenção. Não limpe esses estados nem a fila de acompanhamentos para atualizar.

**Desde a 0.34.0, banco criptografado sem a chave correspondente não permite recuperar os tokens.** A chave precisa existir e ser legível pelo GLPI. Não a substitua por uma chave nova para contornar falha de leitura. Uma rotação deve seguir o mecanismo nativo do GLPI, com plugin instalado/ativo e backup compatível; não é requisito desta atualização.

### 3. Baixar e conferir o pacote

Baixe da [release 0.36.0](https://github.com/euzebioalves/glpi-gestao-demandas/releases/tag/v0.36.0) os anexos **`demandas-0.36.0.zip`** e **`demandas-0.36.0.sha256`**. Não use os arquivos automáticos **Source code**: eles contêm o repositório e os recursos de homologação, não somente o plugin.

No Linux, com os dois anexos no mesmo diretório:

```bash
sha256sum -c demandas-0.36.0.sha256
unzip -l demandas-0.36.0.zip
```

O checksum deve retornar `OK`. SHA256 do ZIP publicado:

```text
40e9608984cf59b83a6f81a33ab0c1444547e019b67ac48f0db9c79f4183f6b2
```

Extraia primeiro em diretório temporário fora da árvore pública. O ZIP contém a pasta `demandas/`; o destino final deve ser `plugins/demandas/setup.php`, sem uma segunda pasta `demandas` aninhada. Não copie scripts de teste, `bootstrap`, backups, `.env` ou dependências de desenvolvimento para o servidor.

### 4. Substituir os arquivos e executar a atualização

1. Com backup concluído e a janela ativa, substitua o código de `plugins/demandas` pela pasta `demandas` extraída. Mantenha a cópia antiga fora da árvore pública, sem deixar duas instalações do plugin em diretórios diferentes. Preserve os dados persistentes, proprietário/grupo e permissões adequadas ao serviço PHP.
2. Acesse **Configuração > Plugins** com usuário autorizado à administração de plugins e execute **Atualizar** em **Gestão de Demandas**. Aguarde a conclusão e ative se necessário. **Não desinstale**: a desinstalação remove dados. Apenas trocar os arquivos não substitui a atualização do banco pelo GLPI.
3. Confira a versão **0.36.0** e o estado ativo. Se o servidor mantiver código PHP antigo em OPcache, a infraestrutura deve aplicar seu procedimento de invalidação/recarregamento do serviço. Ctrl+F5 atualiza o navegador, não o OPcache.
4. Em caso de erro de migração, interrompa a liberação, preserve o backup e consulte os logs protegidos. Não force a versão no banco, não apague colunas ou históricos e não remova marcadores de migração. As alterações de schema são idempotentes, mas não se deve presumir rollback global de toda a atualização.
5. Após os testes abaixo, retome os acessos e automações coordenados na manutenção. Recarregue com **Ctrl+F5**; se menus/permissões não refletirem a versão, saia e entre novamente no GLPI.

O instalador realiza, quando ainda necessário:

- **0.33.0:** criação de `glpi_plugin_demandas_followup_jobs` e da coluna anulável `pending_operation_json` nas entradas de tempo. Preserva intenções pendentes e não importa eventos históricos para a fila.
- **0.34.0:** migração transacional dos tokens pessoais, automático/legado e segredo do webhook para criptografia nativa. Preserva seus valores; se a chave falhar ou uma credencial não couber no campo protegido, interrompe essa migração sem confirmar criptografia parcial. Não altere `credentials_encrypted_0340` nem grave tokens diretamente por SQL.
- **0.35.0:** adição de `owner_users_id`, `due_date` e `treatment_note` em `operational_findings`, e `metrics_json` em `operational_runs`, inicialmente nulos para os registros existentes.
- **0.36.0:** controles de desenvolvimento/CI; nenhuma nova alteração funcional ou de schema em relação à 0.35.0.

**Instalação nova:** se não existe instalação nem dados anteriores do plugin, use **Instalar** e **Ativar**. Configure URLs reais, token automático de usuário técnico dedicado, tokens pessoais, classificação, templates e webhook pela interface/Tutorial. Conceda somente os direitos aprovados aos perfis. Não execute o bootstrap de homologação em produção nem copie `integration.env` local. Em uma atualização existente, essas configurações e permissões devem ser preservadas, sem recadastro.

### 5. Requisitos de rede, anexos e extração local

- Mantenha URLs HTTP(S) válidas; em produção, use HTTPS. URLs administrativas não devem conter usuário/senha embutidos, query ou fragmento. A API não segue redirecionamentos: configure diretamente o endereço final, mantendo a rota `/api/v3` da API e a conectividade interna autorizada. Não substitua endereços de produção por exemplos `localhost`/Docker.
- Preserve URL e segredo do webhook existentes. Assinaturas SHA-1/SHA-256 continuam aceitas; a migração não exige regenerar o segredo. O endpoint limita o corpo a **2 MB** e o JSON a **64 níveis**. Um GET de saúde não prova que um evento assinado foi processado.
- Respostas JSON da API são limitadas a **16 MB** e **64 níveis**. Timeout configurado tem teto de **120 segundos** e leitura bloqueante de **15 segundos**. Limitação de taxa/conexões continua a cargo do proxy/servidor.
- Uploads continuam limitados a **15 MB por arquivo**, até **20 anexos por operação de ponto** ou **por edição de documentação**. PHP e proxy podem impor limites menores; confira suas configurações de upload/corpo da requisição conforme o uso aprovado, sem remover os limites de segurança. A validação usa conteúdo/MIME reais, não somente a extensão: imagens até 40 milhões de pixels e arquivos compactados/Office até 2.000 entradas e 100 MB descompactados, além do controle de expansão.
- **Somente para extração PDF/Office/OCR no preparo de contexto:** o servidor/container PHP precisa ter `pdftotext` (Poppler), `libreoffice`, `tesseract` com idiomas `por` e `eng`, e os executáveis **`/usr/bin/timeout`** e **`/usr/bin/prlimit`**. O ZIP não instala pacotes do sistema. Sem as ferramentas exigidas, as conversões correspondentes ficam indisponíveis; os demais módulos continuam funcionando. Em Docker, as ferramentas devem estar na imagem do serviço, não apenas no host.
- A extração admite até cinco anexos e limita cada processo a 35 segundos (mais dois para encerramento), 30 segundos de CPU, 1 GiB de endereçamento, 16 MB por arquivo produzido e 1 MB de stdout/stderr. Não reaproveita conversão parcial como sucesso. Mantenha conversores atualizados e isolados, com rede restrita; validação de formato não é antivírus e limites de processo não equivalem a sandbox.

### 6. Conferência após a atualização

Faça alterações de teste apenas em registros destinados à validação; não provoque falhas nem execute fixtures automatizados em produção.

| Área | O que conferir |
|---|---|
| Versão e logs | 0.36.0 ativa, páginas sem HTTP 500 e sem novos erros de migração. Consultar logs PHP/GLPI e `demandas-security.log` no diretório protegido; ao relatar falha, usar referência/categoria sem expor segredos. |
| Perfis e entidades | Administrador, equipe interna e cliente mantêm os acessos aprovados. Conferir grupos/ajudas em Administração > Perfis > Gestão de Demandas e exceções Herdar/Permitir/Negar; não recriar nem conceder direitos apenas para fazer uma tela aparecer. Conferir perfil e entidades ativos. |
| Tokens e Backlog | Testar token pessoal e conexão automática já existentes, sem substituí-los; abrir Backlog e monitoramento e conferir preferências e links. |
| Evolução da Demanda | Vínculos e histórico preservados; sincronização funcionando. Na criação, editor/anexos aparecem após projeto, tipo, campos e template carregados; em WP existente, Documentar carrega a documentação. Testar imagem/anexo válido em registro de validação. |
| Horas e Ponto | Jornadas, marcações, ausências, comprovantes e saldos anteriores preservados; salvar/reabrir quatro marcações em dia de teste, baixar comprovante autorizado. Dia sem marcação e sem ausência continua neutro. Ponto não é enviado ao OpenProject. |
| Entradas de Tempo | Conferir IDs remotos e estados pendentes. Sincronização repetida de uma entrada confirmada não deve criar outro ID; testar criação/edição/exclusão somente em lançamento de validação aprovado. |
| Webhook e acompanhamentos | Confirmar recebimento de evento assinado em WP de teste e histórico local; repetição sem mudança de status não deve duplicar acompanhamento. Falhas pendentes são retomadas na próxima sincronização manual ou webhook; não há novo worker periódico. |
| Pendências e dashboard | Verificar Bugs e Melhorias, evidências, qualidade dos dados e histórico. Rodada parcial não resolve pendências anteriores; sincronizar pela Evolução da Demanda e revisar evidências antes de verificar novamente. A central usa dados locais, não consulta o OpenProject nem fecha chamados. |
| Contexto e anexos | Se o recurso for utilizado, conferir PDF/Office/OCR e download dos comprovantes. Conversão indisponível/falha deve ser identificada, sem apresentar conteúdo parcial como completo. |

### 7. Tratamento de pendências preservadas

**Entradas de tempo:** resultado incerto exige **Conferir envio** e verificação humana no OpenProject. Informe o ID da entrada de tempo, não o da WP; o plugin confere usuário, WP, atividade, data, duração e comentário. Só declare ausência após comprovar que o lançamento não existe: uma declaração incorreta pode duplicar horas. Para edição/exclusão pendente, restaure acesso/token se necessário e use **Sincronizar** para retomar no mesmo ID. Evite editar diretamente no OpenProject enquanto essa intenção estiver pendente. Operações históricas não são reconstruídas automaticamente.

**Central de Pendências:** históricos legados insuficientes podem produzir resultado parcial; não são fabricados retroativamente. Uma rodada parcial preserva todas as pendências anteriores e chamados inacessíveis/fora de entidade nunca são resolvidos pela ausência de resultados. Pendências resolvidas indevidamente antes das correções não são reabertas automaticamente por uma migração.

Em **Tratar / histórico**, o operador autorizado pode assumir em seu nome, informar prazo entre hoje e os próximos 365 dias e observação de até 2.000 caracteres. Adiar por sete dias exige motivo de 10–2.000 caracteres e não corrige a causa. Leitura exige dados técnicos e visualização de pendências; executar/tratar exige também a permissão de gestão. A atualização não concede esses direitos. Tempo de etapa representa observações locais até a última sincronização, não a data exata da mudança no OpenProject.

### 8. Restauração e limites da entrega

Se for necessário voltar, mantenha a janela e restaure um conjunto consistente de **código, banco, documentos e configuração/chave** do mesmo backup. Em especial, voltar apenas aos arquivos da 0.33.0 ou anteriores não permite ler as credenciais criptografadas desde 0.34.0. Não elimine o marcador de criptografia ou colunas para tentar reverter manualmente.

O backup do GLPI não desfaz entradas de tempo ou WPs já criadas/alteradas no OpenProject depois dele. Antes de retomar envios após restauração, confira as operações remotas para evitar duplicação. Preserve evidências e investigue erros de chave, schema ou permissões antes de liberar o serviço.

Os testes/CI da 0.36.0 ficam fora do ZIP. Não é necessário instalar Node.js, Playwright ou executar `scripts/quality`, `tests`, `configure-mvp.ps1` ou scripts de reset no servidor de produção. A análise estática/segredos tem cobertura limitada e o inventário PHP não é auditoria completa de CVEs do GLPI ou do sistema operacional.

Fontes históricas deste roteiro: [0.32.0](RELEASE_0.32.0.md), [0.32.1](RELEASE_0.32.1.md), [0.33.0](RELEASE_0.33.0.md), [0.34.0](RELEASE_0.34.0.md) e [0.35.0](RELEASE_0.35.0.md).

## Resultados locais

- Comando completo `python scripts/quality/run.py --integration` aprovado, incluindo 66 casos isolados de dashboard, 36 de indicadores, instalação limpa e duas atualizações repetidas, credenciais/conteúdo, rede, HTTP/perfis, operações e webhook.
- Cinco controles negativos aprovados, incluindo recusa de host/porta/volume incorretos e rejeição de PHP perigoso/inválido. Sintaxe PHP/JavaScript/Python e YAML conferida; npm audit não encontrou vulnerabilidades na árvore de testes fixada nesta execução.
- Duas gerações do ZIP com os mesmos bytes de origem produziram o mesmo SHA256; 101 arquivos do plugin conferidos, sem fixtures, dependências ou backups.
- Homologação http://localhost:8180 atualizada e ativa em 0.36.0. Os 22 grupos de dados/configurações/direitos mantiveram exatamente os mesmos hashes antes e depois, incluindo valores criptografados e os campos de acompanhamento da etapa 4.
- Backups privados e evidências locais em dist/before-stage5.sql, dist/before-stage5-config.tar.gz, dist/stage5-before.json, dist/stage5-after.json e dist/stage5-regression.log, ignorados pelo Git. Não distribuir os backups.

A publicação inclui conferência do workflow remoto no [PR #21](https://github.com/euzebioalves/glpi-gestao-demandas/pull/21) e na main antes de disponibilizar o ZIP. O runner é provisionado do zero no GitHub; os fixtures sintéticos são legíveis pelo usuário www-data também no Linux. A instalação em produção é realizada pela infraestrutura após backup.
