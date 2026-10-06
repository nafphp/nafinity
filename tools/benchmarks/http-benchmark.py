"""Disposable localhost acceptance; public demo credentials, no external services."""
import concurrent.futures, http.cookiejar, json, math, re, ssl, statistics, time, urllib.request, urllib.error
from pathlib import Path
root = Path(__file__).resolve().parents[2]
context = ssl.create_default_context(cafile=str(root / 'docker/rootfs/etc/nginx/ssl/ca.pem'))

class NoRedirect(urllib.request.HTTPRedirectHandler):

    def redirect_request(self, *args, **kwargs):
        return None

class LocalhostCookiePolicy(http.cookiejar.DefaultCookiePolicy):
    """Accept Domain=localhost only for this local test host; retain all other checks."""

    def set_ok_domain(self, cookie, request):
        if urllib.parse.urlsplit(request.full_url).hostname == 'localhost' and cookie.domain in ['localhost', '.localhost']:
            return True
        return super().set_ok_domain(cookie, request)

    def return_ok_domain(self, cookie, request):
        if urllib.parse.urlsplit(request.full_url).hostname == 'localhost' and cookie.domain in ['localhost', '.localhost']:
            return True
        return super().return_ok_domain(cookie, request)

class Session:

    def __init__(self, base, email='alice@example.test'):
        self.base = base
        self.jar = http.cookiejar.CookieJar(policy=LocalhostCookiePolicy())
        self.opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        (status, body, headers) = self.request('/login')
        token = self.token(body)
        (status, body, headers) = self.request('/login', urllib.parse.urlencode({'_csrf': token, 'email': email, 'password': 'Nafinity-Demo-2026!'}).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})
        assert status == 303, (base, status, body[:100])

    def request(self, path, body=None, headers={}):
        try:
            r = self.opener.open(urllib.request.Request(self.base + path, data=body, headers=headers), timeout=30)
        except urllib.error.HTTPError as e:
            r = e
        return (r.code, r.read(), dict(r.headers))

    def token(self, body):
        return re.search(b'name="(?:csrf-token|_csrf)" (?:content|value)="([^"]+)"', body).group(1).decode()

    def json(self, path, data):
        token = self.token(self.request('/projects/1')[1])
        return self.request(path, json.dumps(data).encode(), {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token})

def percentile(v, p):
    return sorted(v)[math.ceil(len(v) * p) - 1]

def sample(client):
    t = time.perf_counter()
    (status, body, headers) = client.request('/projects/1')
    ms = (time.perf_counter() - t) * 1000
    assert status == 200, (status, body[:100])
    headers = {k.lower(): v for (k, v) in headers.items()}
    return {'ms': ms, 'bytes': len(body), 'boot_ms': float(headers['x-naf-probe-boot-ms']), 'queries': int(headers['x-naf-probe-queries']), 'peak_memory_bytes': int(headers['x-naf-probe-memory'])}

def run_benchmark():
    result = {'recorded_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'transport': 'verified localhost HTTPS, PHP-FPM, OPcache enabled, 10 warmups, 50 sequential requests and 4 independent sessions × 30 requests', 'dataset': __import__('os').environ.get('NAF_BENCH_DATASET', 'isolated production seed, 8 tickets, no benchmark bulk inserts'), 'hosts': []}
    for (label, port) in [('production', 38543), ('dev_mount', 38544)]:
        base = f'https://localhost:{port}'
        clients = [Session(base) for _ in range(4)]
        owner = clients[0]
        for _ in range(10):
            sample(owner)
        rows = [sample(owner) for _ in range(50)]

        def workload(client):
            return [sample(client) for _ in range(30)]
        start = time.perf_counter()
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            parallel = sum(list(pool.map(workload, clients)), [])
        elapsed = time.perf_counter() - start
        row = {'host': label, 'requests': 50, 'p50_ms': round(statistics.median((x['ms'] for x in rows)), 2), 'p95_ms': round(percentile([x['ms'] for x in rows], 0.95), 2), 'boot_p50_ms': round(statistics.median((x['boot_ms'] for x in rows)), 3), 'boot_p95_ms': round(percentile([x['boot_ms'] for x in rows], 0.95), 3), 'queries_min_max': [min((x['queries'] for x in rows)), max((x['queries'] for x in rows))], 'response_bytes_min_max': [min((x['bytes'] for x in rows)), max((x['bytes'] for x in rows))], 'peak_memory_bytes': max((x['peak_memory_bytes'] for x in rows)), 'concurrency': 4, 'parallel_requests': len(parallel), 'throughput_rps': round(len(parallel) / elapsed, 2), 'parallel_p50_ms': round(statistics.median((x['ms'] for x in parallel)), 2), 'parallel_p95_ms': round(percentile([x['ms'] for x in parallel], 0.95), 2)}
        if label == 'production':
            bob = Session(base, 'bob@example.test')
            assert bob.request('/projects/1')[0] == 404
            ticket = '/projects/1/tickets/NAF-1'
            token = owner.token(owner.request(ticket)[1])
            boundary = 'naf-production-acceptance'
            content = b'Private production-image acceptance bytes.\n'
            upload = f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n{token}\r\n--{boundary}\r\nContent-Disposition: form-data; name="attachment"; filename="production-probe.txt"\r\nContent-Type: text/plain\r\n\r\n'.encode() + content + f'\r\n--{boundary}--\r\n'.encode()
            assert owner.request(ticket + '/attachments', upload, {'Content-Type': 'multipart/form-data; boundary=' + boundary, 'Accept': 'application/json'})[0] == 200
            page = owner.request(ticket)[1]
            link = re.search(b'href="([^"]+/attachments/\\d+)"', page).group(1).decode()
            (status, body, headers) = owner.request(link)
            assert status == 200 and body == content and ('attachment;' in headers.get('Content-Disposition', ''))
            assert bob.request(link)[0] == 404
            board_page = owner.request('/projects/1')[1]
            filtered_page = owner.request('/projects/1?q=sunflower')[1]
            assert b'data-filtered="1"' in filtered_page
            truncated = b'data-filtered="1"' in board_page
            if '5,000' in result['dataset']:
                assert truncated
            row['acceptance'] = {'login': True, 'foreign_project_404': True, 'private_download_byte_exact': True, 'foreign_download_404': True, 'filtered_drag_lock': True, 'truncated_drag_lock': truncated}
            assert owner.json(link + '/delete', {})[0] == 200
        result['hosts'].append(row)
    print(json.dumps(result, indent=2))
if __name__ == '__main__':
    run_benchmark()
