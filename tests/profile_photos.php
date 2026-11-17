<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\ProfilePhotoService;
use MonElevage\Database\Connection;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Profile photo test failed: ' . $message);
    }
    $passed++;
};
$expectFailure = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (RuntimeException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$makePng = static function (int $width, int $height): string {
    $image = imagecreatetruecolor($width, $height);
    if (!$image instanceof GdImage) {
        throw new RuntimeException('Could not create test image.');
    }
    $background = imagecolorallocate($image, 46, 128, 86);
    imagefill($image, 0, 0, $background);
    ob_start();
    try {
        $written = imagepng($image);
        $data = ob_get_contents();
    } finally {
        ob_end_clean();
        imagedestroy($image);
    }
    if (!$written || !is_string($data)) {
        throw new RuntimeException('Could not encode test image.');
    }
    return $data;
};

if (!function_exists('imagewebp')) {
    throw new RuntimeException('The test requires GD with WEBP support.');
}

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(8));
$userId = null;
try {
    $source = $makePng(800, 400);
    $normalized = ProfilePhotoService::normalizeImageData($source);
    $normalizedInfo = getimagesizefromstring($normalized['data']);
    $assert($normalized['mime_type'] === 'image/webp', 'normalizes the profile image as WEBP');
    $assert(
        is_array($normalizedInfo)
            && $normalizedInfo['mime'] === 'image/webp'
            && $normalizedInfo[0] <= 512
            && $normalizedInfo[1] <= 512,
        'resizes the image without exceeding 512 pixels'
    );

    $expectFailure(
        static fn () => ProfilePhotoService::normalizeImageData('document, not an image'),
        'rejects documents and unsupported formats'
    );
    $oversized = $source . str_repeat('x', 5 * 1024 * 1024);
    $expectFailure(
        static fn () => ProfilePhotoService::normalizeImageData($oversized),
        'rejects images larger than 5 MiB'
    );
    $excessiveDimensions = substr_replace($source, pack('N', 5000), 16, 4);
    $excessiveDimensions = substr_replace($excessiveDimensions, pack('N', 5000), 20, 4);
    $expectFailure(
        static fn () => ProfilePhotoService::normalizeImageData($excessiveDimensions),
        'rejects excessive pixel dimensions before decoding'
    );

    $email = 'profile-photo-' . $suffix . '@example.test';
    $insertUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES ('Profile photo test', :email, :password_hash, 'active', UTC_TIMESTAMP())"
    );
    $insertUser->execute([
        'email' => $email,
        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();

    $service = new ProfilePhotoService($pdo);
    $service->storeImageData($userId, $source);
    $savedPhoto = $service->imageForUser($userId);
    $assert(
        is_array($savedPhoto)
            && $savedPhoto['mime_type'] === 'image/webp'
            && hash_equals($normalized['data'], $savedPhoto['data']),
        'stores and retrieves the profile image for its account'
    );
    $replacement = $makePng(200, 100);
    $service->storeImageData($userId, $replacement);
    $replacedPhoto = $service->imageForUser($userId);
    $replacementInfo = is_array($replacedPhoto)
        ? getimagesizefromstring($replacedPhoto['data'])
        : false;
    $assert(
        is_array($replacementInfo)
            && $replacementInfo[0] === 200
            && $replacementInfo[1] === 100,
        'replaces the previously saved photo with a new one'
    );
    $assert($service->imageForUser(0) === null, 'returns no image for another user id');
    $expectFailure(
        static fn () => $service->storeImageData(0, $source),
        'refuses to store an image for a missing account'
    );

    echo 'OK — ' . $passed . ' profile photo assertions.' . PHP_EOL;
} finally {
    if (is_int($userId)) {
        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $deleteUser->execute(['id' => $userId]);
    }
}
