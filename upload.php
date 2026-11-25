<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

$dir = __DIR__ . '/menus/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

// ✅ استخدم نفس generateShortId من JS
function generateShortId($length = 6) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

$id = generateShortId();

// ✅ الملف دايمًا بامتداد PDF بغض النظر عن النوع الأصلي
// أو احتفظ بالامتداد الأصلي
$filename = $id . '.' . $ext;
$dest = $dir . $filename;

if (move_uploaded_file($file['tmp_name'], $dest)) {
    $base = 'https://tap-werbung.de/menus/';
    $url  = $base . $filename;

    echo json_encode([
        'url'      => $url,       // https://.../menus/wXuK7g.pdf
        'filename' => $filename,  // wXuK7g.pdf
        'id'       => $id         // wXuK7g
    ]);
} else {
    echo json_encode(['error' => 'Could not save file']);
}