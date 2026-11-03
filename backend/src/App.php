<?php
declare(strict_types=1);

namespace MTelecom\Sales;

use MTelecom\Sales\Auth\AuthService;
use MTelecom\Sales\Core\Config;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;
use MTelecom\Sales\Core\Request;
use MTelecom\Sales\Core\Response;
use MTelecom\Sales\Core\Router;
use MTelecom\Sales\Services\AccessControl;
use MTelecom\Sales\Services\AnalyticsService;
use MTelecom\Sales\Services\AuditService;
use MTelecom\Sales\Services\ClientService;
use MTelecom\Sales\Services\DuplicateService;
use MTelecom\Sales\Services\FollowUpService;
use MTelecom\Sales\Services\InteractionService;
use MTelecom\Sales\Services\ReportService;
use MTelecom\Sales\Services\SyncService;
use MTelecom\Sales\Services\TaskService;
use MTelecom\Sales\Services\UserService;

final class App
{
    public Config $config;
    public Database $db;
    public Router $router;
    public AuthService $auth;
    public AccessControl $access;
    public AuditService $audit;
    public ClientService $clients;
    public DuplicateService $duplicates;
    public InteractionService $interactions;
    public UserService $users;
    public TaskService $tasks;
    public AnalyticsService $analytics;
    public ReportService $reports;
    public SyncService $sync;
    public string $root;

    public function __construct(Config $config, Database $db, string $root)
    {
        $this->config = $config;
        $this->db = $db;
        $this->root = $root;
        $this->router = new Router();
        $this->audit = new AuditService($db);
        $this->auth = new AuthService($db, $config, $this->audit);
        $this->access = new AccessControl($db);
        $this->duplicates = new DuplicateService($db);
        $this->clients = new ClientService($db, $this->access, $this->duplicates, $this->audit);
        $this->interactions = new InteractionService($db, $this->access, $this->audit, new FollowUpService($db));
        $this->users = new UserService($db, $this->access, $this->audit, $this->auth);
        $this->tasks = new TaskService($db, $this->access, $this->audit);
        $this->analytics = new AnalyticsService($db, $this->access);
        $this->reports = new ReportService($db, $this->access, $this->analytics);
        $this->sync = new SyncService($db, $this->access, $this->clients, $this->interactions, $this->tasks);
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        try {
            if ($request->method === 'OPTIONS') {
                $preflight = new Response();
                $preflight->status = 204;
                $this->applyCors($request, $preflight);
                $preflight->headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, X-CSRF-Token, Idempotency-Key';
                $preflight->headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
                return $preflight;
            }
            $static = $this->serveAdminAsset($request);
            if ($static) {
                return $static;
            }
            $apk = $this->serveApkDownload($request);
            if ($apk) {
                return $apk;
            }
            $match = $this->router->match($request);
            if (!$match) {
                if (str_starts_with($request->path, '/api/')) {
                    return Response::error('Маршрут не найден', 404);
                }
                return $this->adminPage($request);
            }
            $request->params = $match['params'];
            $user = null;
            if ($match['auth']) {
                $user = $this->auth->userFromRequest($request);
                $this->auth->assertCsrf($request, $user);
                if ($match['roles']) {
                    $this->access->requireRole($user, $match['roles']);
                }
            }
            $result = ($match['handler'])($request, $user);
            $response = $result instanceof Response ? $result : Response::ok(is_array($result) ? $result : ['data' => $result]);
            return $this->applyCors($request, $response);
        } catch (HttpException $e) {
            return $this->applyCors($request, Response::error($e->getMessage(), $e->status, $e->extra));
        } catch (\Throwable $e) {
            if ($this->config->isDebug() || $this->config->isTest()) {
                return $this->applyCors($request, Response::error($e->getMessage(), 500, ['trace' => $e->getTraceAsString()]));
            }
            $this->log($e);
            return $this->applyCors($request, Response::error('Внутренняя ошибка сервера', 500));
        }
    }

    public function run(): void
    {
        $base = $this->resolveBasePath();
        $this->config->set('base_path', $base);
        $request = Request::fromGlobals($base);
        $this->handle($request)->send();
    }

    public function resolveBasePath(): string
    {
        $configured = $this->config->basePath();
        if ($configured !== '') {
            return $configured;
        }
        if (PHP_SAPI === 'cli-server') {
            return '';
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = str_replace('\\', '/', dirname($script));
        $dir = rtrim($dir, '/');
        if ($dir === '' || $dir === '/' || $dir === '.' || $dir === '\\') {
            return '';
        }
        return $dir;
    }

    private function registerRoutes(): void
    {
        $r = $this->router;
        $r->get('/api/health', fn () => ['ok' => true, 'name' => $this->config->get('name'), 'time' => $this->db->now()], false);
        $r->get('/api/catalog', fn () => [
            'statuses' => Catalog::STATUSES,
            'results' => Catalog::RESULTS,
            'contact_results' => Catalog::CONTACT_RESULTS,
            'next_workday' => Catalog::nextWorkday(),
            'interest' => Catalog::INTEREST,
            'call_categories' => Catalog::CALL_CATEGORIES,
            'call_channels' => Catalog::CALL_CHANNELS,
            'call_directions' => Catalog::CALL_DIRECTIONS,
            'data_sources' => Catalog::DATA_SOURCES,
            'interaction_types' => Catalog::INTERACTION_TYPES,
            'whatsapp_kinds' => Catalog::WHATSAPP_KINDS,
            'manager_transitions' => Catalog::MANAGER_TRANSITIONS,
            'admin_only_statuses' => Catalog::ADMIN_ONLY_STATUSES,
            'max_active_managers' => Catalog::MAX_ACTIVE_MANAGERS,
        ], false);

        $r->post('/api/auth/login', function (Request $req) {
            $result = $this->auth->login(
                (string) $req->str('login', ''),
                (string) $req->input('password', ''),
                $req,
                $req->str('device'),
                $req->str('platform')
            );
            $web = $this->auth->createWebSession($result['user'] + ['id' => $result['user']['id']], $req);
            $resp = Response::ok([
                'user' => $result['user'],
                'token' => $result['token'],
                'expires_at' => $result['expires_at'],
                'csrf' => $web['csrf'],
            ]);
            $resp->headers['Set-Cookie'] = $this->sessionCookie($web['session_id']);
            return $resp;
        }, false);

        $r->post('/api/auth/logout', function (Request $req, array $user) {
            $this->auth->logout($req, $user);
            $resp = Response::ok(['logged_out' => true]);
            $resp->headers['Set-Cookie'] = $this->sessionCookie('deleted', 0);
            return $resp;
        });
        $r->get('/api/auth/me', fn (Request $req, array $user) => [
            'user' => $this->auth->publicUser($user),
            'csrf' => $user['csrf_token'] ?? null,
        ]);

        $r->get('/api/users', fn (Request $req, array $user) => ['users' => $this->users->list($user)], true, ['admin']);
        $r->post('/api/users', fn (Request $req, array $user) => ['user' => $this->users->create($req->body, $user, $req)], true, ['admin']);
        $r->patch('/api/users/{id}', fn (Request $req, array $user) => ['user' => $this->users->update((int) $req->param('id'), $req->body, $user, $req)], true, ['admin']);
        $r->post('/api/users/{id}/block', fn (Request $req, array $user) => ['user' => $this->users->setBlocked((int) $req->param('id'), true, $user, $req)], true, ['admin']);
        $r->post('/api/users/{id}/unblock', fn (Request $req, array $user) => ['user' => $this->users->setBlocked((int) $req->param('id'), false, $user, $req)], true, ['admin']);
        $r->post('/api/users/{id}/revoke-sessions', function (Request $req, array $user) {
            $this->access->requireAdmin($user);
            $this->auth->revokeAllSessions((int) $req->param('id'), $req, (int) $user['id']);
            return ['revoked' => true];
        }, true, ['admin']);

        $r->get('/api/clients', function (Request $req, array $user) {
            $query = $req->query;
            if (!empty($query['status_event']) && empty($query['from'])) {
                [$query['from'], $query['to']] = $this->analytics->period($query);
            }
            return $this->clients->list($user, $query, (int) ($query['page'] ?? 1), (int) ($query['per_page'] ?? 50));
        });
        $r->post('/api/clients/check-duplicates', fn (Request $req, array $user) => [
            'duplicates' => $this->duplicates->find($req->body),
        ]);
        $r->post('/api/clients', function (Request $req, array $user) {
            $force = $req->bool('force');
            return ['client' => $this->clients->create($req->body, $user, $req, $force)];
        });
        $r->get('/api/clients/{id}', fn (Request $req, array $user) => ['client' => $this->clients->get((int) $req->param('id'), $user)]);
        $r->put('/api/clients/{id}', fn (Request $req, array $user) => ['client' => $this->clients->update((int) $req->param('id'), $req->body, $user, $req)]);
        $r->post('/api/clients/{id}/status', fn (Request $req, array $user) => [
            'client' => $this->clients->changeStatus((int) $req->param('id'), (string) $req->str('status'), $user, $req),
        ]);
        $r->post('/api/clients/{id}/assign', fn (Request $req, array $user) => [
            'client' => $this->clients->assign((int) $req->param('id'), (int) $req->input('manager_id'), $user, $req),
        ], true, ['admin']);
        $r->get('/api/clients/{id}/timeline', fn (Request $req, array $user) => [
            'timeline' => $this->clients->timeline((int) $this->access->clientOrFail((int) $req->param('id'), $user)['id']),
        ]);
        $r->post('/api/clients/{id}/interactions', fn (Request $req, array $user) => [
            'interaction' => $this->interactions->create((int) $req->param('id'), $req->body, $user, $req),
        ]);
        $r->post('/api/clients/{id}/calls', fn (Request $req, array $user) => [
            'call' => $this->interactions->recordCall((int) $req->param('id'), $req->body, $user, $req),
        ]);
        $r->patch('/api/calls/{id}', fn (Request $req, array $user) => [
            'call' => $this->interactions->completeCall((int) $req->param('id'), $req->body, $user, $req),
        ]);
        $r->post('/api/clients/{id}/whatsapp', fn (Request $req, array $user) => [
            'whatsapp' => $this->interactions->recordWhatsapp((int) $req->param('id'), $req->body, $user, $req),
        ]);
        $r->post('/api/clients/{id}/contacts', function (Request $req, array $user) {
            $client = $this->access->clientOrFail((int) $req->param('id'), $user);
            $id = $this->db->insert('client_contacts', [
                'client_id' => $client['id'],
                'full_name' => $req->str('full_name'),
                'position' => $req->str('position'),
                'phone' => $req->str('phone'),
                'phone_norm' => Catalog::normalizePhone($req->str('phone')),
                'email' => $req->str('email'),
                'is_primary' => $req->bool('is_primary') ? 1 : 0,
                'created_at' => $this->db->now(),
            ]);
            return ['contact' => $this->db->fetch('SELECT * FROM client_contacts WHERE id = :id', ['id' => $id])];
        });
        $r->post('/api/clients/{id}/phones', function (Request $req, array $user) {
            $client = $this->access->clientOrFail((int) $req->param('id'), $user);
            $id = $this->db->insert('client_phones', [
                'client_id' => $client['id'],
                'phone' => $req->str('phone'),
                'phone_norm' => Catalog::normalizePhone($req->str('phone')),
                'label' => $req->str('label'),
                'is_primary' => $req->bool('is_primary') ? 1 : 0,
                'created_at' => $this->db->now(),
            ]);
            return ['phone' => $this->db->fetch('SELECT * FROM client_phones WHERE id = :id', ['id' => $id])];
        });
        $r->post('/api/clients/{id}/objects', function (Request $req, array $user) {
            $client = $this->access->clientOrFail((int) $req->param('id'), $user);
            $now = $this->db->now();
            $id = $this->db->insert('client_objects', [
                'uuid' => $this->uuid(),
                'client_id' => $client['id'],
                'name' => $req->str('name'),
                'address' => $req->str('address'),
                'lat' => $req->input('lat'),
                'lng' => $req->input('lng'),
                'comment' => $req->str('comment'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['object' => $this->db->fetch('SELECT * FROM client_objects WHERE id = :id', ['id' => $id])];
        });

        $r->post('/api/interactions/{id}/amend', fn (Request $req, array $user) => [
            'interaction' => $this->interactions->amend((int) $req->param('id'), $req->body, $user, $req),
        ]);

        $r->get('/api/calls', function (Request $req, array $user) {
            $mf = $this->access->managerFilterSql($user, 'c');
            $params = [];
            $query = $req->query;
            if (empty($query['from']) && empty($query['to']) && !empty($query['period'])) {
                [$query['from'], $query['to']] = $this->analytics->period($query);
            }
            $sql = "SELECT cl.*, u.full_name AS manager_name, c.full_name, c.company_name
                    FROM calls cl JOIN clients c ON c.id = cl.client_id JOIN users u ON u.id = cl.user_id
                    WHERE {$mf}";
            if (!empty($query['from'])) {
                $sql .= ' AND cl.started_at >= :from';
                $params['from'] = $query['from'];
            }
            if (!empty($query['to'])) {
                $sql .= ' AND cl.started_at <= :to';
                $params['to'] = $query['to'];
            }
            $sql .= ' ORDER BY cl.started_at DESC LIMIT 500';
            $items = $this->db->fetchAll($sql, $params);
            foreach ($items as &$i) {
                $i = Catalog::decorateCall($i);
            }
            unset($i);
            return ['items' => $items];
        });

        $r->get('/api/whatsapp', function (Request $req, array $user) {
            $mf = $this->access->managerFilterSql($user, 'c');
            $params = [];
            $query = $req->query;
            if (empty($query['from']) && empty($query['to']) && !empty($query['period'])) {
                [$query['from'], $query['to']] = $this->analytics->period($query);
            }
            $sql = "SELECT w.*, u.full_name AS manager_name, c.full_name, c.company_name
                 FROM whatsapp_events w JOIN clients c ON c.id = w.client_id JOIN users u ON u.id = w.user_id
                 WHERE {$mf}";
            if (!empty($query['from'])) {
                $sql .= ' AND w.created_at >= :from';
                $params['from'] = $query['from'];
            }
            if (!empty($query['to'])) {
                $sql .= ' AND w.created_at <= :to';
                $params['to'] = $query['to'];
            }
            $sql .= ' ORDER BY w.created_at DESC LIMIT 500';
            $items = $this->db->fetchAll($sql, $params);
            foreach ($items as &$i) {
                $i = Catalog::decorateWhatsapp($i);
            }
            unset($i);
            return ['items' => $items];
        });

        $r->get('/api/tasks', fn (Request $req, array $user) => ['items' => $this->tasks->list($user, $req->query)]);
        $r->post('/api/tasks', fn (Request $req, array $user) => ['task' => $this->tasks->create($req->body, $user, $req)]);
        $r->patch('/api/tasks/{id}', fn (Request $req, array $user) => ['task' => $this->tasks->update((int) $req->param('id'), $req->body, $user, $req)]);

        $r->get('/api/dashboard', fn (Request $req, array $user) => [
            'dashboard' => $this->analytics->overview($user, array_merge($req->query, ['period' => $req->query['period'] ?? 'today'])),
        ]);
        $r->get('/api/analytics/overview', fn (Request $req, array $user) => [
            'overview' => $this->analytics->overview($user, $req->query),
        ]);
        $r->get('/api/analytics/funnel', fn (Request $req, array $user) => [
            'funnel' => $this->analytics->funnel($user, $req->query),
        ]);
        $r->get('/api/analytics/series', fn (Request $req, array $user) => [
            'series' => $this->analytics->series($user, $req->query),
        ]);
        $r->get('/api/analytics/control', fn (Request $req, array $user) => [
            'control' => $this->analytics->control($user, $req->query),
        ]);
        $r->get('/api/analytics/sales', fn (Request $req, array $user) => [
            'sales' => $this->analytics->sales($user, $req->query),
        ]);
        $r->get('/api/analytics/managers', function (Request $req, array $user) {
            $managers = $this->analytics->managers($user, $req->query);
            return [
                'managers' => $managers,
                'rankings' => $this->analytics->rankFrom($managers),
            ];
        }, true, ['admin']);
        $r->get('/api/map/markers', fn (Request $req, array $user) => [
            'markers' => $this->clients->mapMarkers($user, $req->query),
            'tile_url' => $this->config->get('map_tile_url'),
            'attribution' => $this->config->get('map_attribution'),
        ]);

        $r->get('/api/reports/export', function (Request $req, array $user) {
            $type = (string) ($req->query['type'] ?? 'clients');
            $format = (string) ($req->query['format'] ?? 'csv');
            $pack = $this->reports->export($type, $user, $req->query);
            if ($format === 'xlsx') {
                $bin = $this->reports->toXlsx($pack['headers'], $pack['rows'], $type);
                return Response::download($bin, $pack['filename'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            }
            return Response::download($pack['csv'], $pack['filename'] . '.csv', 'text/csv; charset=utf-8');
        });

        $r->get('/api/audit', function (Request $req, array $user) {
            $this->access->requireAdmin($user);
            $items = $this->db->fetchAll(
                'SELECT a.*, u.full_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                 ORDER BY a.id DESC LIMIT 300'
            );
            return ['items' => $items];
        }, true, ['admin']);

        $r->get('/api/settings', function (Request $req, array $user) {
            $this->access->requireAdmin($user);
            $rows = $this->db->fetchAll('SELECT * FROM settings');
            $map = [];
            foreach ($rows as $row) {
                $map[$row['setting_key']] = $row['setting_value'];
            }
            $map['android_version_code'] = $this->config->get('android_version_code');
            $map['android_version_name'] = $this->config->get('android_version_name');
            return ['settings' => $map];
        }, true, ['admin']);
        $r->put('/api/settings', function (Request $req, array $user) {
            $this->access->requireAdmin($user);
            $allowed = ['app_name', 'backup_retention_days', 'whatsapp_business_ready'];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $req->body)) {
                    $exists = $this->db->fetch('SELECT setting_key FROM settings WHERE setting_key = :k', ['k' => $key]);
                    if ($exists) {
                        $this->db->update('settings', [
                            'setting_value' => (string) $req->body[$key],
                            'updated_at' => $this->db->now(),
                        ], ['setting_key' => $key]);
                    } else {
                        $this->db->insert('settings', [
                            'setting_key' => $key,
                            'setting_value' => (string) $req->body[$key],
                            'updated_at' => $this->db->now(),
                        ]);
                    }
                }
            }
            $this->audit->log((int) $user['id'], 'settings.update', 'settings', null, $req);
            return ['saved' => true];
        }, true, ['admin']);

        $r->get('/api/app/version', function () {
            $row = $this->db->fetch('SELECT * FROM app_versions WHERE is_stable = 1 ORDER BY version_code DESC, id DESC');
            return [
                'version_code' => $row ? (int) $row['version_code'] : (int) $this->config->get('android_version_code'),
                'version_name' => $row['version_name'] ?? $this->config->get('android_version_name'),
                'apk_url' => $row['apk_url'] ?? $this->config->get('android_apk_url'),
                'changelog' => $row['changelog'] ?? 'Первая рабочая версия M-TELECOM SALES',
                'history' => $this->db->fetchAll('SELECT version_code, version_name, changelog, is_stable, created_at FROM app_versions ORDER BY version_code DESC'),
            ];
        }, false);

        $r->post('/api/uploads', function (Request $req, array $user) {
            $clientId = $req->int('client_id');
            if (!$clientId) {
                throw new HttpException('Укажите клиента для файла');
            }
            $this->access->clientOrFail($clientId, $user);
            $file = $req->files['file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new HttpException('Файл не получен');
            }
            $max = (int) $this->config->get('upload_max_mb', 5) * 1024 * 1024;
            if (($file['size'] ?? 0) > $max) {
                throw new HttpException('Файл слишком большой');
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                throw new HttpException('Разрешены только изображения JPG, PNG, WEBP');
            }
            $dirRel = 'uploads/' . date('Y/m');
            $absDir = $this->config->get('storage') . '/' . $dirRel;
            if (!is_dir($absDir)) {
                mkdir($absDir, 0775, true);
            }
            $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            $dest = $absDir . '/' . $name;
            if (!move_uploaded_file($file['tmp_name'], $dest) && !rename($file['tmp_name'], $dest) && !copy($file['tmp_name'], $dest)) {
                throw new HttpException('Не удалось сохранить файл');
            }
            $id = $this->db->insert('uploads', [
                'uuid' => $this->uuid(),
                'user_id' => $user['id'],
                'client_id' => $clientId,
                'original_name' => substr((string) $file['name'], 0, 255),
                'stored_path' => $dirRel . '/' . $name,
                'mime' => $mime,
                'size_bytes' => (int) $file['size'],
                'created_at' => $this->db->now(),
            ]);
            return ['file' => ['id' => $id, 'mime' => $mime]];
        });
        $r->get('/api/files/{id}', function (Request $req, array $user) {
            $row = $this->db->fetch('SELECT * FROM uploads WHERE id = :id', ['id' => (int) $req->param('id')]);
            if (!$row) {
                throw HttpException::notFound();
            }
            $this->access->assertCanViewUpload($row, $user);
            $path = $this->config->get('storage') . '/' . $row['stored_path'];
            if (!is_file($path)) {
                throw HttpException::notFound();
            }
            return Response::file($path, (string) $row['mime']);
        });

        $r->get('/api/sync/pull', fn (Request $req, array $user) => $this->sync->pull($user, $req->query['since'] ?? null));
        $r->post('/api/sync/push', fn (Request $req, array $user) => [
            'results' => $this->sync->push($req->body['ops'] ?? [], $user, $req),
        ]);

        $r->get('/admin', fn (Request $req) => $this->adminPage($req), false);
        $r->get('/admin/login', fn (Request $req) => $this->adminPage($req), false);
        $r->get('/', fn (Request $req) => $this->adminPage($req), false);
    }

    private function serveAdminAsset(Request $request): ?Response
    {
        if (!preg_match('#^/assets/(css|js|img)/([a-zA-Z0-9._-]+)$#', $request->path, $m)) {
            return null;
        }
        $file = $this->root . '/admin/assets/' . $m[1] . '/' . $m[2];
        if (!is_file($file)) {
            return null;
        }
        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
        return Response::file($file, $mime);
    }

    /**
     * Serves packaged APK for the login-page download link.
     * File lives outside git: backend/storage/downloads/m-telecom-sales.apk
     * (or optional backend/public/downloads/ for Apache direct serve).
     */
    private function serveApkDownload(Request $request): ?Response
    {
        if (!preg_match('#^/downloads/([a-zA-Z0-9._-]+\.apk)$#', $request->path, $m)) {
            return null;
        }
        $name = $m[1];
        $candidates = [
            $this->config->get('storage') . '/downloads/' . $name,
            $this->root . '/backend/public/downloads/' . $name,
        ];
        $file = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $file = $candidate;
                break;
            }
        }
        if ($file === null) {
            return Response::error('APK-файл ещё не размещён на сервере', 404);
        }
        $response = Response::file($file, 'application/vnd.android.package-archive');
        $response->headers['Content-Disposition'] = 'attachment; filename="' . $name . '"';
        $response->headers['Cache-Control'] = 'private, no-cache';
        $response->headers['Content-Length'] = (string) filesize($file);
        return $response;
    }

    private function adminPage(Request $request): Response
    {
        $adminDir = $this->root . '/admin';
        $html = file_get_contents($adminDir . '/index.html');
        if ($html === false) {
            return Response::error('Кабинет руководителя не установлен', 500);
        }
        $base = htmlspecialchars($this->config->basePath(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $assetV = (string) max(
            (int) @filemtime($adminDir . '/assets/js/app.js'),
            (int) @filemtime($adminDir . '/assets/css/app.css')
        );
        $html = str_replace(['{{BASE_PATH}}', '{{ASSET_V}}'], [$base, $assetV], $html);
        return Response::html($html);
    }

    private function applyCors(Request $request, Response $response): Response
    {
        $origin = $request->header('origin');
        if (!$origin) {
            return $response;
        }
        $allowed = $this->config->allowedOrigins();
        foreach ($allowed as $item) {
            if (strcasecmp($origin, $item) === 0) {
                $response->headers['Access-Control-Allow-Origin'] = $origin;
                $response->headers['Access-Control-Allow-Credentials'] = 'true';
                $response->headers['Vary'] = 'Origin';
                break;
            }
        }
        return $response;
    }

    private function sessionCookie(string $value, ?int $maxAge = null): string
    {
        $parts = [
            'mts_session=' . $value,
            'Path=' . $this->config->cookiePath(),
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($this->config->isHttps()) {
            $parts[] = 'Secure';
        }
        if ($maxAge !== null) {
            $parts[] = 'Max-Age=' . $maxAge;
        }
        return implode('; ', $parts);
    }

    private function log(\Throwable $e): void
    {
        $file = $this->config->get('storage') . '/logs/app.log';
        $line = date('c') . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
