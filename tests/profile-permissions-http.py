"""Regressão HTTP em GLPI 11.0.9 descartável; veja docs/RELEASE_0.18.4.md."""
import http.cookiejar, json, os, pathlib, re, secrets, subprocess, sys, tempfile, time
import urllib.request, urllib.parse, urllib.error
CONTAINER = 'demandas-release-0183-glpi'
BASE = 'http://127.0.0.1:8383'
PAGE = '/plugins/demandas/front/config.form.php'
STATE = pathlib.Path(tempfile.gettempdir()) / 'demandas-0184-test-state.json'
def fixture(mode, **args):
    result = subprocess.run(['docker','exec','-i',CONTAINER,'php','/tmp/profile-permissions-fixture.php'], input=json.dumps({'mode':mode,**args}),text=True,capture_output=True,check=True)
    return json.loads(result.stdout)
def check(value, label):
    if not value: raise AssertionError(label)
    print('PASS:',label)
class Browser:
    def __init__(self):
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(self,path,data=None):
        req=urllib.request.Request(BASE+path,headers={'Referer':BASE+PAGE},data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try: response=self.opener.open(req,timeout=30)
        except urllib.error.HTTPError as error: response=error
        return response.status,response.read().decode('utf-8')
    def login(self,password):
        _,html=self.request('/')
        status,html=self.request('/front/login.php',{'login_name':'release-permissions','login_password':password,'_glpi_csrf_token':csrf(html),'noAUTO':'1'})
        check(status==200 and 'name="login_password"' not in html,'login com sessão GLPI')
        return html
    def post(self,path,data):
        status,html=self.request(PAGE+'?tab=my-access')
        return self.request(path,{'_glpi_csrf_token':csrf(html),**data})
def csrf(html):
    return re.search(r'name=[\"\']_glpi_csrf_token[\"\']\s+value=[\"\']([^\"\']+)',html)[1]
def tabs(html,admin):
    for tab in ['automation','classification','templates','tutorial']:
        check(("href='?tab="+tab+"'") in html if admin else ("href='?tab="+tab+"'") not in html, f'aba {tab}: '+('permitida' if admin else 'oculta'))
if sys.argv[1]=='prepare':
    password=json.loads(STATE.read_text())['password'] if STATE.exists() else secrets.token_urlsafe(24)
    state=fixture('seed',password=password)
    state['password']=password
    state['snapshot']=fixture('snapshot')
    STATE.write_text(json.dumps(state))
    b=Browser();html=b.login(password)
    _,html=b.request(PAGE)
    if '--baseline' in sys.argv:
        check("href='?tab=automation'" not in html,'defeito 0.18.3 reproduzido via HTTP com Master autorizado')

elif sys.argv[1]=='verify':
    state=json.loads(STATE.read_text())
    check(fixture('snapshot')==state['snapshot'],'atualização preserva 11 tabelas, configurações, tokens e permissões')
    check(fixture('helper')['passed']==10,'helper acompanha direitos e troca de perfil, sem inferência pelo nome')
    b=Browser();html=b.login(state['password'])
    _,html=b.request(PAGE)
    tabs(html,True)
    for name in ['Administrador Corporativo','Super-Admin','Comum Release','Cliente Release','Master']:
        status,_=b.post('/Session/ChangeProfile',{'id':state['profiles'][name]})
        check(status==200, 'troca nativa para '+name)
        status,html=b.request(PAGE)
        check(status==200,'configuração pessoal acessível: '+name)
        granted=name in ['Master','Administrador Corporativo']
        tabs(html,granted)
        if not granted:
            check('fixture-webhook' not in html and 'fixture-automation' not in html, 'segredos globais ausentes da resposta pessoal')
            for tab in ['automation','classification','templates','tutorial']:
                status,_=b.request(PAGE+'?tab='+tab)
                check(status==403,'URL administrativa negada: '+tab+' / '+name)
            before=fixture('snapshot')
            status,_=b.post(PAGE,{'save':'1','openproject_internal_url':'http://invalid.example','webhook_secret':'manipulado'})
            check(status==403,'POST global manipulado negado: '+name)
            check(before==fixture('snapshot'),'POST negado sem escrita')
    # Revogação via API nativa em sessão já aberta, sem logout.
    time.sleep(1.1) # GLPI usa marcador de atualização de direitos com precisão de segundos.
    fixture('right',profile=state['profiles']['Master'],enabled=False)
    status,html=b.request(PAGE)
    tabs(html,False)
    check(b.request(PAGE+'?tab=automation')[0]==403,'revogação efetiva na próxima requisição da sessão aberta')
    for token in ['fixture-personal-new','fixture-401','fixture-403']:
        status,html=b.post(PAGE+'?tab=my-access',{'test_personal_token':'1','personal_openproject_api_token':token,'users_id':'999999'})
        check(status==200,'teste de token pessoal retorna à aba pessoal: '+token)
        check(fixture('assert-token',user=state['user'],token=token)=={'matches':True,'automationPreserved':True},'token salvo somente para usuário autenticado; automação preservada')
        if token=='fixture-personal-new': check('conexão com o OpenProject foi validada' in html,'API pessoal testada com sucesso')
        else: check('conexão com o OpenProject foi validada' not in html,'falha da API não indica sucesso')
        tabs(html,False)
    status,html=b.post(PAGE+'?tab=my-access',{'save_personal_token':'1','personal_openproject_api_token':''})
    check(status==200 and fixture('assert-token',user=state['user'],token='fixture-403')['matches'],'token pessoal vazio preserva valor anterior')
    status,_=b.request(PAGE,{'save_personal_token':'1','personal_openproject_api_token':'forjado'})
    check(status==403,'CSRF nativo rejeita POST sem token')
    time.sleep(1.1)
    fixture('right',profile=state['profiles']['Master'],enabled=True)
    status,html=b.request(PAGE)
    tabs(html,True)
    payload={'test_connection':'1','public_phases[0][id]':'received','public_phases[0][name]':'Recebida','classification_mode':'none','openproject_automation_api_token':''}
    status,html=b.post(PAGE+'?tab=automation',payload)
    check(status==200 and 'Conexão automática realizada com sucesso' in html,'Master salva configurações e testa automação')
    check(fixture('assert-token',user=state['user'],token='fixture-403')['automationPreserved'],'campo automático vazio preserva token existente')
    status,_=b.post('/plugins/demandas/front/profile.form.php',{'profiles_id':state['profiles']['Master'],'update':'1'})
    check(status==403,'MANAGE_CONFIG não concede administração dos perfis GLPI')
    # Permissões próprias de ponto permanecem independentes da configuração global.
    status,_=b.request('/plugins/demandas/front/timeclock-admin.php')
    check(status==403,'MANAGE_CONFIG sozinho não concede administração do ponto')
    time.sleep(1.1)
    fixture('right',profile=state['profiles']['Master'],right='demandas_manage_holidays',enabled=True)
    fixture('right',profile=state['profiles']['Master'],enabled=False)
    status,html=b.request('/plugins/demandas/front/timeclock-admin.php')
    check(status==200 and 'save_holiday' in html and 'save_access' not in html,'direito de feriados independente de configuração e gestão de acessos')
    status,_=b.post('/plugins/demandas/front/timeclock-admin.php',{'save_access':'1','users_id':state['user'],'right_name':'demandas_manage_config','decision':'1'})
    check(status==403,'gestor de feriados não pode conceder exceções de acesso')
    time.sleep(1.1)
    fixture('right',profile=state['profiles']['Master'],right='demandas_manage_time_access',enabled=True)
    fixture('right',profile=state['profiles']['Master'],right='demandas_manage_holidays',enabled=False)
    status,html=b.request('/plugins/demandas/front/timeclock-admin.php')
    check(status==200 and 'save_access' in html and 'save_holiday' not in html,'gestor de acessos não herda direito de feriados')
    check(b.post('/plugins/demandas/front/timeclock-admin.php',{'save_holiday':'1','name':'Forjado','holiday_date':'2026-09-23'})[0]==403,'POST de feriado negado ao gestor de acessos')
    before=fixture('snapshot')
    b.post('/plugins/demandas/front/timeclock-admin.php',{'save_access':'1','users_id':state['user'],'right_name':'demandas_manage_config','decision':'1'})
    check(before==fixture('snapshot'),'exceção manipulada não altera configuração global')
    status,_=b.post('/plugins/demandas/front/timeclock-admin.php',{'save_access':'1','users_id':state['user'],'right_name':'demandas_log_own_time','decision':'1'})
    check(status==200 and before!=fixture('snapshot'),'exceção de horas permitida é persistida')
    check(b.request(PAGE+'?tab=automation')[0]==403,'exceção individual não concede configuração global')
    print('PASS: matriz de autorização HTTP concluída')
