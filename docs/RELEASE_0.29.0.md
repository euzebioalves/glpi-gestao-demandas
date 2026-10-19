# Gestão de Demandas 0.29.0 — documentação e relações entre WPs

## Operação

A conciliação considera os vínculos locais já confirmados para exigir justificativa. Referências no Fields, por si sós, não tornam o primeiro vínculo excepcional. Vínculos seguintes do mesmo chamado ou da mesma WP exigem **Resolver conflitos de conciliação de Work Packages** e justificativa de 10 a 2.000 caracteres. A seleção conjunta respeita a ordem da grade e o limite de cinco validações por envio; a justificativa comum cobre os vínculos adicionais, e justificativas individuais têm precedência. O botão fica bloqueado durante o envio. O retorno ao chamado e os vínculos anteriores são preservados.

Ao criar outra WP, escolha opcionalmente a WP anterior, o tipo de relação e confirme. Os rótulos expressam a perspectiva da **nova WP em relação à anterior**. O OpenProject valida permissões, ciclos e tipos suportados. A criação e a relação são operações distintas: se a relação falhar, a WP criada permanece vinculada e a mesma tela permite tentar novamente somente a relação. Não reabra a criação para resolver uma falha de relação. Após expirar a sessão, confira a WP criada e finalize a relação no OpenProject.

Na criação, carregue o template do tipo e revise a documentação. O relato bruto do chamado continua fora do template. Nas WPs vinculadas, **Documentar** consulta a descrição atual e permite editá-la. **Código** abre editor com linguagem e realce de sintaxe; as marcações de linguagem são enviadas em Markdown. Imagens podem ser coladas ou enviadas no editor e redimensionadas. Anexos são enviados ao OpenProject e associados ao salvar. O modo Markdown permite preservar construções específicas do OpenProject que não possuem representação no editor visual.

## Segurança e limites

- Documentação exige cumulativamente **Visualizar dados técnicos do OpenProject**, **Criar e vincular Work Packages**, leitura/alteração nativas do chamado e entidade ativa. O token pessoal precisa das permissões correspondentes no OpenProject. Não há concessão automática de novos direitos nem exposição à visão pública.
- Toda operação mutável usa POST e CSRF nativo. Sessões de editor são vinculadas ao usuário, token, instância e par chamado–WP, com duração de duas horas e limite de 20 sessões simultâneas.
- Até 20 arquivos por edição, 15 MB por arquivo, respeitando também limites menores de PHP/OpenProject. Formatos permitidos: imagens PNG/JPEG/GIF/WebP, PDF, documentos Office/OpenDocument, texto, CSV, JSON, XML e ZIP. SVG/HTML/executáveis não são aceitos.
- Uploads são enviados imediatamente, ainda sem contêiner, e associados ao salvar. Ao abandonar uma edição, a retenção de anexos não associados segue o OpenProject. O plugin não armazena cópias dos arquivos ou da descrição no banco/localStorage e não remove anexos existentes.
- A descrição é limitada a 500 KB. A edição visual usa HTML saneado, Markdown GFM e HTML de tabelas/imagens. Macros e construções específicas devem ser editadas no modo Markdown; não se promete equivalência integral com CKEditor do OpenProject.
- O salvamento compara `lockVersion`: uma alteração concorrente exige recarregar e comparar o texto, sem sobrescrever silenciosamente. Copie o rascunho antes de recarregar.
- Se o resultado da criação ficar desconhecido por falha de comunicação, não há repetição automática. Confira a WP no OpenProject e concilie se ela foi criada.
- Repetições confirmadas na mesma sessão não recriam WP, relação ou evento. A auditoria registra autor, WP e relação, sem copiar texto/anexos.
- Imagens são consultadas por endpoint autenticado, com IDs autorizados pelo editor, limite de tamanho e MIME verificado. Redirecionamentos de arquivos não recebem o token. Instâncias que entregam arquivos exclusivamente por armazenamento externo redirecionado podem não fornecer prévia; confira o anexo no OpenProject.

## Atualização e homologação

Substitua a pasta `demandas`, atualize e ative no GLPI. Não desinstale. Esta versão não altera tabelas; mantenha backups e permissões existentes.

Roteiro: primeiro vínculo sem justificativa, segundo com justificativa, seleção em lote e clique repetido; criação com/sem relação e recuperação de falha parcial; edição visual/Markdown; código literal com HTML/backticks; imagens e dimensões; anexos; alteração concorrente; token inválido, permissão negada e indisponibilidade; perfis administrativo/interno/cliente; temas claro/escuro e telas estreitas.

Validação realizada em GLPI 11.0.9 isolado:

- sintaxe de todos os arquivos PHP do plugin e JavaScript do editor; `git diff --check`;
- instalação limpa em banco separado e duas atualizações consecutivas, com hash das 17 tabelas preservado;
- `tests/documentation.cjs`: editor no navegador, código com linguagem/realce e caracteres literais, ida/volta visual–Markdown, imagem redimensionada, anexos, criação e repetição da relação após falha, edição, CSRF, perfis administrativo/interno/cliente/somente leitura, HTML saneado, 401/403/503, conflito de versão e conciliação com primeiro vínculo sem justificativa;
- `tests/reconciliation-many.cjs`: múltiplos vínculos, retorno à aba, justificativa, histórico e tempo preservados, repetição, perfis, CSRF e webhook em todos os vínculos, sem duplicar acompanhamentos;
- revisão visual do editor em 1366 e 390 pixels e nos temas claro/escuro.

A API do OpenProject foi simulada em `tests/documentation-api.php`, com contratos conferidos no código instalado da versão 17.x e na [documentação de relações](https://www.openproject.org/docs/api/endpoints/relations/). Não houve escrita no OpenProject de produção. Ainda é necessária homologação com as permissões, limites de anexos e customizações da instância real. A pasta compartilhada foi instalada/ativada no GLPI local para essa validação; nenhuma release foi publicada nesta etapa.
