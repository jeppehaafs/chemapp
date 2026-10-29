"""HTTP and CLI migration tests; use only with a disposable empty MySQL database."""
import csv
from datetime import date, timedelta
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

APP = Path(__file__).resolve().parents[1]
if os.environ.get('CHEMAPP_TEST_DATABASE') != '1':
    raise SystemExit('Set CHEMAPP_TEST_DATABASE=1 only for a disposable test database.')
PHP = os.environ.get('PHP_BINARY', 'php')
TOKEN = 'disposable-local-test-token-not-a-real-secret'


def run(*args, ok=True, **kwargs):
    result = subprocess.run([PHP, *map(str, args)], cwd=APP, capture_output=True, text=True, **kwargs)
    if ok and result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return result


def request(base, path, expected=200, method='GET', body=None, authorized=False, content_type='application/json'):
    headers = {'Content-Type': content_type}
    if authorized:
        headers['Authorization'] = 'Bearer ' + TOKEN
    req = Request(base + path, data=body, headers=headers, method=method)
    try:
        response = urlopen(req, timeout=5)
    except HTTPError as error:
        response = error
    with response:
        data = response.read().decode('utf-8')
        assert response.status == expected, (path, response.status, expected, data)
        assert response.headers.get('X-Content-Type-Options') == 'nosniff' or path.endswith('.css')
        return data


class Server:
    def __init__(self, env, docroot=None):
        self.env = env
        self.docroot = docroot or APP / 'public'

    def __enter__(self):
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.base = f'http://127.0.0.1:{port}'
        command = [PHP, '-S', f'127.0.0.1:{port}', '-t', str(self.docroot)]
        if self.docroot == APP / 'public':
            command.append(str(APP / 'public/router.php'))
        self.log = tempfile.TemporaryFile()
        self.process = subprocess.Popen(command, cwd=APP, env=self.env, stdout=self.log, stderr=self.log)
        for _ in range(100):
            try:
                with urlopen(self.base + '/index.php', timeout=0.2):
                    return self.base
            except HTTPError:
                # In a subfolder-only installation the root correctly returns 404.
                return self.base
            except (URLError, TimeoutError):
                if self.process.poll() is not None:
                    self.log.seek(0)
                    raise AssertionError(self.log.read().decode())
                time.sleep(0.05)
        raise AssertionError('PHP server did not start.')

    def __exit__(self, *args):
        self.process.terminate()
        self.process.wait(timeout=5)
        self.log.close()


def map_shows(html):
    embedded = re.search(r'<script type="application/json" id="shows-data">(.*?)</script>', html, re.S).group(1)
    shows = json.loads(embedded)
    # The rendered list and the map must always contain the same selected shows.
    assert re.findall(r'data-id="(\d+)"', html) == [str(show['id']) for show in shows]
    return shows


today = date.fromisoformat(run('-r', 'echo (new DateTimeImmutable("today", new DateTimeZone("Europe/Amsterdam")))->format("Y-m-d");').stdout)
env = dict(os.environ, CHEMAPP_WRITE_TOKEN=TOKEN, CHEMAPP_BASE_PATH='')
with Server(env) as base:
    for path in ['/', '/about_bp', '/music/music_bp', '/mail/mail_bp', '/info_bp', '/map/map_bp']:
        html = request(base, path)
        assert '{%' not in html and '{{' not in html, path
    assert json.loads(request(base, '/database/show')) == [], 'Use an empty disposable test database.'
    assert 'No upcoming shows' in request(base, '/index.php?page=map')
    assert 'No past shows' in request(base, '/index.php?page=map&period=past')
    request(base, '/missing-page', 404)
    request(base, '/index.php?page[]=map', 404)
    request(base, '/index.php?page=info&id=99999', 404)
    request(base, '/index.php?page=info&id[]=1', 404)
    request(base, '/index.php', 405, method='POST', body=b'{}')
    request(base, '/database/show', 405, method='DELETE')
    request(base, '/database/show', 401, method='POST', body=b'{}')
    request(base, '/database/show', 415, method='POST', body=b'{}', authorized=True, content_type='text/plain')
    request(base, '/database/show', 400, method='POST', body=b'{', authorized=True)
    request(base, '/database/show', 400, method='POST', body=b'[]', authorized=True)
    request(base, '/database/show', 413, method='POST', body=b'x' * 65537, authorized=True)
    dangerous_name = '</script><script>alert("Mañana 🎸")</script>'
    show = {'name': dangerous_name, 'date': f'{today.year}-{today.month}-{today.day}', 'geometry': {'type': 'Point', 'coordinates': [6.42, 52.02]}, 'link_ticket': 'https://example.com/tickets?x=1&y=2', 'link_img': ''}
    result = json.loads(request(base, '/database/show', 201, 'POST', json.dumps(show).encode(), True))
    show_id = result['id']
    data = json.loads(request(base, '/index.php?page=api%2Fshows'))
    assert len(data) == 1 and data[0]['name'] == dangerous_name
    assert data[0]['geometry']['coordinates'] == [6.42, 52.02] and data[0]['date'] == today.isoformat()
    html = request(base, '/index.php?page=map')
    assert dangerous_name not in html and '&lt;/script&gt;' in html
    assert 'data-marker-icon="/static/guitar-pick-marker.png"' in html
    with urlopen(base + '/static/guitar-pick-marker.png', timeout=5) as response:
        assert response.status == 200 and response.headers.get_content_type() == 'image/png'
        assert response.read(8) == b'\x89PNG\r\n\x1a\n'
    embedded = re.search(r'<script type="application/json" id="shows-data">(.*?)</script>', html, re.S).group(1)
    assert json.loads(embedded)[0]['name'] == dangerous_name and '<script>' not in embedded
    fixtures = []
    for offset in [2, -2, 1, -1]:
        fixture = show | {'name': f'Tour date {offset}', 'date': (today + timedelta(days=offset)).isoformat()}
        if offset == 1:
            fixture.pop('geometry')  # A show without a map location still belongs in the list.
        fixtures.append(json.loads(request(base, '/database/show', 201, 'POST', json.dumps(fixture).encode(), True))['id'])
    for suffix in ['', '&period=upcoming', '&period=invalid', '&period[]=past', '&period=%27%20OR%201=1']:
        html = request(base, '/index.php?page=map' + suffix)
        selected = map_shows(html)
        assert [row['id'] for row in selected] == [show_id, fixtures[2], fixtures[0]]
        assert selected[1]['latitude'] is None
        assert 'period=upcoming" aria-current="page"' in html
        assert 'period=past" aria-current="page"' not in html
        assert 'due-date' not in html
    html = request(base, '/index.php?page=map&period=past')
    assert [row['id'] for row in map_shows(html)] == [fixtures[3], fixtures[1]]
    assert 'period=past" aria-current="page"' in html and 'due-date' in html
    assert len(json.loads(request(base, '/database/show'))) == 5  # Public API still returns all shows.
    # Keep the original migration fixtures and their expected row counts independent.
    run('-r', 'require "src/bootstrap.php"; database()->exec("DELETE FROM shows WHERE id IN (" . implode(",", json_decode($argv[1], true)) . ")");', json.dumps(fixtures))
    assert 'No past shows' in request(base, '/map/map_bp?period=past')
    html = request(base, f'/index.php?page=info&id={show_id}')
    assert dangerous_name not in html and 'x=1&amp;y=2' in html
    assert 'undefined' not in html
    for change in [{'date': '2027-02-29'}, {'link_ticket': 'javascript:alert(1)'}, {'geometry': {'type': 'Point', 'coordinates': [6, 91]}}]:
        request(base, '/database/show', 400, 'POST', json.dumps(show | change).encode(), True)
    assert len(json.loads(request(base, '/database/show'))) == 1

with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    (tmp / 'band').symlink_to(APP / 'public', target_is_directory=True)
    with Server(dict(env, CHEMAPP_BASE_PATH='/band'), tmp) as base:
        html = request(base, '/band/index.php?page=about')
        assert '/band/static/style.css' in html and '/band/index.php?page=map' in html
        assert 'static\\' not in html
        request(base, '/band/static/style.css')
        html = request(base, '/band/index.php?page=map&period=past')
        assert 'data-marker-icon="/band/static/guitar-pick-marker.png"' in html
        with urlopen(base + '/band/static/guitar-pick-marker.png', timeout=5) as response:
            assert response.status == 200 and response.read(8) == b'\x89PNG\r\n\x1a\n'
        assert '/band/index.php?page=map&amp;period=upcoming' in html
        assert '/band/index.php?page=map&amp;period=past" aria-current="page"' in html
        assert map_shows(html) == []

    source = tmp / 'shows.csv'
    with source.open('w', newline='', encoding='utf-8') as stream:
        writer = csv.writer(stream)
        writer.writerow(['id', 'name', 'date', 'latitude', 'longitude', 'link_ticket', 'link_img'])
        writer.writerow([100, 'Mañana, "Live" \\ 🎸\nFestival', '2028-02-29', 52.02, 6.42, '', ''])
        writer.writerow([101, 'No location', '2027-01-01', '', '', '', ''])
    assert 'Validated 2' in run('bin/import-shows.php', '--dry-run', source).stdout
    converted = run('bin/csv-to-mysql.php', source).stdout
    assert converted.count('INSERT INTO shows') == 2 and "X'" in converted
    assert 'Imported 2' in run('bin/import-shows.php', '--apply', source).stdout
    failure = run('bin/import-shows.php', '--apply', source, ok=False)
    assert failure.returncode != 0 and 'no rows committed' in failure.stderr
    # Execute the phpMyAdmin SQL export against real MySQL too.
    with source.open('w', newline='', encoding='utf-8') as stream:
        writer = csv.writer(stream)
        writer.writerow(['id', 'name', 'date', 'latitude', 'longitude', 'link_ticket', 'link_img'])
        writer.writerow([200, 'SQL export: Mañana, "Live" \\ 🎸', '2028-02-29', 52.02, 6.42, '', ''])
    sql_file = tmp / 'shows.mysql.sql'
    sql_file.write_text(run('bin/csv-to-mysql.php', source).stdout, encoding='utf-8')
    run('-r', 'require "src/bootstrap.php"; database()->exec(file_get_contents($argv[1]));', sql_file)
    with Server(env) as base:
        records = json.loads(request(base, '/database/show'))
        exported = next(row for row in records if row['id'] == 200)
        assert exported['name'] == 'SQL export: Mañana, "Live" \\ 🎸'
        assert exported['date'] == '2028-02-29' and exported['geometry']['coordinates'] == [6.42, 52.02]
    # Fail after a new row followed by an existing ID; the first insert must roll back.
    with source.open('w', newline='', encoding='utf-8') as stream:
        writer = csv.writer(stream)
        writer.writerow(['id', 'name', 'date', 'latitude', 'longitude', 'link_ticket', 'link_img'])
        writer.writerow([102, 'Must roll back', '2027-01-01', '', '', '', ''])
        writer.writerow([100, 'Existing ID', '2027-01-01', '', '', '', ''])
    assert run('bin/import-shows.php', '--apply', source, ok=False).returncode != 0
    with Server(env) as base:
        records = json.loads(request(base, '/database/show'))
        assert len(records) == 4 and all(row['id'] != 102 for row in records)
    with source.open('a', newline='', encoding='utf-8') as stream:
        csv.writer(stream).writerow([102, 'Bad date', '2027-02-30', 0, 0, '', ''])
    failure = run('bin/csv-to-mysql.php', source, ok=False)
    assert failure.returncode != 0 and failure.stdout == ''

# Missing database settings and disabled writes return controlled responses.
no_db = {k: v for k, v in env.items() if not k.startswith('CHEMAPP_DB_')}
no_db.update(CHEMAPP_CONFIG='/nonexistent/chemapp-config.php', CHEMAPP_WRITE_TOKEN='')
with Server(no_db) as base:
    request(base, '/')
    assert 'temporarily unavailable' in request(base, '/map/map_bp', 503)
    assert 'temporarily unavailable' in request(base, '/index.php?page=map&period=past', 503)
    assert 'temporarily unavailable' in request(base, '/database/show', 503)
    request(base, '/database/show', 403, method='POST', body=b'{}')

print('HTTP routes, upcoming/past filters, date boundaries, sorting, list/map parity, subfolder install, JSON API, auth, validation, XSS escaping, DB failure and CSV CLI smoke tests passed.')
