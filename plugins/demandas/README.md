# Gestão de Demandas

## Versão 0.19.4

- torna os cards de status do monitoramento de Work Packages filtros de drill-down;
- inclui filtros por status da WP, chamado GLPI, cliente do OpenProject e responsável;
- interpreta corretamente o campo `Cliente` quando a API o devolve como uma lista de valores;
- reutiliza a descoberta do schema por projeto/tipo, evitando chamadas repetidas durante a consulta paginada.

Consulte `docs/RELEASE_0.19.4.md` para validação.

## Versão 0.19.3

- corrige a paginação do OpenProject para avançar de página em página (`offset=1`, `2`, `3`…), em vez de saltar para o número de itens recebidos;
- mantém blocos de 25 WPs e passa a consolidar todas as páginas até o total devolvido pela API.

Consulte `docs/RELEASE_0.19.3.md` para validação.

## Versão 0.19.2

- pagina a leitura de Work Packages abertas em blocos de 25 itens;
- usa o total devolvido pela API quando disponível para não executar uma página vazia adicional;
- evita timeout de respostas muito grandes sem reduzir o conjunto de WPs consolidado.

Consulte `docs/RELEASE_0.19.2.md` para validação.

## Versão 0.19.1

- exibe indicador de consulta na página **Minhas Work Packages**, com barra de progresso animada sem porcentagem fictícia enquanto o OpenProject processa e devolve o total;
- mantém o botão de consulta bloqueado durante a requisição para impedir disparos repetidos;
- posiciona o sino de alertas horizontalmente ao lado direito do campo de busca do cabeçalho do GLPI 11.

Consulte `docs/RELEASE_0.19.1.md` para validação.

## Versão 0.19.0

- adiciona **Monitoramento de Work Packages** no menu Gerência para qualquer usuário autenticado com token pessoal configurado;
- consulta no OpenProject somente **User Story**, **Épico/Epic** e **Bug** abertos cujo usuário do token é o **Responsável**;
- exibe cards por status, lista com identificador, título, criação, atualização, responsável, cliente e chamados vinculados; links de WP e chamado abrem em nova aba;
- identifica vínculos pela tabela do plugin, pelo campo do plugin Fields rotulado **Atividade DevOps** e por URLs de Work Package em acompanhamentos de chamados;
- grava o último resultado de cada consulta e disponibiliza o consolidado gerencial filtrável por responsável para perfis com **Acessar a visão gerencial**;
- cria alertas deduplicados para WPs abertas, com prioridade para status Novo e Em especificação, e os exibe no sino do cabeçalho e na página **Alertas de Work Packages**;
- cria as tabelas de monitoramento e alertas de forma idempotente. Atualize sem desinstalar e execute **Atualizar** em **Configuração > Plugins**.

Consulte `docs/RELEASE_0.19.0.md` para roteiro de validação e limitações.

## Versão 0.18.5

- disponibiliza **Salvar todas as configurações administrativas** e **Salvar e testar conexão** no início de Integração e automação, Classificação e Templates;
- troca as abas na própria página, preservando o preenchimento e a aba selecionada após salvar;
- corrige o fechamento do formulário compartilhado; as três abas administrativas são salvas juntas e o token pessoal continua separado;
- abre a aba de um campo obrigatório inválido e avisa antes de sair/recarregar com campos alterados;
- não armazena rascunhos ou tokens no armazenamento local do navegador e mantém autorização e CSRF no servidor.

Atualize substituindo `plugins/demandas` e usando **Atualizar** em **Configuração > Plugins**, sem desinstalar. Consulte `docs/RELEASE_0.18.5.md` para os testes e o roteiro de conferência.

## Versão 0.18.4

- configurações globais, teste da automação e as abas Integração e automação, Classificação, Templates e Tutorial usam `MANAGE_CONFIG` do perfil ativo; o nome do perfil não concede nem restringe direitos;
- sem essa permissão, somente o token pessoal fica disponível; URL administrativa e POST global retornam acesso negado;
- feriados usam `MANAGE_HOLIDAYS` e exceções individuais usam `MANAGE_TIME_ACCESS`, mantendo a política própria de horas; nenhuma dessas permissões concede configuração global;
- a gestão dos perfis continua exigindo o direito nativo do GLPI de alterar perfis;
- corrige a gravação de novas exceções de horas e restringe no servidor os direitos que podem receber essas exceções;
- preserva configurações, tokens, permissões e históricos; não cria migração de banco nem exige renomear perfis.

### Atualização da 0.18.4

Faça backup, substitua os arquivos de `plugins/demandas` pela pasta `demandas/` do ZIP e execute **Atualizar** em **Configuração > Plugins**. **Não desinstale**: isso remove dados. Em **Administração > Perfis > Gestão de Demandas**, confira **Administrar as configurações do plugin** e selecione o perfil autorizado na sessão. O GLPI recarrega os direitos após alterações feitas por sua interface nativa; o plugin consulta o perfil ativo em cada requisição. Tokens pessoais não autorizam configurações globais.

Testes automatizados, roteiro manual e limitações: `docs/RELEASE_0.18.4.md` no repositório. As referências a Super-Admin nas versões abaixo descrevem o comportamento histórico, substituído na 0.18.4.

## Versão 0.18.3

- elimina os avisos de uso de `DATETIME` na instalação, utilizando `TIMESTAMP` nos campos de data e hora;
- converte as colunas legadas durante a atualização, sem recriar tabelas, vínculos, tokens ou históricos;
- verifica previamente datas não representáveis e interrompe a atualização em vez de descartá-las;
- usa a conexão principal do GLPI no instalador e mantém a migração repetível;
- preserva campos `DATE` e `TIME` e as regras de ponto e banco de horas.
- corrige uma aspa ausente na descrição de faltas justificadas da auditoria, que impedia carregar o serviço de ponto na 0.18.2.

### Atualização da 0.18.3

Faça backup do banco e dos arquivos do GLPI. Substitua a pasta `plugins/demandas`
pelo conteúdo `demandas/` do pacote e execute **Atualizar** em **Configuração >
Plugins**; depois ative o plugin, se necessário. Não desinstale para atualizar:
a desinstalação apaga os dados do plugin. Preserve o fuso horário configurado no
GLPI durante a migração e confira os horários das marcações após atualizar.
Se houver datas inválidas ou fora do intervalo de `TIMESTAMP` do servidor, a
migração informa a coluna e para para revisão administrativa, sem corrigi-las
automaticamente. Consulte `docs/RELEASE_0.18.3.md` no repositório para os testes.

Os erros `plugin_propostas_index_exists` e `plugin_version_propostas` pertencem
ao plugin **propostas**, que não integra este repositório nem este pacote.
Esta release corrige o aviso referente ao plugin **demandas**.

## Versão 0.18.2

- apresenta faltas justificadas no extrato de auditoria apenas para conferência, sem crédito ou débito no banco de horas.

## Versão 0.18.1

- exibe no cabeçalho de **Horas e Ponto** os acessos diretos à auditoria do banco, às ausências e, para Super-Admin, à administração do ponto.

## Versão 0.18.0

- adiciona o extrato de auditoria do banco de horas, com saldo histórico, créditos, débitos e saldo acumulado por lançamento;
- adiciona a página **Minhas ausências**, com filtros para justificadas e não justificadas e download autorizado dos comprovantes;
- restringe o cadastro, a edição e a exclusão de feriados e dias não úteis ao perfil ativo **Super-Admin**;
- torna feriados, dias não úteis e suas compensações neutros no banco de horas, sem crédito ou débito mesmo se houver registros no dia;
- atualiza o tutorial administrativo com a configuração de dias sem expediente.

## Versão 0.17.9

- preenche novas marcações com a sequência **Entrada Manhã**, **Saída Manhã**, **Entrada Tarde** e **Saída Tarde**;
- preserva o tipo de marcações já registradas.

## Versão 0.17.8

- recolhe as marcações de ponto ao registrar ou editar uma ausência e expande automaticamente a ausência já existente;
- destaca visualmente o dia atual no calendário de ponto;
- permite informar saldo histórico positivo ou negativo e a data a partir da qual ele será acumulado com os novos registros.

## Versão 0.17.7

- organiza o calendário de ponto no padrão brasileiro, de domingo a sábado;
- apresenta o intervalo de almoço registrado entre **Saída Manhã** e **Entrada Tarde**, com ícone e duração;
- mantém o cálculo de jornada e do banco de horas inalterado.

## Versão 0.17.6

- adiciona a aba pessoal **OpenProject** em **Minhas configurações** para todos os usuários autenticados;
- permite salvar e testar o token pessoal sem conceder acesso às configurações globais do plugin;
- direciona os avisos de criação e sincronização manual para a aba pessoal correta.

## Versão 0.17.5

- corrige a rolagem excessiva das abas de configuração ao ocultar explicitamente os painéis inativos;
- amplia a coluna e a altura inicial de **Mensagem padrão** no De/Para de status;
- adiciona a aba **Tutorial**, exclusiva ao perfil ativo **Super-Admin**, com o roteiro de configuração, webhook, tokens, classificação, templates e checklist de validação;
- estabelece que novidades que alterem o processo de configuração devem atualizar o tutorial integrado.

## Versão 0.17.4

- separa o token automático do usuário técnico, usado apenas por webhook e outras sincronizações automáticas;
- bloqueia programaticamente a criação de Work Packages quando o cliente foi aberto com o token automático;
- adiciona token pessoal por usuário para criação, sincronização manual e entradas de tempo;
- restringe as configurações globais e o token automático ao perfil ativo **Super-Admin**;
- reorganiza a página de configuração em abas de acesso pessoal, integração e automação, classificação e templates.

## Versão 0.17.3

- O formulário de nova entrada de tempo é aberto em modal.
- Entradas em andamento podem receber o horário final diretamente na grid ou no modal de edição.
- O número da Work Package na grid abre a WP correspondente no OpenProject.

- direciona o ID sincronizado para o relatório de custos filtrado pela Work Package;
- armazena o identificador textual do projeto nos novos vínculos;
- resolve e grava automaticamente o identificador dos vínculos existentes.

## Versão 0.17.1

- habilita o módulo **Tempo e custos** (`costs`) na automação do projeto de homologação;
- mantém as permissões de visualização e lançamento de horas no papel da integração;
- informa claramente quando o projeto não possui atividades de tempo disponíveis ou quando o OpenProject rejeita a consulta.

## Versão 0.17.0

- separa **Entradas de Tempo** e **Horas e Ponto** em menus próprios;
- permite iniciar um apontamento sem informar o término e finalizá-lo posteriormente;
- carrega obrigatoriamente as atividades disponíveis no OpenProject ao selecionar a Work Package;
- mantém as entradas no GLPI com sincronização manual individual ou em lote;
- exibe o estado de sincronização e o ID vinculado à entrada no OpenProject;
- atualiza ou exclui imediatamente no OpenProject uma entrada já sincronizada;
- inclui automaticamente o prefixo `- ` no comentário opcional enviado ao OpenProject;
- lista os apontamentos em uma grade filtrável por intervalo de datas.

## Versão 0.16.8

- permite registrar faltas justificadas e não justificadas pelo calendário;
- utiliza automaticamente a jornada individual nas faltas de dia inteiro;
- exibe início e fim somente quando a falta for de período parcial;
- aceita múltiplos comprovantes em PDF, imagem, Word, Excel e OpenDocument, com limite de 15 MB por arquivo;
- mantém faltas justificadas neutras e debita faltas não justificadas do banco de horas;
- diferencia visualmente os dois tipos de falta no calendário.

## Versão 0.16.7

- corrige o erro HTTP 500 ao abrir **Gerência > Horas e Ponto**;
- impede que expressões JavaScript do calendário sejam interpretadas pelo PHP;
- preserva todos os recursos e dados adicionados na versão 0.16.6.

## Versão 0.16.6

- reformula o portal mensal de ponto com quatro indicadores e calendário compacto;
- permite editar, incluir e excluir múltiplas marcações do mesmo dia;
- registra tipo da marcação, NSR e observação individual;
- permite falta não justificada integral ou parcial no modal do dia;
- sinaliza ausências, feriados, registros completos e incompletos no calendário;
- preserva como neutros os dias sem marcação ou ausência registrada.

## Versão 0.16.5

- inclui `view_time_entries` e `log_own_time` nos papéis idempotentes de homologação;
- valida as permissões efetivas do usuário técnico após o bootstrap;
- apresenta orientação amigável quando o OpenProject responder com falta de permissão para entradas de tempo.

## Versão 0.16.4

- corrige o erro 404 na descoberta das atividades de entrada de tempo;
- remove a tentativa de consultar uma coleção global inexistente no OpenProject;
- obtém as atividades permitidas pelo schema oficial do formulário de entrada de tempo;
- consolida, sem duplicidade, as atividades disponíveis nas WPs acessíveis ao usuário.

## Versão 0.16.3

- torna neutros os dias sem marcações de ponto ou ausência registrada;
- mantém débito apenas quando há marcações insuficientes ou ausência não justificada;
- mantém ausências justificadas neutras no banco de horas;
- separa explicitamente o controle de ponto do tempo efetivamente gasto em Work Packages;
- envia ao OpenProject somente os lançamentos feitos na tela de horas por WP;
- adiciona início, fim, duração calculada, projeto, chamado, WP, tipo, atividade e situação do envio;
- adiciona acesso direto ao lançamento de tempo em cada WP vinculada ao chamado.

## Versão 0.16.2

- corrige o erro HTTP 500 apresentado depois da primeira gravação de ponto;
- normaliza os índices dos registros antes de formar os pares de entrada e saída;
- preserva integralmente as marcações que já foram salvas pela versão anterior;
- reduz o cálculo do saldo consolidado de centenas de consultas diárias para um conjunto fixo de consultas por período;
- mantém a ordenação cronológica das marcações antes do cálculo da jornada.

## Versão 0.16.1

- corrige o erro HTTP 500 ao abrir **Gerência > Horas e Ponto**, adequando os filtros de intervalo ao construtor de consultas do GLPI;
- remove a dependência da extensão PHP `intl` na apresentação do calendário;
- adiciona a aba **Horas e Ponto** ao cadastro de cada usuário;
- permite configurar jornada, localização e vínculo do usuário com o OpenProject;
- permite definir individualmente **Herdar do perfil**, **Permitir** ou **Negar** para cada permissão do módulo;
- garante que a decisão individual prevaleça sobre a permissão herdada do perfil.

## Versão 0.16.0

- adiciona o portal **Horas e Ponto** ao menu de Gerência;
- registra entradas de tempo diretamente nas Work Packages vinculadas aos chamados;
- consulta atividades de tempo do OpenProject e associa o lançamento ao usuário correspondente;
- mantém histórico local de sucessos e falhas no envio de horas;
- oferece calendário mensal de ponto com uma ou várias marcações por dia;
- calcula automaticamente períodos trabalhados, jornada esperada e saldo consolidado do banco de horas;
- permite que cada usuário configure entrada, saída, intervalo, tolerância e localização de sua jornada;
- trata marcações intermediárias e sinaliza dias com marcação sem par;
- registra ausências justificadas ou não justificadas, inclusive com anexos de até 15 MB;
- torna ausências justificadas neutras no banco e mantém o débito das não justificadas;
- administra feriados internacionais, nacionais, estaduais e municipais;
- permite marcar feriado trabalhado e transferir a folga para uma data de compensação;
- adiciona permissões por perfil e exceções individuais para visão, lançamento e administração;
- audita alterações de jornada, ponto, ausências e entradas de tempo.

## Versão 0.15.0

- reformula a Visão Gerencial com apresentação executiva inspirada em painéis analíticos;
- destaca KPIs de cobertura, documentação pendente, envelhecimento e sincronização;
- apresenta cobertura por Work Package em gráfico circular;
- apresenta classificações, clientes, idades, status, fases, projetos e tipos em gráficos proporcionais;
- preserva filtros, drill-down, exportações e tabela detalhada;
- oferece layout responsivo e compatível com os temas claro e escuro do GLPI.

## Versão 0.14.0

- templates Markdown configuráveis individualmente para cada tipo de Work Package disponibilizado pelo OpenProject;
- template inicial de User Story baseado no padrão corporativo informado;
- variáveis estruturadas do GLPI para ID, URL, título, abertura, cliente, sistema, classificação, requerente, contato, e-mail e motivo de WP adicional;
- bloqueio explícito das variáveis de descrição/conteúdo para impedir o envio do relato bruto do cliente à documentação da WP;
- criação bloqueada com mensagem objetiva quando o tipo escolhido ainda não possui template configurado.

## Versão 0.13.0

- adiciona total de melhorias e bugs ainda sem Work Package no OpenProject;
- adiciona totais não solucionados para bugs, melhorias e coletores;
- adiciona total de sugestões de melhoria;
- apresenta os cinco clientes com mais melhorias ou bugs abertos;
- permite abrir os chamados correspondentes diretamente pelos indicadores;
- inclui cliente e classificação na grade e nas exportações PDF e Excel;
- mantém contagem única por chamado mesmo quando há múltiplas WPs vinculadas.

## Versão 0.12.1

- substitui o formulário adicional por um botão junto da sincronização manual;
- abre a criação de outra Work Package em um modal;
- exige e valida o motivo da nova WP quando o chamado já possui vínculos;
- registra a justificativa na descrição da WP e nos detalhes da integração;
- identifica o número da Work Package em cada evento do histórico.

## Versão 0.12.0

- permite criar múltiplas Work Packages (User Stories e/ou Bugs) a partir do mesmo chamado;
- mantém vínculo, webhook, cache e histórico independentes por Work Package;
- exibe número, status, atribuído, responsável, abertura, projeto, prioridade e cliente antes do histórico da integração;
- sincroniza manualmente todas as Work Packages vinculadas ao chamado;

- reconhece automaticamente os campos corporativos **ServiceDesk** e **Link ServiceDesk**;
- mantém compatibilidade com **Ticket GLPI**, **URL do Ticket GLPI** e **URL do Ticket**;
- envia o número do chamado como inteiro quando o campo correspondente utiliza esse formato;
- preenche automaticamente o número e o link do chamado ao criar a Work Package.

## Versão 0.10.1

- preenche os modais de eventos antigos usando os dados atuais armazenados no vínculo da Work Package;
- consulta o OpenProject e atualiza o cache automaticamente quando o vínculo ainda não possui detalhes;
- preserva os snapshots próprios dos eventos novos, mantendo a informação correspondente ao momento da sincronização.

## Versão 0.10.0

- carrega dinamicamente, conforme projeto e tipo, os valores permitidos para **Atribuído para**, **Encarregado**, **Prioridade** e o campo customizado **Cliente**;
- descobre o campo **Cliente** pelo schema do formulário da API, sem exigir acesso administrativo aos campos customizados do OpenProject;
- valida novamente no servidor todos os valores selecionados antes da criação da Work Package;
- exibe o vínculo direto da Work Package no painel lateral do chamado, junto à **Fase Pública**;
- registra nas sincronizações os dados úteis da WP para consulta no histórico da integração;
- simplifica o histórico removendo a coluna de evento, substitui o resultado por um indicador visual com tooltip e apresenta os detalhes em modal.

## Versão 0.9.1

- corrige a identificação do responsável usando o nome gravado pelo histórico nativo do GLPI;
- remove do evento consolidado linhas técnicas redundantes geradas pela mesma alteração;
- resume as referências de auditoria em uma contagem, preservando os IDs nativos em uma dica de contexto.

## Versão 0.9.0

- adiciona o **Log do Chamado**, ativado opcionalmente nas configurações do plugin;
- adiciona permissão específica por perfil para visualizar a nova aba;
- apresenta datas em `dd/mm/aaaa hh:mm:ss` e eventos em ordem cronológica crescente;
- consolida alterações realizadas no mesmo segundo em um único evento legível;
- traduz alterações para o formato campo, valor anterior e valor novo;
- oculta atualizações técnicas redundantes quando o mesmo evento contém mudanças relevantes;
- oferece paginação com 25 registros por padrão e opções de 50, 100 e 200 registros;
- mantém íntegro e acessível o histórico nativo do GLPI para auditoria.

## Versão 0.8.1

- detecta automaticamente listas suspensas do plugin **Fields** aplicadas a chamados;
- carrega as classificações cadastradas no Fields sem exigir consultas diretas ao banco;
- permite relacionar cada classificação aos tipos de Work Package por caixas de seleção;
- preserva a configuração técnica manual como modo avançado de contingência;
- valida a tabela e as colunas escolhidas antes de salvar a configuração.

## Versão 0.8.0

- permite configurar os nomes exibidos para **Fase Pública**, **Evolução da Demanda** e **Visão Gerencial de Demandas**;
- inclui um ícone próprio no menu e no título da visão gerencial;
- permite obter a classificação de um campo do chamado ou de uma tabela customizada relacionada;
- configura os tipos de Work Package permitidos para cada valor de classificação pelo nome do tipo, sem IDs fixos do OpenProject;
- filtra a seleção de tipos no ticket e repete a validação no servidor antes da criação;
- bloqueia, por segurança, classificações configuradas que não possuam regra correspondente.

## Versão 0.7.0

- adiciona a **Visão Gerencial de Demandas** ao menu **Gerência** do GLPI;
- aplica escopo pelas entidades ativas e permissões próprias de visualização e exportação;
- apresenta KPIs de volume, vínculo com Work Package, idade e sincronização;
- apresenta distribuições por status do GLPI, status da WP, fase pública, projeto e tipo;
- permite drill-down dos cartões e indicadores até os chamados correspondentes;
- oferece filtros por período, idade, vínculo, status, fase pública, projeto, tipo e texto;
- exporta o resumo e a relação filtrada em PDF e Excel.

Plugin MVP para rastrear a evolução de solicitações entre tickets do GLPI 11 e Work Packages do OpenProject 17.

## Versão 0.6.6

- insere a **Fase Pública** somente no painel lateral do chamado, imediatamente antes de **Origem da requisição**;
- impede que o campo seja inserido no formulário de acompanhamento;
- concede, sem remover outros direitos, a permissão nativa mínima do GLPI para perfis autorizados visualizarem acompanhamentos públicos;
- repara automaticamente perfis configurados em versões anteriores durante a atualização.

## Versão 0.6.5

- corrige a localização dos campos-alvo no painel lateral do GLPI 11;
- insere a Fase Pública imediatamente antes de Origem da requisição usando a estrutura real do painel lateral;
- confirma no banco a visibilidade pública ou privada configurada para cada acompanhamento automático.

- insere a Fase Pública como campo bloqueado no painel principal do chamado;
- posiciona o campo exatamente entre Status e Origem da requisição usando os identificadores estáveis do formulário do GLPI 11.

## Versão 0.6.3

- converte explicitamente a máscara retornada por `Session::haveRight()` para booleano;
- torna idempotente o registro de direitos durante instalações e atualizações, evitando violações de unicidade em `glpi_profilerights`.

## Versão 0.6.2

- corrige o erro HTTP 500 ao abrir um chamado no GLPI 11, aceitando as duas assinaturas utilizadas pelo hook da seção ITIL;
- publica o JavaScript no diretório público esperado pelo roteamento de recursos dos plugins.

## Versão 0.6.1

- corrige o conflito de assinatura com `CommonDBTM::check()` que impedia a atualização para a matriz de permissões;
- mantém a migração idempotente, sem alterar vínculos, configurações ou histórico existentes.

## Versão 0.6.0

- adiciona uma matriz de permissões própria do plugin em **Administração > Perfis > Gestão de Demandas**;
- separa os direitos de visão pública, dados técnicos, histórico, criação de WP, sincronização e configuração;
- valida cada direito tanto na interface quanto nos endpoints do servidor;
- aplica ao perfil Cliente somente a visualização pública e ao perfil Requisitos os direitos operacionais;
- impede que permissões genéricas de alteração do chamado concedam acesso às ações do OpenProject.

## Versão 0.5.3

- identifica a audiência pela permissão efetiva no chamado, inclusive quando o perfil Cliente usa a interface central;
- limita a visão do cliente à fase e à atualização públicas, sem expor WP, status técnico, sincronização ou histórico;
- insere a fase pública clonando a linha completa de **Origem da requisição**, conforme a estrutura do formulário lateral do GLPI 11;
- preserva a exibição técnica completa para administradores e perfis internos com permissão de atualização.

## Versão 0.5.2

- usa o hook nativo `post_itil_info_section` do GLPI 11 como fonte da fase pública;
- exibe a fase pública tanto na interface central quanto na simplificada;
- localiza **Origem da requisição** por campo técnico ou pelo rótulo traduzido;
- mantém o endpoint autenticado como fallback para páginas renderizadas por AJAX.

## Versão 0.5.1

- corrige a criação de acompanhamentos automáticos em webhooks stateless;
- seleciona um usuário interno válido como autor do acompanhamento;
- executa a regra do status inicial imediatamente após criar a Work Package;
- carrega o campo **Fase pública** na visão simplificada por endpoint autenticado;
- insere o campo antes de **Origem da requisição** mesmo com a renderização AJAX do GLPI 11.

## Versão 0.5.0

- remove a configuração manual de IDs de projetos, tipos e campos personalizados;
- lista dinamicamente os projetos acessíveis ao usuário técnico;
- carrega os tipos de Work Package disponíveis no projeto selecionado;
- identifica automaticamente os campos corporativos `ServiceDesk` e `Link ServiceDesk`, preservando os nomes legados `Ticket GLPI`, `URL do Ticket GLPI` e `URL do Ticket`;
- permite ao administrador cadastrar as fases públicas;
- configura por status a mensagem padrão, o envio automático e a privacidade do acompanhamento;
- disponibiliza variáveis de ticket, requerente, status, fase, projeto e Work Package;
- apresenta a fase pública como campo bloqueado no formulário padrão do chamado para o cliente;
- adiciona ícone à aba **Evolução da Demanda**.

Variáveis disponíveis nas mensagens:

- `{{ticket.id}}`, `{{ticket.name}}` e `{{requester.name}}`;
- `{{status.previous}}`, `{{status.current}}`;
- `{{phase.previous}}`, `{{phase.current}}`;
- `{{work_package.id}}` e `{{project.name}}`.

## Permissões por perfil

Depois de instalar ou atualizar o plugin, acesse **Administração > Perfis**, abra o perfil desejado e selecione a aba **Gestão de Demandas**. Os direitos disponíveis são:

- visualizar a evolução pública da demanda;
- visualizar dados técnicos do OpenProject;
- visualizar o histórico da integração;
- criar e vincular Work Packages;
- executar sincronização manual;
- administrar as configurações do plugin.
- acessar a visão gerencial;
- exportar a visão gerencial em PDF e Excel.

## Versão 0.4.1

- adiciona os botões **Mostrar/Ocultar** e **Copiar** ao segredo do webhook;
- mantém o segredo mascarado por padrão;
- informa visualmente quando o segredo foi copiado.

## Versão 0.4.0

- endpoint stateless para webhooks do OpenProject;
- validação da assinatura `X-Op-Signature` com comparação resistente a temporização;
- sincronização automática por ID da Work Package;
- nova origem `webhook` no histórico da integração;
- teste de saúde do endpoint por GET;
- segredo compartilhado gerado automaticamente.

## Configuração do webhook

No OpenProject, cadastre um webhook ativo com:

- URL: `http://glpi/plugins/demandas/webhook.php`;
- segredo: o valor exibido na configuração do plugin;
- evento: Work package atualizada;
- projeto: Homologação — Gestão de Demandas.

O endereço de saúde acessível no navegador é `http://localhost:8180/plugins/demandas/webhook.php`.

## Versão 0.3.0

- carrega os status reais pela API do OpenProject;
- matriz administrativa de status técnico para fase pública;
- opção de manter a fase pública anterior;
- sincronização baseada primeiro no mapeamento configurado;
- regras padrão usadas como sugestão para status ainda não configurados.

## Versão 0.2.0

- sincronização manual da Work Package;
- tradução do status técnico para fase pública;
- histórico interno de sincronizações e falhas;
- registro da origem e do resultado de cada sincronização;
- preparação da camada de serviços usada futuramente pelo webhook.

## Versão 0.1.3

- utiliza diretamente o hostname interno `openproject` autorizado no Compose;
- remove cabeçalhos de proxy simulados da comunicação entre contêineres.

## Versão 0.1.2

- preserva o caminho `/api/v3` nas requisições ao OpenProject;
- envia o hostname externo configurado ao acessar o serviço pela rede interna do Docker;
- corrige `400 Bad Request: Invalid host_name configuration`.

## Versão 0.1.1

- remove a validação manual duplicada de CSRF dos endpoints POST;
- corrige o bloqueio ao salvar a configuração ou criar uma Work Package no GLPI 11.

## Primeiro incremento

- instalação e desinstalação pelo GLPI;
- configuração da conexão com o OpenProject;
- teste de conectividade;
- aba **Evolução da Demanda** no ticket;
- visão pública resumida e visão técnica interna;
- criação de User Story ou Bug no OpenProject;
- persistência do vínculo ticket ↔ Work Package.

## Instalação

1. mantenha a pasta com o nome `demandas` dentro de `plugins`;
2. acesse **Configuração > Plugins** no GLPI;
3. instale e ative **Gestão de Demandas**;
4. abra a configuração do plugin;
5. informe as URLs e o token registrado em `integration.env`;
6. execute **Salvar e testar conexão**.

Esta versão é destinada exclusivamente à homologação local.
