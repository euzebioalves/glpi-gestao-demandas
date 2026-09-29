# Versão 0.21.1 — isolamento de dados técnicos de Work Packages

## Regra de acesso

As capacidades do plugin passam a seguir estritamente os direitos concedidos ao **perfil ativo** em **Administração > Perfis > Gestão de Demandas**.

- **Visualizar a evolução pública da demanda** permite apenas a fase pública e os acompanhamentos públicos do chamado que o usuário já pode ler.
- **Visualizar dados técnicos do OpenProject** é obrigatório para visualizar IDs, links, título, status, responsável, cliente e demais dados de Work Packages.
- O monitoramento pessoal, o sino de alertas, o histórico de alertas, as exportações pessoais e o preparo de contexto para IA exigem a permissão de dados técnicos, também no backend.
- O consolidado continua exigindo, além da permissão de dados técnicos, **Acessar a visão gerencial**; sua exportação exige ainda **Exportar a visão gerencial em PDF e Excel**.

Assim, um perfil de cliente configurado somente com a visão pública não recebe o menu de monitoramento, alertas ou links para WPs e não consegue acessar esses recursos por URL.

## Atualização

1. Faça backup normal do GLPI.
2. Substitua a pasta `plugins/demandas` pelo pacote da versão 0.21.1.
3. Em **Configuração > Plugins**, execute **Atualizar**. Não desinstale o plugin.
4. Em **Administração > Perfis > Gestão de Demandas**, revise cada perfil:
   - deixe somente **Visualizar a evolução pública da demanda** nos perfis de cliente que devem acompanhar a fase pública;
   - conceda **Visualizar dados técnicos do OpenProject** apenas aos perfis internos autorizados a consultar WPs;
   - mantenha os direitos de criar, sincronizar, exportar, administrar ou preparar IA somente onde forem necessários.
5. Oriente usuários que alternam de perfil a selecionar o perfil correto antes de testar o acesso.

Nenhum dado de vínculo, configuração, histórico ou token é alterado pela atualização.

## Validação recomendada

- Com um perfil de cliente apenas com visão pública, abra um chamado permitido: a fase pública e a mensagem pública podem aparecer, mas nenhum ID, link ou dado técnico de WP deve estar presente no HTML nem na resposta de `public-phase.php`.
- Com esse mesmo perfil, acesse diretamente as URLs de monitoramento, alertas, exportação e preparo de IA: todas devem responder com acesso negado.
- Com um perfil técnico, confirme a aba de evolução, os links de WP, o monitoramento pessoal, os alertas e a exportação pessoal.
- Com perfil técnico sem visão gerencial, confirme o bloqueio do consolidado; com perfil técnico e visão gerencial, confirme o acesso; para exportar o consolidado, conceda também o direito de exportação.
- Confirme que um usuário sem leitura do chamado não vê a aba de evolução nem a fase pública por hook ou endpoint.
