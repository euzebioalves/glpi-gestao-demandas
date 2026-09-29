# Release 0.22.0 — Central de Pendências de Integração

## Objetivo

Disponibilizar uma visão operacional para identificar e tratar, sem alterar dados externos, situações que podem impedir a evolução normal de demandas entre GLPI e OpenProject.

## Entrega

- nova página **Gerência > Pendências de Integração**;
- verificação manual baseada somente em dados já persistidos no GLPI;
- regras iniciais:
  - chamado elegível sem vínculo de Work Package, quando a classificação configurada permite User Story, Épico ou Bug;
  - Work Package aberta em um dos status iniciais configurados por mais tempo que o prazo definido;
  - Work Package aberta sem sincronização recente;
- filtros por situação, regra e prioridade;
- links em nova aba para o chamado GLPI e, quando houver, para a Work Package no OpenProject;
- ações auditadas para reconhecer, ignorar temporariamente por sete dias ou reabrir uma pendência;
- tabelas idempotentes para execuções, pendências e ações;
- parâmetros administrativos na aba **Integração e automação**:
  - status iniciais separados por vírgula;
  - dias máximos no status inicial;
  - dias máximos sem sincronização.

## Segurança e permissões

Nenhum perfil recebe os novos direitos automaticamente. Em **Administração > Perfis > Gestão de Demandas**, conceda explicitamente:

- **Visualizar pendências operacionais da integração** para consultar a central;
- **Executar e tratar pendências operacionais da integração** para executar a verificação e mudar a situação de um item.

Os dois direitos dependem também de **Visualizar dados técnicos do OpenProject**. A página, o endpoint de execução e cada ação validam esses direitos no servidor. Antes de mostrar ou operar uma pendência, o plugin confirma que o usuário ainda pode ler o chamado correspondente no escopo de entidades ativo.

As regras não transmitem dados nem fazem requisições ao OpenProject. A página mostra somente identificadores, dados técnicos já armazenados e evidências curtas necessárias à conferência. Não são registrados tokens, conteúdo integral do chamado, acompanhamentos ou anexos.

## Atualização

1. Faça backup do banco e dos arquivos do plugin.
2. Substitua a pasta `plugins/demandas` sem desinstalar o plugin.
3. Em **Configuração > Plugins**, execute **Atualizar**.
4. Em **Configuração do plugin > Integração e automação**, revise os status e prazos da central e salve.
5. Em **Administração > Perfis > Gestão de Demandas**, conceda os dois novos direitos apenas aos perfis internos responsáveis.
6. Abra **Gerência > Pendências de Integração** e execute uma verificação com um perfil autorizado.

## Limitações deliberadas desta entrega

- a verificação é manual e local; ela não agenda consultas e não atualiza WPs;
- a regra de chamado sem WP depende da política de classificação já configurada;
- a central não cria WPs, não muda status, não envia acompanhamentos e não chama serviços externos;
- uma Work Package marcada como fechada, concluída, resolvida ou cancelada é excluída das regras locais que dependem de WP aberta.

Essas limitações evitam efeitos silenciosos no ambiente oficial. Integrações automáticas, alertas e ações corretivas devem ser avaliados e entregues em incrementos separados.

## Roteiro de validação

1. Com um perfil sem os novos direitos, confira que o menu não aparece e a URL direta é negada.
2. Com somente o direito de visualização e o direito técnico, confira que a página abre, mas não exibe botões de execução ou tratamento.
3. Com os dois direitos, execute a verificação e confira o total retornado.
4. Valide os filtros e os links para GLPI/OpenProject.
5. Reconheça, ignore e reabra uma pendência; reexecute a verificação e confirme que a situação e o histórico são preservados.
6. Acesse com um perfil de cliente e confirme que nenhum menu, dado técnico ou endpoint da central é acessível.
