const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const assert=require('node:assert/strict');
const base='http://127.0.0.1:8386';
function docker(args,input){const r=spawnSync('docker',['exec','-i','demandas-test-021-glpi',...args],{input,encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);return r.stdout;}
function fixture(file,input){return JSON.parse(docker(['php','/tmp/'+file+'.php'],JSON.stringify(input)));}
const operation=(mode,args={})=>fixture('operations-fixture',{mode,user:'security-admin',...args});
(async()=>{
 const password=randomBytes(24).toString('hex');
 const seed=fixture('security-stage1-fixture',{mode:'seed',password});
 operation('configure');
 const browser=await chromium.launch({channel:'msedge',headless:true});
 async function login(role){const context=await browser.newContext();const page=await context.newPage();await page.goto(base);await page.locator('[name=login_name]').fill('security-'+role);await page.locator('[name=login_password]').fill(password);await Promise.all([page.waitForNavigation(),page.locator('[name=submit]').click()]);return {context,page};}
 const snapshot=()=>fixture('security-stage1-fixture',{mode:'snapshot'});
 const inventory=()=>docker(['sh','-c','find /var/glpi/_plugins/demandas/absences -type f 2>/dev/null | sort']);
 try{
  const {context,page}=await login('admin');
  const clock=base+'/plugins/demandas/front/timeclock.php?year=2026&month=10';
  async function punch(extra){await page.goto(clock);const csrf=await page.locator('input[name=_glpi_csrf_token]').first().inputValue();return context.request.post(base+'/plugins/demandas/front/punch.form.php',{multipart:{_glpi_csrf_token:csrf,users_id:String(seed.users.admin),punch_date:'2026-10-21','records[0][time]':'08:00','records[1][time]':'17:00',absence_action:'set',absence_kind:'justified',...extra},maxRedirects:0});}
  await punch({'absence_files[]':{name:'qa.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 synthetic QA')}});
  const before=snapshot();const files=inventory();
  assert.equal((await punch({'records[1][time]':'bad',absence_kind:'unjustified'})).status(),302);
  assert.deepEqual(snapshot(),before,'failed day modified absence or punches');assert.equal(inventory(),files,'failed day deleted old attachment');
  assert.equal((await punch({'absence_files[0]':{name:'qa-new.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 synthetic QA')},'absence_files[1]':{name:'bad.exe',mimeType:'application/octet-stream',buffer:Buffer.from('synthetic')}})).status(),302);
  assert.deepEqual(snapshot(),before,'partial upload changed DB');assert.equal(inventory(),files,'partial upload left a new file');
  for (const name of ['fake.png','fake.pdf','fake.docx']) {
   await punch({'absence_files[]':{name,mimeType:'application/pdf',buffer:Buffer.from('<?php echo "invalid";')}});
   assert.deepEqual(snapshot(),before,'forged attachment changed DB');assert.equal(inventory(),files,'forged attachment left file');
  }
  console.log('PASS: forged contents rejected regardless of browser MIME; previous files and records preserved');
  console.log('PASS: multipart failure preserves existing files, rolls back records and cleans newly uploaded files');
  const entry=operation('create').id;
  docker(['sh','-c','cat > /tmp/operations-api-mode'],'lost');operation('sync',{id:entry});
  docker(['sh','-c','cat > /tmp/operations-api-mode'],'ok');
  const url=base+'/plugins/demandas/front/time-entry.php?date_from=2026-10-20&date_to=2026-10-20';
  await page.goto(url);assert.equal(await page.locator('#te-recovery-'+entry).count(),1);
  const row=page.locator('tr').filter({has:page.locator('a[href="#te-recovery-'+entry+'"]')});
  assert.equal(await row.locator('.te-select,.te-action[data-action=sync]').count(),0);
  assert.equal(await row.locator('.te-edit:disabled').count(),1);
  for(const theme of ['light','dark'])for(const width of [390,1366]){
   await page.setViewportSize({width,height:768});await page.locator('html').evaluate((el,theme)=>el.setAttribute('data-glpi-theme',theme),theme);
   await page.locator('#te-recovery-'+entry).evaluate(el=>el.open=true);
   const button=page.locator('#te-recovery-'+entry+' button');await button.scrollIntoViewIfNeeded();await button.click({trial:true});
  }
  await page.goto(url);const form=page.locator('#te-recovery-'+entry+' form');const token=await form.locator('[name=_glpi_csrf_token]').inputValue();
  const remote=970000+JSON.parse(docker(['cat','/tmp/operations-api-state'])).posts;
  const body={action:'recover',entry_id:String(entry),remote_id:String(remote),date_from:'2026-10-20',date_to:'2026-10-20'};
  assert.equal((await context.request.post(base+'/plugins/demandas/front/time-entry.form.php',{form:body,maxRedirects:0})).status(),403);
  assert.equal(operation('row',{id:entry}).sync_status,'uncertain');
  const other=await login('internal');await other.page.goto(url);const otherToken=await other.page.locator('[name=_glpi_csrf_token]').first().inputValue();
  await other.context.request.post(base+'/plugins/demandas/front/time-entry.form.php',{form:{...body,_glpi_csrf_token:otherToken},maxRedirects:0});assert.equal(operation('row',{id:entry}).sync_status,'uncertain');
  const client=await login('client');assert.equal((await client.page.goto(url)).status(),403);
  await page.goto(url);const finalToken=await page.locator('#te-recovery-'+entry+' [name=_glpi_csrf_token]').inputValue();
  assert.equal((await context.request.post(base+'/plugins/demandas/front/time-entry.form.php',{form:{...body,_glpi_csrf_token:finalToken},maxRedirects:0})).status(),302);
  assert.equal(operation('row',{id:entry}).sync_status,'synced');
  console.log('PASS: recovery UI accessible on mobile/desktop; CSRF, owner and client rights preserved; verified recovery succeeds');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
