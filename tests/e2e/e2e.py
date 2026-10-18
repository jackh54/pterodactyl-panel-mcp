# End-to-end test of the Panel MCP server + OAuth server on a LOCAL Pterodactyl v2 dev instance.
# Never point this at production: it creates connections, writes files through the fake Wings node,
# and changes server limits and schedules. See README.md for setup; run.sh does all of it.
import base64, hashlib, hmac, json, os, re, secrets, struct, subprocess, sys, threading, time
from urllib.parse import parse_qs, unquote, urlparse

import requests

HERE = os.path.dirname(os.path.abspath(__file__))
STATE = json.load(open(os.environ.get('MCP_STATE', os.path.join(HERE, '.state.json'))))
B = os.environ.get('PANEL_URL', 'http://127.0.0.1:8181').rstrip('/')
INSTANCE = os.environ.get('PANEL_DIR', os.path.expanduser('~/Documents/GitHub/_v2eval/inst-mcp'))
PHP = os.environ.get('PHP', 'php')
SQL = os.environ.get('MYSQL_CMD', 'docker --context orbstack exec -i v2dev-mariadb mariadb -uroot -pdevroot panel_mcp').split()
JAR = os.environ.get('TEST_JAR', os.path.join(HERE, 'fixtures', 'TestPlugin.jar'))
T = os.environ.get('PANEL_MCP_TABLE_PREFIX', 'ext_panel_mcp_')
# Admin-only read tools other installed extensions add (comma separated), e.g. EXTRA_ADMIN_READ_TOOLS=get_health.
EXTRA = [x for x in os.environ.get('EXTRA_ADMIN_READ_TOOLS', '').split(',') if x]
FIXTURE_TOOLS = ['fixture_echo']  # added by fixtures/extension (the ProvidesTools tag)
GAME_DB = os.environ.get('MCP_SQL_DB', 'mcp_game')
PANEL_DB = os.environ.get('PANEL_DB', 'panel_mcp')
W = 'http://127.0.0.1:8081'
ADMIN, STAFF, TOTP = STATE['users']['admin'], STATE['users']['staff'], STATE['users']['totp']
SECRET_UUID = STATE['servers']['Secret']['uuid']
SMP = STATE['servers']['SMP']['uuid']

ok = fail = 0


def check(name, cond, extra=''):
    global ok, fail
    if cond:
        ok += 1; print('  ok  ', name)
    else:
        fail += 1; print('  FAIL', name, str(extra)[:2000])


def sql(statement):
    return subprocess.run(SQL, input=statement, capture_output=True, text=True, check=True).stdout


def artisan(*args, **kw):
    return subprocess.Popen([PHP, '-d', 'error_reporting=0', 'artisan', *args], cwd=INSTANCE, **kw)


# Activity written before this run is ignored by the activity checks.
A0 = int(sql('SELECT COALESCE(MAX(id), 0) FROM activity_logs;').split()[-1])


def pkce():
    v = secrets.token_urlsafe(48)
    c = base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b'=').decode()
    return v, c


def totp_code(secret):
    key = base64.b32decode(secret.upper() + '=' * (-len(secret) % 8))
    h = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
    o = h[-1] & 0x0f
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


def xsrf(s):
    return {'X-XSRF-TOKEN': unquote(s.cookies['XSRF-TOKEN']), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}


def post(url, **kw):
    # The OAuth machine endpoints are rate limited per IP; wait it out instead of failing the test.
    while True:
        r = requests.post(url, **kw)
        if r.status_code != 429:
            return r
        time.sleep(int(r.headers.get('Retry-After', '5')) + 1)


def login(s, user):
    s.get(B + '/sanctum/csrf-cookie')
    while True:  # the panel allows 10 sign-ins a minute per IP
        r = s.post(B + '/auth/login', json={'user': user['username'], 'password': user['password']}, headers=xsrf(s))
        if r.status_code != 429:
            break
        time.sleep(int(r.headers.get('Retry-After', '10')) + 1)
    data = r.json()['data']
    if not data['complete']:
        r = s.post(B + '/auth/login/checkpoint', json={'confirmation_token': data['confirmation_token'], 'authentication_code': totp_code(user['secret'])}, headers=xsrf(s))
        data = r.json()['data']
    return data


def register(redirect='http://localhost:7777/cb', name='Test <b>App</b>'):
    return post(B + '/oauth/register', json={'client_name': name, 'redirect_uris': [redirect]})


def consent_fields(text):
    return re.search(r'name="rid" value="([^"]+)"', text).group(1), re.search(r'name="_token" value="([^"]+)"', text).group(1)


SESSIONS = {}


def connect(user, scopes, redirect='http://localhost:7777/cb', fresh=False):
    """Registers a client and approves it as `user`. The first time (or with fresh=True) the browser
    is signed out: /oauth/authorize sends it to /auth/login and the login answer must send it back.
    Later calls reuse that signed-in session and land on the consent page directly."""
    cid = register(redirect).json()['client_id']; v, c = pkce()
    q = dict(response_type='code', client_id=cid, redirect_uri=redirect, code_challenge=c, code_challenge_method='S256', state='st1', scope='panel:read panel:control panel:write', resource=B + '/mcp')
    s = None if fresh else SESSIONS.get(user['username'])
    intended = None
    if s is None:
        s = requests.Session()
        r = s.get(B + '/oauth/authorize', params=q, allow_redirects=False)
        assert r.status_code == 302 and r.headers['Location'].endswith('/auth/login'), (r.status_code, r.headers)
        data = login(s, user)
        intended = data['intended']
        assert intended.startswith('/oauth/authorize?'), intended
        r = s.get(B + intended)
        SESSIONS[user['username']] = s
    else:
        r = s.get(B + '/oauth/authorize', params=q, allow_redirects=False)
    assert r.status_code == 200, (r.status_code, r.text[:500])
    rid, tok = consent_fields(r.text)
    r = s.post(B + '/oauth/authorize', data=[('rid', rid), ('_token', tok), ('decision', 'approve')] + [('scopes[]', x) for x in scopes], allow_redirects=False)
    loc = r.headers['Location']; qs = parse_qs(urlparse(loc).query)
    assert loc.startswith(redirect) and qs['state'] == ['st1'] and qs['iss'] == [B], loc
    return s, cid, v, qs['code'][0], redirect, r, (rid, tok), intended


def token(cid, v, code, redirect):
    return post(B + '/oauth/token', data=dict(grant_type='authorization_code', client_id=cid, code=code, redirect_uri=redirect, code_verifier=v))


n = 0


def rpc(tok, method, params=None, notify=False, wait_on_limit=True):
    global n; n += 1
    m = {'jsonrpc': '2.0', 'method': method}
    if params is not None: m['params'] = params
    if not notify: m['id'] = n
    while True:  # each connection may make 120 requests a minute; this test makes more on some
        r = requests.post(B + '/mcp', json=m, headers={'Authorization': 'Bearer ' + tok, 'Accept': 'application/json, text/event-stream'})
        if r.status_code != 429 or not wait_on_limit:
            return r
        time.sleep(int(r.headers.get('Retry-After', '5')) + 1)


def tool(tok, _tool, **args):
    r = rpc(tok, 'tools/call', {'name': _tool, 'arguments': args}).json()['result']
    return r['isError'], r['content'][0]['text']


print('== v2 routing: discovery, stateless machine endpoints')
for path in ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']:
    r = requests.get(B + path); d = r.json()
    check(f'{path}', r.status_code == 200 and d['resource'] == B + '/mcp' and d['authorization_servers'] == [B] and d['scopes_supported'] == ['panel:read', 'panel:control', 'panel:write'], r.text)
for path in ['/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server/mcp']:
    r = requests.get(B + path); d = r.json()
    check(f'{path}', r.status_code == 200 and d['issuer'] == B and d['token_endpoint'] == B + '/oauth/token' and d['registration_endpoint'] == B + '/oauth/register'
          and d['revocation_endpoint'] == B + '/oauth/revoke' and d['authorization_endpoint'] == B + '/oauth/authorize' and d['code_challenge_methods_supported'] == ['S256']
          and d['token_endpoint_auth_methods_supported'] == ['none'] and d['authorization_response_iss_parameter_supported'] is True, r.text)
r = register()
check('register: 201, no cookie, own 20/min limit', r.status_code == 201 and 'Set-Cookie' not in r.headers and r.headers.get('X-RateLimit-Limit') == '20', dict(r.headers))
r = requests.post(B + '/oauth/token', data={'grant_type': 'authorization_code', 'code': 'x', 'client_id': 'y'})
check('token without CSRF token: 400 invalid_grant (not 419), no cookie, own 60/min limit', r.status_code == 400 and r.json()['error'] == 'invalid_grant' and 'Set-Cookie' not in r.headers and r.headers.get('X-RateLimit-Limit') == '60', (r.status_code, dict(r.headers)))
r = requests.post(B + '/oauth/token', data={'grant_type': 'password'})
check('token: unsupported grant type', r.status_code == 400 and r.json()['error'] == 'unsupported_grant_type', r.text)
r = requests.post(B + '/oauth/revoke', data={'token': 'lmcp_nothing'})
check('revoke: 200, no cookie', r.status_code == 200 and 'Set-Cookie' not in r.headers, dict(r.headers))
r = requests.post(B + '/mcp', json={'jsonrpc': '2.0', 'id': 1, 'method': 'ping'})
check('mcp without token: 401 + resource_metadata challenge, no cookie, no root throttle',
      r.status_code == 401 and 'resource_metadata="' + B + '/.well-known/oauth-protected-resource/mcp"' in r.headers.get('WWW-Authenticate', '')
      and 'Set-Cookie' not in r.headers and 'X-RateLimit-Limit' not in r.headers, (r.status_code, dict(r.headers)))
r = requests.post(B + '/oauth/register', data='not json', headers={'Content-Type': 'application/json'})
check('register: bad body', r.status_code == 400 and r.json()['error'] == 'invalid_redirect_uri', r.text)

rm = subprocess.run([PHP, '-d', 'error_reporting=0', 'artisan', 'tinker', '--execute=require "' + os.path.join(HERE, 'route-middleware.php') + '";'], cwd=INSTANCE, capture_output=True, text=True)
check('route middleware: machine routes stateless with their own throttles, pages keep session/CSRF/auth/2FA', 'ROUTE_MIDDLEWARE_OK' in rm.stdout, rm.stdout[-1500:] + rm.stderr[-500:])

print('== discovery / registration')
check('bad redirect scheme rejected', register('javascript:alert(1)').status_code == 400)
check('http non-loopback rejected', register('http://evil.example/cb').status_code == 400)
check('fragment rejected', register('https://claude.ai/cb#x').status_code == 400)
check('userinfo rejected', register('https://user:pw@claude.ai/cb').status_code == 400)
check('app scheme accepted', register('com.example.app:/cb').status_code == 201)
check('backslash in redirect URI rejected', register('https://evil.example\\.claude.ai/cb').status_code == 400)
check('non-ASCII host rejected (punycode accepted)', register('https://bücher.example/cb').status_code == 400 and register('https://xn--bcher-kva.example/cb').status_code == 201)
check('https without a host rejected', register('https:///cb').status_code == 400)
r = register('https://claude.ai/api/mcp/auth_callback'); check('https registered', r.status_code == 201)
check('client name sanitised', '<' not in r.json()['client_name'], r.json()['client_name'])
r = requests.get(B + '/oauth/authorize', params=dict(client_id='nope', redirect_uri='https://x/y'), allow_redirects=False)
check('unauthenticated authorize -> login', r.status_code == 302 and r.headers['Location'] == B + '/auth/login')

print('== full flow (admin, all scopes)')
s, cid, v, code, red, resp, _, intended = connect(ADMIN, ['panel:read', 'panel:control', 'panel:write'])
check('login returned to the consent page (url.intended)', intended.startswith('/oauth/authorize?') and 'client_id=' + cid in intended, intended)
check('consent redirect has no-store', resp.headers.get('Cache-Control', '').startswith('no-store'), dict(resp.headers))
check('wrong verifier refused', token(cid, 'x' * 50, code, red).status_code == 400)
check('code is single use after a failed attempt', token(cid, v, code, red).status_code == 400)
s, cid, v, code, red, resp, _, intended = connect(ADMIN, ['panel:read', 'panel:control', 'panel:write'])
check('wrong client refused', token('other', v, code, red).status_code == 400)
s, cid, v, code, red, resp, _, intended = connect(ADMIN, ['panel:read', 'panel:control', 'panel:write'])
check('wrong redirect_uri refused', token(cid, v, code, 'http://localhost:7777/other').status_code == 400)
s, cid, v, code, red, *_ = connect(ADMIN, ['panel:read', 'panel:control', 'panel:write'])
r = token(cid, v, code, red); t = r.json()
check('token issued', r.status_code == 200 and t['scope'] == 'panel:read panel:control panel:write' and t['token_type'] == 'Bearer' and t['expires_in'] == 3600
      and t['access_token'].startswith('lmcp_') and r.headers.get('Cache-Control', '').startswith('no-store') and r.headers.get('Pragma') == 'no-cache' and 'Set-Cookie' not in r.headers, (r.text, dict(r.headers)))
A = t['access_token']
stored = sql(f"SELECT COUNT(*) FROM {T}oauth_tokens WHERE access_hash = SHA2('{A}', 256) AND refresh_hash = SHA2('{t['refresh_token']}', 256);").split()
check('only SHA-256 hashes stored', stored[-1] == '1' and A not in sql(f'SELECT access_hash, refresh_hash FROM {T}oauth_tokens;'), stored)
r2 = token(cid, v, code, red); check('code replay refused', r2.status_code == 400)
check('code replay revoked the connection', rpc(A, 'ping').status_code == 401)
s, cid, v, code, red, *_ = connect(ADMIN, ['panel:read', 'panel:control', 'panel:write'])
t = token(cid, v, code, red).json(); A = t['access_token']

print('== authorize hardening')
r = s.get(B + '/oauth/authorize', params=dict(response_type='code', client_id=cid, redirect_uri='http://localhost:9999/other', code_challenge='a' * 43, code_challenge_method='S256'), allow_redirects=False)
check('unregistered redirect_uri shows error page, no redirect', r.status_code == 400 and 'Location' not in r.headers)
r = s.get(B + '/oauth/authorize', params=dict(response_type='code', redirect_uri=red), allow_redirects=False)
check('missing client_id shows error page', r.status_code == 400 and 'Location' not in r.headers)
r = s.get(B + '/oauth/authorize', params=dict(response_type='code', client_id=cid, redirect_uri=red, state='z'), allow_redirects=False)
check('missing PKCE refused (error redirect carries iss and state)', r.status_code == 302 and 'error=invalid_request' in r.headers['Location'] and 'state=z' in r.headers['Location']
      and parse_qs(urlparse(r.headers['Location']).query)['iss'] == [B], r.headers.get('Location'))
r = s.get(B + '/oauth/authorize', params=dict(response_type='code', client_id=cid, redirect_uri=red, code_challenge='a' * 43, code_challenge_method='plain'), allow_redirects=False)
check('plain PKCE refused', r.status_code == 302 and 'error=invalid_request' in r.headers['Location'])
r = s.get(B + '/oauth/authorize', params=dict(response_type='token', client_id=cid, redirect_uri=red, code_challenge='a' * 43, code_challenge_method='S256'), allow_redirects=False)
check('implicit flow refused', r.status_code == 302 and 'error=unsupported_response_type' in r.headers['Location'])
r = s.get(B + '/oauth/authorize', params=dict(response_type='code', client_id=cid, redirect_uri=red, code_challenge='a' * 43, code_challenge_method='S256', resource='https://other/mcp'), allow_redirects=False)
check('wrong resource refused', 'error=invalid_target' in r.headers.get('Location', ''))
r = s.post(B + '/oauth/authorize', data={'rid': 'x', 'decision': 'approve'}, allow_redirects=False)
check('consent POST without CSRF token refused', r.status_code == 419, r.status_code)
r = s.post(B + '/oauth/authorize', data={'rid': 'x', 'decision': 'approve'}, headers={'Sec-Fetch-Site': 'cross-site'}, allow_redirects=False)
check('cross-site consent POST refused', r.status_code == 419, r.status_code)
pg = s.get(B + '/oauth/authorize', params=dict(response_type='code', client_id=cid, redirect_uri=red, code_challenge='a' * 43, code_challenge_method='S256', state='dd'))
check('consent page escapes app name, shows destination, no-store', '<b>App</b>' not in pg.text and 'Test bAppb' in pg.text and 'localhost:7777' in pg.text
      and pg.headers.get('Cache-Control', '').startswith('no-store'), pg.text[:300])
check('consent page cannot be framed', pg.headers.get('X-Frame-Options') == 'DENY', dict(pg.headers))
rid, tk = consent_fields(pg.text)
r = s.post(B + '/oauth/authorize', data={'rid': rid, '_token': tk, 'decision': 'deny'}, allow_redirects=False)
check('deny -> access_denied with iss', 'error=access_denied' in r.headers['Location'] and 'state=dd' in r.headers['Location'] and parse_qs(urlparse(r.headers['Location']).query)['iss'] == [B])
r = s.post(B + '/oauth/authorize', data={'rid': rid, '_token': tk, 'decision': 'approve'}, allow_redirects=False)
check('consent request cannot be replayed', r.status_code == 400)

print('== mcp protocol')
r = rpc(A, 'initialize', {'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 't', 'version': '1'}})
d = r.json()
check('initialize', d['result']['protocolVersion'] == '2025-06-18' and 'instructions' in d['result'] and d['result']['serverInfo']['name'] == 'pterodactyl-panel'
      and d['result']['serverInfo']['version'] == '1.0.0' and d['result']['capabilities'] == {'tools': {'listChanged': False}}, d)
ins = d['result']['instructions'].split('\n')
check('instructions not indented', ins[0].startswith('This is a Pterodactyl game panel.') and ins[1].startswith('- If get_ops_guide') and not any(l.startswith(' ') for l in ins), ins[:3])
check('authenticated /mcp sets no cookie and has no root throttle', 'Set-Cookie' not in r.headers and 'X-RateLimit-Limit' not in r.headers, dict(r.headers))
r = rpc(A, 'initialize', {'protocolVersion': '1999-01-01'}).json()
check('unknown protocol version -> newest', r['result']['protocolVersion'] == '2025-11-25', r)
check('notification -> 202', rpc(A, 'notifications/initialized', notify=True).status_code == 202)
tools = [x['name'] for x in rpc(A, 'tools/list').json()['result']['tools']]; check(f'{33 + len(FIXTURE_TOOLS) + len(EXTRA)} tools with all scopes (admin)', len(tools) == 33 + len(FIXTURE_TOOLS) + len(EXTRA), tools)
check('added tools listed after the built-in ones', set(tools[33:]) == set(FIXTURE_TOOLS + EXTRA) and 'Bad-Name' not in tools, tools[30:])
e, x = tool(A, 'list_servers'); check('an added tool cannot replace a built-in one', not e and 'hijacked' not in x, x[:100])
e, x = tool(A, 'fixture_echo', text='hi'); check('a tool added through ProvidesTools runs', not e and x == 'echo: hi (user ' + str(ADMIN['id']) + ')', x)
tl = rpc(A, 'tools/list').json()['result']['tools']
pa = [x for x in tl if x['name'] == 'power_action'][0]
check('tool schema shape', pa['inputSchema']['required'] == ['server', 'action'] and pa['inputSchema']['additionalProperties'] is False
      and pa['inputSchema']['properties']['action']['enum'] == ['start', 'stop', 'restart', 'kill'] and pa['annotations']['destructiveHint'] is True and pa['annotations']['readOnlyHint'] is False, pa)
ls_ = [x for x in tl if x['name'] == 'list_servers'][0]
check('read tool annotations', ls_['annotations']['readOnlyHint'] is True and ls_['annotations']['destructiveHint'] is False, ls_)
check('unknown method', rpc(A, 'nope').json()['error']['code'] == -32601)
r = requests.post(B + '/mcp', json=[{'jsonrpc': '2.0', 'id': 'a', 'method': 'ping'}, {'jsonrpc': '2.0', 'method': 'notifications/x'}, {'jsonrpc': '2.0', 'id': 'b', 'method': 'ping'}], headers={'Authorization': 'Bearer ' + A})
check('batch request', r.status_code == 200 and [x['id'] for x in r.json()] == ['a', 'b'], r.text)
r = requests.post(B + '/mcp', data='{not json', headers={'Authorization': 'Bearer ' + A, 'Content-Type': 'application/json'})
check('parse error', r.status_code == 400 and r.json()['error']['code'] == -32700, r.text)
check('GET /mcp -> 405', requests.get(B + '/mcp').status_code == 405)
check('DELETE /mcp -> 405', requests.delete(B + '/mcp').status_code == 405)
check('bad token -> 401', rpc('lmcp_bad', 'ping').status_code == 401)
check('session cookie alone is not accepted by /mcp', s.post(B + '/mcp', json={'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}).status_code == 401)

print('== tools (admin)')
e, x = tool(A, 'list_servers'); d = json.loads(x); check('list_servers sees 3 with live state', not e and d['count'] == 3 and d['servers'][0]['state'] == 'running' and d['servers'][0]['cpu_percent'] == 42.5, x[:300])
e, x = tool(A, 'list_servers', filter='hub'); d = json.loads(x); check('list_servers filter', not e and d['count'] == 1 and d['servers'][0]['name'] == 'Hub-01', x[:300])
e, x = tool(A, 'get_server', server='SMP'); d = json.loads(x) if not e else {}
check('get_server by name', not e and d['your_permissions'] == 'all' and 'sftp' in d and d['sftp']['username'] == ADMIN['username'] + '.' + STATE['servers']['SMP']['id'], x[:400])
check('get_server startup variables (viewable only)', not e and d['startup_variables'] == {'SERVER_JARFILE': 'server.jar', 'MC_VERSION': '1.21.11'} and d['type'] == 'MCP Test Egg', x[:600])
e, x = tool(A, 'get_server', server=STATE['servers']['SMP']['id']); check('get_server by short id', not e and json.loads(x)['name'] == 'SMP', x[:100])
e, x = tool(A, 'get_server', server=SMP); check('get_server by uuid', not e and json.loads(x)['name'] == 'SMP', x[:100])
e, x = tool(A, 'get_console', server='smp'); check('console, colour codes stripped', not e and '[INFO] Done (3.2s)!' in x and '\x1b' not in x, x)
e, x = tool(A, 'get_console', server='smp', lines='abc'); check('bad integer argument -> clean error', e and 'whole number' in x, x)
e, x = tool(A, 'list_files', server='SMP'); check('list_files', not e and 'plugins/' in x and 'server.properties' in x, x[:200])
e, x = tool(A, 'read_file', server='SMP', path='server.properties'); check('read_file', not e and 'max-players=100' in x, x)
e, x = tool(A, 'read_file', server='SMP', path='logs/latest.log', tail=True, max_lines=3); check('read_file tail', not e and 'line 3000' in x and 'line 2997' not in x and 'lines 2998-3000' in x, x)
e, x = tool(A, 'read_file', server='SMP', path='logs/latest.log', contains='LINE 1234'); check('read_file contains', not e and x.count('\n') == 2 and '1234: ' in x, x)
e, x = tool(A, 'read_file', server='SMP', path='logs/latest.log', max_lines=5000); check('read_file whole 3000 lines', not e and 'line 3000' in x)
e, x = tool(A, 'read_file', server='SMP', path='bin.dat'); check('binary refused', e and 'not text' in x, x)
e, x = tool(A, 'read_file', server='SMP', path='nope.txt'); check('missing file -> clean error', e and 'not found' in x.lower(), x)
e, x = tool(A, 'read_file', server='SMP', path='../etc/passwd'); check('.. refused', e and '..' in x, x)
e, x = tool(A, 'read_file', server='SMP', path='plugins/../../etc/passwd'); check('nested .. refused', e and '..' in x, x)
e, x = tool(A, 'read_file', server='SMP', path=['a']); check('non-string path refused', e and 'not valid' in x, x)
e, x = tool(A, 'write_file', server='SMP', path='server.properties', content='x'); check('write_file refuses to overwrite', e and 'already exists' in x, x)
body = '  leading spaces\n\nkey: ""\ntrailing  \n'
e, x = tool(A, 'write_file', server='SMP', path='plugins/New/a.yml', content=body); check('write_file creates', not e, x)
e, x = tool(A, 'read_file', server='SMP', path='plugins/New/a.yml'); check('content survives byte-for-byte (no trimming)', x.split('\n', 1)[1] == body, repr(x))
e, x = tool(A, 'write_file', server='SMP', path='plugins/New/empty.yml', content=''); check('empty content is written as empty (not null)', not e and '(0 bytes)' in x, x)
e, x = tool(A, 'edit_file', server='SMP', path='plugins/Foo/config.yml', old_text='value', new_text='v2'); check('edit_file ambiguous refused', e and '2 times' in x, x)
e, x = tool(A, 'edit_file', server='SMP', path='plugins/Foo/config.yml', old_text='key: value', new_text='key: changed'); check('edit_file', not e, x)
e, x = tool(A, 'read_file', server='SMP', path='plugins/Foo/config.yml'); check('edit applied, rest untouched', 'key: changed\ntoken: abc\nkey2: value\n' in x, x)
e, x = tool(A, 'copy_file', server='SMP', path='server.properties'); check('copy_file', not e, x)
e, x = tool(A, 'rename_file', server='SMP', **{'from': 'plugins/New/a.yml', 'to': 'plugins/New/b.yml'}); check('rename_file', not e, x)
e, x = tool(A, 'create_folder', server='SMP', path='plugins/Dir'); check('create_folder', not e, x)
e, x = tool(A, 'compress_files', server='SMP', directory='/plugins', names=['Foo']); check('compress_files', not e and 'archive.tar.gz' in x, x)
e, x = tool(A, 'decompress_file', server='SMP', path='plugins/archive.tar.gz'); check('decompress_file', not e, x)
e, x = tool(A, 'pull_file', server='SMP', url='https://example.com/a.jar', directory='/plugins'); check('pull_file', not e, x)
e, x = tool(A, 'list_files', server='SMP', directory='/plugins'); check('pulled file arrived', 'a.jar' in x, x)
e, x = tool(A, 'pull_file', server='SMP', url='file:///etc/passwd'); check('pull_file non-http refused', e, x)
e, x = tool(A, 'delete_files', server='SMP', directory='/plugins/New', names=['b.yml']); check('delete_files', not e, x)
e, x = tool(A, 'list_files', server='SMP', directory='/plugins/New'); check('deleted', e or 'b.yml' not in x, x)
e, x = tool(A, 'delete_files', server='SMP', directory='/plugins', names=['../server.properties']); check('delete with .. refused', e and '..' in x, x)
e, x = tool(A, 'delete_files', server='SMP', directory='/plugins', names=['.']); check('delete of the folder itself refused', e and 'folder itself' in x, x)
e, x = tool(A, 'send_command', server='SMP', command='/say hi', wait_seconds=1); check('send_command returns console', not e and '> say hi' in x, x)
e, x = tool(A, 'send_command', server='SMP', command='a\nb'); check('multi-line command refused', e, x)
e, x = tool(A, 'list_schedules', server='SMP'); sid = json.loads(x)[0]['id']; check('list_schedules', not e and json.loads(x)[0]['tasks'][0]['payload'] == 'say hi', x)
e, x = tool(A, 'run_schedule', server='SMP', schedule_id=sid); check('run_schedule', not e, x)
e, x = tool(A, 'get_console', server='SMP'); check('schedule ran its command', '> say hi' in x.split('\n')[-1] or x.count('> say hi') >= 2, x[-300:])
e, x = tool(A, 'run_schedule', server='SMP', schedule_id=999999); check('run_schedule unknown id', e and 'No schedule' in x, x)
e, x = tool(A, 'power_action', server='SMP', action='stop'); check('power stop', not e, x)
e, x = tool(A, 'get_server', server='SMP'); check('state is fresh after a power action', json.loads(x)['state'] == 'offline', x[:200])
e, x = tool(A, 'send_command', server='SMP', command='say hi'); check('command to offline server -> clean error', e and 'online' in x, x)
e, x = tool(A, 'power_action', server='SMP', action='explode'); check('bad power action', e, x)
e, x = tool(A, 'power_action', server='SMP', action='start'); check('power start', not e, x)
e, x = tool(A, 'nope'); check('unknown tool', e, x)
e, x = tool(A, 'read_file', server='SMP'); check('missing argument', e and 'path' in x, x)

print('== guide, sql (admin)')
e, x = tool(A, 'get_ops_guide'); check('guide overview has ground rules + section list', not e and 'Ground rules' in x and '## Sections' in x and '4. Restarts' in x and len(x) < 30000, x[:200])
e, x = tool(A, 'get_ops_guide', section='4'); check('guide section by number', not e and x.startswith('## 4.') and 'restart-safely' in x and '## 5.' not in x, x[:200])
e, x = tool(A, 'get_ops_guide', section='2b'); check('guide section 2b only', not e and x.startswith('## 2b.') and '## 2c' not in x, x[:100])
e, x = tool(A, 'get_ops_guide', search='somecommands'); check('guide search', not e and 'NEVER run' in x and '## 7.' in x, x[:300])
e, x = tool(A, 'get_ops_guide', section='999'); check('guide unknown section', e, x)
e, x = tool(A, 'query_database', database=GAME_DB, sql='SELECT id, name, ip, blob_col FROM bans ORDER BY id LIMIT 2;'); check('sql select', not e and '2 rows' in x and 'player1' in x and '0x00ff10' in x, x)
e, x = tool(A, 'query_database', database=GAME_DB, sql='SELECT * FROM bans'); check('sql row cap', not e and 'only the first 100' in x, x[:120])
e, x = tool(A, 'query_database', database=GAME_DB, sql='SELECT * FROM bans', max_rows=500); check('sql max_rows 500', not e and x.startswith('500 rows'), x[:120])
e, x = tool(A, 'query_database', database=GAME_DB, sql='SHOW TABLES'); check('sql show tables', not e and 'bans' in x, x)
e, x = tool(A, 'query_database', database=GAME_DB, sql="/* x */ (SELECT COUNT(*) AS n FROM bans)"); check('sql leading comment/paren', not e and '700' in x, x)
for bad, why in [("DELETE FROM bans", 'delete'), ("UPDATE bans SET name='x'", 'update'), ("SELECT 1; DROP TABLE bans", 'stacked'), ("SELECT * FROM bans INTO OUTFILE '/tmp/x'", 'outfile'),
                 ("/* SELECT */ DROP TABLE bans", 'comment trick'), ("SET GLOBAL max_connections=1", 'set'), ("-- SELECT\nDROP TABLE bans", 'line comment trick'),
                 ("WITH x AS (SELECT 1) DELETE FROM bans", 'cte delete (refused by the read-only grant/transaction)'),
                 ("SELECT * FROM bans INTO/**/OUTFILE '/tmp/x'", 'outfile hidden by a comment')]:
    e, x = tool(A, 'query_database', database=GAME_DB, sql=bad); check('sql refuses ' + why, e, x)
e, x = tool(A, 'query_database', database=PANEL_DB, sql='SELECT * FROM users'); check('sql panel db not offered', e and 'must be one of' in x, x)
e, x = tool(A, 'query_database', database=GAME_DB, sql=f'SELECT password FROM {PANEL_DB}.users'); check('sql cross-db to panel denied by grants', e and 'denied' in x.lower(), x)
e, x = tool(A, 'query_database', database=GAME_DB, sql='SELECT SLEEP(30) AS s'); check('sql time limit', (e and 'interrupt' in x.lower()) or '"s": 1' in x, x)
e, x = tool(A, 'query_database', database=GAME_DB, sql='SELECT nope FROM bans'); check('sql error is readable', e and 'nope' in x, x)
e, x = tool(A, 'query_database', database=GAME_DB, sql='SELECT COUNT(*) AS n FROM bans'); check('table untouched', '700' in x, x)
q = sql(f"SELECT JSON_VALUE(properties, '$.database'), JSON_VALUE(properties, '$.mcp_app') IS NULL FROM activity_logs WHERE event = 'user:mcp.query' ORDER BY id DESC LIMIT 1;")
check('sql query logged as user:mcp.query, no app tag', q.split()[-2:] == [GAME_DB, '1'], q)

print('== activity, search')
e, x = tool(A, 'get_activity', server='SMP', event='power'); d = json.loads(x)
check('activity filtered', not e and d and all('power' in r['event'] for r in d) and d[0]['by'] == ADMIN['username'] and 'mcp_app' not in d[0].get('details', {}) and 'ip' in d[0], x[:300])
e, x = tool(A, 'get_activity', server='SMP', event='file.write', limit=1); d = json.loads(x); check('activity limit + file events', not e and len(d) == 1 and d[0]['event'] == 'server:file.write', x[:300])
e, x = tool(A, 'get_activity', server='SMP', event='%'); check('activity filter is not a LIKE wildcard', not e and json.loads(x) == [], x[:200])
e, x = tool(A, 'search_files', server='SMP', query='KEY2'); check('search content', not e and '/plugins/Foo/config.yml:3: key2: value' in x, x)
e, x = tool(A, 'search_files', server='SMP', query='config', match='name'); check('search names', not e and '/plugins/Foo/config.yml' in x, x)
e, x = tool(A, 'search_files', server='SMP', query='line 2999'); check('search skips logs folder by default', not e and x.startswith('0 lines'), x)
e, x = tool(A, 'search_files', server='SMP', query='line 2999', directory='/logs', name_pattern='*.log'); check('search with name_pattern in /logs', not e and 'latest.log:2999' in x, x)
e, x = tool(A, 'search_files', server='SMP', query='x', directory='/nope12'); check('search missing folder', e, x)

print('== settings and schedules')
e, x = tool(A, 'set_startup_variable', server='SMP', key='NOPE', value='x'); check('unknown startup variable', e, x)
e, x = tool(A, 'set_startup_variable', server='SMP', key='HIDDEN_SECRET', value='x'); check('hidden startup variable is unknown', e and 'No startup variable' in x, x)
e, x = tool(A, 'set_startup_variable', server='SMP', key='MC_VERSION', value='1.20'); check('read-only startup variable refused', e and 'read-only' in x, x)
e, x = tool(A, 'set_startup_variable', server='SMP', key='SERVER_JARFILE', value='not a jar'); check('variable rules applied (regex)', e and 'Not accepted' in x, x)
e, x = tool(A, 'set_startup_variable', server='SMP', key='SERVER_JARFILE', value='canvas-794.jar'); check('startup variable set', not e and '"server.jar" -> "canvas-794.jar"' in x, x)
e, x = tool(A, 'get_server', server='SMP'); check('startup variable visible', json.loads(x)['startup_variables']['SERVER_JARFILE'] == 'canvas-794.jar', x[:600])
e, x = tool(A, 'update_server_config', server='SMP', memory_mb=2048, cpu_percent=400); check('limits updated', not e and 'memory: 512 -> 2048' in x and 'cpu: 0 -> 400' in x, x)
e, x = tool(A, 'get_server', server='SMP'); d = json.loads(x); check('limits visible, other limits untouched', d['limits'] == {'memory_mb': 2048, 'disk_mb': 512, 'cpu_percent': 400}, x[:400])
lim = sql(f"SELECT swap, io, backup_limit, IFNULL(database_limit,'null'), IFNULL(allocation_limit,'null') FROM servers WHERE uuid = '{SMP}';").split()
check('feature limits not reset by the build action', lim[-5:] == ['0', '500', '0', '0', 'null'], lim)
e, x = tool(A, 'update_server_config', server='SMP', startup_command='java -Xms1G -Xmx1G -jar server.jar', docker_image='ghcr.io/pterodactyl/yolks:java_21'); check('startup + image updated', not e and 'startup:' in x and 'image:' in x, x)
e, x = tool(A, 'get_server', server='SMP'); d = json.loads(x); check('startup + image visible', d['startup_command'] == 'java -Xms1G -Xmx1G -jar server.jar' and d['docker_image'] == 'ghcr.io/pterodactyl/yolks:java_21', x[:600])
e, x = tool(A, 'update_server_config', server='SMP', startup_command='java -jar *.jar'); check('glob warning', not e and 'Warning' in x, x)
e, x = tool(A, 'update_server_config', server='SMP', startup_command='a\nb'); check('multi-line startup refused', e, x)
e, x = tool(A, 'update_server_config', server='SMP', docker_image='bad image name!'); check('bad image refused', e, x)
e, x = tool(A, 'update_server_config', server='SMP', memory_mb=-5); check('negative limit refused', e, x)
e, x = tool(A, 'update_server_config', server='SMP', memory_mb=2048); check('no-op detected', not e and 'Nothing to change' in x, x)
q = sql("SELECT COUNT(*) FROM activity_logs WHERE event = 'server:mcp.config-update';").split()
check('config updates logged', int(q[-1]) >= 3, q)
e, x = tool(A, 'save_schedule', server='SMP', name='mcp test', minute='*/5', hour='*'); check('schedule created', not e and 'Created' in x, x); nid = int(re.search(r'id (\d+)', x).group(1))
e, x = tool(A, 'save_schedule', server='SMP', name='bad', minute='99', hour='*'); check('bad cron refused', e and 'cron' in x, x)
e, x = tool(A, 'save_schedule', server='SMP', name='only name'); check('new schedule needs timing', e, x)
e, x = tool(A, 'save_schedule', server='SMP', name='x' * 192, minute='0', hour='0'); check('schedule name too long refused', e and '191' in x, x)
e, x = tool(A, 'save_schedule', server='SMP', schedule_id=nid, active=False); check('schedule edited, other fields kept', not e and '*/5 * * * *' in x and 'not active' in x, x)
e, x = tool(A, 'save_schedule_task', server='SMP', schedule_id=nid, action='command', payload='say one'); check('task added', not e and 'step 1' in x, x)
e, x = tool(A, 'save_schedule_task', server='SMP', schedule_id=nid, action='power', payload='restart', delay_seconds=30); check('power task added', not e and 'step 2' in x, x); tid = int(re.search(r'task (\d+)', x).group(1))
e, x = tool(A, 'save_schedule_task', server='SMP', schedule_id=nid, action='power', payload='explode'); check('bad power payload', e, x)
e, x = tool(A, 'save_schedule_task', server='SMP', schedule_id=nid, action='backup'); check('backup task refused at backup limit 0', e and 'backup limit' in x, x)
e, x = tool(A, 'save_schedule_task', server='SMP', schedule_id=nid, action='command', payload='x', delay_seconds=901); check('delay out of range', e, x)
e, x = tool(A, 'save_schedule_task', server='SMP', schedule_id=nid, task_id=tid, delay_seconds=60); check('task edited', not e, x)
e, x = tool(A, 'list_schedules', server='SMP'); sch = [q_ for q_ in json.loads(x) if q_['id'] == nid][0]; check('schedule as expected', [t_['delay_seconds'] for t_ in sch['tasks']] == [0, 60] and sch['tasks'][1]['payload'] == 'restart' and not sch['active'], x)
q = sql("SELECT GROUP_CONCAT(s.subject_type ORDER BY s.subject_type) FROM activity_logs a JOIN activity_log_subjects s ON s.activity_log_id = a.id WHERE a.event = 'server:task.create' GROUP BY a.id ORDER BY a.id DESC LIMIT 1;")
check('task events have server, schedule and task as subjects', all(k in q.lower() for k in ['server', 'schedule', 'task']), q)
e, x = tool(A, 'delete_schedule_task', server='SMP', schedule_id=nid, task_id=tid); check('task deleted', not e, x)
e, x = tool(A, 'delete_schedule', server='SMP', schedule_id=nid); check('schedule deleted', not e, x)
e, x = tool(A, 'list_schedules', server='SMP'); check('schedule gone, original kept', nid not in [q_['id'] for q_ in json.loads(x)] and len(json.loads(x)) == 1, x)
e, x = tool(A, 'delete_schedule', server='Hub-01', schedule_id=sid); check("another server's schedule id is not found", e and 'No schedule' in x, x)

print('== binary files, archives, copy, search, wait')
s_v, cid_v, v_v, code_v, red_v, *_ = connect(ADMIN, ['panel:read', 'panel:control', 'panel:write'])
V = token(cid_v, v_v, code_v, red_v).json()['access_token']
jar = open(JAR, 'rb').read()
e, x = tool(V, 'get_server', server='SMP'); d = json.loads(x); check('get_server has started_at', not e and d['usage']['started_at'] and d['usage']['uptime_seconds'] == 3723, x[:400])
e, x = tool(V, 'upload_file', server='SMP', path='/plugins/TestPlugin.jar', content_base64=base64.b64encode(jar).decode()); check('upload jar', not e and hashlib.sha256(jar).hexdigest() in x, x)
e, x = tool(V, 'upload_file', server='SMP', path='/plugins/TestPlugin.jar', content_base64=base64.b64encode(jar).decode()); check('upload refuses overwrite', e and 'overwrite' in x, x)
e, x = tool(V, 'upload_file', server='SMP', path='/plugins/x.bin', content_base64='not base64!!'); check('upload bad base64', e, x)
e, x = tool(V, 'upload_file', server='SMP', path='/plugins', content_base64='AA=='); check('upload onto folder refused', e, x)
e, x = tool(V, 'upload_file', server='SMP', path='/plugins/big.bin', content_base64=base64.b64encode(b'\0' * (4 * 1024 * 1024 + 1)).decode()); check('upload over 4 MB refused', e and '4 MB' in x, x)
e, x = tool(V, 'download_file', server='SMP', path='/plugins/TestPlugin.jar'); b = base64.b64decode(x.split('\n', 1)[1]); check('download base64 round-trips byte for byte', not e and b == jar, x[:120])
e, x = tool(V, 'download_file', server='SMP', path='/plugins/TestPlugin.jar', **{'as': 'link'}); check('download link', not e and W + '/download/file?token=' in x, x[:200])
jwt = re.search(r'token=([\w-]+\.[\w-]+\.[\w-]+)', x).group(1); claims = json.loads(base64.urlsafe_b64decode(jwt.split('.')[1] + '=='))
check('download token claims', claims['file_path'] == '/plugins/TestPlugin.jar' and claims['server_uuid'] == SMP and claims['scope'] == 'file-download' and claims['exp'] - claims['iat'] <= 15 * 60 + 5, claims)
e, x = tool(V, 'create_upload_link', server='SMP', directory='/plugins'); check('upload link (and its warning)', not e and '/upload/file?token=' in x and 'directory=%2Fplugins' in x and 'curl' in x and 'can be changed' in x, x[:400])
jwt = re.search(r'token=([\w-]+\.[\w-]+\.[\w-]+)', x).group(1); claims = json.loads(base64.urlsafe_b64decode(jwt.split('.')[1] + '=='))
check('upload token claims', claims['server_uuid'] == SMP and claims['scope'] == 'file-upload' and 'file_path' not in claims, claims)
e, x = tool(V, 'inspect_archive', server='SMP', path='/plugins/TestPlugin.jar'); check('archive listing + plugin.yml', not e and 'main: com.example.test.TestPlugin' in x and 'com/example/test/TestPlugin.class' in x, x)
e, x = tool(V, 'inspect_archive', server='SMP', path='/plugins/TestPlugin.jar', entry='plugin.yml'); check('archive text entry', not e and 'api-version' in x, x)
e, x = tool(V, 'inspect_archive', server='SMP', path='/plugins/TestPlugin.jar', entry='com/example/test/TestPlugin.class')
check('class outline', not e and 'public class com.example.test.TestPlugin implements Runnable' in x and 'public static boolean check(String, long, double[])' in x and 'protected int[] counts' in x and 'java.util.List names' in x and 'public TestPlugin()' in x and '"drillcancel.use"' in x and 'Enabling TestPlugin v1.0' in x, x)
e, x = tool(V, 'inspect_archive', server='SMP', path='/plugins/TestPlugin.jar', entry='nope.yml'); check('archive missing entry', e, x)
e, x = tool(V, 'inspect_archive', server='SMP', path='/server.properties'); check('not an archive', e and 'not a zip' in x, x)
e, x = tool(V, 'inspect_archive', server='SMP', path='/plugins/missing.jar'); check('missing archive -> clean error', e and 'not found' in x.lower(), x)
e, x = tool(V, 'copy_file', server='SMP', path='/plugins/TestPlugin.jar', destination='/backups/TestPlugin.jar'); check('copy to destination', not e, x)
e, x = tool(V, 'list_files', server='SMP', directory='/plugins'); names = [q_['name'] for q_ in json.loads(x)['entries']]; check('no stray copy left in /plugins', not any('copy' in n_ for n_ in names), names)
e, x = tool(V, 'download_file', server='SMP', path='/backups/TestPlugin.jar'); check('copy is identical', base64.b64decode(x.split('\n', 1)[1]) == jar, x[:100])
e, x = tool(V, 'copy_file', server='SMP', path='/server.properties', destination='/backups/TestPlugin.jar'); check('copy refuses to overwrite', e and 'overwrite' in x, x)
e, x = tool(V, 'copy_file', server='SMP', path='/server.properties', destination='/backups/TestPlugin.jar', overwrite=True); check('copy overwrite', not e and 'replacing' in x, x)
e, x = tool(V, 'list_files', server='SMP', directory='/backups'); names = [q_['name'] for q_ in json.loads(x)['entries']]; check('overwrite left no parked/old files', names == ['TestPlugin.jar'], names)
e, x = tool(V, 'read_file', server='SMP', path='/backups/TestPlugin.jar'); check('overwrite content is the new file', 'motd=hello' in x, x[:100])
e, x = tool(V, 'copy_file', server='SMP', path='/plugins', destination='/x'); check('copy of folder refused', e, x)
e, x = tool(V, 'copy_file', server='SMP', path='/server.properties'); check('copy without destination still works', not e and 'copy' in x, x)
e, x = tool(V, 'search_files', server='SMP', query='TestPlugin', directory='/plugins'); check('text search lists matching file names (jar)', not e and 'Files whose name contains' in x and '/plugins/TestPlugin.jar' in x, x)
e, x = tool(V, 'search_files', server='SMP', query='testplug', match='name', directory='/', max_depth=1); check('name search with depth', not e and '/plugins/TestPlugin.jar' in x and '/backups/' not in x.split('matching')[0], x)
tool(V, 'copy_file', server='SMP', path='/server.properties', destination='/deep/x/server.properties')
e, x = tool(V, 'search_files', server='SMP', query='server.prop', match='name', directory='/'); check('name search stops after the level with the first match', not e and '/server.properties' in x and '/deep/' not in x and 'exhaustive' in x, x)
e, x = tool(V, 'search_files', server='SMP', query='server.prop', match='name', directory='/', exhaustive=True); check('exhaustive name search goes deeper', not e and '/deep/x/server.properties' in x, x)
e, x = tool(V, 'search_files', server='SMP', query='foo', match='name', directory='/plugins'); check('name search matches folders', not e and '/plugins/Foo/ (folder)' in x, x)
e, x = tool(V, 'search_files', server='SMP', query='key2', directory='/', max_depth=0); check('max_depth 0 stays in the folder', not e and x.startswith('0 lines'), x)


def later(line, delay, rotate=False):
    def f():
        time.sleep(delay)
        if rotate: requests.get(W + '/_rotate', params={'server': SMP})
        requests.get(W + '/_say', params={'server': SMP, 'line': line})
    threading.Thread(target=f, daemon=True).start()


later('[Server thread/INFO]: Enabling TestPlugin v1.0', 3)
t0 = time.time(); e, x = tool(V, 'wait_for_console', server='SMP', text='Enabling TestPlugin', timeout_seconds=20); check('wait sees a new line', not e and 'Seen after' in x and 'Enabling TestPlugin' in x and 2 < time.time() - t0 < 12, x)
e, x = tool(V, 'wait_for_console', server='SMP', text='Enabling TestPlugin', timeout_seconds=5); check('old lines ignored by default', not e and 'Not seen' in x and 'SMP is running' in x, x)
e, x = tool(V, 'wait_for_console', server='SMP', text='Enabling TestPlugin', timeout_seconds=5, include_existing=True); check('include_existing matches old lines', not e and 'Seen after' in x, x)
later('Done (12.345s)! For help, type "help"', 4, rotate=True)
e, x = tool(V, 'wait_for_console', server='SMP', text=r'Done \(\d+\.\d+s\)', regex=True, timeout_seconds=25); check('wait across a log rotation, regex', not e and 'Done (12.345s)' in x, x)
for i in range(150): requests.get(W + '/_say', params={'server': SMP, 'line': f'noise {i}'})
later('needle-after-burst', 1)


def burst():
    time.sleep(1.5)
    for i in range(150): requests.get(W + '/_say', params={'server': SMP, 'line': f'more noise {i}'})


threading.Thread(target=burst, daemon=True).start()
e, x = tool(V, 'wait_for_console', server='SMP', text='needle-after-burst', timeout_seconds=20); check('line that scrolled out of the console is still found (log file)', not e and 'Seen' in x, x)
e, x = tool(V, 'wait_for_console', server='SMP', text='(', regex=True); check('bad regex refused', e, x)
holder = artisan('tinker', '--execute=$l=Cache::lock("panel-mcp:wait:%d",30); $l->get(); sleep(8); $l->release();' % ADMIN['id'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(4)
e, x = tool(V, 'wait_for_console', server='SMP', text='never-2', timeout_seconds=5); holder.wait()
check('only one wait at a time per account', e and 'still running' in x, x)
e, x = tool(V, 'wait_for_console', server='SMP', text='never-3', timeout_seconds=5); check('lock released afterwards', not e and 'Not seen' in x, x)

print('== scopes (admin, read only)')
s2, cid2, v2, code2, red2, *_ = connect(ADMIN, [])
t2 = token(cid2, v2, code2, red2).json(); R = t2['access_token']; check('read-only scope granted', t2['scope'] == 'panel:read', t2)
tl = [x['name'] for x in rpc(R, 'tools/list').json()['result']['tools']]
check('only read tools listed', tl[:13] == ['list_servers', 'get_server', 'get_console', 'list_files', 'read_file', 'list_schedules', 'get_activity', 'search_files', 'download_file', 'inspect_archive', 'wait_for_console', 'get_ops_guide', 'query_database'] and set(tl[13:]) == set(FIXTURE_TOOLS + EXTRA), tl)
e, x = tool(R, 'power_action', server='SMP', action='stop'); check('power refused without control scope', e and 'Control' in x, x)
e, x = tool(R, 'write_file', server='SMP', path='x', content='y'); check('write refused without write scope', e and 'Change files' in x, x)
e, x = tool(R, 'update_server_config', server='SMP', memory_mb=1); check('admin write tool refused without write scope', e and 'Change files' in x, x)
e, x = tool(R, 'read_file', server='SMP', path='server.properties'); check('read still works', not e)
s2b, cid2b, v2b, code2b, red2b, *_ = connect(ADMIN, ['panel:read', 'panel:write', 'bogus:scope'])
t2b = token(cid2b, v2b, code2b, red2b).json(); check('unknown scopes dropped, read always kept', t2b['scope'] == 'panel:read panel:write', t2b)

print('== panel permissions (staff subuser, all scopes)')
s3, cid3, v3, code3, red3, *_ = connect(STAFF, ['panel:read', 'panel:control', 'panel:write'])
S = token(cid3, v3, code3, red3).json()['access_token']
e, x = tool(S, 'list_servers'); d = json.loads(x); check('staff sees only Hub-01', d['count'] == 1 and d['servers'][0]['name'] == 'Hub-01', x)
e, x = tool(S, 'get_server', server='Secret'); check('other server invisible', e and 'No server' in x, x)
e, x = tool(S, 'read_file', server=SECRET_UUID, path='server.properties'); check('other server by uuid invisible', e and 'No server' in x, x)
e, x = tool(S, 'read_file', server='Hub-01', path='server.properties'); check('staff may read', not e, x)
e, x = tool(S, 'write_file', server='Hub-01', path='x.txt', content='y'); check('staff may not write (no file.create)', e and 'file.create' in x, x)
e, x = tool(S, 'edit_file', server='Hub-01', path='server.properties', old_text='motd', new_text='x'); check('staff may not edit (no file.create)', e and 'file.create' in x, x)
e, x = tool(S, 'delete_files', server='Hub-01', names=['server.properties']); check('staff may not delete (no file.delete)', e and 'file.delete' in x, x)
e, x = tool(S, 'rename_file', server='Hub-01', **{'from': 'a', 'to': 'b'}); check('staff may not rename (no file.update)', e and 'file.update' in x, x)
e, x = tool(S, 'compress_files', server='Hub-01', names=['a']); check('staff may not archive (no file.archive)', e and 'file.archive' in x, x)
e, x = tool(S, 'power_action', server='Hub-01', action='stop'); check('staff may not stop (no control.stop)', e and 'control.stop' in x, x)
e, x = tool(S, 'power_action', server='Hub-01', action='start'); check('staff may not start (no control.start)', e and 'control.start' in x, x)
e, x = tool(S, 'run_schedule', server='Hub-01', schedule_id=1); check('staff may not run schedules (no schedule.update)', e and 'schedule.update' in x, x)
e, x = tool(S, 'list_schedules', server='Hub-01'); check('staff may not list schedules (no schedule.read)', e and 'schedule.read' in x, x)
e, x = tool(S, 'set_startup_variable', server='Hub-01', key='SERVER_JARFILE', value='x.jar'); check('staff may not change startup (no startup.update)', e and 'startup.update' in x, x)
e, x = tool(S, 'create_upload_link', server='Hub-01'); check('staff may not get an upload link (no file.create)', e and 'file.create' in x, x)
e, x = tool(S, 'send_command', server='Hub-01', command='list', wait_seconds=0); check('staff may send commands', not e, x)
e, x = tool(S, 'get_console', server='Hub-01'); check('staff may read the console', not e and '> list' in x, x)
e, x = tool(S, 'get_server', server='Hub-01'); check('staff sees own permissions, no startup/sftp', not e and 'startup_command' not in x and 'sftp' not in x and 'control.console' in x, x)
tl3 = [x['name'] for x in rpc(S, 'tools/list').json()['result']['tools']]
check('staff is not offered admin tools', not ({'get_ops_guide', 'query_database', 'update_server_config', *EXTRA} & set(tl3)) and 'search_files' in tl3 and len(tl3) == 30 + len(FIXTURE_TOOLS), tl3)
for nm, kw in [('get_ops_guide', {}), *[(x, {}) for x in EXTRA], ('query_database', dict(database=GAME_DB, sql='SELECT 1')), ('update_server_config', dict(server='Hub-01', memory_mb=1))]:
    e, x = tool(S, nm, **kw); check(f'staff cannot call {nm}', e and 'Unknown tool' in x, x)
e, x = tool(S, 'get_activity', server='Hub-01'); check('staff without activity.read refused', e and 'activity.read' in x, x)
e, x = tool(S, 'save_schedule', server='Hub-01', name='x', minute='0', hour='0'); check('staff without schedule.create refused', e and 'schedule.create' in x, x)
e, x = tool(S, 'search_files', server='Hub-01', query='motd'); check('staff may search', not e and 'server.properties:1' in x, x)
e, x = tool(S, 'upload_file', server='Hub-01', path='/x.bin', content_base64='AA=='); check('staff without file.create cannot upload', e and 'file.create' in x, x)
e, x = tool(S, 'download_file', server='Hub-01', path='/server.properties'); check('staff with read-content can download', not e and 'Base64' in x, x[:80])
e, x = tool(S, 'wait_for_console', server='Hub-01', text='never', timeout_seconds=5); check('staff may wait for the console', not e and 'Not seen' in x, x)

print('== suspended server')
sql(f"UPDATE servers SET status = 'suspended' WHERE uuid = '{SMP}';")
e, x = tool(A, 'read_file', server='SMP', path='server.properties'); check('suspended server: tools refused', e and 'suspended' in x, x)
e, x = tool(A, 'list_servers'); check('suspended server shown as suspended', '"state": "suspended"' in x, x[:400])
sql(f"UPDATE servers SET status = NULL WHERE uuid = '{SMP}';")
e, x = tool(A, 'read_file', server='SMP', path='server.properties'); check('unsuspended server works again', not e, x[:80])

print('== two-factor rule and TOTP sign-in')
s4, cid4, v4, code4, red4, resp4, _, intended4 = connect(TOTP, ['panel:read'], fresh=True)
check('TOTP checkpoint sign-in returns to the consent page', intended4.startswith('/oauth/authorize?') and 'client_id=' + cid4 in intended4, intended4)
T = token(cid4, v4, code4, red4).json()['access_token']
sql("DELETE FROM settings WHERE `key` = 'settings::pterodactyl:auth:2fa_required'; INSERT INTO settings (`key`, value) VALUES ('settings::pterodactyl:auth:2fa_required', '2');")
try:
    r = rpc(S, 'ping'); check('2FA required for all: account without TOTP gets 401', r.status_code == 401 and 'Two-factor' in r.json()['error_description'] and 'error="invalid_token"' in r.headers['WWW-Authenticate'], r.text)
    check('2FA required for all: account with TOTP still works', rpc(T, 'ping').status_code == 200)
    pg = s3.get(B + '/oauth/connections', allow_redirects=False); check('2FA required: browser pages send the account to /account', pg.status_code == 302 and pg.headers['Location'].endswith('/account'), (pg.status_code, pg.headers.get('Location')))
    sql("UPDATE settings SET value = '1' WHERE `key` = 'settings::pterodactyl:auth:2fa_required';")
    check('2FA required for admins: non-admin without TOTP works', rpc(S, 'ping').status_code == 200)
    check('2FA required for admins: admin without TOTP gets 401', rpc(A, 'ping').status_code == 401)
finally:
    sql("DELETE FROM settings WHERE `key` = 'settings::pterodactyl:auth:2fa_required';")
check('2FA rule off again', rpc(A, 'ping').status_code == 200)

print('== per-connection rate limit')
s5, cid5, v5, code5, red5, *_ = connect(ADMIN, ['panel:read'])
L = token(cid5, v5, code5, red5).json()['access_token']
codes = [rpc(L, 'ping', wait_on_limit=False).status_code for _ in range(121)]
r = rpc(L, 'ping', wait_on_limit=False)
check('121st request in a minute -> 429 with Retry-After', codes[:120].count(200) == 120 and codes[120] == 429 and r.status_code == 429 and int(r.headers['Retry-After']) > 0, (codes[-3:], r.headers))
check('other connections are not affected', rpc(A, 'ping').status_code == 200)
s5b, cid5b, v5b, code5b, red5b, *_ = connect(ADMIN, ['panel:read'])
L2 = token(cid5b, v5b, code5b, red5b).json()['access_token']
batch = [{'jsonrpc': '2.0', 'id': i, 'method': 'ping'} for i in range(20)]
codes = [requests.post(B + '/mcp', json=batch, headers={'Authorization': 'Bearer ' + L2}).status_code for _ in range(6)]
check('every message of a batch counts: 6 batches of 20 use up the minute', codes == [200] * 6 and rpc(L2, 'ping', wait_on_limit=False).status_code == 429, codes)

print('== refresh / revoke')
r = post(B + '/oauth/token', data=dict(grant_type='refresh_token', client_id=cid, refresh_token=t['refresh_token'])); nt = r.json()
check('refresh works', r.status_code == 200 and nt['access_token'] != A and nt['refresh_token'] != t['refresh_token'], r.text)
check('old access token dead after refresh', rpc(A, 'ping').status_code == 401)
r_ = rpc(nt['access_token'], 'ping'); check('new access token works', r_.status_code == 200, r_.status_code)
check('refresh with wrong client refused', post(B + '/oauth/token', data=dict(grant_type='refresh_token', client_id='x', refresh_token=nt['refresh_token'])).status_code == 400)
check('old refresh reuse refused (within grace: connection kept)', post(B + '/oauth/token', data=dict(grant_type='refresh_token', client_id=cid, refresh_token=t['refresh_token'])).status_code == 400 and rpc(nt['access_token'], 'ping').status_code == 200)
sql(f"UPDATE {T}oauth_tokens SET rotated_at = NOW() - INTERVAL 2 MINUTE WHERE rotated_at IS NOT NULL;")
check('old refresh reuse after the grace -> refused', post(B + '/oauth/token', data=dict(grant_type='refresh_token', client_id=cid, refresh_token=t['refresh_token'])).status_code == 400)
check('... and the whole connection is revoked', rpc(nt['access_token'], 'ping').status_code == 401)
sql(f"UPDATE {T}oauth_tokens SET access_expires_at = NOW() - INTERVAL 1 SECOND WHERE access_hash = SHA2('%s', 256);" % V)
check('expired access token -> 401', rpc(V, 'ping').status_code == 401)
pg = s2.get(B + '/oauth/connections'); check('connections page lists app', pg.status_code == 200 and 'Remove' in pg.text and 'Test bAppb' in pg.text and B + '/mcp' in pg.text and pg.headers.get('Cache-Control', '').startswith('no-store'))
check('connections page shows granted access', 'Change files and settings' in pg.text and 'Read' in pg.text)
ids = re.findall(r'/oauth/connections/(\d+)/remove', pg.text); tk = re.search(r'name="_token" value="([^"]+)"', pg.text).group(1)
pg3 = s3.get(B + '/oauth/connections'); tk3 = re.search(r'name="_token" value="([^"]+)"', pg3.text).group(1)
s3.post(B + f'/oauth/connections/{ids[0]}/remove', data={'_token': tk3}); check("staff cannot remove admin's connection", rpc(R, 'ping').status_code == 200)
r = s2.post(B + f'/oauth/connections/{ids[0]}/remove', data={}); check('remove without CSRF token refused', r.status_code == 419, r.status_code)
for i in ids: s2.post(B + f'/oauth/connections/{i}/remove', data={'_token': tk})
check('removed connection dead immediately', rpc(R, 'ping').status_code == 401)
pg = s2.get(B + '/oauth/connections'); check('connections page confirms removal', 'The connection was removed.' in pg.text or 'No apps are connected' in pg.text, pg.text[:500])
q = sql("SELECT COUNT(*) FROM activity_logs WHERE event = 'user:mcp.disconnect';").split(); check('disconnect logged', int(q[-1]) >= 1, q)
q = sql("SELECT COUNT(*) FROM activity_logs WHERE event = 'user:mcp.connect';").split(); check('connect logged', int(q[-1]) >= 5, q)
post(B + '/oauth/revoke', data={'token': S}); check('revocation endpoint', rpc(S, 'ping').status_code == 401)
check('anonymous connections page -> login', requests.get(B + '/oauth/connections', allow_redirects=False).headers.get('Location') == B + '/auth/login')

print('== grant cap and cleanup')
before = int(sql(f"SELECT COUNT(*) FROM {T}oauth_grants WHERE user_id = {ADMIN['id']} AND revoked_at IS NULL;").split()[-1])
for _ in range(max(0, 21 - before)):
    connect(ADMIN, [])
active = int(sql(f"SELECT COUNT(*) FROM {T}oauth_grants WHERE user_id = {ADMIN['id']} AND revoked_at IS NULL;").split()[-1])
check('at most 20 active connections per user', active == 20, active)
sql(f"UPDATE {T}oauth_codes SET expires_at = NOW() - INTERVAL 1 SECOND;")
s6, cid6, v6, code6, red6, *_ = connect(ADMIN, [])
sql(f"UPDATE {T}oauth_codes SET expires_at = NOW() - INTERVAL 1 SECOND;")
check('expired code refused', token(cid6, v6, code6, red6).status_code == 400)

print('== activity reads as if the user did it')
since = f'id > {A0}'
q = sql(f"SELECT COUNT(*) FROM activity_logs WHERE {since} AND event LIKE 'server:%' AND actor_id IS NULL;").split()
check('no server activity logged as system', q[-1] == '0', q)
q = sql(f"SELECT COUNT(*) FROM activity_logs WHERE {since} AND JSON_VALUE(properties, '$.mcp_app') IS NOT NULL;").split()
check('no entry carries the app name', q[-1] == '0', q)
q = sql(f"SELECT COUNT(*), MIN(actor_id) FROM activity_logs WHERE {since} AND event = 'server:console.command' AND JSON_VALUE(properties, '$.command') LIKE '%say hi';").split()
check('a console command is logged once, as the user', q[-2:] == ['1', str(ADMIN['id'])], q)
q = sql(f"SELECT COUNT(*), MIN(actor_id) FROM activity_logs WHERE {since} AND event = 'server:power.stop';").split()
check('a power action is logged once, as the user', q[-2:] == ['1', str(ADMIN['id'])], q)
q = sql(f"SELECT COUNT(*), MAX(JSON_VALUE(properties, '$.sha256') IS NOT NULL) FROM activity_logs WHERE {since} AND event = 'server:file.write' AND JSON_VALUE(properties, '$.file') LIKE '%TestPlugin.jar';").split()
check('an upload is logged once and keeps its sha256', q[-2:] == ['1', '1'], q)
for event, where, n in [('server:file.create-directory', "JSON_VALUE(properties, '$.name') = 'Dir'", 1),
                         ('server:file.delete', "JSON_EXTRACT(properties, '$.files') LIKE '%b.yml%'", 1),
                         ('server:schedule.create', "JSON_VALUE(properties, '$.name') = 'mcp test'", 1),
                         ('server:task.create', '1', 2)]:
    q = sql(f"SELECT COUNT(*) FROM activity_logs WHERE {since} AND event = '{event}' AND {where};").split()
    check(f'{event} logged once per action', q[-1] == str(n), q)

# Checks for tools other extensions add, run in this file's context (tool(), connect(), check(), ...).
for extra in [x for x in os.environ.get('E2E_EXTRA_CHECKS', '').split(os.pathsep) if x]:
    print('== extra checks: ' + os.path.basename(extra))
    exec(open(extra).read())

print(f'\n{ok} passed, {fail} failed'); sys.exit(1 if fail else 0)
