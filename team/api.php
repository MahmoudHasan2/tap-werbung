<?php
// TAP Team — internal communication & task system (single JSON API)
declare(strict_types=1);

// Always the site's root /data/team (protected by data/.htaccess), wherever this file sits
define('TEAM_DIR', (is_dir(rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/data')
    ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/')
    : dirname(__DIR__)) . '/data/team');
const TEAM_DB     = TEAM_DIR . '/db.json';
const TEAM_CODES  = TEAM_DIR . '/codes.json';
const TEAM_FILES  = TEAM_DIR . '/files';
const TEAM_AVATAR = TEAM_DIR . '/avatars';
const DEPTS    = ['media', 'it', 'tech', 'promo'];
const WARN_AFTER   = 24 * 3600; // after a task is done: warn about file deletion
const DELETE_AFTER = 24 * 3600; // after the warning: delete uploaded files

session_name('TAPTEAM');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Any PHP error/exception becomes a JSON reply instead of an HTML error page
set_error_handler(function ($no, $msg, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($msg, 0, $no, $file, $line);
});
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()]);
    exit;
});

foreach ([TEAM_DIR, TEAM_FILES, TEAM_AVATAR] as $d) if (!is_dir($d)) @mkdir($d, 0755, true);
if (($_GET['action'] ?? '') === 'health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['php' => PHP_VERSION, 'mbstring' => function_exists('mb_substr'), 'fileinfo' => class_exists('finfo'),
        'data_dir' => is_dir(TEAM_DIR), 'writable' => is_writable(TEAM_DIR), 'codes_file' => is_file(TEAM_CODES),
        'upload_max' => ini_get('upload_max_filesize'), 'post_max' => ini_get('post_max_size')], JSON_PRETTY_PRINT);
    exit;
}
if (!is_writable(TEAM_DIR)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'storage_not_writable']);
    exit;
}

/** Responses are thrown so tx() can save (or discard, on errors) before anything is sent. */
class Reply extends Exception {
    public $data; public $status;
    public function __construct($data, int $status) { parent::__construct(''); $this->data = $data; $this->status = $status; }
}
function out($data, int $code = 200): void { throw new Reply($data, $code); }
function send(Reply $r): void {
    http_response_code($r->status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $err, int $code = 400): void { out(['error' => $err], $code); }
function body(): array { $d = json_decode((string)file_get_contents('php://input'), true); return is_array($d) ? $d : []; }
function str($v, int $max = 500): string {
    $s = trim((string)$v);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/** Run $fn with an exclusive lock on the database; $fn may modify $db by reference. */
function tx(callable $fn) {
    $lock = fopen(TEAM_DIR . '/db.lock', 'c');
    flock($lock, LOCK_EX);
    $db = is_file(TEAM_DB) ? json_decode((string)file_get_contents(TEAM_DB), true) : null;
    if (!is_array($db)) $db = ['users' => [], 'tasks' => [], 'messages' => [], 'notifs' => [], 'seq' => 0];
    $before = json_encode($db);
    $reply = null;
    try { $fn($db); } catch (Reply $r) { $reply = $r; }
    if (($reply === null || $reply->status < 400) && json_encode($db) !== $before) {
        $tmp = TEAM_DB . '.tmp';
        file_put_contents($tmp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, TEAM_DB);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    if ($reply) throw $reply;
}
function nid(array &$db): int { return ++$db['seq']; }
function is_admin(array $u): bool { return in_array($u['role'], ['manager', 'itadmin'], true); }
function find_user(array $db, int $id): ?array { foreach ($db['users'] as $u) if ($u['id'] === $id) return $u; return null; }
function task_idx(array $db, int $id): int { foreach ($db['tasks'] as $i => $t) if ($t['id'] === $id) return $i; return -1; }
function can_see(array $u, array $t): bool { return is_admin($u) || ($t['dept'] !== null && $t['dept'] === $u['dept']); }
function pub_user(array $u): array {
    return ['id' => $u['id'], 'username' => $u['username'], 'first' => $u['first'], 'last' => $u['last'],
        'phone' => $u['phone'], 'email' => $u['email'], 'role' => $u['role'], 'dept' => $u['dept'],
        'avatar' => $u['avatar'] ? 'api.php?action=avatar&id=' . $u['id'] . '&v=' . $u['avatarV'] : null];
}
function log_task(array &$t, int $uid, string $type, array $extra = []): void {
    $t['log'][] = ['at' => time(), 'by' => $uid, 'type' => $type] + $extra;
}
/** Notify admins + members of a department (except $except). */
function notify(array &$db, ?string $dept, string $type, array $data, int $except = 0): void {
    foreach ($db['users'] as $u) {
        if ($u['id'] === $except) continue;
        if (is_admin($u) || ($dept !== null && $u['dept'] === $dept)) {
            $db['notifs'][] = ['id' => nid($db), 'to' => $u['id'], 'at' => time(), 'type' => $type, 'data' => $data, 'read' => false];
        }
    }
    if (count($db['notifs']) > 3000) $db['notifs'] = array_slice($db['notifs'], -3000);
}
function me(array $db): array {
    $uid = $_SESSION['uid'] ?? 0;
    $u = $uid ? find_user($db, $uid) : null;
    if (!$u) fail('auth', 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('csrf', 403);
    return $u;
}

/** Lazy housekeeping (no cron needed): warn 24h after completion, delete files 24h after the warning. */
function housekeeping(array &$db): void {
    $now = time();
    foreach ($db['tasks'] as &$t) {
        if ($t['status'] !== 'done' || empty($t['files'])) continue;
        if (empty($t['warnedAt']) && $now >= $t['doneAt'] + WARN_AFTER) {
            $t['warnedAt'] = $now;
            log_task($t, 0, 'warned');
            notify($db, $t['dept'], 'files_warning', ['task' => $t['id'], 'title' => $t['title'], 'deleteAt' => $now + DELETE_AFTER]);
        } elseif (!empty($t['warnedAt']) && $now >= $t['warnedAt'] + DELETE_AFTER) {
            foreach ($t['files'] as $f) @unlink(TEAM_FILES . '/' . $f['stored']);
            $t['files'] = [];
            $t['filesPurged'] = true;
            log_task($t, 0, 'purged');
        }
    }
    unset($t);
}

$action = $_GET['action'] ?? '';

try {
switch ($action) {

case 'me':
    tx(function (&$db) {
        $u = !empty($_SESSION['uid']) ? find_user($db, $_SESSION['uid']) : null;
        out(['user' => $u ? pub_user($u) : null, 'csrf' => $_SESSION['csrf'] ?? null]);
    });

case 'register':
    $b = body();
    $code = strtoupper(str($b['code'] ?? '', 40));
    $username = str($b['username'] ?? '', 40);
    $pass = (string)($b['password'] ?? '');
    $first = str($b['first'] ?? '', 60); $last = str($b['last'] ?? '', 60);
    $phone = str($b['phone'] ?? '', 40); $email = str($b['email'] ?? '', 120);
    $dept = (string)($b['dept'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) fail('bad_username');
    if (strlen($pass) < 8) fail('short_password');
    if ($first === '' || $last === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('missing_fields');
    tx(function (&$db) use ($code, $username, $pass, $first, $last, $phone, $email, $dept) {
        $codes = json_decode((string)@file_get_contents(TEAM_CODES), true) ?: [];
        $ci = -1;
        foreach ($codes as $i => $c) if (hash_equals($c['code'], $code)) $ci = $i;
        if ($ci < 0) fail('bad_code');
        if (!empty($codes[$ci]['usedBy'])) fail('code_used');
        foreach ($db['users'] as $u) if (strcasecmp($u['username'], $username) === 0) fail('username_taken');
        $role = $codes[$ci]['role'];
        if ($role === 'member' && !in_array($dept, DEPTS, true)) fail('pick_dept');
        if ($role === 'itadmin') $dept = 'it';
        if ($role === 'manager') $dept = null;
        $id = nid($db);
        $db['users'][] = ['id' => $id, 'username' => $username, 'pass' => password_hash($pass, PASSWORD_DEFAULT),
            'first' => $first, 'last' => $last, 'phone' => $phone, 'email' => $email, 'role' => $role, 'dept' => $dept,
            'avatar' => null, 'avatarV' => 0, 'createdAt' => time()];
        $codes[$ci]['usedBy'] = $id;
        $codes[$ci]['usedAt'] = time();
        file_put_contents(TEAM_CODES, json_encode($codes, JSON_PRETTY_PRINT));
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    });

case 'login':
    $b = body();
    $_SESSION['fails'] = array_filter($_SESSION['fails'] ?? [], fn($t) => $t > time() - 900);
    if (count($_SESSION['fails']) >= 6) fail('too_many', 429);
    tx(function (&$db) use ($b) {
        foreach ($db['users'] as $u) {
            if (strcasecmp($u['username'], str($b['username'] ?? '', 40)) === 0 && password_verify((string)($b['password'] ?? ''), $u['pass'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['fails'] = [];
                out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
            }
        }
        $_SESSION['fails'][] = time();
        fail('invalid', 401);
    });

case 'logout':
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);

case 'state':
    // One polling call: users, departments, visible tasks, chat since ?since=, notifications
    $since = (int)($_GET['since'] ?? 0);
    tx(function (&$db) use ($since) {
        housekeeping($db);
        $u = me($db);
        $now = time();
        $depts = [];
        foreach (DEPTS as $d) {
            $open = count(array_filter($db['tasks'], fn($t) => $t['dept'] === $d && $t['status'] === 'open'));
            $depts[] = ['id' => $d, 'open' => $open, 'color' => $open === 0 ? 'green' : ($open === 1 ? 'yellow' : 'red'),
                'members' => array_values(array_map(fn($x) => $x['id'], array_filter($db['users'], fn($x) => $x['dept'] === $d)))];
        }
        $tasks = array_values(array_filter($db['tasks'], fn($t) => can_see($u, $t)));
        $msgs = array_values(array_filter($db['messages'], fn($m) => $m['id'] > $since &&
            ($m['to'] === 'public' || $m['to'] === $u['id'] || $m['from'] === $u['id'])));
        $notifs = array_values(array_filter($db['notifs'], fn($n) => $n['to'] === $u['id']));
        $notifs = array_slice($notifs, -50);
        $stats = null;
        if (is_admin($u)) {
            $stats = ['active' => 0, 'overdue' => 0, 'done' => 0, 'cancelled' => 0, 'inbox' => 0];
            foreach ($db['tasks'] as $t) {
                if ($t['status'] === 'done') $stats['done']++;
                elseif ($t['status'] === 'cancelled') $stats['cancelled']++;
                elseif ($t['dept'] === null) $stats['inbox']++;
                elseif ($t['dueAt'] && $t['dueAt'] < $now) $stats['overdue']++;
                else $stats['active']++;
            }
        }
        out(['me' => pub_user($u), 'now' => $now, 'users' => array_map('pub_user', $db['users']), 'depts' => $depts,
            'tasks' => $tasks, 'messages' => $msgs, 'notifs' => $notifs, 'stats' => $stats]);
    });

case 'profile':
    $b = body();
    tx(function (&$db) use ($b) {
        $u = me($db);
        foreach ($db['users'] as &$x) {
            if ($x['id'] !== $u['id']) continue;
            foreach (['first' => 60, 'last' => 60, 'phone' => 40] as $k => $max) if (isset($b[$k]) && str($b[$k], $max) !== '') $x[$k] = str($b[$k], $max);
            if (isset($b['email'])) { if (!filter_var($b['email'], FILTER_VALIDATE_EMAIL)) fail('bad_email'); $x['email'] = str($b['email'], 120); }
            if (!empty($b['newPassword'])) {
                if (!password_verify((string)($b['currentPassword'] ?? ''), $x['pass'])) fail('wrong_password');
                if (strlen((string)$b['newPassword']) < 8) fail('short_password');
                $x['pass'] = password_hash((string)$b['newPassword'], PASSWORD_DEFAULT);
            }
        }
        unset($x);
        out(['ok' => true]);
    });

case 'avatar_upload':
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) fail('upload_failed');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || !@getimagesize($f['tmp_name'])) fail('not_image');
    tx(function (&$db) use ($f, $mime) {
        $u = me($db);
        move_uploaded_file($f['tmp_name'], TEAM_AVATAR . '/' . $u['id']);
        foreach ($db['users'] as &$x) if ($x['id'] === $u['id']) { $x['avatar'] = $mime; $x['avatarV']++; }
        unset($x);
        out(['ok' => true]);
    });

case 'avatar':
    tx(function (&$db) {
        me($db);
        $u = find_user($db, (int)($_GET['id'] ?? 0));
        if (!$u || !$u['avatar'] || !is_file(TEAM_AVATAR . '/' . $u['id'])) fail('not_found', 404);
        header('Content-Type: ' . $u['avatar']);
        header('Cache-Control: private, max-age=86400');
        readfile(TEAM_AVATAR . '/' . $u['id']);
        exit;
    });

case 'task_create':
    $b = body();
    tx(function (&$db) use ($b) {
        $u = me($db);
        if (!is_admin($u)) fail('forbidden', 403);
        $title = str($b['title'] ?? '', 200);
        if ($title === '') fail('missing_title');
        $dept = in_array($b['dept'] ?? null, DEPTS, true) ? $b['dept'] : null;
        $hours = max(0, (float)($b['hours'] ?? 0));
        $t = ['id' => nid($db), 'title' => $title, 'desc' => str($b['desc'] ?? '', 5000),
            'priority' => in_array($b['priority'] ?? '', ['low', 'normal', 'high'], true) ? $b['priority'] : 'normal',
            'dept' => $dept, 'status' => 'open', 'createdBy' => $u['id'], 'createdAt' => time(),
            'dueAt' => $hours > 0 ? time() + (int)round($hours * 3600) : null,
            'doneAt' => null, 'doneBy' => null, 'files' => [], 'comments' => [], 'log' => []];
        log_task($t, $u['id'], 'created');
        if ($dept) { log_task($t, $u['id'], 'assigned', ['dept' => $dept]); notify($db, $dept, 'task_assigned', ['task' => $t['id'], 'title' => $title], $u['id']); }
        $db['tasks'][] = $t;
        out(['ok' => true, 'id' => $t['id']]);
    });

case 'task_move':     // drag & drop into a department (or back to the inbox)
case 'task_status':   // done / cancelled / open
case 'task_comment':
    $b = body();
    tx(function (&$db) use ($b, $action) {
        $u = me($db);
        $i = task_idx($db, (int)($b['id'] ?? 0));
        if ($i < 0 || !can_see($u, $db['tasks'][$i])) fail('not_found', 404);
        $t = &$db['tasks'][$i];
        if ($action === 'task_move') {
            if (!is_admin($u)) fail('forbidden', 403);
            $dept = in_array($b['dept'] ?? null, DEPTS, true) ? $b['dept'] : null;
            if ($t['dept'] === $dept) out(['ok' => true]);
            $t['dept'] = $dept;
            log_task($t, $u['id'], $dept ? 'assigned' : 'unassigned', ['dept' => $dept]);
            if ($dept) notify($db, $dept, 'task_assigned', ['task' => $t['id'], 'title' => $t['title']], $u['id']);
        } elseif ($action === 'task_status') {
            $s = $b['status'] ?? '';
            if (!in_array($s, ['done', 'cancelled', 'open'], true)) fail('bad_status');
            if ($s !== 'done' && !is_admin($u)) fail('forbidden', 403);
            if ($s === 'done' && $t['dept'] === null) fail('not_assigned');
            $t['status'] = $s;
            $t['doneAt'] = $s === 'done' ? time() : null;
            $t['doneBy'] = $s === 'done' ? $u['id'] : null;
            unset($t['warnedAt']);
            log_task($t, $u['id'], $s === 'open' ? 'reopened' : $s);
            notify($db, $t['dept'], 'task_' . $s, ['task' => $t['id'], 'title' => $t['title']], $u['id']);
        } else {
            $text = str($b['text'] ?? '', 3000);
            if ($text === '') fail('empty');
            $t['comments'][] = ['id' => nid($db), 'by' => $u['id'], 'at' => time(), 'text' => $text];
            notify($db, $t['dept'], 'task_comment', ['task' => $t['id'], 'title' => $t['title']], $u['id']);
        }
        unset($t);
        out(['ok' => true]);
    });

case 'file_upload':
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('upload_failed');
    tx(function (&$db) use ($f) {
        $u = me($db);
        $i = task_idx($db, (int)($_POST['id'] ?? 0));
        if ($i < 0 || !can_see($u, $db['tasks'][$i])) fail('not_found', 404);
        $stored = bin2hex(random_bytes(16));
        if (!move_uploaded_file($f['tmp_name'], TEAM_FILES . '/' . $stored)) fail('write_failed', 500);
        $t = &$db['tasks'][$i];
        $name = preg_replace('/[\x00-\x1f"\\\\\/]/', '_', basename((string)$f['name']));
        $t['files'][] = ['id' => nid($db), 'name' => str($name, 150), 'size' => (int)$f['size'], 'stored' => $stored, 'by' => $u['id'], 'at' => time()];
        log_task($t, $u['id'], 'file', ['name' => $name]);
        notify($db, $t['dept'], 'task_file', ['task' => $t['id'], 'title' => $t['title']], $u['id']);
        unset($t);
        out(['ok' => true]);
    });

case 'file':
    tx(function (&$db) {
        $u = me($db);
        $i = task_idx($db, (int)($_GET['task'] ?? 0));
        if ($i < 0 || !can_see($u, $db['tasks'][$i])) fail('not_found', 404);
        foreach ($db['tasks'][$i]['files'] as $f) {
            if ($f['id'] === (int)($_GET['id'] ?? 0) && is_file(TEAM_FILES . '/' . $f['stored'])) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $f['name'] . '"; filename*=UTF-8\'\'' . rawurlencode($f['name']));
                header('Content-Length: ' . filesize(TEAM_FILES . '/' . $f['stored']));
                readfile(TEAM_FILES . '/' . $f['stored']);
                exit;
            }
        }
        fail('not_found', 404);
    });

case 'chat_send':
    $b = body();
    tx(function (&$db) use ($b) {
        $u = me($db);
        $text = str($b['text'] ?? '', 3000);
        if ($text === '') fail('empty');
        $to = $b['to'] ?? 'public';
        if ($to !== 'public') {
            $peer = find_user($db, (int)$to);
            // Private chat only between an employee and the management (manager / IT general)
            if (!$peer || $peer['id'] === $u['id'] || (!is_admin($u) && !is_admin($peer))) fail('forbidden', 403);
            $to = $peer['id'];
        }
        $db['messages'][] = ['id' => nid($db), 'from' => $u['id'], 'to' => $to, 'at' => time(), 'text' => $text];
        if (count($db['messages']) > 5000) $db['messages'] = array_slice($db['messages'], -5000);
        out(['ok' => true]);
    });

case 'notifs_read':
    tx(function (&$db) {
        $u = me($db);
        foreach ($db['notifs'] as &$n) if ($n['to'] === $u['id']) $n['read'] = true;
        unset($n);
        out(['ok' => true]);
    });

default:
    fail('unknown_action');
}
} catch (Reply $r) {
    send($r);
}
