<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\ProfilePhotoService;
use MonElevage\Database\Connection;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
if ($userId === null) {
    http_response_code(404);
    exit;
}

$photo = (new ProfilePhotoService($pdo))->imageForUser($userId);
if ($photo === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $photo['mime_type']);
header('Content-Length: ' . strlen($photo['data']));
header('Content-Disposition: inline; filename="profile-photo.webp"');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Cross-Origin-Resource-Policy: same-origin');
echo $photo['data'];
