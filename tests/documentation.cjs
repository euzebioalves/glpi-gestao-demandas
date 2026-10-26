const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const assert = require('node:assert/strict');
const path = require('node:path');
const os = require('node:os');
function fixture(input, legacy = false) {
    const result = spawnSync('docker', ['exec','-i','demandas-test-021-glpi','php','/tmp/' + (legacy ? 'legacy-reconciliation' : 'documentation') + '-fixture.php'], {input: JSON.stringify(input), encoding:'utf8'});
    assert.equal(result.status, 0, result.stdout + result.stderr); return JSON.parse(result.stdout);
}
const base = 'http://127.0.0.1:8386', endpoint = '/plugins/demandas/front/work-package-documentation.php';
(async () => {
    const password = randomBytes(24).toString('hex');
    fixture({mode:'seed', password}, true); const seed = fixture({mode:'seed'}); fixture({mode:'failure'});
    const browser = await chromium.launch({channel:'msedge', headless:true});
    try {
        const context = await browser.newContext({viewport:{width:1366,height:900}});
        const page = await context.newPage(); const errors=[];page.on('pageerror',error=>{errors.push(error.message);console.error('Browser:',error.message);});
        async function login(page, role) {
            await page.goto(base); await page.locator('[name=login_name]').fill('reconciliation-'+role); await page.locator('[name=login_password]').fill(password);
            await Promise.all([page.waitForNavigation(), page.locator('[name=submit]').click()]);
        }
        await login(page,'admin');
        const editUrl = base + endpoint + '?ticket='+seed.ticket+'&wp='+seed.wp;
        assert.equal((await page.goto(editUrl)).status(),200);
        await page.waitForFunction(()=>document.querySelector('.doc-status')?.textContent.includes('Documentação carregada'), {timeout:20000});
        await page.getByRole('button',{name:'Inserir ou editar bloco de código com linguagem',exact:true}).click();
        await page.locator('#demandas-code-editor .monaco-editor').waitFor();
        await page.evaluate(()=>{monaco.editor.getModels()[0].setValue('const teste = "<b>";\nconsole.log(teste);');});
        await page.getByRole('button',{name:'Inserir código',exact:true}).click();
        await page.evaluate(()=>{tinymce.activeEditor.setContent('<h2>QA <em>documentação</em></h2><pre class="language-javascript"><code>const x = `a`;\nconsole.log("&lt;b&gt;");</code></pre><table><tbody><tr><td>QA</td><td>Teste</td></tr></tbody></table>');});
        await page.waitForFunction(()=>new Set([...tinymce.activeEditor.getBody().querySelectorAll('pre span')].map(span=>span.style.color)).size>1);
        await page.locator('.doc-files').setInputFiles({name:'evidencia.txt',mimeType:'text/plain',buffer:Buffer.from('Evidência fictícia')});
        await page.waitForFunction(()=>document.querySelector('.doc-attachments')?.textContent.includes('evidencia.txt') || document.querySelector('.doc-status')?.classList.contains('alert-danger'));
        assert.equal(await page.locator('.doc-attachments li').count(),1,await page.locator('.doc-status').innerText());
        // Inline image upload through TinyMCE's actual handler.
        await page.evaluate(async()=>{
            const blob = await (await fetch('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3XcAAAAASUVORK5CYII=')).blob();
            const upload=tinymce.activeEditor.options.get('images_upload_handler');
            const url=await upload({blob:()=>blob,filename:()=> 'imagem.png'});
            tinymce.activeEditor.insertContent('<img src="'+url.replaceAll('&','&amp;')+'" style="width:120px;height:80px" alt="QA">');
        });
        assert.equal(await page.locator('.doc-attachments li').count(),2);
        await page.getByRole('button',{name:'Editar Markdown',exact:true}).click();
        await page.locator('.doc-markdown').waitFor({state:'visible'});
        const markdown = await page.locator('.doc-markdown').inputValue();
        assert(!markdown.includes('<?xml'));
        assert.match(markdown,/```javascript/);assert.match(markdown,/const x = `a`/);assert.match(markdown,/width:120px/);assert.match(markdown,/<table>/);assert(!markdown.includes('DEMANDASPRESERVE'));assert(!markdown.includes('draft='));
        await page.getByRole('button',{name:'Editor visual',exact:true}).click();
        await page.waitForFunction(()=>tinymce.activeEditor.getBody().textContent.includes('const x'));
        for (const width of [1366,390]) {
            await page.setViewportSize({width,height:800});
            await page.screenshot({path:path.join(os.tmpdir(),'documentation-'+width+'.png'),fullPage:true});
        }
        await Promise.all([page.waitForURL('**/front/ticket.form.php?**'),page.getByRole('button',{name:'Salvar documentação',exact:true}).click()]);
        let state=fixture({mode:'api-state'});assert.match(state.wps[seed.wp].description.raw,/```javascript/);assert.equal(state.wps[seed.wp]._links.attachments.length,2);
        console.log('PASS: edição, Markdown, código, imagem e anexos.');
        // Create another WP with documentation and a relation; failed relation can be retried without another create.
        await page.setViewportSize({width:1366,height:900});
        await page.goto(base+'/front/ticket.form.php?id='+seed.ticket+'&forcetab=GlpiPlugin%5CDemandas%5CTicketDemand%241');
        await page.getByRole('tab',{name:'Evolução da Demanda',exact:true}).click();
        await page.locator('[data-bs-target="#demandas-create-additional-wp"]').waitFor();
        await page.screenshot({path:path.join(os.tmpdir(),'documentation-ticket.png'),fullPage:true});
        await page.locator('[data-bs-target="#demandas-create-additional-wp"]').click();
        const modal=page.locator('#demandas-create-additional-wp');
        assert.equal(await modal.locator('.doc-workspace').isVisible(),false,'No editor before project/type');
        assert.equal(await modal.locator('.tox-tinymce').count(),0,'No TinyMCE initialized before options');
        await modal.locator('[name=additional_wp_reason]').fill('Correção anterior não resolveu completamente o bug.');
        await modal.locator('[name=project_id]').selectOption('77');
        assert.equal(await modal.locator('.doc-workspace').isVisible(),false,'Project alone does not show editor');
        await modal.locator('[name=type_id] option[value="88"]').waitFor({state:'attached'});
        let failTemplate = true;
        await page.route('**/front/work-package-documentation.php', async route => {
            if (failTemplate && /name="action"\r\n\r\ntemplate/.test(route.request().postData() || '')) {
                failTemplate = false;
                await route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({error:'Template indisponível no teste'})});
            } else await route.continue();
        });
        await modal.locator('[name=type_id]').selectOption('88');
        await page.waitForFunction(()=>document.querySelector('#demandas-create-additional-wp .doc-status')?.textContent.includes('Template indisponível'));
        assert.equal(await modal.locator('.doc-workspace').isVisible(),false,'Failed template must not expose editor/uploads');
        await modal.locator('.doc-template').click();
        await page.waitForFunction(()=>document.querySelector('#demandas-create-additional-wp .doc-status')?.textContent.includes('Template carregado'));
        assert.equal(await modal.locator('.doc-workspace').isVisible(),true);
        await page.evaluate(()=>tinymce.activeEditor.setContent('<p>Texto preservado ao trocar seleção</p>'));
        await modal.locator('[name=project_id]').selectOption('');
        assert.equal(await modal.locator('.doc-workspace').isVisible(),false);
        assert.equal(await modal.locator('#demandas-create-button').isDisabled(),true);
        await modal.locator('[name=project_id]').selectOption('77');
        await modal.locator('[name=type_id] option[value="88"]').waitFor({state:'attached'});
        await modal.locator('[name=type_id]').selectOption('88');
        await modal.locator('.doc-workspace').waitFor({state:'visible'});
        assert.match(await page.evaluate(()=>tinymce.activeEditor.getContent()),/Texto preservado/);
        await modal.locator('[name=relation_target]').selectOption(String(seed.wp)); await modal.locator('[name=confirm_relation]').check();
        fixture({mode:'failure',relation:true});const before=fixture({mode:'api-state'}).creates;
        await modal.locator('#demandas-create-button').click();
        await page.waitForFunction(()=>document.querySelector('#demandas-create-additional-wp .doc-status')?.textContent.includes('foi criada e vinculada'));
        assert.equal(fixture({mode:'api-state'}).creates,before+1);
        fixture({mode:'failure'});
        await Promise.all([page.waitForNavigation(),modal.locator('#demandas-create-button').click()]);
        state=fixture({mode:'api-state'});assert.equal(state.creates,before+1);assert(state.relations.some(r=>r._links.to.href.endsWith('/'+seed.wp)));
        await page.goto(editUrl);
        await page.waitForFunction(()=>document.querySelector('.doc-status')?.textContent.includes('Documentação carregada'));
        async function api(action, extra={}) {
            return page.evaluate(async ({endpoint,action,extra})=>{
                const section=document.querySelector('.demandas-documentation');
                const form=new FormData();form.set('action',action);form.set('doc_draft',section.dataset.draft);form.set('_glpi_csrf_token',section.dataset.csrf);
                for(const [key,value] of Object.entries(extra))form.set(key,value);
                const response=await fetch(endpoint,{method:'POST',body:form});const data=await response.json();if(data.csrf)section.dataset.csrf=data.csrf;
                delete data.csrf;return {status:response.status,data};
            },{endpoint,action,extra});
        }
        const safe=await api('to_html',{documentation:'<script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(1)">'});
        const unsafe=await page.evaluate(html=>{const doc=new DOMParser().parseFromString(html,'text/html');return !!doc.querySelector('script,[onerror],img[src^="javascript:"]');},safe.data.html);
        assert(!unsafe);
        const updates=fixture({mode:'api-state'}).updates;
        for(const code of [401,403,503]) {
            fixture({mode:'failure',code});
            assert.equal((await api('save',{format:'markdown',documentation:'Não deve ser salvo'})).status,400);
            assert.equal(fixture({mode:'api-state'}).updates,updates);
        }
        fixture({mode:'failure'});fixture({mode:'concurrent',wp:seed.wp});
        const conflict=await api('save',{format:'markdown',documentation:'Não sobrescrever'});
        assert.equal(conflict.status,400);assert.match(conflict.data.error,/alterada no OpenProject/);assert.equal(fixture({mode:'api-state'}).updates,updates);
        // First actual link is free even with several source references; subsequent links require justification.
        const candidates=fixture({mode:'first-links'});
        await page.goto(base+'/front/ticket.form.php?id='+candidates.tickets.first+'&forcetab=GlpiPlugin%5CDemandas%5CTicketDemand%241');
        await page.locator('.demandas-documentation').waitFor();
        assert.equal(await page.locator('.doc-workspace').isVisible(),false,'Unlinked ticket starts without editor');
        assert.equal(await page.locator('.doc-files').isVisible(),false);
        const reconciliation='/plugins/demandas/front/legacy-reconciliation.php?ticket_scope=all&ticket=';
        const postReconciliation='/plugins/demandas/front/legacy-reconciliation.form.php';
        await page.goto(base+reconciliation+candidates.tickets.first);
        assert.equal(await page.locator('input[name="pairs[]"]').count(),2);
        await page.locator('#legacy-select-all').click();assert(await page.locator('#legacy-batch-reason').getAttribute('required')!==null);
        await page.locator('input[name="pairs[]"]').nth(1).uncheck();assert.equal(await page.locator('#legacy-batch-reason').getAttribute('required'),null);
        await page.locator('input[name=confirm]').check();
        await page.evaluate(()=>document.addEventListener('submit',event=>queueMicrotask(()=>sessionStorage.setItem('qa-submit-disabled',String([...event.target.querySelectorAll('button')].every(button=>button.disabled))))));
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Validar e conciliar selecionadas',exact:true}).click()]);
        assert.equal(await page.evaluate(()=>sessionStorage.getItem('qa-submit-disabled')),'true');
        assert.equal(fixture({mode:'links',wp:candidates.wp}).length,1);
        await page.goto(base+reconciliation+candidates.tickets.second);
        const token=await page.locator('form[action="'+postReconciliation+'"] input[name=_glpi_csrf_token]').inputValue();
        await context.request.post(base+postReconciliation,{form:{_glpi_csrf_token:token,confirm:'1','pairs[]':candidates.tickets.second+':'+candidates.wp},maxRedirects:0});
        assert.equal(fixture({mode:'links',wp:candidates.wp}).length,1);
        await page.reload();await page.locator('input[name="pairs[]"]').check();await page.locator('#legacy-batch-reason').fill('Mesma entrega atende aos dois chamados, conforme análise.');await page.locator('input[name=confirm]').check();
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Validar e conciliar selecionadas',exact:true}).click()]);
        assert.equal(fixture({mode:'links',wp:candidates.wp}).length,2);
        // Missing CSRF and native rights must reject writes and reads respectively.
        assert.equal((await context.request.post(base+endpoint,{form:{action:'save',doc_draft:'invalid'}})).status(),403);
        for (const role of ['client','readonly']) {
            const restricted=await browser.newContext();const restrictedPage=await restricted.newPage();await login(restrictedPage,role);
            assert.equal((await restrictedPage.goto(editUrl)).status(),403);await restricted.close();
        }
        const darkContext=await browser.newContext({viewport:{width:390,height:844}});
        await darkContext.addInitScript(()=>{
            const dark=()=>{if(!document.documentElement)return false;document.documentElement.dataset.glpiTheme='auror_dark';document.documentElement.dataset.glpiThemeDark='1';return true;};
            if(!dark()){const observer=new MutationObserver(()=>{if(dark())observer.disconnect();});observer.observe(document,{childList:true,subtree:true});}
        });
        const darkPage=await darkContext.newPage();await login(darkPage,'internal');await darkPage.goto(editUrl);
        await darkPage.waitForFunction(()=>document.querySelector('.doc-status')?.textContent.includes('Documentação carregada'));
        assert.match(await darkPage.evaluate(()=>tinymce.activeEditor.getBody().textContent),/const x/);
        await darkPage.waitForFunction(()=>new Set([...tinymce.activeEditor.getBody().querySelectorAll('pre span')].map(span=>span.style.color)).size>1);
        assert.match(await darkPage.evaluate(()=>tinymce.activeEditor.options.get('skin_url')),/oxide-dark/);
        await darkPage.screenshot({path:path.join(os.tmpdir(),'documentation-dark-390.png'),fullPage:true});await darkContext.close();
        assert.deepEqual(errors,[]);
        console.log('PASS: editor, código, imagens redimensionadas, anexos, criação/relação com repetição segura, edição, CSRF, perfis e responsividade.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
