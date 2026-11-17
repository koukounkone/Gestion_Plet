<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use PDO;
use RuntimeException;
use Throwable;

final class ProfilePhotoService
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const MAX_IMAGE_PIXELS = 20_000_000;
    private const MAX_IMAGE_DIMENSION = 10_000;
    private const AVATAR_SIZE = 512;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function storeUploadedFile(int $userId, mixed $upload): void
    {
        if (!is_array($upload)) {
            throw new RuntimeException('Sélectionnez une image à importer.');
        }

        $uploadError = filter_var($upload['error'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($uploadError) {
                UPLOAD_ERR_NO_FILE => 'Sélectionnez une image à importer.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'L’image dépasse la taille maximale autorisée de 5 Mio.',
                UPLOAD_ERR_PARTIAL => 'L’image n’a été reçue que partiellement. Réessayez.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Le serveur n’a pas pu recevoir l’image.',
                default => 'L’import de l’image a échoué. Réessayez.',
            });
        }

        $temporaryPath = is_string($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : '';
        $fileSize = filter_var($upload['size'] ?? null, FILTER_VALIDATE_INT);
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)
            || !is_int($fileSize) || $fileSize < 1 || $fileSize > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Choisissez une image valide de 5 Mio maximum.');
        }

        $imageData = file_get_contents($temporaryPath);
        if (!is_string($imageData)) {
            throw new RuntimeException('L’image n’a pas pu être lue. Réessayez.');
        }

        $this->storeImageData($userId, $imageData);
    }

    public function storeImageData(int $userId, string $imageData): void
    {
        $photo = self::normalizeImageData($imageData);

        try {
            $this->pdo->beginTransaction();
            $user = $this->pdo->prepare(
                "SELECT id FROM users WHERE id = :id AND status = 'active' LIMIT 1 FOR UPDATE"
            );
            $user->execute(['id' => $userId]);
            if ($user->fetchColumn() === false) {
                throw new RuntimeException('Compte introuvable ou désactivé.');
            }

            $update = $this->pdo->prepare(
                'UPDATE users
                 SET profile_photo_data = :photo, profile_photo_mime_type = :mime_type
                 WHERE id = :id'
            );
            $update->bindValue(':photo', $photo['data'], PDO::PARAM_LOB);
            $update->bindValue(':mime_type', $photo['mime_type'], PDO::PARAM_STR);
            $update->bindValue(':id', $userId, PDO::PARAM_INT);
            $update->execute();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function imageForUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT profile_photo_data, profile_photo_mime_type
             FROM users
             WHERE id = :id AND status = 'active'
               AND profile_photo_data IS NOT NULL
               AND profile_photo_mime_type IS NOT NULL
             LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        $photo = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($photo)
            || !is_string($photo['profile_photo_data'] ?? null)
            || ($photo['profile_photo_mime_type'] ?? null) !== 'image/webp') {
            return null;
        }

        return [
            'data' => $photo['profile_photo_data'],
            'mime_type' => $photo['profile_photo_mime_type'],
        ];
    }

    public static function normalizeImageData(string $imageData): array
    {
        if (strlen($imageData) < 1 || strlen($imageData) > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Choisissez une image valide de 5 Mio maximum.');
        }

        $imageInfo = @getimagesizefromstring($imageData);
        $mimeType = is_array($imageInfo) && is_string($imageInfo['mime'] ?? null)
            ? $imageInfo['mime']
            : '';
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Format non accepté. Utilisez une image JPEG, PNG ou WEBP.');
        }
        if (!class_exists(\finfo::class)) {
            throw new RuntimeException('Le serveur doit activer l’extension PHP fileinfo pour importer une photo.');
        }
        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($imageData);
        if (!is_string($detectedMimeType) || $detectedMimeType !== $mimeType) {
            throw new RuntimeException('Le contenu de cette image est invalide.');
        }

        $width = filter_var($imageInfo[0] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($imageInfo[1] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($width) || !is_int($height)
            || $width < 1 || $height < 1
            || $width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION
            || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new RuntimeException('Les dimensions de cette image sont trop importantes.');
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new RuntimeException('Le serveur doit activer GD avec la prise en charge WEBP pour importer une photo.');
        }

        $source = @imagecreatefromstring($imageData);
        if (!$source instanceof \GdImage) {
            throw new RuntimeException('Le contenu de cette image est invalide.');
        }

        $scale = min(1, self::AVATAR_SIZE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof \GdImage) {
            imagedestroy($source);
            throw new RuntimeException('Le serveur n’a pas pu préparer cette image.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $resampled = imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );
        imagedestroy($source);
        if (!$resampled) {
            imagedestroy($target);
            throw new RuntimeException('Le serveur n’a pas pu préparer cette image.');
        }

        ob_start();
        try {
            $encoded = imagewebp($target, null, 82);
            $normalizedData = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($target);
        }

        if (!$encoded || !is_string($normalizedData) || $normalizedData === '') {
            throw new RuntimeException('Le serveur n’a pas pu enregistrer cette image.');
        }

        return ['data' => $normalizedData, 'mime_type' => 'image/webp'];
    }
}
