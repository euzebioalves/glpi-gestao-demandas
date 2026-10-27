# Gestão de Demandas 0.34.0 — proteção de credenciais e conteúdo

Implementação da etapa 3 da revisão de segurança. Validação local aprovada pelo usuário; publicação na main e release 0.34.0 autorizadas.

## Alterações

- Tokens pessoais, token automático, token global legado e segredo do webhook passam a usar a criptografia autenticada nativa do GLPI. A atualização preserva os valores originais, sem exigir novos tokens. Campos e configurações são registrados nos hooks nativos de rotação de chave.
- Migração transacional e idempotente, com verificação da chave e da capacidade dos campos antes de gravar. Uma falha desfaz a criptografia parcial e não grava o marcador de conclusão. Não altera vínculos, históricos, perfis, exceções individuais, fases públicas ou regras de ponto.
- URLs administrativas aceitam HTTP(S), sem credenciais embutidas, query ou fragmento. A API mantém a URL interna configurada, inclusive Docker/intranet, mas não segue redirecionamentos. Caminhos relativos perigosos são bloqueados. Respostas JSON limitadas a 16 MB e 64 níveis; timeout configurado limitado a 120 segundos e leitura bloqueante a 15 segundos.
- Falhas de API mostram orientação em português e uma referência. O log `demandas-security.log`, no diretório de logs protegido do GLPI, guarda somente referência, categoria e status HTTP quando disponível; não guarda tokens, URLs, corpos nem cabeçalhos. Exceções inesperadas de bibliotecas/banco não são apresentadas na interface. Mensagens de validação são escapadas antes dos avisos nativos, que renderizam HTML. Históricos anteriores não são apagados ou reescritos.
- Uploads conferem tamanho e MIME reais, assinatura de PDF/Office e dimensões de imagens. ZIP/Office compactados são inspecionados sem extração: até 2.000 entradas, 100 MB descompactados e limite de expansão. Imagens até 40 milhões de pixels. Permanecem os formatos permitidos e 15 MB por arquivo; ponto aceita até 20 anexos por operação e documentação até 20 por edição.
- Webhook lê no máximo 2 MB, retorna 413 para excesso e valida a assinatura antes de interpretar JSON, limitado a 64 níveis. Assinaturas SHA-1/SHA-256 existentes permanecem aceitas.
- Preparação de contexto admite até cinco anexos. Conversores usam tempo máximo de 35 segundos (mais dois para encerramento forçado), 30 segundos de CPU, 1 GiB de espaço de endereçamento, 16 MB por arquivo produzido e 1 MB de stdout/stderr. As duas saídas são drenadas simultaneamente para evitar bloqueio. Falhas não aproveitam conversões parciais.

## Backup, atualização e restauração

Faça backup do banco, documentos e **diretório de configuração do GLPI, incluindo `glpicrypt.key`**, mantendo a chave protegida e separada do pacote público. Banco criptografado sem a chave correspondente não permite recuperar tokens. Nunca envie a chave em chamados, logs ou Git.

Atualize pelo fluxo nativo de plugins, sem desinstalar. A chave precisa existir e ser legível pelo GLPI. Não gere outra chave arbitrariamente: use o procedimento nativo de rotação, com o plugin instalado/ativo e backup do conjunto. Na migração, credenciais excepcionalmente grandes que não caibam criptografadas interrompem a atualização e preservam o valor anterior.

Rollback para 0.33.0 exige restaurar o backup coordenado de banco/configuração e os arquivos anteriores; apenas substituir o código antigo não permite interpretar as credenciais criptografadas. Não remova o marcador `credentials_encrypted_0340` nem grave tokens diretamente por SQL. Automações devem usar o serviço do plugin ou a configuração nativa com os hooks carregados.

## Validação

Executada em GLPI 11.0.9 e MariaDB 11.4, com OpenProject 17.7.2 na homologação e API sintética para simular falhas:

- comparação semântica dos 22 grupos de tabelas e configurações antes/depois da migração, incluindo credenciais sem revelar valores;
- instalação limpa e duas atualizações repetidas, sem alteração de dados/permissões;
- criptografia, rotação nativa em cópia privada da chave e transação revertida, preservação de tokens pessoais e rollback de migração com falha;
- URLs inválidas, caminhos perigosos, redirecionamento HTTP sem atingir o destino, JSON inválido/grande, webhook excedente/profundo e assinaturas válidas/inválidas;
- uploads HTTP reais, extensão disfarçada, falso MIME do navegador, arquivo Office incompatível, expansão excessiva e rollback sem perder anexos antigos;
- limites de saída dos processadores, incluindo stderr, sem bloquear o processo; extração real de PDF e Office sob esses limites;
- administrador, equipe interna, cliente, ausência de permissão, entidade externa, CSRF e preservação de permissões;
- edição/criação de documentação, imagem no editor, redimensionamento, anexos, relações e repetição segura;
- regressão das operações da 0.33.0: concorrência, timeout, 401/403/422, recuperação, PATCH/DELETE pendentes e acompanhamentos sem duplicação.

Testes: `tests/security-content.php`, `tests/security-content-network.php`, `tests/operations.py`, `tests/operations-http.cjs`, `tests/security-stage1.cjs`, `tests/security-installation.php` e `tests/documentation.cjs`. Fixtures mutáveis exigem o container isolado; não executar em produção. O teste `security-content.php` também exige a imagem `demandas-glpi:11.0.9-local-extraction`, construída pelo Dockerfile existente, para verificar PDF e Office reais.

## Roteiro para validação humana

1. Em `http://localhost:8180`, recarregue com Ctrl+F5 e confirme a versão 0.34.0.
2. Teste seu token pessoal e a conexão automática existentes, sem substituí-los. Abra o Backlog e sincronize uma WP de homologação.
3. Edite uma documentação, inclua uma imagem e um anexo válido e confira no OpenProject.
4. Em Horas e Ponto, salve um dia de teste com comprovante e confirme o download; confira que os registros anteriores continuam disponíveis.
5. Confira perfis administrativo, interno e cliente; os acessos devem permanecer iguais.
6. Prepare um contexto com PDF/documento/imagem de teste. Arquivos não extraídos devem ser identificados, sem conteúdo parcial apresentado como sucesso.

## Limitações e operação

Validação de formato não é antivírus. Limites de processo não equivalem a sandbox: mantenha Poppler, LibreOffice e Tesseract atualizados e use isolamento/restrição de rede na infraestrutura. Não foi adicionada dependência externa. Sem `/usr/bin/timeout` ou `/usr/bin/prlimit`, conversões ficam indisponíveis; leitura de texto e demais módulos continuam funcionando. A imagem local foi reconstruída a partir do Dockerfile existente, mantendo GLPI 11.0.9 e os volumes. Os containers antigos usavam a imagem-base sem os conversores; a homologação agora usa a imagem do projeto com PDF/Office/OCR.

O host de integração continua sendo uma escolha do administrador; não há bloqueio genérico de IPs privados que impediria a comunicação interna existente. Use HTTPS em produção. Limitação de taxa/conexões deve ser aplicada no proxy/servidor; esta etapa limita tamanho e processamento, não implementa rate limiting distribuído. A infraestrutura de produção e seus anexos reais não foram acessados nos testes.

## Estado local

Homologação atualizada e ativada em 0.34.0. Hashes semânticos de tabelas/configurações coincidiram antes/depois, incluindo tokens e permissões. Backup de banco e configuração protegido em `dist/` (ignorado pelo Git); conexão automática com o OpenProject local confirmada. Validação humana concluída com sucesso e publicação autorizada pelo usuário.
