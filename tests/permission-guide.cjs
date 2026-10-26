const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const assert=require('node:assert/strict');
const path=require('node:path'),os=require('node:os');
const base='http://127.0.0.1:8386';
function fixture(file,input){const r=spawnSync('docker',['exec','-i','demandas-test-021-glpi','php','/tmp/'+file+'-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'});assert.equal(r.status,0,r.stderr);return JSON.parse(r.stdout);}
(async()=>{
 const password=randomBytes(24).toString('hex');
 fixture('legacy-reconciliation',{mode:'seed',password});
 const seed=fixture('permission-guide',{mode:'seed'});
 const url=base+'/front/profile.form.php?id='+seed.profiles.internal+'&forcetab=GlpiPlugin%5CDemandas%5CProfile%241';
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  for(const role of ['admin','readonly','internal','client']){
   const context=await browser.newContext({viewport:{width:1440,height:900}}),page=await context.newPage();
   await page.goto(base);await page.locator('[name=login_name]').fill('reconciliation-'+role);await page.locator('[name=login_password]').fill(password);
   await Promise.all([page.waitForNavigation(),page.locator('[name=submit]').click()]);
   const response=await page.goto(url);
   if(['internal','client'].includes(role)){
    assert.equal(response.status(),403);
    assert.equal((await context.request.post(base+'/plugins/demandas/front/profile.form.php',{form:{profiles_id:seed.profiles.internal}})).status(),403);
   }else{
    const root=page.locator('.demandas-profile-permissions');await root.waitFor();
    assert.equal(await root.locator('section').count(),7);
    assert.equal(await root.locator('[name="rights[]"]').count(),28);
    assert.equal(await root.locator('details').count(),28);
    for(const help of await root.locator('details').all()){
     await help.locator('summary').click();assert.equal(await help.locator('dt').count(),3);
     for(const text of await help.locator('dd').allTextContents())assert(text.trim().length>0);
     await help.locator('summary').click();
    }
    const controls=root.locator('[name="rights[]"]');
    if(role==='readonly'){
     for(const control of await controls.all())assert(await control.isDisabled());
     assert.equal(await root.getByRole('button',{name:'Salvar permissões'}).count(),0);
     const token=await root.locator('[name=_glpi_csrf_token]').inputValue();
     assert.equal((await context.request.post(base+'/plugins/demandas/front/profile.form.php',{form:{profiles_id:seed.profiles.internal,_glpi_csrf_token:token}})).status(),403);
    }else{
     const before=fixture('permission-guide',{mode:'snapshot'}).rights;
     await Promise.all([page.waitForNavigation(),root.getByRole('button',{name:'Salvar permissões'}).click()]);
     await page.locator('.demandas-profile-permissions').waitFor();
     assert.deepEqual(fixture('permission-guide',{mode:'snapshot'}).rights,before,'Unchanged selections preserve all rights');
     await page.locator('#demandas_view_backlog').check();
     await page.locator('a[href="#demandas-rights-settings"]').click();
     assert(await page.locator('#demandas_view_backlog').isChecked(),'Navigation preserves unsaved choices');
     await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Salvar permissões'}).click()]);
     assert.equal(fixture('permission-guide',{mode:'snapshot'}).rights.demandas_view_backlog,1);
     await page.locator('#demandas_view_backlog').uncheck();
     await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Salvar permissões'}).click()]);
     assert.deepEqual(fixture('permission-guide',{mode:'snapshot'}).rights,before);
     assert.equal((await context.request.post(base+'/plugins/demandas/front/profile.form.php',{form:{profiles_id:seed.profiles.internal}})).status(),403);
     for(const dark of [false,true]) for(const width of [1440,390]){
      await page.setViewportSize({width,height:900});await page.reload();await page.locator('.demandas-profile-permissions').waitFor();
      await page.evaluate(dark=>{document.documentElement.dataset.glpiTheme=dark?'auror_dark':'auror';document.documentElement.dataset.glpiThemeDark=dark?'1':'0';},dark);
      await page.locator('summary').filter({hasText:'Ajuda'}).first().click();
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
      await page.screenshot({path:path.join(os.tmpdir(),`permission-guide-${width}-${dark}.png`),fullPage:true});
     }
    }
   }
   await context.close();console.log('PASS permissions: '+role);
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
