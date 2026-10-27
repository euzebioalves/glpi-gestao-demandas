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

## Homologação e atualização

1. Faça backup consistente de banco, documentos, código e configuração, incluindo glpicrypt.key.
2. Atualize sem desinstalar; confira a versão 0.36.0 ativa.
3. Confira os mesmos perfis e permissões, Backlog, Evolução da Demanda, Pendências de Integração e Horas e Ponto.
4. Nenhuma permissão, token, vínculo ou configuração precisa ser recadastrada.

## Resultados locais

- Comando completo `python scripts/quality/run.py --integration` aprovado, incluindo 66 casos isolados de dashboard, 36 de indicadores, instalação limpa e duas atualizações repetidas, credenciais/conteúdo, rede, HTTP/perfis, operações e webhook.
- Cinco controles negativos aprovados, incluindo recusa de host/porta/volume incorretos e rejeição de PHP perigoso/inválido. Sintaxe PHP/JavaScript/Python e YAML conferida; npm audit não encontrou vulnerabilidades na árvore de testes fixada nesta execução.
- Duas gerações do ZIP com os mesmos bytes de origem produziram o mesmo SHA256; 101 arquivos do plugin conferidos, sem fixtures, dependências ou backups.
- Homologação http://localhost:8180 atualizada e ativa em 0.36.0. Os 22 grupos de dados/configurações/direitos mantiveram exatamente os mesmos hashes antes e depois, incluindo valores criptografados e os campos de acompanhamento da etapa 4.
- Backups privados e evidências locais em dist/before-stage5.sql, dist/before-stage5-config.tar.gz, dist/stage5-before.json, dist/stage5-after.json e dist/stage5-regression.log, ignorados pelo Git. Não distribuir os backups.

A publicação inclui conferência do workflow remoto no [PR #21](https://github.com/euzebioalves/glpi-gestao-demandas/pull/21) e na main antes de disponibilizar o ZIP. O runner é provisionado do zero no GitHub; os fixtures sintéticos são legíveis pelo usuário www-data também no Linux. A instalação em produção é realizada pela infraestrutura após backup.
