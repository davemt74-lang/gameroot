"""End-to-end smoke against a localhost PHP web server and isolated CI MySQL."""
import os, re, sys, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = os.environ.get('TEST_BASE_URL','http://127.0.0.1:8188')
cookies=http.cookiejar.CookieJar(); browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
def request(path, data=None):
    body=None if data is None else urllib.parse.urlencode(data).encode()
    try:
        with browser.open(urllib.request.Request(BASE+path,data=body),timeout=14) as response:
            return response.status,response.url,response.read().decode('utf-8','replace'),dict(response.headers)
    except urllib.error.HTTPError as exc:return exc.code,exc.url,exc.read().decode('utf-8','replace'),dict(exc.headers)
def token(html):
    match=re.search(r'name="csrf"\s+value="([a-f0-9]{64})"',html)
    if not match:raise AssertionError('Missing CSRF input')
    return match.group(1)
def check(test,label):
    if not test:raise AssertionError(label)
    print('PASS',label,flush=True)
status,url,content,headers=request('/install.php')
check(status==200 and 'Set up GameRoot' in content,'First-time installer displayed')
owner={'csrf':token(content),'db_host':os.getenv('TEST_DB_HOST','127.0.0.1'),'db_port':os.getenv('TEST_DB_PORT','3306'),'db_name':os.getenv('TEST_DB_NAME','gameroot_test'),'db_user':os.getenv('TEST_DB_USER','portal'),'db_password':os.getenv('TEST_DB_PASSWORD','portalpass'),'owner_name':'Portal Owner','email':'owner@game.test','owner_password':'owner_password_for_ci_only_!','owner_confirm':'owner_password_for_ci_only_!'}
status,url,content,h=request('/install.php',owner)
check(status==200 and '/admin.php' in url and 'Control Center' in content,'Owner installer finished and signed in')
status,url,content,h=request('/admin.php?tab=integrations')
check(status==200 and all(term in content for term in ('HostGator','OpenAI','Claude')),'All three provider integrations displayed')
key='fake_key_for_CI_not_a_live_credential'
request('/admin.php?tab=integrations',{'csrf':token(content),'action':'save_provider','provider':'openai','credential':key})
status,url,content,h=request('/admin.php?tab=integrations')
check(status==200 and 'Configured' in content and key not in content,'Saved integration key is masked in the owner UI')
request('/admin.php?tab=integrations',{'csrf':token(content),'action':'save_provider','provider':'openai','credential':'','revoke':'1'})
status,url,content,h=request('/admin.php?tab=integrations')
check(status==200 and content.count('Not configured')>=2,'Key revocation works')
request('/logout.php',{'csrf':token(content)})
status,url,content,h=request('/register.php')
check(status==200 and 'Get your game on' in content,'Customer registration page')
customer={'csrf':token(content),'name':'Sample Customer','email':'customer@game.test','password':'customer_secure_password_12345','password_confirm':'customer_secure_password_12345'}
status,url,content,h=request('/register.php',customer)
check(status==200 and '/account.php' in url and 'Sample Customer' in content,'Customer registration and login')
status,url,content,h=request('/admin.php')
check(status==403,'Customer blocked from admin')
status,url,content,h=request('/account.php')
request('/account.php',{'csrf':token(content),'action':'request_installation','project_name':'Friday Fun','slug':'friday-fun'})
status,url,content,h=request('/account.php')
check(status==200 and 'friday-fun' in content and 'Pending' in content,'Pending subdomain request created')
valid_account_content=content
status,url,content,h=request('/account.php',{'action':'request_installation','project_name':'Bad request','slug':'unverified'})
check(status==419,'CSRF rejects unverified request')
request('/logout.php',{'csrf':token(valid_account_content)})
status,url,content,h=request('/login.php')
status,url,content,h=request('/login.php',{'csrf':token(content),'email':owner['email'],'password':owner['owner_password']})
check(status==200 and '/account.php' in url,'Owner login')
status,url,content,h=request('/admin.php?tab=requests')
check(status==200 and 'Friday Fun' in content and 'customer@game.test' in content,'Owner sees customer installation request')
status,url,content,h=request('/index.php')
check(status==200 and 'Turn any night into' in content,'Public landing page loads')
check("default-src 'self'" in h.get('Content-Security-Policy',''),'Security CSP present')
print('HTTP Acceptance passed',flush=True)
