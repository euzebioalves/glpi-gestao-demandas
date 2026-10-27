# Validação contínua

## Comandos locais

Requisitos existentes: Git, Python 3, Node 20+, Docker e Playwright da raiz do repositório. Não há ferramentas novas no servidor de produção.

```powershell
npm ci --ignore-scripts
npm audit --audit-level=high
python scripts/quality/check.py
python scripts/quality/selftest.py
python scripts/quality/run.py --integration
```

O runner usa exclusivamente demandas-test-021-glpi, demandas-test-021-db e a porta 8386. Ele exige os containers em funcionamento, copia fixtures para /tmp e usa apenas dados sintéticos. Esses nomes são reservados para testes descartáveis: nunca monte neles volumes reais ou backups de produção. A verificação de nome/host é uma barreira contra enganos, não uma garantia contra configuração deliberadamente incorreta. A homologação compartilhada na porta 8180 não é alvo do runner.

O ambiente de teste precisa dos conversores já definidos em docker/glpi/Dockerfile. Por padrão, os testes HTTP usam Edge no Windows; para Chromium instalado pelo Playwright, defina PLAYWRIGHT_CHANNEL=chromium. PLAYWRIGHT_MODULE permite reutilizar uma instalação existente.

O .dockerignore limita o contexto de construção ao Dockerfile; backups, chaves, anexos e arquivos locais não são enviados ao build. O runner compara também as origens dos volumes de dados com os containers da homologação e recusa compartilhamento. Os controles negativos testam rejeição de pacote indevido, segredo conhecido, sintaxe inválida e operações PHP proibidas, sem imprimir o conteúdo sensível.

Sem --integration, executa somente política estática, sintaxe, testes isolados do dashboard, inventário e pacote. Isso não substitui a suíte de integração. O flag --provision-ci é exclusivo do GitHub Actions: cria containers novos e recusa qualquer container já existente com os nomes reservados. Não substitui nem remove volumes locais. O runner remoto é descartado pelo GitHub ao terminar.

## Cobertura de integração

- instalação do plugin, atualização repetida e instalação em schema vazio separado;
- preservação de tabelas e direitos nas atualizações;
- regras do dashboard e indicadores/tratativas com rollback;
- credenciais/rotação de chave, uploads, conversores e limites;
- administrador, equipe, consulta, cliente e outra entidade por HTTP, CSRF e responsividade;
- timeout, indisponibilidade, 401/403/422, concorrência, resultado incerto, repetição e recuperação;
- acompanhamentos atômicos e webhook assinado repetível.

Fixtures não dependem de usuários de suítes antigas. Indicadores usam um único instante de referência, evitando falsos erros quando o banco demora. A conferência de anexos usa GLPI_DOC_DIR e hashes dos arquivos reais, junto com seus metadados no banco. Temas HTTP usam auror/auror_dark nativos do GLPI.

Os testes de instalação criam schemas glpi_security_clean_* somente no banco descartável, preservados para diagnóstico. As APIs simuladas usam portas internas 8398/8399 e são encerradas ao finalizar. Não execute duas suítes simultaneamente nesse mesmo container.

## Dependências e revisão

package-lock.json fixa a árvore de testes e sua integridade. Atualizações devem passar por PR e validação; não usar npm audit fix automático. O npm audit consulta o registro npm e falha também quando a consulta não pode ser concluída; falha de rede não significa ausência de vulnerabilidades.

dist/quality/runtime-inventory.json registra apenas metadados de bibliotecas PHP da imagem, sem inicializar GLPI ou ler configurações. As bibliotecas Guzzle, Symfony, sanitização, Markdown, PDF, planilhas e demais componentes são fornecidas pelo GLPI. A imagem base continua GLPI 11.0.9; MariaDB 11.4 e OpenProject 17.7.2 permanecem inalterados. Tags de imagens e pacotes do sistema não constituem um lock binário; revisar avisos de segurança oficiais do GLPI, OpenProject, MariaDB e distribuição antes de alterar a matriz. Alterações de versões-base ou novas dependências exigem decisão conforme AGENTS.md.

A análise estática aqui é uma política limitada baseada em tokens, sem PHPStan/Psalm e sem alegar cobertura completa de tipos/taint. Novos analisadores/dependências precisam de aprovação e desenho de stubs para GLPI. A varredura de segredos cobre padrões de chaves privadas, tokens GitHub e chaves AWS; não comprova ausência de outros segredos. Relatórios não devem imprimir valores encontrados.

## CI e release

.github/workflows/quality.yml executa em pull_request, push na main, manualmente e semanalmente. Usa checkout do SHA do evento, não interpola título/corpo de PR em shell e não recebe credenciais externas. Referência de segurança: [documentação oficial do GitHub](https://docs.github.com/en/actions/reference/security/secure-use).

O workflow gera e verifica o pacote, mas não faz merge, deploy, publicação de release nem upload de backups ou capturas. Em falha, corrija a causa e repita a suíte. Após a primeira execução remota aprovada, configure o check Security and regression / security-regression como obrigatório na proteção da main, se essa for a política do repositório; não foi alterada a proteção administrativa nesta etapa.

Para preparar o pacote local:

```powershell
python scripts/quality/check.py --package
```

O resultado fica em dist/quality: ZIP, SHA256, manifesto por arquivo. O manifesto identifica commit e checkout alterado; para produção, empacote somente após o merge aprovado, com código limpo. Não publicar toda a pasta dist, que também pode conter backups privados. A versão candidata local ainda não é uma release publicada.
