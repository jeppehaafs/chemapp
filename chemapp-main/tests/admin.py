"""Exercise admin sessions and real MySQL writes in an isolated application copy."""
import contextlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request

APP = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
if os.environ.get('CHEMAPP_TEST_DATABASE') != '1':
    raise SystemExit('Set CHEMAPP_TEST_DATABASE=1 only for a disposable test database.')


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def browser():
    jar = http.cookiejar.CookieJar()
    return build_opener(HTTPCookieProcessor(jar), NoRedirect()), jar


def request(client, base, path='/index.php?page=manage', fields=None, expected=200, headers=None, method=None):
    data = urlencode(fields, doseq=True).encode() if fields is not None else None
    req = Request(base + path, data=data, headers=headers or {}, method=method)
    try:
        response = client.open(req, timeout=10)
    except HTTPError as error:
        response = error
    with response:
        text = response.read().decode()
        assert response.status == expected, (response.status, expected, text)
        if 'page=manage' in path:
            assert response.headers['Cache-Control'] == 'no-store'
            assert response.headers['X-Frame-Options'] == 'DENY'
        return text, response.headers


def csrf(html):
    return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)


@contextlib.contextmanager
def server(app, env, subfolder=False):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    if subfolder:
        root = app / 'webroot'
        root.mkdir(exist_ok=True)
        (root / 'band').symlink_to(app / 'public', target_is_directory=True)
        cmd = [PHP, '-S', f'127.0.0.1:{port}', '-t', str(root)]
    else:
        cmd = [PHP, '-S', f'127.0.0.1:{port}', '-t', str(app / 'public'), str(app / 'public/router.php')]
    sessions = app / 'sessions'
    sessions.mkdir(exist_ok=True)
    cmd[1:1] = ['-d', 'session.save_path=' + str(sessions)]
    base = f'http://127.0.0.1:{port}'
    with tempfile.TemporaryFile() as log:
        proc = subprocess.Popen(cmd, cwd=app, env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), .1):
                        break
                except OSError:
                    if proc.poll() is not None:
                        log.seek(0)
                        raise AssertionError(log.read().decode())
                    time.sleep(.05)
            yield base + ('/band' if subfolder else '')
        finally:
            proc.terminate()
            proc.wait(timeout=5)


with tempfile.TemporaryDirectory() as directory:
    app = Path(directory) / 'app'
    shutil.copytree(APP, app, ignore=shutil.ignore_patterns('storage', 'static', 'config.php', '__pycache__'))
    (app / 'public/static').symlink_to(APP / 'public/static', target_is_directory=True)
    env = dict(os.environ, CHEMAPP_CONFIG=str(app / 'absent-config.php'), CHEMAPP_BASE_PATH='')
    password = 'disposable-admin-passphrase'
    client, cookies = browser()
    guest, _ = browser()
    with server(app, env) as base:
        html, _ = request(client, base, headers={'Host': 'public.example', 'X-Forwarded-For': '127.0.0.1'})
        assert 'Admin setup required' in html and 'name="password"' not in html, html
        html, _ = request(client, base)
        assert 'Choose your admin password' in html
        token = csrf(html)
        request(client, base, fields={'action': 'setup', 'password': password, 'password_confirm': password}, expected=403)
        request(client, base, fields={'csrf': token, 'action': 'setup', 'password': 'short', 'password_confirm': 'short'}, expected=400)
        _, headers = request(client, base, fields={'csrf': token, 'action': 'setup', 'password': password, 'password_confirm': password}, expected=303)
        assert headers['Location'] == '/index.php?page=manage'
        state = json.loads((app / 'storage/admin.json').read_text())
        assert password not in json.dumps(state) and state['password_hash'].startswith('$2y$')
        html, headers = request(client, base)
        assert 'Add a show' in html and 'Choose your admin password' not in html
        assert all(cookie.has_nonstandard_attr('HttpOnly') and cookie.get_nonstandard_attr('SameSite') == 'Strict' for cookie in cookies)
        token = csrf(html)
        original = json.loads(request(client, base, '/index.php?page=api%2Fshows')[0])
        guest_html, _ = request(guest, base)
        guest_token = csrf(guest_html)
        show = {'action': 'save', 'csrf': token, 'id': '', 'name': 'Admin test 🎸 <script>alert(1)</script>', 'date': '2028-05-17', 'latitude': '52.02', 'longitude': '6.42', 'link_ticket': 'https://example.com/tickets?x=1&y=2', 'link_img': ''}
        request(guest, base, fields=show | {'csrf': guest_token}, expected=403)
        request(client, base, fields=show | {'csrf': 'wrong'}, expected=403)
        html, _ = request(client, base, fields=show | {'date': '2027-02-29'}, expected=400)
        assert 'Admin test 🎸 &lt;script&gt;' in html
        request(client, base, fields=show | {'latitude': '91'}, expected=400)
        request(client, base, fields=show | {'link_ticket': 'javascript:alert(1)'}, expected=400)
        request(client, base, fields=show | {'id': '999999999999'}, expected=400)
        request(client, base, fields=show | {'id[]': '1'}, expected=400)
        assert json.loads(request(client, base, '/index.php?page=api%2Fshows')[0]) == original
        _, headers = request(client, base, fields=show, expected=303)
        edit_path = headers['Location']
        show_id = int(re.search(r'id=(\d+)', edit_path).group(1))
        html, _ = request(client, base, edit_path)
        assert 'Show added.' in html and '&lt;script&gt;' in html and '<script>alert(1)</script>' not in html
        public = json.loads(request(client, base, '/index.php?page=api%2Fshows')[0])
        created = next(row for row in public if row['id'] == show_id)
        assert created['geometry']['coordinates'] == [6.42, 52.02]
        updated = show | {'id': str(show_id), 'name': 'Changed tour date', 'date': '2029-06-18', 'latitude': '', 'longitude': ''}
        request(client, base, fields=updated, expected=303)
        # Saving an unchanged row is successful too (MySQL may report zero affected rows).
        request(client, base, fields=updated, expected=303)
        public = json.loads(request(client, base, '/index.php?page=api%2Fshows')[0])
        row = next(row for row in public if row['id'] == show_id)
        assert row['name'] == 'Changed tour date' and row['date'] == '2029-06-18' and row['geometry'] is None
        assert len(public) == len(original) + 1
        assert [r for r in public if r['id'] != show_id] == original
        request(client, base, fields=updated | {'id': '4294967295'}, expected=404)
        request(client, base, '/index.php?page=manage&id[]=1', expected=404)
        deletion = {'action': 'delete', 'csrf': token, 'id': str(show_id), 'confirm_delete': str(show_id)}
        request(guest, base, fields=deletion | {'csrf': guest_token}, expected=403)
        request(client, base, fields=deletion | {'csrf': 'wrong'}, expected=403)
        request(client, base, fields=deletion | {'confirm_delete': ''}, expected=400)
        request(client, base, fields=deletion | {'confirm_delete': str(show_id + 1)}, expected=400)
        for invalid_id in ['', '0', '-1', '1 OR 1=1', '999999999999']:
            request(client, base, fields=deletion | {'id': invalid_id}, expected=400)
        request(client, base, fields=deletion | {'id[]': '1'}, expected=400)
        before_delete = json.loads(request(client, base, '/index.php?page=api%2Fshows')[0])
        assert before_delete == public
        # Opening a confirmation page, a forged GET action, or Cancel never deletes data.
        confirm_path = edit_path + '&delete=1'
        html, _ = request(client, base, confirm_path)
        assert 'Delete this show?' in html and 'Changed tour date' in html and '2029-06-18' in html
        assert 'name="confirm_delete"' in html and 'Cancel' in html
        request(client, base, edit_path + '&action=delete&confirm_delete=' + str(show_id))
        html, _ = request(client, base, edit_path)
        assert 'Delete show…' in html
        assert json.loads(request(client, base, '/index.php?page=api%2Fshows')[0]) == public
        _, headers = request(client, base, fields=deletion, expected=303)
        assert headers['Location'] == '/index.php?page=manage'
        html, _ = request(client, base)
        assert 'Show deleted.' in html
        assert json.loads(request(client, base, '/index.php?page=api%2Fshows')[0]) == original
        request(client, base, '/index.php?page=info&id=' + str(show_id), expected=404)
        request(client, base, fields=deletion, expected=404)
        request(client, base, fields={'csrf': token, 'action': 'logout'}, expected=303)
        html, _ = request(client, base)
        assert 'name="password"' in html and 'show-form' not in html
        token = csrf(html)
        request(client, base, fields={'csrf': token, 'action': 'login', 'password': 'wrong-password'}, expected=401)
        request(client, base, fields={'csrf': token, 'action': 'login', 'password': password}, expected=303)
        html, _ = request(client, base)
        assert 'show-form' in html
        # A password reset must revoke existing authenticated sessions.
        result = subprocess.run([PHP, 'bin/set-admin-password.php'], input='replacement-admin-passphrase\nreplacement-admin-passphrase\n', text=True, capture_output=True, cwd=app, env=env)
        assert result.returncode == 0, result.stderr
        html, _ = request(client, base)
        assert 'name="password"' in html and 'show-form' not in html
        token = csrf(html)
        for _ in range(10):
            request(client, base, fields={'csrf': token, 'action': 'login', 'password': 'wrong-password'}, expected=401)
        request(client, base, fields={'csrf': token, 'action': 'login', 'password': 'replacement-admin-passphrase'}, expected=429)
        request(client, base, method='DELETE', expected=405)

    # Reset the isolated admin password and verify cookie/redirect paths in a subfolder.
    subprocess.run([PHP, 'bin/set-admin-password.php'], input=password + '\n' + password + '\n', text=True, capture_output=True, cwd=app, env=env, check=True)
    with server(app, dict(env, CHEMAPP_BASE_PATH='/band'), subfolder=True) as base:
        client, cookies = browser()
        html, _ = request(client, base)
        _, headers = request(client, base, fields={'csrf': csrf(html), 'action': 'login', 'password': password}, expected=303)
        assert headers['Location'] == '/band/index.php?page=manage'
        html, _ = request(client, base)
        assert '/band/static/admin-map.js' in html and '/band/index.php?page=manage' in html
        assert all(cookie.path == '/band/' for cookie in cookies)
    no_db = {key: value for key, value in env.items() if not key.startswith('CHEMAPP_DB_')}
    with server(app, no_db) as base:
        client, _ = browser()
        html, _ = request(client, base)
        request(client, base, fields={'csrf': csrf(html), 'action': 'login', 'password': password}, expected=303)
        html, _ = request(client, base, expected=503)
        assert 'show manager is unavailable' in html and 'PDOException' not in html

print('Admin setup, login, logout, CSRF, authorization, validation, MySQL add/edit/delete, confirmation/cancel, escaping, password reset, throttling and subfolder tests passed.')
