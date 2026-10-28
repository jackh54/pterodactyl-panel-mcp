# A fake Wings node for the local MCP test: in-memory files (bytes) per server, a console buffer,
# and the endpoints the panel's MCP tools call. Behaviour copies Wings where the tools depend on it
# (copy naming, rename refusing to overwrite, 100-line console window, 404 JSON errors, 502 for
# commands to an offline server). It does not check the node token.
#
#   python3 tests/mcp/fakewings.py <server uuid> [<server uuid> ...]     (listens on 127.0.0.1:8081)
import json, re, sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

FS = {}      # uuid -> {path: bytes}
LOG = {}     # uuid -> [console lines]
STATE = {}
CALLS = []   # backup and reinstall requests the panel made: {server, method, path, body}
FAIL = set() # kinds (backup, restore, delete, reinstall) whose next request answers 500
SERVERS = sys.argv[1:]
NOT_FOUND = {'error': 'The requested resource was not found on the system'}


def fs(u):
    if u not in FS:
        FS[u] = {
            '/server.properties': b'motd=hello\nmax-players=100\nview-distance=8\n',
            '/plugins/Foo/config.yml': b'key: value\ntoken: abc\nkey2: value\n',
            '/logs/latest.log': ''.join(f'[12:00:{i % 60:02d}] line {i}\n' for i in range(1, 3001)).encode(),
            '/bin.dat': b'\x00\x01\x02',
        }
    return FS[u]


def say(u, line):
    LOG.setdefault(u, ['hello']).append(line)
    f = fs(u)
    f['/logs/latest.log'] = f.get('/logs/latest.log', b'') + (line + '\n').encode()


def utilization(uptime):
    return {'memory_bytes': 300 * 1048576, 'cpu_absolute': 42.5, 'disk_bytes': 5 * 1048576, 'uptime': uptime,
            'network': {'rx_bytes': 1, 'tx_bytes': 2}}


class H(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *a):
        pass

    def out(self, code, body=None, raw=None):
        data = raw if raw is not None else (json.dumps(body).encode() if body is not None else b'')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json' if raw is None else 'application/octet-stream')
        self.send_header('Content-Length', str(len(data)))
        self.send_header('X-Request-Id', 'fake')
        self.end_headers()
        self.wfile.write(data)

    def do(self):
        u = urlparse(self.path)
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        n = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(n) if n else b''

        # Test helpers (not Wings): append a console line, rotate the log like a restart, reset everything.
        if u.path == '/_say':
            say(q['server'], q['line']); return self.out(204)
        if u.path == '/_rotate':
            fs(q['server'])['/logs/latest.log'] = b''; LOG[q['server']] = []; return self.out(204)
        if u.path == '/_reset':
            FS.clear(); LOG.clear(); STATE.clear(); CALLS.clear(); FAIL.clear(); return self.out(204)
        if u.path == '/_calls':
            return self.out(200, [c for c in CALLS if c['server'] == q['server']])
        if u.path == '/_fail':
            FAIL.add(q['kind']); return self.out(204)

        if u.path == '/api/servers' and self.command == 'GET':
            return self.out(200, [{'state': STATE.get(k, 'running'), 'is_suspended': False, 'utilization': utilization(60000), 'configuration': {'uuid': k}} for k in SERVERS])
        m = re.match(r'^/api/servers/([0-9a-f-]{36})(/.*)?$', u.path)
        if not m:
            return self.out(404, NOT_FOUND)
        sid, rest = m.group(1), m.group(2) or ''
        if sid not in SERVERS:
            return self.out(404, NOT_FOUND)
        f = fs(sid)
        body = json.loads(raw) if raw and rest != '/files/write' else {}

        if rest == '':
            return self.out(200, {'state': STATE.get(sid, 'running'), 'is_suspended': False, 'utilization': utilization(3723000)})
        if rest == '/logs':
            return self.out(200, {'data': (LOG.get(sid) or ['\x1b[32m[INFO]\x1b[0m Done (3.2s)!', 'hello'])[-int(q.get('size', 100)):]})
        if rest == '/power':
            STATE[sid] = {'start': 'running', 'stop': 'offline', 'kill': 'offline', 'restart': 'running'}[body['action']]; return self.out(204)
        if rest == '/commands':
            if STATE.get(sid) == 'offline':
                return self.out(502, {'error': 'offline'})
            for c in body['commands']:
                say(sid, '> ' + c)
            return self.out(204)
        if rest == '/files/list-directory':
            d = q.get('directory', '/').rstrip('/') + '/'
            names = {}
            for p, c in f.items():
                if p.startswith(d):
                    r = p[len(d):]; nm = r.split('/')[0]
                    names[nm] = {'name': nm, 'file': '/' not in r, 'size': len(c) if '/' not in r else 0, 'modified': '2026-09-30T00:00:00Z', 'created': '2026-09-30T00:00:00Z', 'mode': '-rw', 'mode_bits': '644', 'symlink': False, 'mime': 'text/plain'}
            if not names and d != '/':
                return self.out(404, NOT_FOUND)
            return self.out(200, list(names.values()))
        if rest == '/files/contents':
            p = q['file'] if q['file'].startswith('/') else '/' + q['file']
            if p not in f:
                return self.out(404, NOT_FOUND)
            return self.out(200, raw=f[p])
        if rest == '/files/write':
            p = q['file'] if q['file'].startswith('/') else '/' + q['file']
            f[p] = raw; return self.out(204)
        if rest == '/files/rename':
            for it in body['files']:
                src = (body['root'].rstrip('/') + '/' + it['from']).replace('//', '/')
                dst = (body['root'].rstrip('/') + '/' + it['to']).replace('//', '/')
                if dst in f:
                    return self.out(400, {'error': 'cannot rename: file already exists'})
                moved = {p: c for p, c in f.items() if p == src or p.startswith(src + '/')}
                if not moved:
                    return self.out(404, NOT_FOUND)
                for p, c in moved.items():
                    del f[p]; f[dst + p[len(src):]] = c
            return self.out(204)
        if rest == '/files/delete':
            for nm in body['files']:
                base = (body['root'].rstrip('/') + '/' + nm).replace('//', '/')
                for p in [p for p in f if p == base or p.startswith(base + '/')]:
                    del f[p]
            return self.out(204)
        if rest == '/files/copy':
            loc = body['location'] if body['location'].startswith('/') else '/' + body['location']
            if loc not in f:
                return self.out(404, NOT_FOUND)
            stem, dot, ext = loc.rpartition('.')
            base, suffix = (stem, '.' + ext) if dot and '/' not in ext else (loc, '')
            name, i = f'{base} copy{suffix}', 1
            while name in f:
                i += 1; name = f'{base} copy {i}{suffix}'
            f[name] = f[loc]; return self.out(204)
        if rest == '/files/create-directory':
            f[body['path'].rstrip('/') + '/' + body['name'] + '/.keep'] = b''; return self.out(204)
        if rest == '/files/compress':
            f[body['root'].rstrip('/') + '/archive.tar.gz'] = b'x'; return self.out(200, {'name': 'archive.tar.gz', 'file': True, 'size': 1})
        if rest == '/files/pull':
            name = body.get('file_name') or body['url'].rstrip('/').rsplit('/', 1)[-1]
            f[body['root'].rstrip('/') + '/' + name] = b'pulled'; return self.out(204)
        if rest in ('/files/decompress', '/sync'):
            return self.out(204)
        # Backups and reinstall: Wings answers 202 and works in the background; the test finishes them in the panel's database.
        mb = re.match(r'^/(backup|reinstall)(?:/([0-9a-f-]{36})(/restore)?)?$', rest)
        if mb:
            kind = 'reinstall' if mb.group(1) == 'reinstall' else 'backup' if not mb.group(2) else 'restore' if mb.group(3) else 'delete'
            if kind in FAIL:
                FAIL.discard(kind); return self.out(500, {'error': 'fake wings was told to fail this ' + kind})
            CALLS.append({'server': sid, 'method': self.command, 'path': rest, 'body': body})
            return self.out(204 if kind == 'delete' else 202)
        return self.out(404, NOT_FOUND)

    do_GET = do_POST = do_PUT = do_DELETE = do


ThreadingHTTPServer(('127.0.0.1', 8081), H).serve_forever()
