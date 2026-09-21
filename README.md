# MVP de Gestão de Demandas — GLPI + OpenProject

## Plugin 0.17.3

A versão 0.17.3 move o lançamento de entradas de tempo para um modal, permite finalizar diretamente na grid uma entrada em andamento e transforma o número da WP em link direto para o OpenProject.

Ambiente local de homologação para validar a integração entre tickets do GLPI e Work Packages do OpenProject.

> Leia [AGENTS.md](AGENTS.md) e [docs/CODEX_HANDOFF.md](docs/CODEX_HANDOFF.md) antes de iniciar mudanças com o Codex.

## Componentes

| Componente | Versão | Endereço local |
|---|---:|---|
| GLPI | 11.0.7 | <http://localhost:8180> |
| MariaDB | 11.4 | Somente na rede Docker |
| OpenProject | 17.7.2 | <http://localhost:8280> |

Consulte o README completo no repositório após o commit inicial para instalação, configuração, homologação, segurança e histórico funcional.
