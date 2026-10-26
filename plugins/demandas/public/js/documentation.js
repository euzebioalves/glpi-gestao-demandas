(() => {
    'use strict';
    const endpoint = '/plugins/demandas/front/work-package-documentation.php';
    const libraries = new Map();
    function library(name, ready) {
        if (ready()) return Promise.resolve();
        if (!libraries.has(name)) libraries.set(name, new Promise((resolve, reject) => {
            const script = document.createElement('script'); script.src = '/lib/' + name + '.min.js';
            script.onload = resolve; script.onerror = () => reject(new Error('Não foi possível carregar o editor do GLPI.'));
            document.head.append(script);
        }));
        return libraries.get(name);
    }
    const escape = text => String(text).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
    async function initialize(section) {
        if (section.dataset.initialized) return;
        section.dataset.initialized = '1';
        const form = section.closest('form'), status = section.querySelector('.doc-status');
        const workspace = section.querySelector('.doc-workspace');
        const isExisting = Number(section.dataset.wp) > 0;
        const ready = () => isExisting || form.dataset.creationReady === '1';
        const rich = section.querySelector('.doc-rich'), markdown = section.querySelector('.doc-markdown');
        const list = section.querySelector('.doc-attachments'), files = section.querySelector('.doc-files');
        let editor, raw = '', initialHtml = '', mode = 'visual', loaded = false, busy = false, dirty = false, queue = Promise.resolve();
        const colored = new WeakMap();
        async function highlight() {
            if (!editor?.getBody()) return;
            const nodes = [...editor.getBody().querySelectorAll('pre')];
            if (!nodes.length) return;
            try {
                await library('monaco', () => !!window.monaco);
                monaco.editor.setTheme(document.documentElement.dataset.glpiThemeDark === '1' ? 'vs-dark' : 'vs');
                for (const node of nodes) {
                    const code = node.textContent;
                    const language = (node.className + ' ' + (node.querySelector('code')?.className || '')).match(/language-([\w+-]+)/)?.[1] || 'plaintext';
                    if (colored.get(node) === code + language) continue;
                    colored.set(node, code + language);
                    const html = await monaco.editor.colorize(code, language, {tabSize: 1});
                    if (!node.isConnected || node.textContent !== code) continue;
                    const sample = document.createElement('div'); sample.className = 'monaco-colors'; sample.hidden = true; sample.innerHTML = html.replace(/<br\s*\/?\s*>/gi, '\n'); document.body.append(sample);
                    const textNodes = document.createTreeWalker(sample, NodeFilter.SHOW_TEXT);
                    let textNode, offset = 0;
                    while ((textNode = textNodes.nextNode())) {
                        // Monaco uses nonbreaking spaces for layout. Restore the exact source whitespace.
                        textNode.textContent = [...textNode.textContent].map(character => {
                            const original = code[offset]; offset += character.length;
                            return character === '\u00a0' && (original === ' ' || original === '\t') ? original : character;
                        }).join('');
                    }
                    if (sample.textContent === code + '\n' && sample.lastChild?.nodeType === Node.TEXT_NODE) sample.lastChild.textContent = sample.lastChild.textContent.slice(0, -1);
                    sample.querySelectorAll('span').forEach(span => { const style = getComputedStyle(span); span.style.color = style.color; });
                    // Highlighting must never alter literal code or create a change in the undo history.
                    if (sample.textContent === code) editor.undoManager.ignore(() => { node.innerHTML = '<code class="language-' + escape(language) + '">' + sample.innerHTML + '</code>'; });
                    if (loaded && !dirty) initialHtml = editor.getContent();
                    sample.remove();
                }
            } catch (_) { /* Editing and language-tagged Markdown still work if highlighting cannot load. */ }
        }
        const report = (message, error = false) => { status.textContent = message; status.className = 'doc-status alert ' + (error ? 'alert-danger' : 'alert-info'); };
        function api(action, extra = {}) {
            const task = async () => {
                const body = new FormData(form);
                body.set('action', action); body.set('doc_draft', section.dataset.draft); body.set('_glpi_csrf_token', section.dataset.csrf);
                for (const [key, value] of Object.entries(extra)) body.set(key, value);
                const response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', body});
                let data;
                try { data = await response.json(); } catch (_) { throw new Error('A sessão ou a conexão falhou. Copie seu texto antes de recarregar.'); }
                if (data.csrf) section.dataset.csrf = data.csrf;
                if (data.draft) { section.dataset.draft = data.draft; form.elements.doc_draft.value = data.draft; }
                if (!response.ok || data.error) throw new Error(data.error || 'Não foi possível concluir a operação.');
                return data;
            };
            const result = queue.then(task); queue = result.catch(() => {}); return result;
        }
        function attachment(item) {
            if (list.querySelector('[data-id="' + Number(item.id) + '"]')) return;
            const li = document.createElement('li'); li.dataset.id = item.id;
            const link = document.createElement('a'); link.textContent = item.name; link.href = item.url; link.target = '_blank'; link.rel = 'noopener';
            li.append(link); list.append(li);
        }
        async function upload(file) {
            if (!loaded || !ready()) throw new Error('Carregue os dados e a documentação da WP antes de enviar arquivos.');
            if (file.size > 15 * 1024 * 1024) throw new Error('Cada arquivo pode ter até 15 MB.');
            report('Enviando ' + file.name + '…');
            const result = await api('upload', {file}); attachment(result); dirty = true; report('Arquivo enviado. Salve para associá-lo à WP.'); return result;
        }
        async function codeDialog() {
            try {
                await library('monaco', () => !!window.monaco);
                if (!document.querySelector('link[data-doc-monaco]')) {
                    const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = '/lib/monaco.min.css'; css.dataset.docMonaco = '1'; document.head.append(css);
                }
                const node = editor.dom.getParent(editor.selection.getNode(), 'pre');
                const current = node?.textContent || editor.selection.getContent({format: 'text'});
                const selectedLanguage = node?.className.match(/language-([\w+-]+)/)?.[1] || 'plaintext';
                let codeEditor;
                const languages = monaco.languages.getLanguages().map(language => ({text: language.aliases?.[0] || language.id, value: language.id})).sort((a, b) => a.text.localeCompare(b.text));
                const dialog = editor.windowManager.open({
                    title: 'Bloco de código', size: 'large',
                    body: {type: 'panel', items: [
                        {type: 'selectbox', name: 'language', label: 'Linguagem', items: languages},
                        {type: 'htmlpanel', html: '<div id="demandas-code-editor" style="height:300px;max-height:45vh;border:1px solid #aaa" aria-label="Código"></div>'}
                    ]},
                    initialData: {language: selectedLanguage},
                    buttons: [{type: 'cancel', text: 'Cancelar'}, {type: 'submit', text: 'Inserir código', primary: true}],
                    onChange: dialog => { if (codeEditor) monaco.editor.setModelLanguage(codeEditor.getModel(), dialog.getData().language); },
                    onSubmit: dialog => {
                        const html = '<pre class="language-' + escape(dialog.getData().language) + '"><code class="language-' + escape(dialog.getData().language) + '">' + escape(codeEditor.getValue()) + '</code></pre><p></p>';
                        if (node) editor.selection.select(node);
                        editor.insertContent(html); dirty = true; dialog.close();
                    },
                    onClose: () => { const model = codeEditor?.getModel(); codeEditor?.dispose(); model?.dispose(); }
                });
                codeEditor = monaco.editor.create(document.getElementById('demandas-code-editor'), {value: current, language: selectedLanguage, automaticLayout: true, minimap: {enabled: false}, theme: document.documentElement.dataset.glpiThemeDark === '1' ? 'vs-dark' : 'vs', scrollBeyondLastLine: false});
                codeEditor.focus();
            } catch (error) { report(error.message, true); }
        }
        function install(data) {
            raw = data.raw || ''; markdown.value = raw; editor.setContent(data.html || ''); initialHtml = editor.getContent();
            (data.attachments || []).forEach(attachment); loaded = true; dirty = false;
            workspace.hidden = !ready();
        }
        async function loadTemplate() {
            if (!ready() || busy) return;
            if (dirty && !window.confirm('Substituir o texto atual pelo template? Os arquivos enviados serão mantidos.')) return;
            const project = form.elements.project_id.value, type = form.elements.type_id.value;
            try {
                const data = await api('template', {project_id: project, type_id: type});
                if (!ready() || project !== form.elements.project_id.value || type !== form.elements.type_id.value) return;
                install(data); report('Template carregado. Revise e complemente antes de criar a WP.');
            }
            catch (error) { report(error.message, true); }
        }
        form.addEventListener('demandas:creation-reset', () => {
            workspace.hidden = true;
            const template = section.querySelector('.doc-template');
            if (template) template.hidden = true;
            report('Selecione projeto e tipo e aguarde o carregamento dos campos. Seu texto já digitado será preservado.');
        });
        // Prevent native submission even while the editor/library is still loading.
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;
            if (!ready() || !loaded || !editor) { report('Carregue os dados e a documentação ou o template antes de salvar.', true); return; }
            if (form.elements.relation_target?.value && !form.elements.confirm_relation.checked) { report('Confirme a relação entre as WPs ou selecione “Não relacionar”.', true); return; }
            busy = true;
            const buttons = [...form.querySelectorAll('button')]; const states = buttons.map(button => button.disabled);
            buttons.forEach(button => { button.disabled = true; }); form.setAttribute('aria-busy', 'true');
            try {
                await editor.uploadImages(); await queue;
                const unchanged = editor.getContent() === initialHtml;
                const text = mode === 'markdown' ? markdown.value : unchanged ? raw : editor.getContent();
                report('Salvando no OpenProject…');
                const data = await api('save', {documentation: text, format: mode === 'markdown' || unchanged ? 'markdown' : 'html'});
                dirty = false; report('Documentação salva na WP #' + data.wp + '.'); window.location.assign(data.redirect);
            } catch (error) { report(error.message, true); }
            finally { busy = false; buttons.forEach((button, i) => { button.disabled = states[i]; }); form.removeAttribute('aria-busy'); }
        });
        window.addEventListener('beforeunload', event => { if (dirty && section.isConnected) { event.preventDefault(); event.returnValue = ''; } });
        try {
            await api('start', {tickets_id: section.dataset.ticket, wp_id: section.dataset.wp});
            await library('tinymce', () => !!window.tinymce);
            const dark = document.documentElement.dataset.glpiThemeDark === '1';
            [editor] = await tinymce.init({
                target: rich, license_key: 'gpl', base_url: '/lib/tinymce', suffix: '.min',
                skin_url: '/lib/tinymce/skins/ui/' + (dark ? 'oxide-dark' : 'oxide'),
                content_css: '/lib/tinymce/skins/content/' + (dark ? 'dark' : 'default') + '/content.min.css',
                language: 'pt_BR', language_url: '/lib/tinymce-i18n/langs7/pt_BR.js',
                plugins: 'lists link image table code fullscreen', menubar: false, height: 420,
                toolbar: 'undo redo | blocks | bold italic strikethrough | bullist numlist | link image table | documentcode code fullscreen',
                object_resizing: 'img', image_dimensions: true, automatic_uploads: true, paste_data_images: true,
                images_upload_handler: async blob => { const result = await upload(new File([blob.blob()], blob.filename(), {type: blob.blob().type})); return result.preview; },
                convert_urls: false, extended_valid_elements: 'pre[class],code[class],img[src|alt|width|height|style]',
                content_style: 'body{font-family:Arial,sans-serif;font-size:14px}img{max-width:100%;height:auto}pre{padding:12px;white-space:pre-wrap;background:#80808018;border:1px solid #8886;border-radius:4px}table{border-collapse:collapse}td,th{border:1px solid #aaa;padding:6px}',
                setup: instance => {
                    instance.ui.registry.addButton('documentcode', {text: '</> Código', tooltip: 'Inserir ou editar bloco de código com linguagem', onAction: codeDialog});
                    instance.on('change input undo redo', () => { dirty = true; });
                    instance.on('SetContent change', () => { highlight(); });
                }
            });
            if (isExisting) { install(await api('load')); report('Documentação carregada. Alterações simultâneas serão verificadas ao salvar.'); }
            section.querySelector('.doc-template')?.addEventListener('click', loadTemplate);
            const prepare = () => {
                if (!ready()) return;
                section.querySelector('.doc-template').hidden = false;
                if (!loaded && !dirty) loadTemplate();
                else { workspace.hidden = false; report('Documentação preservada. Revise o texto para o projeto e tipo selecionados ou carregue outro template.'); }
            };
            if (!isExisting) { form.addEventListener('demandas:creation-options', prepare); prepare(); }
            files.addEventListener('change', async () => {
                const selected = [...files.files]; files.disabled = true;
                try { for (const file of selected) await upload(file); }
                catch (error) { report(error.message, true); }
                finally { files.value = ''; files.disabled = false; }
            });
            markdown.addEventListener('input', () => { dirty = true; });
            section.querySelector('.doc-mode').addEventListener('click', async event => {
                if (!loaded || busy) return;
                event.currentTarget.disabled = true;
                try {
                    if (mode === 'visual') {
                        await editor.uploadImages();
                        markdown.value = editor.getContent() === initialHtml ? raw : (await api('to_markdown', {documentation: editor.getContent()})).raw;
                        editor.hide(); markdown.hidden = false; mode = 'markdown';
                    } else {
                        const data = await api('to_html', {documentation: markdown.value});
                        raw = markdown.value; editor.setContent(data.html); initialHtml = editor.getContent();
                        markdown.hidden = true; editor.show(); mode = 'visual';
                    }
                    event.target.textContent = mode === 'visual' ? 'Editar Markdown' : 'Editor visual';
                } catch (error) { report(error.message, true); }
                finally { event.target.disabled = false; }
            });
        } catch (error) { report(error.message, true); }
    }
    function scan() { document.querySelectorAll('.demandas-documentation').forEach(section => { const modal = section.closest('.modal'); if ((!modal || modal.classList.contains('show')) && (Number(section.dataset.wp) || section.closest('form').dataset.creationReady === '1')) initialize(section); }); }
    document.addEventListener('demandas:creation-options', scan);
    document.addEventListener('shown.bs.modal', scan);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
    new MutationObserver(scan).observe(document.documentElement, {childList: true, subtree: true});
})();
