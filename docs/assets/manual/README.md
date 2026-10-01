# Capturas do manual

As imagens desta pasta são produzidas pelo Playwright com:

```powershell
npm run test:e2e:manual
```

O comando só funciona quando a suíte declara um ambiente fictício isolado. Revise cada imagem antes de versioná-la: ela não pode exibir nomes reais, dados de clientes, tokens, URLs privadas, chamados ou anexos sensíveis.

O manual referencia, após revisão, `configuracao-integracao-automacao.png`, `monitoramento-work-packages.png` e `central-pendencias-operacionais.png`.

As capturas `conciliacao-detalhes-conflito.png` e `entrada-tempo-wp-externa.png` vêm de `tests/legacy-reconciliation-visual.cjs`, com perfis, chamados e API fictícios. O procedimento e as variáveis da instância isolada estão em `docs/RELEASE_0.24.0.md`. O teste também valida temas claro/escuro e largura móvel de 390 px. Nunca configure essa fixture para produção.
