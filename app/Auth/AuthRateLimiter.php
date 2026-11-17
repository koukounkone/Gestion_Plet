<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use PDO;
use RuntimeException;

final class AuthRateLimiter
{
    private const LOGIN_LIMIT = 5;
    private const RESET_LIMIT = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function key(string $scope, string $identity, string $ipAddress, string $pepper): string
    {
        return hash_hmac(
            'sha256',
            $scope . "\0" . $identity . "\0" . $ipAddress,
            $pepper
        );
    }

    public function assertAllowed(string $key): void
    {
        $statement = $this->pdo->prepare(
            'SELECT blocked_until > UTC_TIMESTAMP()
             FROM login_attempts
             WHERE identifier_hash = :identifier_hash'
        );
        $statement->execute(['identifier_hash' => $key]);
        if ((int) $statement->fetchColumn() === 1) {
            throw new RuntimeException('Trop de demandes. Réessayez plus tard.');
        }
    }

    public function failedLogin(string $key): void
    {
        $this->increment($key, self::LOGIN_LIMIT);
    }

    public function consumeResetRequest(string $key, int $limit = self::RESET_LIMIT): void
    {
        $this->increment($key, $limit);
    }

    public function clear(string $key): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE identifier_hash = :identifier_hash'
        );
        $statement->execute(['identifier_hash' => $key]);
    }

    private function increment(string $key, int $limit): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO login_attempts
                    (identifier_hash, attempts, window_started_at, blocked_until)
                 VALUES (:identifier_hash, 0, UTC_TIMESTAMP(), NULL)'
            );
            $insert->execute(['identifier_hash' => $key]);

            $select = $this->pdo->prepare(
                'SELECT attempts,
                        window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) AS expired
                 FROM login_attempts
                 WHERE identifier_hash = :identifier_hash
                 FOR UPDATE'
            );
            $select->execute(['identifier_hash' => $key]);
            $current = $select->fetch();
            if (!is_array($current)) {
                throw new RuntimeException('État de limitation des tentatives introuvable.');
            }

            $attempts = (int) $current['expired'] === 1
                ? 1
                : (int) $current['attempts'] + 1;
            $update = $this->pdo->prepare(
                'UPDATE login_attempts
                 SET attempts = :attempts,
                     window_started_at = IF(
                         :is_expired = 1,
                         UTC_TIMESTAMP(),
                         window_started_at
                     ),
                     blocked_until = IF(
                         :attempts_for_block >= :attempt_limit,
                         DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                         NULL
                     )
                 WHERE identifier_hash = :identifier_hash'
            );
            $update->execute([
                'attempts' => $attempts,
                'is_expired' => (int) $current['expired'],
                'attempts_for_block' => $attempts,
                'attempt_limit' => $limit,
                'identifier_hash' => $key,
            ]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
