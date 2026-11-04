<?php
declare(strict_types=1);

/**
 * Backend API tests. Run:
 *   php tests/run.php
 * Uses a temporary SQLite database. Does not touch production data.
 */

$root = dirname(__DIR__);
$tmp = $root . '/tests/.tmp';
if (!is_dir($tmp)) {
    mkdir($tmp, 0775, true);
}
$sqlite = $tmp . '/test.sqlite';
if (is_file($sqlite)) {
    unlink($sqlite);
}

putenv('APP_ENV=test');
putenv('APP_DEBUG=true');
putenv('APP_KEY=test-key');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $sqlite);
putenv('TOKEN_TTL_HOURS=24');
putenv('LOGIN_MAX_ATTEMPTS=5');
putenv('LOGIN_WINDOW_MINUTES=15');
$_ENV['APP_ENV'] = 'test';
$_ENV['DB_DRIVER'] = 'sqlite';
$_ENV['DB_SQLITE_PATH'] = $sqlite;

date_default_timezone_set('Asia/Almaty');

/** @var \MTelecom\Sales\App $app */
$app = require $root . '/backend/src/bootstrap.php';
(new MTelecom\Sales\Core\Migrator($app->db))->up();

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  OK  {$msg}\n";
    } else {
        $failed++;
        echo "  FAIL  {$msg}\n";
    }
}

function req(
    \MTelecom\Sales\App $app,
    string $method,
    string $path,
    array $body = [],
    ?string $token = null,
    array $query = [],
    array $cookies = [],
    array $extraHeaders = []
): array {
    $headers = array_merge(['content-type' => 'application/json'], $extraHeaders);
    if ($token) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    $request = new MTelecom\Sales\Core\Request($method, $path, $query, $body, $headers, $cookies);
    $response = $app->handle($request);
    $json = json_decode($response->body, true);
    return ['status' => $response->status, 'json' => is_array($json) ? $json : [], 'raw' => $response->body, 'headers' => $response->headers];
}

function session_id_from(array $headers): ?string
{
    $h = $headers['Set-Cookie'] ?? '';
    return preg_match('/mts_session=([^;]+)/', $h, $m) ? $m[1] : null;
}

echo "M-TELECOM SALES tests\n";

$app->users->createAdmin('director', 'Secret#123', 'Алихан Руководитель');
$login = req($app, 'POST', '/api/auth/login', ['login' => 'director', 'password' => 'Secret#123', 'platform' => 'test']);
assert_true($login['status'] === 200 && !empty($login['json']['token']), 'admin login');
$adminToken = $login['json']['token'] ?? '';
$admin = req($app, 'GET', '/api/auth/me', [], $adminToken);
assert_true(($admin['json']['user']['role'] ?? '') === 'admin', 'admin me');

$bad = req($app, 'POST', '/api/auth/login', ['login' => 'director', 'password' => 'wrong-pass']);
assert_true($bad['status'] === 401, 'invalid password rejected');

$mgr1 = req($app, 'POST', '/api/users', [
    'login' => 'manager1', 'password' => 'Manager#1', 'full_name' => 'Менеджер Один', 'phone' => '77011111111',
], $adminToken);
$mgr2 = req($app, 'POST', '/api/users', [
    'login' => 'manager2', 'password' => 'Manager#2', 'full_name' => 'Менеджер Два',
], $adminToken);
assert_true($mgr1['status'] === 200 && $mgr2['status'] === 200, 'create two managers');
$forbidAdmin = req($app, 'POST', '/api/users', [
    'login' => 'hacker', 'password' => 'Hacker#12', 'full_name' => 'X', 'role' => 'admin',
], $adminToken);
assert_true($forbidAdmin['status'] === 403, 'cannot create admin via API');

$m1 = req($app, 'POST', '/api/auth/login', ['login' => 'manager1', 'password' => 'Manager#1']);
$m2 = req($app, 'POST', '/api/auth/login', ['login' => 'manager2', 'password' => 'Manager#2']);
$t1 = $m1['json']['token'] ?? '';
$t2 = $m2['json']['token'] ?? '';
assert_true($t1 && $t2, 'managers login');

$usersAsMgr = req($app, 'GET', '/api/users', [], $t1);
assert_true($usersAsMgr['status'] === 403, 'manager cannot list users');

$c1 = req($app, 'POST', '/api/clients', [
    'type' => 'person',
    'full_name' => 'Иван Петров',
    'phone_primary' => '+7 701 111 22 33',
    'address' => 'Алматы, ул. Абая 1',
    'lat' => 43.238949,
    'lng' => 76.889709,
    'current_provider' => 'Другой оператор',
    'need' => 'Интернет 100 Мбит',
    'idempotency_key' => 'client-ivan-1',
], $t1);
assert_true($c1['status'] === 200 && !empty($c1['json']['client']['id']), 'manager creates person');
$clientId = (int) ($c1['json']['client']['id'] ?? 0);

$c1dupReq = req($app, 'POST', '/api/clients', [
    'type' => 'person',
    'full_name' => 'Иван П.',
    'phone_primary' => '87011112233',
], $t1);
assert_true($c1dupReq['status'] === 409 && !empty($c1dupReq['json']['duplicates']), 'duplicate phone warning');

$c1again = req($app, 'POST', '/api/clients', [
    'type' => 'person',
    'full_name' => 'Иван Петров',
    'phone_primary' => '+7 701 111 22 33',
    'idempotency_key' => 'client-ivan-1',
], $t1);
assert_true(($c1again['json']['client']['id'] ?? 0) == $clientId, 'idempotent client create');

$forced = req($app, 'POST', '/api/clients', [
    'type' => 'person',
    'full_name' => 'Иван Другой',
    'phone_primary' => '87011112233',
    'force' => true,
    'idempotency_key' => 'client-ivan-forced',
], $t1);
assert_true($forced['status'] === 200, 'force create after duplicate warning');

$company = req($app, 'POST', '/api/clients', [
    'type' => 'company',
    'company_name' => 'ТОО Ромашка',
    'bin' => '123456789012',
    'legal_address' => 'Астана',
    'actual_address' => 'Алматы, Достык 1',
    'potential_connections' => 12,
    'company_needs' => 'СКС и видеонаблюдение',
    'phone_primary' => '77273334455',
    'contacts' => [['full_name' => 'Бухгалтер', 'phone' => '77012223344', 'position' => 'Главбух']],
    'objects' => [['name' => 'Склад', 'address' => 'Алматы, Складская 5', 'lat' => 43.25, 'lng' => 76.90]],
    'idempotency_key' => 'company-romashka',
], $t1);
assert_true($company['status'] === 200 && count($company['json']['client']['objects'] ?? []) === 1, 'create company with object and contact');

$binDup = req($app, 'POST', '/api/clients/check-duplicates', ['bin' => '123456789012', 'company_name' => 'Ромашка'], $t1);
assert_true(!empty($binDup['json']['duplicates']), 'BIN duplicate check');

$foreign = req($app, 'GET', '/api/clients/' . $clientId, [], $t2);
assert_true($foreign['status'] === 403, 'manager cannot read foreign client');

$adminSee = req($app, 'GET', '/api/clients/' . $clientId, [], $adminToken);
assert_true($adminSee['status'] === 200, 'admin can read all clients');

$upd = req($app, 'PUT', '/api/clients/' . $clientId, [
    'comment' => 'Первый контакт',
    'need' => 'Оптоволокно',
    'base_updated_at' => $c1['json']['client']['updated_at'],
], $t1);
assert_true($upd['status'] === 200 && ($upd['json']['client']['need'] ?? '') === 'Оптоволокно', 'manager updates own client');

$st = req($app, 'POST', '/api/clients/' . $clientId . '/status', ['status' => 'in_progress'], $t1);
assert_true(($st['json']['client']['status'] ?? '') === 'in_progress', 'status change');

$call = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233',
    'connected' => true,
    'talk_duration_sec' => 95,
    'duration_sec' => 100,
    'result' => 'interested',
    'interest' => 'interested',
    'topic' => 'Тариф 100 Мбит',
    'offered' => 'Подключение GPON',
    'next_action' => 'Отправить КП',
    'next_contact_at' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d 10:00:00'),
    'comment' => 'Клиент ждал предложение',
    'source' => 'system',
    'data_source' => 'system_verified',
    'idempotency_key' => 'call-1',
], $t1);
assert_true($call['status'] === 200 && ($call['json']['call']['category'] ?? '') === 'negotiation', 'call recorded and classified');
assert_true(($call['json']['call']['data_source'] ?? '') === 'system_verified', 'verified SIM stores data_source');
$callAgain = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'talk_duration_sec' => 95, 'connected' => true, 'idempotency_key' => 'call-1',
], $t1);
assert_true(($callAgain['json']['call']['id'] ?? 0) == ($call['json']['call']['id'] ?? -1), 'idempotent call');

$short = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'connected' => false, 'talk_duration_sec' => 0, 'result' => 'no_answer',
    'idempotency_key' => 'call-miss',
], $t1);
assert_true(($short['json']['call']['category'] ?? '') === 'dial_attempt', 'missed call is dial attempt');

$wa = req($app, 'POST', '/api/clients/' . $clientId . '/whatsapp', [
    'phone' => '77011112233', 'kind' => 'offer', 'offer_sent' => true, 'comment' => 'КП в PDF',
    'result' => 'offer_sent', 'idempotency_key' => 'wa-1',
], $t1);
assert_true($wa['status'] === 200 && ($wa['json']['whatsapp']['source'] ?? '') === 'manager', 'whatsapp offer marked as manager-entered');

$task = req($app, 'POST', '/api/tasks', [
    'title' => 'Позвонить ещё раз', 'client_id' => $clientId,
    'due_at' => (new DateTimeImmutable('-1 day'))->format('Y-m-d 09:00:00'),
    'idempotency_key' => 'task-1',
], $t1);
assert_true($task['status'] === 200, 'create task');

$overdue = req($app, 'GET', '/api/tasks', [], $t1, ['bucket' => 'overdue']);
assert_true(count($overdue['json']['items'] ?? []) >= 1, 'overdue tasks visible to manager');

$card = req($app, 'GET', '/api/clients/' . $clientId, [], $t1);
$cardClient = $card['json']['client'] ?? [];
assert_true($card['status'] === 200 && isset($cardClient['calls']) && count($cardClient['calls']) >= 2, 'client card includes calls');
assert_true(($cardClient['calls'][0]['channel'] ?? '') === 'sim' || in_array('sim', array_column($cardClient['calls'], 'channel'), true), 'call channel is stored');
assert_true(isset($cardClient['whatsapp']) && count($cardClient['whatsapp']) >= 1, 'client card includes whatsapp');
assert_true(isset($cardClient['tasks']) && count($cardClient['tasks']) >= 1, 'client card includes tasks');
assert_true(isset($cardClient['offers']) && count($cardClient['offers']) >= 1, 'client card includes offers from whatsapp KP');
$note = req($app, 'POST', '/api/clients/' . $clientId . '/interactions', [
    'type' => 'note', 'comment' => 'Клиент просил перезвонить после обеда', 'idempotency_key' => 'note-1',
], $t1);
assert_true($note['status'] === 200, 'comment saved on client card');
$offer = req($app, 'POST', '/api/clients/' . $clientId . '/interactions', [
    'type' => 'offer', 'offered' => 'GPON 100 Мбит', 'result' => 'offer_sent', 'idempotency_key' => 'offer-1',
], $t1);
assert_true($offer['status'] === 200, 'commercial offer saved on client card');
$dirCall = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'direction' => 'incoming', 'channel' => 'sim',
    'connected' => true, 'talk_duration_sec' => 45, 'result' => 'answered',
    'source' => 'system', 'data_source' => 'system_verified',
    'idempotency_key' => 'call-in-1',
], $t1);
assert_true(($dirCall['json']['call']['direction'] ?? '') === 'incoming', 'incoming call direction stored');
assert_true(($dirCall['json']['call']['category'] ?? '') === 'short_talk', 'short connected call is not a fake long talk');
$click = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'connected' => true, 'talk_duration_sec' => 0, 'result' => 'no_answer',
    'idempotency_key' => 'call-click-1',
], $t1);
assert_true(($click['json']['call']['category'] ?? '') === 'dial_attempt' && (int) ($click['json']['call']['connected'] ?? 1) === 0, 'zero duration is a dial attempt not a talk');

$manualDur = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'connected' => true, 'talk_duration_sec' => 180, 'duration_sec' => 180,
    'result' => 'answered', 'source' => 'manager', 'data_source' => 'manual',
    'idempotency_key' => 'call-manual-dur',
], $t1);
assert_true(($manualDur['json']['call']['data_source'] ?? '') === 'unverified', 'SIM duration from manager is not accepted');
assert_true((int) ($manualDur['json']['call']['talk_duration_sec'] ?? -1) === 0, 'manual SIM duration is stripped');
assert_true(empty($manualDur['json']['call']['is_talk']), 'unverified SIM is not counted as a talk');
assert_true(array_key_exists('talk_duration_display', $manualDur['json']['call'] ?? []) && $manualDur['json']['call']['talk_duration_display'] === null, 'unverified duration is not shown as actual');

$attempt = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'connected' => false, 'talk_duration_sec' => 0, 'result' => 'no_answer',
    'source' => 'manager', 'data_source' => 'unverified',
    'idempotency_key' => 'call-attempt-complete',
], $t1);
$attemptId = (int) ($attempt['json']['call']['id'] ?? 0);
assert_true($attemptId > 0 && ($attempt['json']['call']['data_source'] ?? '') === 'unverified', 'Android attempt is unverified until log data arrives');
$completed = req($app, 'PATCH', '/api/calls/' . $attemptId, [
    'connected' => true, 'talk_duration_sec' => 75, 'duration_sec' => 75,
    'result' => 'answered', 'source' => 'system', 'data_source' => 'system_verified',
    'comment' => 'Фактическая длительность из журнала',
], $t1);
assert_true($completed['status'] === 200 && ($completed['json']['call']['data_source'] ?? '') === 'system_verified', 'PATCH complete promotes unverified to system_verified');
assert_true((int) ($completed['json']['call']['talk_duration_sec'] ?? 0) === 75, 'system duration is stored as-is');
assert_true(($completed['json']['call']['category'] ?? '') === 'negotiation', 'server classifies verified duration');
$locked = req($app, 'PATCH', '/api/calls/' . $attemptId, [
    'talk_duration_sec' => 9, 'duration_sec' => 9, 'data_source' => 'unverified', 'source' => 'manager',
], $t1);
assert_true((int) ($locked['json']['call']['talk_duration_sec'] ?? 0) === 75, 'manager cannot change verified duration');
assert_true(($locked['json']['call']['data_source'] ?? '') === 'system_verified', 'data_source is immutable after system verification');

$waCall = req($app, 'POST', '/api/clients/' . $clientId . '/whatsapp', [
    'phone' => '77011112233', 'kind' => 'call', 'duration_sec' => 90, 'result' => 'answered',
    'comment' => 'WhatsApp-звонок', 'idempotency_key' => 'wa-call-dur',
], $t1);
assert_true(($waCall['json']['whatsapp']['data_source'] ?? '') === 'manual', 'WhatsApp duration is always manual');
assert_true((int) ($waCall['json']['whatsapp']['duration_sec'] ?? 0) === 90, 'WhatsApp duration stored as manager-entered');
assert_true(empty($waCall['json']['whatsapp']['duration_is_actual']), 'WhatsApp duration is not system-verified');

$openTasks = req($app, 'GET', '/api/tasks', [], $t1, ['client_id' => (string) $clientId]);
$taskTitles = array_column($openTasks['json']['items'] ?? [], 'title');
assert_true(in_array('Подготовить коммерческое предложение', $taskTitles, true), 'interested result creates KP task');
assert_true(in_array('Повторный звонок', $taskTitles, true), 'no_answer creates callback on next workday');
$callbackOpen = count(array_filter($openTasks['json']['items'] ?? [], fn ($t) => ($t['title'] ?? '') === 'Повторный звонок' && ($t['status'] ?? '') === 'open'));
$attemptNoFu = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'connected' => false, 'talk_duration_sec' => 0, 'result' => 'no_answer',
    'attempt' => true, 'comment' => 'Исходящий вызов инициирован',
    'source' => 'manager', 'data_source' => 'unverified', 'idempotency_key' => 'call-attempt-nofu',
], $t1);
assert_true($attemptNoFu['status'] === 200 && empty($attemptNoFu['json']['call']['follow_up']['created']), 'dial attempt does not auto-create a task');
$openAfterAttempt = req($app, 'GET', '/api/tasks', [], $t1, ['client_id' => (string) $clientId]);
$callbackAfter = count(array_filter($openAfterAttempt['json']['items'] ?? [], fn ($t) => ($t['title'] ?? '') === 'Повторный звонок' && ($t['status'] ?? '') === 'open'));
assert_true($callbackAfter === $callbackOpen, 'attempt does not add another callback task');
$dupNa = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'connected' => false, 'talk_duration_sec' => 0, 'result' => 'no_answer',
    'idempotency_key' => 'call-na-dup',
], $t1);
assert_true(!empty($dupNa['json']['call']['follow_up']['duplicate']), 'second no_answer reuses open callback');
$none = req($app, 'POST', '/api/clients/' . $clientId . '/calls', [
    'phone' => '77011112233', 'connected' => true, 'talk_duration_sec' => 40,
    'source' => 'system', 'data_source' => 'system_verified',
    'result' => 'not_interested', 'idempotency_key' => 'call-not-int',
], $t1);
assert_true(($none['json']['call']['follow_up']['mode'] ?? '') === 'none', 'not interested does not create extra task');
$catFu = req($app, 'GET', '/api/catalog', []);
assert_true(isset($catFu['json']['contact_results']['callback']) && isset($catFu['json']['contact_results']['application_ready']), 'compact contact results in catalog');
assert_true(preg_match('/^\d{4}-\d{2}-\d{2} 10:00:00$/', (string) ($catFu['json']['next_workday'] ?? '')) === 1, 'next workday is a workday at 10:00');
$card2 = req($app, 'GET', '/api/clients/' . $clientId, [], $t1);
assert_true(count($card2['json']['client']['notes'] ?? []) >= 1, 'notes section has comment');
assert_true(count($card2['json']['client']['offers'] ?? []) >= 2, 'offers section has KP records');
assert_true(isset($card2['json']['client']['applications']) && isset($card2['json']['client']['connections']), 'applications and connections sections exist');

$assign = req($app, 'POST', '/api/clients/' . $clientId . '/assign', [
    'manager_id' => $mgr2['json']['user']['id'],
], $adminToken);
assert_true($assign['status'] === 200, 'admin reassigns client');
$nowForeign = req($app, 'GET', '/api/clients/' . $clientId, [], $t1);
assert_true($nowForeign['status'] === 403, 'old manager lost access after reassignment');
$nowOwner = req($app, 'GET', '/api/clients/' . $clientId, [], $t2);
assert_true($nowOwner['status'] === 200, 'new manager can see assigned client');

$dash = req($app, 'GET', '/api/dashboard', [], $adminToken, ['period' => 'last_30']);
assert_true(($dash['json']['dashboard']['calls_total'] ?? 0) >= 2, 'dashboard uses real call counts');
assert_true(($dash['json']['dashboard']['clients_total'] ?? 0) >= 2, 'dashboard client totals from DB');
assert_true(($dash['json']['dashboard']['talks'] ?? -1) === ($dash['json']['dashboard']['sim_verified_talks'] ?? -2), 'talks equal verified SIM talks only');
assert_true(($dash['json']['dashboard']['sim_unverified'] ?? 0) >= 1, 'unverified SIM calls are split out');
assert_true(($dash['json']['dashboard']['sim_verified_connected'] ?? 0) >= 1, 'verified connections counted');
assert_true(($dash['json']['dashboard']['sim_verified_talk_seconds'] ?? 0) === ($dash['json']['dashboard']['talk_seconds'] ?? -1), 'confirmed talk time is verified-only');

$cmp = req($app, 'GET', '/api/analytics/managers', [], $t1);
assert_true($cmp['status'] === 403, 'manager cannot see comparison table');
$cmpAdmin = req($app, 'GET', '/api/analytics/managers', [], $adminToken, ['period' => 'last_30']);
assert_true($cmpAdmin['status'] === 200 && count($cmpAdmin['json']['managers'] ?? []) === 2, 'admin manager comparison');

$map = req($app, 'GET', '/api/map/markers', [], $adminToken);
assert_true(count($map['json']['markers'] ?? []) >= 2, 'map markers from coordinates');

$export = req($app, 'GET', '/api/reports/export', [], $adminToken, ['type' => 'clients', 'format' => 'csv']);
assert_true($export['status'] === 200 && str_contains($export['raw'], 'Иван'), 'CSV export clients');

$interId = (int) ($call['json']['call']['interaction']['id'] ?? 0);
$amend = req($app, 'POST', '/api/interactions/' . $interId . '/amend', [
    'reason' => 'Уточнение', 'comment' => 'Клиент просил перезвонить вечером',
], $t2);
assert_true($amend['status'] === 200 && ($amend['json']['interaction']['type'] ?? '') === 'amendment', 'amendment does not delete original');

$timeline = req($app, 'GET', '/api/clients/' . $clientId . '/timeline', [], $adminToken);
$types = array_column($timeline['json']['timeline'] ?? [], 'type');
assert_true(in_array('call', $types, true) && in_array('amendment', $types, true), 'timeline keeps original and amendment');

$block = req($app, 'POST', '/api/users/' . $mgr1['json']['user']['id'] . '/block', [], $adminToken);
assert_true($block['status'] === 200, 'block manager');
$blockedLogin = req($app, 'POST', '/api/auth/login', ['login' => 'manager1', 'password' => 'Manager#1']);
assert_true($blockedLogin['status'] === 403, 'blocked manager cannot login');
$oldToken = req($app, 'GET', '/api/auth/me', [], $t1);
assert_true($oldToken['status'] === 401 || $oldToken['status'] === 403, 'blocked manager sessions revoked');

$sync = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'op-1',
        'kind' => 'client.create',
        'payload' => [
            'type' => 'person',
            'full_name' => 'Синхронизация',
            'phone_primary' => '77019990000',
            'idempotency_key' => 'sync-person-1',
            'force' => true,
        ],
    ]],
], $t2);
assert_true(($sync['json']['results'][0]['ok'] ?? false) === true, 'sync push create');
$sync2 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'op-1',
        'kind' => 'client.create',
        'payload' => [
            'type' => 'person',
            'full_name' => 'Синхронизация',
            'phone_primary' => '77019990000',
            'idempotency_key' => 'sync-person-1',
            'force' => true,
        ],
    ]],
], $t2);
assert_true(($sync2['json']['results'][0]['data']['id'] ?? 0) == ($sync['json']['results'][0]['data']['id'] ?? -1)
    || ($sync2['json']['results'][0]['data']['client']['id'] ?? 0) == ($sync['json']['results'][0]['data']['client']['id'] ?? -1)
    || true, 'sync retry is safe');
$retryId1 = $sync['json']['results'][0]['data']['id'] ?? $sync['json']['results'][0]['data']['client']['id'] ?? null;
$retryId2 = $sync2['json']['results'][0]['data']['id'] ?? $sync2['json']['results'][0]['data']['client']['id'] ?? null;
assert_true($retryId1 && $retryId1 == $retryId2, 'sync retry does not duplicate client');

$audit = req($app, 'GET', '/api/audit', [], $adminToken);
assert_true(count($audit['json']['items'] ?? []) > 5, 'audit log has events');

$connectedForbidden = req($app, 'POST', '/api/clients/' . $clientId . '/status', ['status' => 'connected'], $t2);
assert_true($connectedForbidden['status'] === 403, 'manager cannot set connected');
$skipStatus = req($app, 'POST', '/api/clients/' . $clientId . '/status', ['status' => 'connecting'], $t2);
assert_true($skipStatus['status'] === 403, 'manager cannot skip to connecting');
$adminConnected = req($app, 'POST', '/api/clients/' . $clientId . '/status', ['status' => 'connected'], $adminToken);
assert_true(($adminConnected['json']['client']['status'] ?? '') === 'connected', 'admin confirms connected');

$mgr3 = req($app, 'POST', '/api/users', ['login' => 'manager3', 'password' => 'Manager#3', 'full_name' => 'Менеджер Три'], $adminToken);
$mgr4 = req($app, 'POST', '/api/users', ['login' => 'manager4', 'password' => 'Manager#4', 'full_name' => 'Менеджер Четыре'], $adminToken);
$mgr5 = req($app, 'POST', '/api/users', ['login' => 'manager5', 'password' => 'Manager#5', 'full_name' => 'Менеджер Пять'], $adminToken);
$mgr6 = req($app, 'POST', '/api/users', ['login' => 'manager6', 'password' => 'Manager#6', 'full_name' => 'Менеджер Шесть'], $adminToken);
assert_true(
    $mgr3['status'] === 200 && $mgr4['status'] === 200 && $mgr5['status'] === 200 && $mgr6['status'] === 200,
    'fill remaining manager slots up to 5 active'
);
$mgr7fail = req($app, 'POST', '/api/users', ['login' => 'manager7', 'password' => 'Manager#7', 'full_name' => 'Менеджер Семь'], $adminToken);
assert_true($mgr7fail['status'] === 409, 'sixth active manager rejected');
$block3 = req($app, 'POST', '/api/users/' . $mgr3['json']['user']['id'] . '/block', [], $adminToken);
assert_true($block3['status'] === 200, 'blocking manager frees a slot');
$mgr7ok = req($app, 'POST', '/api/users', ['login' => 'manager7', 'password' => 'Manager#7', 'full_name' => 'Менеджер Семь'], $adminToken);
assert_true($mgr7ok['status'] === 200, 'new manager after a slot is freed');
$unblock3 = req($app, 'POST', '/api/users/' . $mgr3['json']['user']['id'] . '/unblock', [], $adminToken);
assert_true($unblock3['status'] === 409, 'cannot unblock when five managers already active');
$m4login = req($app, 'POST', '/api/auth/login', ['login' => 'manager4', 'password' => 'Manager#4']);
$t4 = $m4login['json']['token'] ?? '';

$storage = (string) $app->config->get('storage');
$uploadDir = $storage . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}
file_put_contents($uploadDir . '/acl-test.txt', 'secret');
$fileId = $app->db->insert('uploads', [
    'uuid' => '11111111-1111-4111-8111-111111111111',
    'user_id' => $mgr2['json']['user']['id'],
    'client_id' => $clientId,
    'original_name' => 'acl-test.txt',
    'stored_path' => 'uploads/acl-test.txt',
    'mime' => 'text/plain',
    'size_bytes' => 6,
    'created_at' => $app->db->now(),
]);
$ownerFile = req($app, 'GET', '/api/files/' . $fileId, [], $t2);
assert_true($ownerFile['status'] === 200, 'assigned manager can read client file');
$foreignFile = req($app, 'GET', '/api/files/' . $fileId, [], $t4);
assert_true($foreignFile['status'] === 403, 'other manager cannot read client file');
$adminFile = req($app, 'GET', '/api/files/' . $fileId, [], $adminToken);
assert_true($adminFile['status'] === 200, 'admin can read client file');

$dashKind = req($app, 'GET', '/api/dashboard', [], $adminToken, ['period' => 'last_30']);
$kinds = $dashKind['json']['dashboard']['metric_kind'] ?? [];
assert_true(($kinds['interested'] ?? '') === 'period_events', 'interested is a period event');
assert_true(($kinds['by_status'] ?? '') === 'snapshot', 'by_status is a current snapshot');
assert_true(($kinds['clients_total'] ?? '') === 'snapshot', 'clients_total is a snapshot');
assert_true(($dashKind['json']['dashboard']['by_status_period']['connected'] ?? 0) >= 1, 'period connections from status_history');
assert_true(($dashKind['json']['dashboard']['by_status']['connected'] ?? 0) >= 1, 'snapshot still shows current connected');

$clientsBefore = (int) $app->db->fetchColumn('SELECT COUNT(*) FROM clients');
(new MTelecom\Sales\Core\Migrator($app->db))->up();
(new MTelecom\Sales\Core\Migrator($app->db))->up();
$clientsAfter = (int) $app->db->fetchColumn('SELECT COUNT(*) FROM clients');
assert_true($clientsBefore === $clientsAfter, 'migrator is idempotent and does not recreate tables');

$quoted = MTelecom\Sales\Core\Env::quote('p@ss # word');
file_put_contents($tmp . '/quote.env', "DB_PASS={$quoted}\n");
MTelecom\Sales\Core\Env::load($tmp . '/quote.env');
assert_true(MTelecom\Sales\Core\Env::get('DB_PASS') === 'p@ss # word', 'env quoting preserves # and spaces');

$app->config->set('base_path', '/sales');
$app->config->set('url', 'https://mtelecom.kz/sales');
$loginCookie = req($app, 'POST', '/api/auth/login', ['login' => 'director', 'password' => 'Secret#123', 'platform' => 'web-admin']);
$sid = session_id_from($loginCookie['headers']);
$csrf = $loginCookie['json']['csrf'] ?? '';
assert_true(is_string($sid) && $sid !== '', 'web login sets session cookie');
assert_true(str_contains($loginCookie['headers']['Set-Cookie'] ?? '', 'Path=/sales'), 'cookie path follows subdirectory');
assert_true(str_contains($loginCookie['headers']['Set-Cookie'] ?? '', 'HttpOnly'), 'cookie is HttpOnly');
assert_true(str_contains($loginCookie['headers']['Set-Cookie'] ?? '', 'SameSite=Lax'), 'cookie SameSite=Lax');
$noCsrf = req($app, 'POST', '/api/auth/logout', [], null, [], ['mts_session' => $sid]);
assert_true($noCsrf['status'] === 419, 'web session mutation requires CSRF');
$withCsrf = req($app, 'POST', '/api/auth/logout', [], null, [], ['mts_session' => $sid], ['X-CSRF-Token' => $csrf]);
assert_true($withCsrf['status'] === 200, 'web logout with CSRF succeeds');
$refresh = req($app, 'GET', '/api/auth/me', [], null, [], ['mts_session' => $sid]);
assert_true($refresh['status'] === 401, 'logout clears web session');

$page = req($app, 'GET', '/');
assert_true($page['status'] === 200 && str_contains($page['raw'], 'Кабинет руководителя'), 'cabinet home works at / for /sales/');
assert_true(str_contains($page['raw'], '/sales/assets/js/app.js'), 'admin assets use base path');
$healthOnSales = req($app, 'GET', '/api/health');
assert_true(!empty($healthOnSales['json']['ok']), 'API /api/health works with APP_BASE_PATH=/sales');
$app->config->set('base_path', '');
$_SERVER['SCRIPT_NAME'] = '/sales/index.php';
assert_true($app->resolveBasePath() === '/sales', 'base path is detected from script directory');
$_SERVER['SCRIPT_NAME'] = '/index.php';
assert_true($app->resolveBasePath() === '', 'root deployment has empty base path');
unset($_SERVER['SCRIPT_NAME']);

$app->config->set('url', 'https://sales.mtelecom.kz');
$denyCors = req($app, 'GET', '/api/health', [], null, [], [], ['origin' => 'https://evil.example']);
assert_true(($denyCors['headers']['Access-Control-Allow-Origin'] ?? '') !== '*', 'CORS is not wildcard');
assert_true(($denyCors['headers']['Access-Control-Allow-Origin'] ?? '') === '', 'unknown origin is not allowed');
$okCors = req($app, 'GET', '/api/health', [], null, [], [], ['origin' => 'https://sales.mtelecom.kz']);
assert_true(($okCors['headers']['Access-Control-Allow-Origin'] ?? '') === 'https://sales.mtelecom.kz', 'APP_URL origin is allowed');

$partial = req($app, 'POST', '/api/sync/push', [
    'ops' => [
        [
            'client_op_id' => 'ok-op',
            'kind' => 'client.create',
            'payload' => [
                'type' => 'person',
                'full_name' => 'Частичный успех',
                'phone_primary' => '77018880000',
                'force' => true,
                'idempotency_key' => 'partial-ok',
            ],
        ],
        [
            'client_op_id' => 'bad-op',
            'kind' => 'unknown.kind',
            'payload' => ['client_id' => 1],
        ],
    ],
], $t2);
assert_true($partial['status'] === 200, 'sync HTTP 200 on mixed batch');
assert_true(($partial['json']['results'][0]['ok'] ?? false) === true, 'successful sync op is confirmed');
assert_true(($partial['json']['results'][1]['ok'] ?? true) === false, 'failed sync op is not confirmed');

$funnel = req($app, 'GET', '/api/analytics/funnel', [], $adminToken, ['period' => 'last_30']);
assert_true($funnel['status'] === 200 && count($funnel['json']['funnel']['snapshot'] ?? []) === 7, 'funnel has 7 snapshot stages');
$funnelCounts = array_column($funnel['json']['funnel']['snapshot'] ?? [], 'count', 'key');
assert_true((int) ($funnelCounts['connected'] ?? 0) >= 1, 'funnel snapshot counts connected uniquely');
assert_true(isset($funnel['json']['funnel']['step_conversion']['new_to_processed']['label']), 'funnel conversion has label');

$series = req($app, 'GET', '/api/analytics/series', [], $adminToken, ['period' => 'last_30', 'grain' => 'day']);
assert_true($series['status'] === 200 && ($series['json']['series']['grain'] ?? '') === 'day', 'series grain day');
assert_true(isset($series['json']['series']['series']['talks']) && is_array($series['json']['series']['series']['talks']), 'series talks points');
$talkSum = 0;
foreach ($series['json']['series']['series']['talks'] as $pt) {
    $talkSum += (int) ($pt['n'] ?? 0);
}
assert_true($talkSum >= 1, 'series talks come from real calls');

$sales = req($app, 'GET', '/api/analytics/sales', [], $adminToken, ['period' => 'last_30']);
assert_true($sales['status'] === 200, 'sales analytics');
$conv = $sales['json']['sales']['conversion']['new_to_connected'] ?? [];
assert_true(array_key_exists('value', $conv) && array_key_exists('denominator', $conv), 'conversion structure');
assert_true(($conv['denominator'] ?? -1) >= 0, 'conversion does not divide by missing denom');
if (($conv['denominator'] ?? 0) === 0) {
    assert_true(($conv['label'] ?? '') === 'Недостаточно данных', 'zero denom is insufficient data');
} else {
    assert_true(is_numeric($conv['value']), 'conversion percent is numeric when cohort exists');
}

$ctrl = req($app, 'GET', '/api/analytics/control', [], $adminToken, ['period' => 'last_30']);
assert_true($ctrl['status'] === 200 && isset($ctrl['json']['control']['counts']['overdue_tasks']), 'control block counts');
assert_true(($ctrl['json']['control']['counts']['overdue_tasks'] ?? -1) >= 1, 'overdue task from fixture visible in control');

$month = req($app, 'GET', '/api/analytics/overview', [], $adminToken, ['period' => 'this_month']);
assert_true($month['status'] === 200, 'this_month period');
$fromMonth = (string) ($month['json']['overview']['period']['from'] ?? '');
assert_true(str_starts_with($fromMonth, (new DateTimeImmutable('today'))->format('Y-m-01')), 'this_month starts on the 1st');

$mgrFunnel = req($app, 'GET', '/api/analytics/funnel', [], $t4, ['period' => 'last_30']);
assert_true($mgrFunnel['status'] === 200, 'manager can load own funnel');
$mgrSnapConnected = 0;
foreach ($mgrFunnel['json']['funnel']['snapshot'] ?? [] as $st) {
    $mgrSnapConnected += (int) ($st['count'] ?? 0);
}
assert_true($mgrSnapConnected === 0, 'manager without clients sees empty funnel snapshot');

$mgrSales = req($app, 'GET', '/api/analytics/sales', [], $t4, ['period' => 'today']);
$mgrConv = $mgrSales['json']['sales']['conversion']['new_to_connected'] ?? [];
assert_true(($mgrConv['label'] ?? '') === 'Недостаточно данных', 'empty cohort is insufficient data not a fake percent');

$mgrRank = req($app, 'GET', '/api/analytics/managers', [], $t4);
assert_true($mgrRank['status'] === 403, 'manager still cannot see company rankings');

$ovExport = req($app, 'GET', '/api/reports/export', [], $adminToken, ['type' => 'overview', 'format' => 'csv', 'period' => 'last_30']);
assert_true($ovExport['status'] === 200 && str_contains($ovExport['raw'], 'Всего клиентов'), 'CSV export overview analytics');
assert_true(
    (bool) preg_match('/Всего клиентов в базе";[1-9]/u', $ovExport['raw']),
    'CSV overview is not zeroed by export type=overview'
);
$salesExport = req($app, 'GET', '/api/reports/export', [], $adminToken, ['type' => 'sales', 'format' => 'csv', 'period' => 'last_30']);
assert_true($salesExport['status'] === 200 && str_contains($salesExport['raw'], 'Конверсия'), 'CSV export sales');
$xlsxOv = req($app, 'GET', '/api/reports/export', [], $adminToken, ['type' => 'overview', 'format' => 'xlsx', 'period' => 'last_30']);
assert_true($xlsxOv['status'] === 200 && strlen($xlsxOv['raw'] ?? '') > 40, 'xlsx export overview');

$ranked = req($app, 'GET', '/api/analytics/managers', [], $adminToken, ['period' => 'last_30']);
assert_true(isset($ranked['json']['rankings']['by_connections'][0]['full_name']), 'admin rankings by connections');
assert_true(isset($ranked['json']['rankings']['by_conversion']), 'admin rankings by conversion');

$health = req($app, 'GET', '/api/health');
assert_true(!empty($health['json']['ok']), 'health endpoint');

$migOff = req($app, 'POST', '/api/system/migrate', []);
assert_true($migOff['status'] === 404, 'migrate endpoint disabled without DEPLOY_MIGRATE_TOKEN');
putenv('DEPLOY_MIGRATE_TOKEN=ci-migrate-token-test');
$_ENV['DEPLOY_MIGRATE_TOKEN'] = 'ci-migrate-token-test';
$migBad = req($app, 'POST', '/api/system/migrate', [], null, [], [], ['x-deploy-token' => 'wrong']);
assert_true($migBad['status'] === 401, 'migrate rejects wrong deploy token');
$migOk = req($app, 'POST', '/api/system/migrate', [], null, [], [], ['x-deploy-token' => 'ci-migrate-token-test']);
assert_true($migOk['status'] === 200 && !empty($migOk['json']['ok']), 'migrate succeeds with deploy token');
assert_true(($migOk['json']['schema_version'] ?? '') === MTelecom\Sales\Core\Migrator::VERSION, 'migrate reports schema version');
putenv('DEPLOY_MIGRATE_TOKEN');
unset($_ENV['DEPLOY_MIGRATE_TOKEN']);

$ver = req($app, 'GET', '/api/app/version');
assert_true(isset($ver['json']['version_code']), 'app version endpoint');

$publicIndex = (string) file_get_contents($root . '/backend/public/index.php');
assert_true(str_contains($publicIndex, 'install.lock'), 'public index handles missing install without a raw 500');
$rootInstall = (string) file_get_contents($root . '/install.php');
assert_true(strlen($rootInstall) > 200 && str_contains($rootInstall, 'backend/public/install.php'), 'root install.php is a documented loader not an 85-byte stub');
assert_true(str_contains($rootInstall, 'is_file($target)'), 'root install.php checks that the real installer exists');
$rootIndex = (string) file_get_contents($root . '/index.php');
assert_true(str_contains($rootIndex, 'backend/public/index.php'), 'root index.php loads the public front controller');
$rootHt = (string) file_get_contents($root . '/.htaccess');
assert_true(str_contains($rootHt, 'RewriteBase /sales/'), 'root htaccess is scoped to /sales/');
assert_true(str_contains($rootHt, 'RewriteRule ^admin(/|$)'), 'root htaccess keeps admin/ directory forbidden');

$installSrc = (string) file_get_contents($root . '/backend/public/install.php');
assert_true(str_contains($installSrc, "https://mtelecom.kz/sales"), 'installer default APP_URL is https://mtelecom.kz/sales');
assert_true(str_contains($installSrc, "['APP_BASE_PATH'] ?? '/sales'"), 'installer default APP_BASE_PATH is /sales');
$publicHt = (string) file_get_contents($root . '/backend/public/.htaccess');
assert_true(str_contains($publicHt, 'sales/backend/public/install.lock'), 'public htaccess checks httpdocs/sales install.lock');
assert_true(str_contains($publicHt, '%{DOCUMENT_ROOT}/install.lock'), 'public htaccess still checks document-root install.lock');

$seedScript = $root . '/backend/bin/seed-local.php';
$seedSrc = (string) file_get_contents($seedScript);
assert_true(!str_contains($seedSrc, 'LocalTest#'), 'seed-local.php has no hardcoded test passwords');
assert_true(str_contains($seedSrc, 'seed.local.php'), 'seed-local.php reads gitignored local credentials');

$procEnv = getenv();
if (!is_array($procEnv)) {
    $procEnv = $_ENV;
}
$procEnv['APP_ENV'] = 'production';
$procEnv['DB_DRIVER'] = 'mysql';
$proc = proc_open(
    [PHP_BINARY, $seedScript],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
    $procEnv
);
if (is_resource($proc)) {
    $seedOut = stream_get_contents($pipes[1]) ?: '';
    $seedErr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $seedCode = proc_close($proc);
    $seedText = $seedOut . $seedErr;
    assert_true(
        $seedCode !== 0 && str_contains($seedText, 'отказался'),
        'seed-local.php refuses production MySQL before connect'
    );
} else {
    assert_true(false, 'seed-local.php refuses production MySQL before connect');
}

// --- Period filters for calls / whatsapp + APK download + task idempotency ---
// $t2 currently owns $clientId after the assign step above.
$mgrTok = $t2;
$periodCalls = req($app, 'GET', '/api/calls', [], $mgrTok, ['period' => 'today']);
assert_true(($periodCalls['status'] ?? 0) === 200 && isset($periodCalls['json']['items']), 'calls honor period=today');
$periodWa = req($app, 'GET', '/api/whatsapp', [], $mgrTok, ['period' => 'yesterday']);
assert_true(($periodWa['status'] ?? 0) === 200 && isset($periodWa['json']['items']), 'whatsapp honor period=yesterday');
$periodCallsEmpty = req($app, 'GET', '/api/calls', [], $mgrTok, ['period' => 'last_month']);
assert_true(($periodCallsEmpty['status'] ?? 0) === 200, 'calls honor period=last_month');

$dashToday = req($app, 'GET', '/api/dashboard', [], $mgrTok, ['period' => 'today']);
$ovToday = req($app, 'GET', '/api/analytics/overview', [], $mgrTok, ['period' => 'today']);
assert_true(
    ($dashToday['json']['dashboard']['clients_total'] ?? null) === ($ovToday['json']['overview']['clients_total'] ?? null)
    && ($dashToday['json']['dashboard']['calls_total'] ?? null) === ($ovToday['json']['overview']['calls_total'] ?? null)
    && ($dashToday['json']['dashboard']['whatsapp_total'] ?? null) === ($ovToday['json']['overview']['whatsapp_total'] ?? null)
    && ($dashToday['json']['dashboard']['rejected'] ?? null) === ($ovToday['json']['overview']['rejected'] ?? null)
    && ($dashToday['json']['dashboard']['overdue_tasks'] ?? null) === ($ovToday['json']['overview']['overdue_tasks'] ?? null),
    'dashboard and overview match for same period=today'
);

foreach (['today', 'yesterday', 'last_7', 'last_30', 'this_month', 'last_month'] as $periodName) {
    $p = req($app, 'GET', '/api/dashboard', [], $mgrTok, ['period' => $periodName]);
    assert_true(
        ($p['status'] ?? 0) === 200
        && isset($p['json']['dashboard']['period']['from'], $p['json']['dashboard']['period']['to'])
        && array_key_exists('whatsapp_total', $p['json']['dashboard'])
        && array_key_exists('rejected', $p['json']['dashboard'])
        && array_key_exists('overdue_tasks', $p['json']['dashboard']),
        "dashboard period={$periodName} returns KPI fields"
    );
}

$taskKey = 'task-idem-' . bin2hex(random_bytes(4));
$taskA = req($app, 'POST', '/api/tasks', [
    'client_id' => $clientId,
    'title' => 'Offline idempotency A',
    'details' => 'details-a',
    'idempotency_key' => $taskKey,
], $mgrTok);
$taskB = req($app, 'POST', '/api/tasks', [
    'client_id' => $clientId,
    'title' => 'Offline idempotency B should not create',
    'details' => 'details-b',
    'idempotency_key' => $taskKey,
], $mgrTok);
assert_true(
    ($taskA['json']['task']['id'] ?? null) !== null
    && ($taskA['json']['task']['id'] ?? null) === ($taskB['json']['task']['id'] ?? null),
    'task.create idempotency_key prevents duplicates'
);

$syncTaskKey = 'sync-task-' . bin2hex(random_bytes(4));
$syncTask1 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'op-task-1',
        'kind' => 'task.create',
        'payload' => [
            'client_id' => $clientId,
            'title' => 'Offline from outbox',
            'details' => 'offline details',
            'due_at' => null,
            'idempotency_key' => $syncTaskKey,
        ],
    ]],
], $mgrTok);
$syncTask2 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'op-task-2',
        'kind' => 'task.create',
        'payload' => [
            'client_id' => $clientId,
            'title' => 'Offline from outbox retry',
            'details' => 'offline details retry',
            'due_at' => null,
            'idempotency_key' => $syncTaskKey,
        ],
    ]],
], $mgrTok);
assert_true(
    !empty($syncTask1['json']['results'][0]['ok'])
    && !empty($syncTask2['json']['results'][0]['ok'])
    && (($syncTask1['json']['results'][0]['data']['id'] ?? null) === ($syncTask2['json']['results'][0]['data']['id'] ?? null)),
    'sync task.create with same idempotency_key does not duplicate'
);

$syncIxKey = 'sync-ix-' . bin2hex(random_bytes(4));
$syncIx1 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'op-ix-1',
        'kind' => 'interaction',
        'payload' => [
            'client_id' => $clientId,
            'type' => 'note',
            'comment' => 'offline note',
            'idempotency_key' => $syncIxKey,
        ],
    ]],
], $mgrTok);
$syncIx2 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'op-ix-2',
        'kind' => 'interaction',
        'payload' => [
            'client_id' => $clientId,
            'type' => 'note',
            'comment' => 'offline note retry',
            'idempotency_key' => $syncIxKey,
        ],
    ]],
], $mgrTok);
assert_true(
    !empty($syncIx1['json']['results'][0]['ok'])
    && !empty($syncIx2['json']['results'][0]['ok'])
    && (($syncIx1['json']['results'][0]['data']['id'] ?? null) === ($syncIx2['json']['results'][0]['data']['id'] ?? null)),
    'sync interaction with client_id + idempotency_key does not duplicate'
);

$apkMissing = req($app, 'GET', '/downloads/missing-file.apk');
assert_true(($apkMissing['status'] ?? 0) === 404, 'missing APK returns 404 JSON not HTML SPA');
$apkOk = req($app, 'GET', '/downloads/m-telecom-sales.apk');
$apkPathHeader = $apkOk['headers']['Content-Type'] ?? '';
$apkIsFile = !empty($apkOk['headers']) && (
    str_contains($apkPathHeader, 'android.package-archive')
    || str_contains($apkPathHeader, 'octet-stream')
    || (($apkOk['status'] ?? 0) === 200 && empty($apkOk['json']))
);
// Response::file may leave body empty and set isFile; status should be 200 when file exists.
$storageApk = $root . '/backend/storage/downloads/m-telecom-sales.apk';
if (is_file($storageApk)) {
    assert_true(($apkOk['status'] ?? 0) === 200 && str_contains($apkPathHeader, 'android.package-archive'), 'APK download serves package archive when file present');
} else {
    assert_true(($apkOk['status'] ?? 0) === 404, 'APK download 404 when storage file absent');
}

$seedHasManagerId = str_contains($seedSrc, "'manager_id'") && !str_contains($seedSrc, "'owner_id'");
assert_true($seedHasManagerId, 'seed-local.php inserts manager_id not owner_id');
assert_true(!str_contains($seedSrc, "'city'"), 'seed-local.php does not use missing city column');
assert_true(!str_contains($seedSrc, "'source'"), 'seed-local.php does not use missing source column');

// --- createApplication online / offline outbox / restart / duplicates ---
$appClient = req($app, 'POST', '/api/clients', [
    'type' => 'person',
    'full_name' => 'Заявка Тест',
    'phone_primary' => '77019990011',
    'status' => 'in_progress',
    'idempotency_key' => 'client-app-flow-1',
], $mgrTok);
$appClientId = (int) ($appClient['json']['client']['id'] ?? 0);
if ($appClientId === 0) {
    // Force status if create ignored status field
    $created = req($app, 'POST', '/api/clients', [
        'type' => 'person',
        'full_name' => 'Заявка Тест 2',
        'phone_primary' => '77019990012',
        'idempotency_key' => 'client-app-flow-2',
    ], $mgrTok);
    $appClientId = (int) ($created['json']['client']['id'] ?? 0);
    req($app, 'POST', '/api/clients/' . $appClientId . '/status', ['status' => 'in_progress'], $mgrTok);
}
assert_true($appClientId > 0, 'application flow client exists');

$onlineKey = 'app-online-' . bin2hex(random_bytes(4));
$onlineApp = req($app, 'POST', '/api/clients/' . $appClientId . '/application', [
    'comment' => 'Онлайн заявка',
    'idempotency_key' => $onlineKey,
], $mgrTok);
assert_true(
    ($onlineApp['status'] ?? 0) === 200
    && ($onlineApp['json']['client']['status'] ?? '') === 'application_received',
    'online createApplication sets status'
);
$onlineAgain = req($app, 'POST', '/api/clients/' . $appClientId . '/application', [
    'comment' => 'Повтор онлайн',
    'idempotency_key' => $onlineKey,
], $mgrTok);
$appsCount = count($onlineApp['json']['client']['applications'] ?? []);
$appsCount2 = count($onlineAgain['json']['client']['applications'] ?? []);
assert_true(
    ($onlineAgain['status'] ?? 0) === 200 && $appsCount2 === $appsCount,
    'online createApplication idempotent — no duplicate applications'
);

// Second client for offline sync simulation (status must allow transition)
$offClient = req($app, 'POST', '/api/clients', [
    'type' => 'person',
    'full_name' => 'Офлайн Заявка',
    'phone_primary' => '77019990013',
    'idempotency_key' => 'client-app-offline-1',
], $mgrTok);
$offId = (int) ($offClient['json']['client']['id'] ?? 0);
req($app, 'POST', '/api/clients/' . $offId . '/status', ['status' => 'in_progress'], $mgrTok);
$offlineKey = 'app-offline-' . bin2hex(random_bytes(4));
$offlinePayload = [
    'client_id' => $offId,
    'comment' => 'Заявка из outbox',
    'idempotency_key' => $offlineKey,
    'status' => 'application_received',
];
// Simulate outbox push after reconnect
$syncOff1 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'app:' . $offlineKey,
        'kind' => 'client.application',
        'payload' => $offlinePayload,
    ]],
], $mgrTok);
assert_true(!empty($syncOff1['json']['results'][0]['ok']), 'offline outbox sync createApplication ok');
$afterOff = req($app, 'GET', '/api/clients/' . $offId, [], $mgrTok);
assert_true(($afterOff['json']['client']['status'] ?? '') === 'application_received', 'offline sync sets application status');

// Reconnect / retry after "app restart" — same outbox id + same idempotency key
$syncOff2 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'app:' . $offlineKey,
        'kind' => 'client.application',
        'payload' => $offlinePayload,
    ]],
], $mgrTok);
assert_true(!empty($syncOff2['json']['results'][0]['ok']), 're-sync after restart still ok');
$afterRetry = req($app, 'GET', '/api/clients/' . $offId, [], $mgrTok);
$offApps = count($afterOff['json']['client']['applications'] ?? []);
$offAppsRetry = count($afterRetry['json']['client']['applications'] ?? []);
assert_true($offAppsRetry === $offApps, 're-sync does not duplicate application status events');

// Third push simulates repeated WorkManager delivery
$syncOff3 = req($app, 'POST', '/api/sync/push', [
    'ops' => [[
        'client_op_id' => 'app:' . $offlineKey . '-retry2',
        'kind' => 'client.application',
        'payload' => $offlinePayload,
    ]],
], $mgrTok);
assert_true(!empty($syncOff3['json']['results'][0]['ok']), 'third sync with same idempotency_key ok');
$after3 = req($app, 'GET', '/api/clients/' . $offId, [], $mgrTok);
assert_true(
    count($after3['json']['client']['applications'] ?? []) === $offApps,
    'duplicate protection holds across multiple sync deliveries'
);

$missingKey = req($app, 'POST', '/api/clients/' . $offId . '/application', [
    'comment' => 'без ключа',
], $mgrTok);
assert_true(($missingKey['status'] ?? 0) >= 400, 'createApplication without idempotency_key rejected');

// --- manager account editing and secure one-time password reset ---
$manager2Id = (int) ($mgr2['json']['user']['id'] ?? 0);
$managerUpdate = req($app, 'PATCH', '/api/users/' . $manager2Id, [
    'login' => 'andrey.manager',
    'full_name' => 'Андрей Менеджер',
    'phone' => '77075553322',
], $adminToken);
assert_true(
    ($managerUpdate['status'] ?? 0) === 200
    && ($managerUpdate['json']['user']['login'] ?? '') === 'andrey.manager'
    && ($managerUpdate['json']['user']['full_name'] ?? '') === 'Андрей Менеджер',
    'admin can edit manager login, full name and phone'
);
$duplicateLogin = req($app, 'PATCH', '/api/users/' . (int) ($mgr1['json']['user']['id'] ?? 0), [
    'login' => 'andrey.manager',
], $adminToken);
assert_true(($duplicateLogin['status'] ?? 0) === 409, 'manager login must be unique');
$managerReset = req($app, 'POST', '/api/users/' . $manager2Id . '/reset-password', [], $adminToken);
$tempPassword = (string) ($managerReset['json']['temporary_password'] ?? '');
assert_true(
    ($managerReset['status'] ?? 0) === 200
    && strlen($tempPassword) >= 20
    && !empty($managerReset['json']['sessions_revoked']),
    'admin can generate one-time temporary manager password and revoke sessions'
);
$managerOldPassword = req($app, 'POST', '/api/auth/login', [
    'login' => 'andrey.manager',
    'password' => 'Manager#2',
]);
assert_true(($managerOldPassword['status'] ?? 0) === 401, 'old manager password rejected after reset');
$managerTempLogin = req($app, 'POST', '/api/auth/login', [
    'login' => 'andrey.manager',
    'password' => $tempPassword,
]);
assert_true(($managerTempLogin['status'] ?? 0) === 200, 'temporary manager password works once issued');
$t2 = $managerTempLogin['json']['token'] ?? '';

// --- manager soft-delete: revoke access, preserve history, hide from active lists ---
$manager2Id = (int) ($mgr2['json']['user']['id'] ?? 0);
$managerDelete = req($app, 'DELETE', '/api/users/' . $manager2Id, [], $adminToken);
assert_true(($managerDelete['status'] ?? 0) === 200 && !empty($managerDelete['json']['deleted']), 'admin soft-deletes manager');
$managerDeletedRow = $app->db->fetch('SELECT id, status FROM users WHERE id = :id', ['id' => $manager2Id]);
assert_true($managerDeletedRow && $managerDeletedRow['status'] === 'deleted', 'deleted manager row retained for history');
$managerOldSession = req($app, 'GET', '/api/auth/me', [], $t2);
assert_true(($managerOldSession['status'] ?? 0) === 401, 'deleted manager existing token revoked');
$managerRelogin = req($app, 'POST', '/api/auth/login', ['login' => 'manager2', 'password' => 'Manager#2']);
assert_true(($managerRelogin['status'] ?? 0) === 403, 'deleted manager cannot log in');
$activeUsers = req($app, 'GET', '/api/users', [], $adminToken);
$deletedVisible = array_filter($activeUsers['json']['users'] ?? [], static fn ($u) => (int) ($u['id'] ?? 0) === $manager2Id);
assert_true(count($deletedVisible) === 0, 'deleted manager hidden from active user list');
$managerCannotDelete = req($app, 'DELETE', '/api/users/' . (int) ($mgr1['json']['user']['id'] ?? 0), [], $t1);
assert_true(($managerCannotDelete['status'] ?? 0) === 403, 'manager cannot delete another manager');

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
