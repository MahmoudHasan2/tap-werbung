<?php
// Shared helpers for the TAP CMS API
declare(strict_types=1);

const DATA_DIR      = __DIR__ . '/../data';
const CONTENT_FILE  = DATA_DIR . '/content.json';
const USERS_FILE    = DATA_DIR . '/users.php';
const ATTEMPTS_FILE = DATA_DIR . '/login_attempts.json';
const UPLOAD_DIR    = __DIR__ . '/../uploads';

// Initial login (created on first use) — change it in the dashboard right away
const DEFAULT_USER = 'admin';
const DEFAULT_PASS = 'CHANGE_ME';

session_name('TAPCMS');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function json_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?: '[]', true);
    return is_array($d) ? $d : [];
}

function atomic_write(string $path, string $data): bool {
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

function load_users(): array {
    if (!is_file(USERS_FILE)) {
        $users = [DEFAULT_USER => password_hash(DEFAULT_PASS, PASSWORD_DEFAULT)];
        save_users($users);
        return $users;
    }
    $raw = (string)file_get_contents(USERS_FILE);
    $json = substr($raw, strpos($raw, "\n") + 1);
    $u = json_decode($json, true);
    return is_array($u) ? $u : [];
}

function save_users(array $users): bool {
    // The PHP guard line stops the file from being readable over the web
    return atomic_write(USERS_FILE, "<?php http_response_code(404); exit; ?>\n" . json_encode($users));
}

function require_auth(): void {
    if (empty($_SESSION['user'])) json_out(['error' => 'not_logged_in'], 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            json_out(['error' => 'bad_csrf'], 403);
        }
    }
}
