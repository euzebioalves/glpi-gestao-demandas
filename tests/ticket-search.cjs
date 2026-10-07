// Isolated GLPI 11.0.9 at port 8386, with synthetic data only.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const out = path.join(require('node:os').tmpdir(), 'demandas-ticket-search');
fs.mkdirSync(out, { recursive: true });
const base = 'http://127.0.0.1:8386';
const field = 925001;
function url(type, value = '', link = 'AND') {
  const q = new URLSearchParams({reset:1, is_deleted:0, 'forcetoview[0]':1, 'forcetoview[1]':field,
    'criteria[0][field]':1, 'criteria[0][searchtype]':'contains', 'criteria[0][value]':'^QA Search WP ', 'criteria[0][link]':'AND'});
  if (type === 'empty') value = 'NULL';
  if (type) for (const [key,val] of Object.entries({field,searchtype:type,value,link})) q.set(`criteria[1][${key}]`,val);
  return base + '/front/ticket.php?' + q;
}
(async () => {
  const password = randomBytes(24).toString('hex');
  const seeded = spawnSync('docker',['exec','-i','demandas-test-021-glpi','php','/tmp/ticket-search-fixture.php'], { input:JSON.stringify({password}),encoding:'utf8'});
  assert.equal(seeded.status,0,seeded.stdout+seeded.stderr);
  const {users,tickets} = JSON.parse(seeded.stdout);
  const browser = await chromium.launch({channel:'msedge',headless:true});
  try {
    for (const role of ['admin','internal','client']) {
      const context = await browser.newContext({viewport:{width:1366,height:768}});
      const page = await context.newPage();
      const errors=[]; page.on('pageerror',e=>errors.push(e.message));
      await page.goto(base);
      await page.locator('[name="login_name"]').fill(users[role].login);
      await page.locator('[name="login_password"]').fill(password);
      await Promise.all([page.waitForNavigation(),page.locator('[name="submit"]').click()]);
      async function search(type,value,link) {
        const res=await page.goto(url(type,value,link));
        assert.equal(res.status(),200);
        await page.waitForTimeout(200);
        const html=await page.content();
        fs.writeFileSync(path.join(out,role+'.html'),html);
        return html;
      }
      await search();
      // Add the column through GLPI's native personal display preferences.
      await page.goto(base+'/front/displaypreference.form.php?itemtype=Ticket&forcetab=DisplayPreference$2');
      await page.locator('button[name=activate]').click();
      await page.locator('select[name=num]').waitFor({state:'attached'});
      assert.equal(await page.locator('select[name=num] option[value="925001"]').count(),role==='client'?0:1);
      if (role!=='client') {
        await page.locator('select[name=num]').selectOption(String(field));
        await page.locator('button[name=add_opt]').click();
        await page.locator('li[data-opt-id="925001"]').waitFor();
        const nativeUrl=new URL(url());
        nativeUrl.searchParams.delete('forcetoview[0]');nativeUrl.searchParams.delete('forcetoview[1]');
        await page.goto(nativeUrl.toString());
        assert.equal(await page.locator('table a[href*="openproject.example.invalid/work_packages/"]').count(),3);
      }
      await search();
      const wpLinks=()=>page.locator('table a[href*="openproject.example.invalid/work_packages/"]');
      if (role==='client') {
        assert.equal(await wpLinks().count(),0);
        const forged=await search('equals','982102');
        assert.ok(!forged.includes('href="https://openproject.example.invalid/work_packages/'));
        console.log('PASS cliente: coluna e filtro forjados não expõem WPs');
        await context.close(); continue;
      }
      assert.equal(await wpLinks().count(),3);
      const multiRow=page.locator('table tr').filter({has:page.getByRole('link',{name:'QA Search WP multiple',exact:true})});
      assert.match(await multiRow.innerText(),/982102\s*\|\s*982103/);
      for (const a of await wpLinks().all()) {assert.equal(await a.getAttribute('target'),'_blank');assert.match(await a.getAttribute('rel'),/noopener/);}
      for (const kind of ['none','legacy']) {
        const row=page.locator('table tr').filter({has:page.getByRole('link',{name:'QA Search WP '+kind,exact:true})});
        assert.equal(await row.locator('a[href*="/work_packages/"]').count(),0);
      }
      assert.equal(await page.getByRole('link',{name:'QA Search WP foreign',exact:true}).count(),0);
      async function expectTickets(type,value,kinds,link) {
        await search(type,value,link);
        for (const kind of Object.keys(tickets)) assert.equal(await page.getByRole('link',{name:'QA Search WP '+kind,exact:true}).count(),kinds.includes(kind)?1:0,`${role} ${type} ${value}: ${kind}`);
      }
      await expectTickets('empty','',['none','legacy']);
      await expectTickets('empty','',['single','multiple'],'AND NOT');
      await expectTickets('contains','NULL',['none','legacy']);
      await expectTickets('notcontains','NULL',['single','multiple']);
      await expectTickets('equals','982102',['multiple']);
      assert.equal(await wpLinks().count(),2,'O filtro por uma WP mantém as outras WPs do chamado');
      await expectTickets('notequals','982102',['none','legacy','single']);
      await expectTickets('contains','2103',['multiple']);
      await expectTickets('notcontains','2103',['none','legacy','single']);
      await expectTickets('equals',"982102' OR 1=1 --",[]);
      await expectTickets('contains',"' OR 1=1 --",[]);
      await search();
      const exportUrl=new URL(url());
      exportUrl.pathname='/front/report.dynamic.php';
      exportUrl.searchParams.set('item_type','Ticket');exportUrl.searchParams.set('display_type','3');
      const csv=await page.request.get(exportUrl.toString());
      assert.equal(csv.status(),200);
      const csvText=await csv.text();
      assert.ok(csvText.includes('982102 | 982103'));
      assert.ok(!csvText.includes('<a ') && !csvText.includes('982104'));
      console.log('PASS '+role+': preferência pessoal salva e exportação CSV nativa');
      for (const dark of [false,true]) {
        await page.evaluate(d=>{document.documentElement.setAttribute('data-glpi-theme',d?'auror_dark':'auror');document.documentElement.setAttribute('data-glpi-theme-dark',d?'1':'0');},dark);
        await page.screenshot({path:path.join(out,`${role}-${dark?'dark':'light'}.png`)});
      }
      assert.deepEqual(errors,[]);
      console.log('PASS '+role+': múltiplos links, vazio/não vazio, igualdade/negação, busca parcial, entidades e entrada malformada');
      await context.close();
    }
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
