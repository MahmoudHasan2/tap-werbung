<?php
require __DIR__ . '/_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public: the website reads its texts, images and works from here
    echo is_file(CONTENT_FILE) ? file_get_contents(CONTENT_FILE) : '{}';
    exit;
}

require_auth();
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 5 * 1024 * 1024) json_out(['error' => 'too_large'], 413);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['texts']) || !is_array($data['texts'])) json_out(['error' => 'invalid'], 400);

$clean = [
    'texts' => [
        'de' => (array)($data['texts']['de'] ?? []),
        'en' => (array)($data['texts']['en'] ?? []),
        'ar' => (array)($data['texts']['ar'] ?? []),
    ],
    'images'      => (array)($data['images'] ?? []),
    'links'       => (array)($data['links'] ?? []),
    'collections' => (array)($data['collections'] ?? []),
    'updatedAt'   => date('c'),
    'updatedBy'   => $_SESSION['user'],
];

// Keep the last 15 versions as backups
if (is_file(CONTENT_FILE)) {
    $bdir = DATA_DIR . '/backups';
    if (!is_dir($bdir)) mkdir($bdir, 0755, true);
    copy(CONTENT_FILE, $bdir . '/content-' . date('Ymd-His') . '.json');
    $old = glob($bdir . '/content-*.json') ?: [];
    sort($old);
    while (count($old) > 15) unlink(array_shift($old));
}

$json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!atomic_write(CONTENT_FILE, $json)) json_out(['error' => 'write_failed'], 500);
json_out(['ok' => true, 'updatedAt' => $clean['updatedAt']]);
