"""Disposable localhost acceptance; public demo credentials, no external services."""
import concurrent.futures, json, re, statistics, subprocess, threading, time
from pathlib import Path
import importlib.util
spec = importlib.util.spec_from_file_location('naf_http', Path(__file__).with_name('http-benchmark.py'))
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
(Session, percentile, root) = (http.Session, http.percentile, http.root)
compose = ['docker', 'compose', '-f', str(root / 'tools/benchmarks/compose.production.yaml')]
base = 'https://localhost:38543'
clients = [Session(base) for _ in range(2)]

def sql(query):
    code = '$pdo=new PDO("mysql:host=db;dbname=".getenv("DB_DATABASE"),getenv("DB_USERNAME"),getenv("DB_PASSWORD")); if(getenv("DB_DATABASE")!=="nafinity_probe")throw new RuntimeException("Wrong probe database"); echo json_encode($pdo->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));'
    r = subprocess.run(compose + ['exec', '-T', 'app', 'php', '-r', code, query], check=True, capture_output=True, text=True)
    return json.loads(r.stdout)

def state(ref):
    (status, page, _) = clients[0].request('/projects/1/tickets/' + ref + '?fragment=1')
    assert status == 200
    return {'version': int(re.search(b'data-version="(\\d+)"', page).group(1)), 'board_revision': int(re.search(b'data-revision="(\\d+)"', page).group(1))}
headers = []
for client in clients:
    headers.append({'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': client.token(client.request('/projects/1')[1])})

def move(client, index, ref, data):
    start = time.perf_counter()
    (status, body, head) = client.request('/projects/1/tickets/' + ref + '/move', json.dumps(data).encode(), headers[index])
    ms = (time.perf_counter() - start) * 1000
    return {'status': status, 'ms': ms, 'queries': int(head.get('X-Naf-Probe-Queries', '0'))}
rows = []
before = int(sql("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_time'")[0]['Value'])
for run in range(20):
    data = state('NAF-1')
    barrier = threading.Barrier(2)

    def contender(index):
        barrier.wait()
        return move(clients[index], index, 'NAF-1', {**data, 'column_id': index + 1, 'swimlane_id': 1})
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        pair = list(pool.map(contender, range(2)))
    assert sorted((x['status'] for x in pair)) == [200, 409], pair
    rows += pair
after = int(sql("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_time'")[0]['Value'])
data = {**state('NAF-1'), 'column_id': 2, 'swimlane_id': 1}
code = '$p=new PDO("mysql:host=db;dbname=nafinity_probe",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->beginTransaction();$p->query("SELECT id FROM projects WHERE id=1 FOR UPDATE");echo "held\\n";flush();usleep(250000);$p->rollBack();'
holder = subprocess.Popen(compose + ['exec', '-T', 'app', 'php', '-r', code], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
assert holder.stdout.readline().strip() == 'held'
held = move(clients[0], 0, 'NAF-1', data)
assert holder.wait() == 0 and held['status'] == 200 and (held['ms'] > 180), held
owned = sql("SELECT id,number,title FROM tickets WHERE project_id=1 AND title IN ('Rebalance measurement 0','Rebalance measurement 1','Rebalance measurement 2') ORDER BY title")
refs = []
if len(owned) == 3:
    refs = ['NAF-' + str(row['number']) for row in owned]
else:
    for i in range(3):
        revision = json.loads(clients[0].request('/projects/1/state')[1])['revision']
        status, body, _ = clients[0].request('/projects/1/tickets', json.dumps({'title': 'Rebalance measurement ' + str(i), 'description': 'Disposable measurement fixture', 'priority': 'normal', 'column_id': 1, 'swimlane_id': 1, 'board_revision': revision}).encode(), headers[0])
        assert status == 200, body
        refs.append(json.loads(body)['id'])
ids = [int(sql('SELECT id FROM tickets WHERE project_id=1 AND number=' + str(int(ref.split('-')[-1])))[0]['id']) for ref in refs]
cell_cards = int(sql('SELECT COUNT(*) AS total FROM tickets WHERE project_id=1 AND column_id=1 AND swimlane_id=1')[0]['total'])
dense_rows = []
for _ in range(5):
    sql('UPDATE tickets SET position=1 WHERE project_id=1 AND id=' + str(ids[0]))
    sql('UPDATE tickets SET position=2 WHERE project_id=1 AND id=' + str(ids[1]))
    dense = move(clients[0], 0, refs[2], {**state(refs[2]), 'column_id': 1, 'swimlane_id': 1, 'placement': 'between', 'left_id': ids[0], 'right_id': ids[1]})
    assert dense['status'] == 200, dense
    dense_rows.append(dense)
order = sql('SELECT id,position FROM tickets WHERE id IN (' + ','.join(map(str, ids)) + ') ORDER BY position')
assert [int(x['id']) for x in order] == [ids[0], ids[2], ids[1]], order
result = {'recorded_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'dataset': 'isolated 5,000-card bulk cell plus seed and three owned rebalance cards', 'same_version_pairs': 20, 'requests': 40, 'successes': 20, 'stale_conflicts': 20, 'forced_conflict_rate': 0.5, 'note': 'Deliberately identical stale versions, not an estimate of natural user conflicts. Global InnoDB wait counter is shared with the isolated idle worker.', 'move_p50_ms': round(statistics.median((x['ms'] for x in rows)), 2), 'move_p95_ms': round(percentile([x['ms'] for x in rows], 0.95), 2), 'innodb_row_lock_wait_delta_ms': after - before, 'controlled_250_ms_holder_response_ms': round(held['ms'], 2), 'forced_rebalance_response_ms': round(dense['ms'], 2), 'forced_rebalance_sql_statements': dense['queries'], 'unique_requested_order': True}
result.update({'rebalance_cell_cards': cell_cards, 'rebalance_runs': 5, 'rebalance_p50_ms': round(statistics.median(row['ms'] for row in dense_rows), 2), 'rebalance_p95_ms': round(percentile([row['ms'] for row in dense_rows], .95), 2), 'rebalance_queries_min_max': [min(row['queries'] for row in dense_rows), max(row['queries'] for row in dense_rows)], 'rebalance_samples': dense_rows})
print(json.dumps(result, indent=2))
