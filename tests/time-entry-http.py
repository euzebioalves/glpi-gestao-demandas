"""Practical regression with synthetic OpenProject API and isolated GLPI only."""
import http.cookiejar, json, os, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request

BASE = os.environ.get('RECONCILIATION_BASE', 'http://localhost:8190')
CONTAINER = os.environ.get('RECONCILIATION_CONTAINER', 'demandas-e2e1109-glpi')
PAGE = '/plugins/demandas/front/time-entry.php?date_from=2026-10-01&date_to=2026-10-01'
FORM = '/plugins/demandas/front/time-entry.form.php'

def check(ok, label):
    if not ok: raise AssertionError(label)
    print('PASS:', label)

def fixture(mode, **args):
    result = subprocess.run(['docker','exec','-i',CONTAINER,'php','/tmp/legacy-reconciliation-fixture.php'], input=json.dumps({'mode':mode, **args}), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)

def request(opener, path, data=None):
    body = None if data is None else urllib.parse.urlencode(data).encode()
    try: response=opener.open(urllib.request.Request(BASE+path, data=body), timeout=30)
    except urllib.error.HTTPError as error: response=error
    return response.status, response.read()

def csrf(html):
    return re.findall(rb'name=["\']_glpi_csrf_token["\']\s+value=["\']([^"\']+)', html)[-1].decode()

def login(role):
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    _, html=request(opener,'/')
    status, html=request(opener,'/front/login.php', {'login_name':'reconciliation-'+role,'login_password':password,'_glpi_csrf_token':csrf(html),'noAUTO':'1'})
    check(status==200 and b'name="login_password"' not in html, 'login '+role)
    return opener

def action(opener, values, with_csrf=True):
    data={'date_from':'2026-10-01','date_to':'2026-10-01', **values}
    if with_csrf: data['_glpi_csrf_token']=csrf(request(opener,PAGE)[1])
    return request(opener,FORM,data)

password=secrets.token_urlsafe(24)
seed=fixture('seed', password=password)
fixture('time-seed')
admin=login('admin')
internal=login('internal')
client=login('client')
status, html=request(admin,PAGE)
check(status==200 and 'Outra WP do OpenProject'.encode() in html, 'modal com duas origens')
check(request(admin,'/plugins/demandas/front/config.form.php',{'check_plugin_update':'1','_glpi_csrf_token':csrf(html)})[0]==403, 'consulta de releases exige configuração administrativa')
check(html.index(b'Nova entrada') < html.index(b'Sincronizar selecionadas'), 'botões na ordem solicitada')
search='/plugins/demandas/front/time-entry-work-packages.php?query='
status, body=request(admin,search+'92001')
check(status==200 and json.loads(body)['work_packages'][0]['id']==92001, 'pesquisa por ID com token pessoal')
status, body=request(admin,search+'ficticia')
check(status==200 and len(json.loads(body)['work_packages'])==1, 'pesquisa por título')
status, body=request(admin,search+'92403')
check(status==200 and json.loads(body)['work_packages']==[], 'WP sem acesso não aparece')
status, body=request(admin,search+'92401')
check(status==400 and not json.loads(body)['ok'], 'falha de autenticação não é ocultada')
check(request(client,search+'92001')[0]==403, 'pesquisa bloqueada ao perfil cliente')
check(request(client,'/plugins/demandas/front/time-entry-activities.php?wp=92001')[0]==403, 'atividades bloqueadas ao perfil cliente')
status, body=request(admin,'/plugins/demandas/front/time-entry-activities.php?wp=92001')
check(status==200 and len(json.loads(body)['activities'])==1, 'atividades de WP sem chamado')
before=fixture('time-snapshot')
base={'action':'save','link':'0:92001','spent_on':'2026-10-01','started_at':'09:00','ended_at':'10:00','activity':'/api/v3/time_entries/activities/1|Análise fictícia','comment':'QA externa'}
check(action(admin,base,with_csrf=False)[0]==403 and fixture('time-snapshot')==before, 'CSRF sem alteração local')
action(admin,{**base,'link':'0:92403'})
check(fixture('time-snapshot')==before, 'POST forjado para WP sem acesso recusado')
action(admin,{**base,'link':'0:92999'})
check(fixture('time-snapshot')==before, 'resposta de outra WP recusada')
status, _=action(admin,base)
rows=fixture('time-snapshot')
check(status==200 and len(rows)==len(before)+1, 'entrada externa salva no GLPI')
entry=rows[-1]
check(int(entry['tickets_id'])==0 and int(entry['minutes'])==60, 'sem vínculo e duração correta')
action(internal,{'action':'delete','entry_id':entry['id']})
check(fixture('time-snapshot')==rows, 'outro usuário não pode excluir a entrada')
action(admin,{'action':'sync','entry_id':entry['id']})
synced=fixture('time-snapshot')[-1]
check(synced['sync_status']=='synced' and int(synced['openproject_time_entry_id'])==93001, 'sincronização manual externa')
status, html=request(admin,PAGE)
check(status==200 and b'/projects/projeto-ficticio/cost_reports?' in html, 'link correto de Tempo e Custos')
action(admin,{**base,'entry_id':entry['id'],'ended_at':'10:30'})
check(int(fixture('time-snapshot')[-1]['minutes'])==90, 'edição sincronizada')
action(admin,{'action':'delete','entry_id':entry['id']})
check(fixture('time-snapshot')==before, 'exclusão externa e local')
status, body=request(urllib.request.build_opener(),search+'92001')
check(b'"work_packages"' not in body and (status in [401,403] or b'Access denied - GLPI' in body or b'name="login_password"' in body), 'rota anônima exige login e não expõe JSON de WPs')
print('Entradas de tempo: regressões aprovadas.')
