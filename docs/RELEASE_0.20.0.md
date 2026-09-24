# Release 0.20.0 — contexto local para IA externa

## Recurso

Em **Gerência > Monitoramento de Work Packages > Minhas Work Packages**, o botão **Preparar IA** abre uma página para selecionar informações da Work Package e do chamado vinculado. A página permite incluir ou não status, responsável, cliente, links, resumo, descrição, acompanhamentos públicos e anexos específicos.

O plugin não chama APIs de IA, não transmite dados a terceiros e não persiste o prompt nem o texto extraído. Depois da confirmação explícita de autorização, o usuário recebe um campo de texto para revisar e copiar o contexto para a IA que escolher.

Para anexos selecionados, a imagem local do GLPI inclui:

- Poppler para PDF;
- LibreOffice em modo sem interface para DOC, DOCX, XLS, XLSX, ODT e ODS;
- Tesseract com idiomas português e inglês para PNG, JPG, JPEG e WebP;
- leitura direta para TXT, CSV, JSON e XML.

Cada anexo pode ter até 15 MB. O texto de um anexo é limitado a 30 mil caracteres e o contexto completo a 180 mil caracteres. Arquivos sem extração compatível permanecem identificados no resultado, sem que sejam enviados para fora do GLPI.

## Permissão e atualização

A atualização registra a nova permissão **Preparar contexto de chamado e WP para IA externa**. Perfis que já possuíam **Visualizar dados técnicos do OpenProject** recebem esse direito automaticamente; o administrador pode revisá-lo em **Administração > Perfis > Gestão de Demandas**.

Atualize sem desinstalar: substitua os arquivos, execute **Atualizar** em **Configuração > Plugins** e mantenha o plugin ativo. A migração somente cria o direito ausente e pode ser repetida.

Para a homologação fornecida neste repositório, execute `./start.ps1` ou `docker compose up -d --build` para montar a imagem com as ferramentas locais. Não use uma imagem GLPI padrão sem esses pacotes quando for necessário extrair anexos.

## Validação

1. Atualize o plugin e confirme a nova permissão em um perfil técnico; remova-a de outro perfil e confirme que o botão não aparece e o endpoint recusa acesso.
2. Como usuário autorizado, consulte suas WPs e abra **Preparar IA** em uma WP com chamado vinculado.
3. Confirme que a troca de chamado vinculado recarrega a seleção de anexos e acompanhamentos daquele chamado.
4. Gere um contexto sem dados sensíveis e confirme que a cópia funciona.
5. Selecione descrição, acompanhamentos e um anexo de cada formato suportado; confirme que somente itens explicitamente marcados entram no texto.
6. Verifique que acompanhamentos privados, documentos sem leitura e anexos com mais de 15 MB não são disponibilizados.
7. Abra os logs do GLPI e confirme que o prompt e o conteúdo de anexos não são registrados.
