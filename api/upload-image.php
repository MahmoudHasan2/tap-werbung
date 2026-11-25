<?php
require __DIR__ . '/_lib.php';
require_auth();

$f = $_FILES['file'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'upload_failed'], 400);
if ($f['size'] > 10 * 1024 * 1024) json_out(['error' => 'too_large'], 413);

$types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
if (!isset($types[$mime]) || @getimagesize($f['tmp_name']) === false) json_out(['error' => 'not_an_image'], 400);

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
$name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $types[$mime];
if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) json_out(['error' => 'write_failed'], 500);
json_out(['ok' => true, 'url' => 'uploads/' . $name]);
