# 0.35.0 — Indicadores e acompanhamento das pendências

Etapa 4 da revisão. Validação local aprovada pelo usuário e publicação da versão 0.35.0 autorizada para instalação em produção.

## Mudanças

- Central e dashboard compartilham histórico observado de etapas, normalização de status e validação de datas. A criação do vínculo deixa de ser usada como início da etapa.
- Qualidade explícita: sincronização recente, desatualizada, não registrada, data inválida/futura, status ausente, histórico insuficiente ou última tentativa falha. Novas falhas registram apenas categorias seguras; históricos antigos permanecem intactos.
- Uma rodada parcial pode registrar evidências encontradas, mas não resolve nenhuma pendência anterior. Uma falha de execução reverte todas as alterações da rodada e deixa o registro da execução em erro. Chamados inacessíveis, excluídos ou de outra entidade nunca são resolvidos.
- Responsável pode assumir o acompanhamento em seu próprio nome, com prazo e observação. Adiamento por sete dias exige motivo. Estado e auditoria são gravados juntos, sob controle de concorrência.
- Histórico das tratativas inclui resolução e reabertura automáticas, vinculadas à rodada. Critérios, evidências, próxima ação, volume e duração ficam visíveis. Totais de alta prioridade não incluem resolvidas.
- O dashboard sinaliza classificação indisponível e totais parciais, também em PDF/Excel. Falha recente da sincronização aparece como motivo de atenção.

## Regras dos indicadores

| Indicador | Critério e evidência | Dado insuficiente |
|---|---|---|
| Chamado elegível sem WP | Chamado diferente de SOLVED/CLOSED, classificação configurada permite User Story/Épico/Bug, nenhum vínculo local | Falha ao consultar classificação interrompe a rodada; não equivale a ausência de problema |
| WP em etapa inicial acima do prazo | Nome está na configuração existente; sequência de observações bem-sucedidas na mesma etapa; dias inteiros entre primeira observação e última sincronização >= limite existente | Sem sequência compatível com snapshot não há duração nem valor artificial de 9.999 dias |
| Sincronização sem frescor | Ausente, data inválida/futura ou idade >= limite configurado (dashboard/alerta: sete dias) | Não consultado e inválido têm rótulos próprios |
| Qualidade da WP | Status ausente, duração inicial desconhecida ou última tentativa falha | Falha antiga sem categoria é “sincronização não concluída”; não se infere 403 por texto |
| Etapas do dashboard | Grupos já existentes em ActionDashboard, contagem única por chamado, todos os vínculos mantidos | Sem histórico continua explícito; responsável sem nome não comprova ausência no OpenProject |
| Entrega | Apenas nome Entregue, ignorando caixa/espaços externos; todas/parcial/nenhuma | Não confundir Desenvolvido, build, PR ou isClosed com entrega; desconhecida impede “todas” |
| Classificação gerencial | Regras existentes; falhas identificadas separadamente em chamados acessíveis do escopo inicial | Totais por classificação são parciais; aviso não representa falha de toda a API |

Troca de status dentro da mesma etapa mantém o início observado; saída/retorno e novo vínculo reiniciam. A última sincronização é o limite superior, nunca “agora” para tempo de etapa. Não é a data exata da mudança no OpenProject e não revela transições não observadas entre consultas. Comentários e builds não reiniciam a etapa.

A central verifica frescor de todos os vínculos locais, inclusive snapshots em Entregue ou nomes que pareçam encerrados. Não considera fragmentos como “fech”/“conclu” prova de encerramento. O filtro remoto de WPs abertas continua usando a API do OpenProject; as fases públicas e regras de acompanhamento não mudam.

## Acompanhamento e acesso

Em **Gerência > Pendências de Integração**, abra **Tratar / histórico**:

1. Marque **Assumir o acompanhamento em meu nome**, se for o responsável.
2. Informe prazo entre hoje e os próximos 365 dias e observação de até 2.000 caracteres.
3. Salve. Para adiar por sete dias, informe motivo de 10–2.000 caracteres; essa ação não corrige a causa.
4. Consulte o histórico. Reabertura é explícita ou acontece quando a regra reaparece/adiamento expira em uma nova verificação.
5. Sincronize os dados pela Evolução da Demanda e execute nova verificação. Só uma rodada completa pode resolver ocorrências ausentes em chamados avaliados e ainda acessíveis.

Os direitos VIEW_TECHNICAL + VIEW_OPERATIONAL_HEALTH continuam obrigatórios. MANAGE_OPERATIONAL_HEALTH permite executar/tratar e agora assumir prazo/acompanhamento; a migração não concede direitos. Leitura nativa e entidade são verificadas também em detalhe, histórico e POST. Token, usuário-alvo ou ocultação de botão não substituem autorização. O responsável é sempre o operador que escolheu assumir, nunca um ID arbitrário enviado pelo navegador.

## Preservação e atualização

Migração aditiva/idempotente:

- `operational_findings`: owner_users_id, due_date, treatment_note, inicialmente NULL;
- `operational_runs`: metrics_json, inicialmente NULL.

Não apaga/recria vínculos, históricos, credenciais, permissões ou preferências. Não modifica ponto, jornadas, saldos, fases públicas ou visibilidade para clientes. O diagnóstico não consulta nem altera o OpenProject. Novos eventos de erro de sincronização acrescentam somente categoria, sem payloads ou credenciais.

Backup: banco, documentos e configuração, incluindo `glpicrypt.key`. Atualizar sem desinstalar. Para restauração, utilizar backup consistente de código/banco/configuração; não remover colunas/históricos manualmente. Backups locais estão em `dist/`, ignorado pelo Git, e não integram a release.

## Validação

- `tests/indicators.php`: dados sintéticos transacionais; histórico versus idade do vínculo, datas, rodadas completas/parciais, qualidade, tratativas, autoria, validações e falhas de gravação com rollback.
- `tests/action-dashboard-unit.php`: sequência, retorno à etapa, desconhecidos, filtros, múltiplas WPs, escopo, entrega, preferências e escaping.
- `tests/indicators-http.cjs`: formulários reais/CSRF, persistência, auditoria sem execução de HTML, administrador/equipe/consulta/cliente/outra entidade, 390 e 1366 px em temas claro/escuro.
- `tests/security-stage1.cjs`: regressão de permissões/escopo e resolução de pendências. O adiamento agora envia o motivo obrigatório.
- `tests/operations.py`: regressão de concorrência, timeout, 401/403/422, recuperação de horas, acompanhamentos e webhook assinado repetível.
- `tests/security-installation.php`: instalação limpa e duas atualizações repetidas com dados/direitos preservados.
- Sintaxe PHP, JavaScript e diff sem segredos/erros de whitespace.

Os testes mutáveis usam exclusivamente o container descartável. Na homologação compartilhada, apenas atualização/ativação e conferência de integridade: a execução de novas verificações fica para a validação humana.

Resultado local: instalação limpa e duas atualizações repetidas aprovadas; suites de regras, HTTP/perfis e operações aprovadas. Homologação compartilhada atualizada e ativada em 0.35.0. Comparação semântica dos 22 grupos de dados/configuração/direitos antes e depois idêntica, incluindo credenciais decifradas somente em memória; colunas novas desconsideradas nessa comparação. Capturas sintéticas de celular/desktop e temas claro/escuro em `dist/`, sem dados reais de clientes.

## Limitações

- Históricos legados insuficientes podem tornar rodadas parciais. Não são fabricados nem corrigidos retroativamente. Uma única WP incompleta impede resoluções automáticas daquela rodada; sincronize e revise as evidências antes de repetir.
- Verificação é manual/local; não há worker, envio de notificações, consultas remotas automáticas ou fechamento de chamados.
- História exibida: até 100 ações recentes por ocorrência; histórico anterior permanece no banco. Últimas cinco execuções exibidas pertencem ao próprio operador.
- Uma permissão negada na última tentativa pode depender do token daquele operador; não prova indisponibilidade para todos os usuários. A descrição indica a última tentativa, não o estado remoto atual.
- A observação usa o histórico existente e lotes de 300 chamados, mantendo estado por vínculo. Em bases grandes a leitura e autorização de muitos chamados ainda pode demorar; a duração passa a ser mensurável. Não há cache compartilhado entre usuários.
- A etapa 5 (pipeline contínuo/análise estática/dependências) permanece fora desta entrega.
