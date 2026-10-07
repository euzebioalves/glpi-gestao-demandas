# 0.24.1 — Modal de ponto responsivo

## Correção

O modal **Editar registros** de **Horas e Ponto** podia cortar os botões ao adicionar as quatro marcações do dia. O formulário agora ocupa diretamente o contêiner nativo do modal: o conteúdo tem rolagem interna, enquanto o cabeçalho e os botões **Cancelar** e **Salvar alterações** permanecem acessíveis. As colunas das marcações são flexíveis e as opções de ausência quebram linha em telas estreitas.

Não há alteração de banco, cálculo de jornada/saldo, permissões ou integração com OpenProject. A atualização segue o fluxo nativo de plugins do GLPI.

## Homologação

- Reprodução em GLPI 11.0.9 no Docker isolado: com quatro marcações e viewport 1366 × 768, o rodapé começava em 852 px e o corpo não tinha rolagem.
- Playwright/Edge: 1920 × 1080, 1366 × 768, 1280 × 600, 1024 × 768, 768 × 1024, 390 × 844, 320 × 568, 844 × 390 e 683 × 384, em temas claro e escuro. Botões dentro da tela e clicáveis, sem transbordamento horizontal no corpo do modal.
- Perfis administrativo e interno: inclusão de marcações, abertura/remoção da seção de ausência, salvamento e reabertura das quatro marcações com horários e NSR preservados, sem erros JavaScript.
- Perfil cliente sem permissão de ponto: acesso negado pelo backend.
- Instalação limpa em banco de teste separado e repetição do instalador duas vezes, com preservação dos dados das 16 tabelas do plugin; atualização da base fictícia também preservou os dados.
- Sintaxe PHP dos arquivos alterados e da fixture, sintaxe JavaScript e revisão do diff.

## Repetir o teste de interface

`tests/timeclock-modal-fixture.php` aceita somente o banco Docker isolado `demandas-test-021-db`. O teste cria usuários e marcações fictícios; nunca execute em produção ou em ambiente compartilhado.

Com os contêineres isolados `demandas-test-021-db` e `demandas-test-021-glpi` disponíveis e o plugin instalado/ativado em `http://127.0.0.1:8386`:

```powershell
docker cp tests/timeclock-modal-fixture.php demandas-test-021-glpi:/tmp/timeclock-modal-fixture.php
node tests/timeclock-modal.cjs
```

Requer Playwright e Edge; `PLAYWRIGHT_MODULE` pode indicar uma instalação existente do Playwright. As capturas ficam no diretório temporário `demandas-timeclock-0241`. A opção `--baseline` serve exclusivamente para reproduzir a falha na versão anterior.

Limitações: não houve teste em dispositivos físicos, Safari/Firefox ou zoom real do navegador. O viewport 683 × 384 cobre o espaço útil reduzido equivalente a aproximadamente 200% em 1366 × 768. Não foram repetidos testes de APIs/webhook, pois a mudança se limita à apresentação do formulário de ponto.
