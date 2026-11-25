<?php
require __DIR__ . '/_lib.php';

$action = $_GET['action'] ?? 'me';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

function attempts_load(): array {
    $a = is_file(ATTEMPTS_FILE) ? json_decode((string)file_get_contents(ATTEMPTS_FILE), true) : [];
    $a = is_array($a) ? $a : [];
    $now = time();
    foreach ($a as $k => $list) {
        $a[$k] = array_values(array_filter((array)$list, fn($t) => $t > $now - 900));
        if (!$a[$k]) unset($a[$k]);
    }
    return $a;
}

switch ($action) {
    case 'me':
        json_out([
            'loggedIn' => !empty($_SESSION['user']),
            'user'     => $_SESSION['user'] ?? null,
            'csrf'     => $_SESSION['csrf'] ?? null,
        ]);

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $att = attempts_load();
        if (count($att[$ip] ?? []) >= 5) json_out(['error' => 'too_many_attempts'], 429);
        $b = body_json();
        $user = trim((string)($b['username'] ?? ''));
        $pass = (string)($b['password'] ?? '');
        $users = load_users();
        if ($user !== '' && isset($users[$user]) && password_verify($pass, $users[$user])) {
            unset($att[$ip]);
            atomic_write(ATTEMPTS_FILE, json_encode($att));
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            json_out(['ok' => true, 'user' => $user, 'csrf' => $_SESSION['csrf']]);
        }
        $att[$ip][] = time();
        atomic_write(ATTEMPTS_FILE, json_encode($att));
        json_out(['error' => 'invalid_credentials'], 401);

    case 'logout':
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => true]);

    case 'change':
        require_auth();
        $b = body_json();
        $users = load_users();
        $cur = $_SESSION['user'];
        if (!isset($users[$cur]) || !password_verify((string)($b['currentPassword'] ?? ''), $users[$cur])) {
            json_out(['error' => 'wrong_password'], 400);
        }
        $newUser = trim((string)($b['newUsername'] ?? '')) ?: $cur;
        $newPass = (string)($b['newPassword'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._@-]{3,40}$/', $newUser)) json_out(['error' => 'bad_username'], 400);
        if ($newPass !== '' && strlen($newPass) < 8) json_out(['error' => 'short_password'], 400);
        $hash = $newPass !== '' ? password_hash($newPass, PASSWORD_DEFAULT) : $users[$cur];
        unset($users[$cur]);
        $users[$newUser] = $hash;
        if (!save_users($users)) json_out(['error' => 'write_failed'], 500);
        $_SESSION['user'] = $newUser;
        json_out(['ok' => true, 'user' => $newUser]);

    default:
        json_out(['error' => 'unknown_action'], 400);
}
