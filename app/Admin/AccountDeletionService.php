<?php
declare(strict_types=1);

namespace MonElevage\Admin;

use PDO;
use RuntimeException;
use Throwable;

final class AccountDeletionService
{
    public static function archiveData(PDO $pdo, int $actorId, int $userId): array
    {
        AdminService::requireAdministrator($pdo, $actorId);
        $user = self::user($pdo, $userId);
        $user['is_admin'] = (int) $user['is_admin'];
        $farms = self::ownedFarms($pdo, $userId);
        $tables = self::farmScopedTables($pdo);
        $farmData = [];

        foreach ($farms as $farm) {
            $farmId = (int) $farm['id'];
            $membersQuery = $pdo->prepare(
                'SELECT fm.user_id, u.full_name, u.email, fm.role, fm.status, fm.joined_at
                 FROM farm_members fm
                 INNER JOIN users u ON u.id = fm.user_id
                 WHERE fm.farm_id = :farm_id
                 ORDER BY u.full_name, fm.user_id'
            );
            $membersQuery->execute(['farm_id' => $farmId]);
            $tableData = [];
            foreach ($tables as $table) {
                $query = $pdo->prepare('SELECT * FROM ' . self::quoteIdentifier($table)
                    . ' WHERE farm_id = :farm_id');
                $query->execute(['farm_id' => $farmId]);
                $rows = $query->fetchAll();
                if ($rows !== []) {
                    $tableData[$table] = $rows;
                }
            }
            $farmData[] = [
                'farm' => $farm,
                'members' => $membersQuery->fetchAll(),
                'tables' => $tableData,
            ];
        }

        return [
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'user' => $user,
            'farms' => $farmData,
        ];
    }

    public static function deleteUser(PDO $pdo, int $actorId, int $userId): array
    {
        AdminService::requireAdministrator($pdo, $actorId);
        if ($userId < 1 || $userId === $actorId) {
            throw new RuntimeException('Vous ne pouvez pas supprimer votre propre compte administrateur.');
        }

        $filesToRemove = [];
        $deletedFarmCount = 0;
        self::transaction($pdo, static function () use (
            $pdo,
            $actorId,
            $userId,
            &$filesToRemove,
            &$deletedFarmCount
        ): void {
            $query = $pdo->prepare(
                'SELECT id, full_name, email, is_admin
                 FROM users WHERE id = :user_id FOR UPDATE'
            );
            $query->execute(['user_id' => $userId]);
            $user = $query->fetch();
            if (!is_array($user)) {
                throw new RuntimeException('Le compte demandé est introuvable.');
            }
            if ((int) $user['is_admin'] === 1) {
                throw new RuntimeException('Un compte administrateur ne peut pas être supprimé depuis cette page.');
            }

            $farms = self::ownedFarms($pdo, $userId, true);
            $membershipCheck = $pdo->prepare(
                'SELECT fm.farm_id
                 FROM farm_members fm
                 LEFT JOIN farms f ON f.id = fm.farm_id
                 WHERE fm.user_id = :user_id
                   AND (f.owner_user_id IS NULL OR f.owner_user_id <> :owner_user_id)
                 LIMIT 1'
            );
            $membershipCheck->execute([
                'user_id' => $userId,
                'owner_user_id' => $userId,
            ]);
            if ($membershipCheck->fetchColumn() !== false) {
                throw new RuntimeException(
                    'Ce compte est membre d’une exploitation appartenant à un autre compte. '
                    . 'Retirez-le de cette exploitation avant de le supprimer.'
                );
            }

            $farmIds = array_map(static fn (array $farm): int => (int) $farm['id'], $farms);
            foreach ($farmIds as $farmId) {
                $filesToRemove = [
                    ...$filesToRemove,
                    ...self::privateFarmFiles($pdo, $farmId),
                ];
            }

            if ($farmIds !== []) {
                $farmTables = self::farmScopedTables($pdo);
                self::breakSelfReferences($pdo, $farmTables, $farmIds);
                $orderedTables = self::farmDeletionOrder($pdo, $farmTables);
                foreach ($farmIds as $farmId) {
                    foreach ($orderedTables as $table) {
                        $delete = $pdo->prepare('DELETE FROM ' . self::quoteIdentifier($table)
                            . ' WHERE farm_id = :farm_id');
                        $delete->execute(['farm_id' => $farmId]);
                    }
                    $deleteFarm = $pdo->prepare('DELETE FROM farms WHERE id = :farm_id');
                    $deleteFarm->execute(['farm_id' => $farmId]);
                }
            }

            $pdo->prepare(
                'UPDATE audit_logs SET actor_user_id = NULL WHERE actor_user_id = :user_id'
            )->execute(['user_id' => $userId]);
            $pdo->prepare(
                'DELETE FROM pending_registrations WHERE email = :email'
            )->execute(['email' => $user['email']]);
            $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);

            self::audit($pdo, $actorId, $userId, (string) $user['email'], count($farmIds));
            $deletedFarmCount = count($farmIds);
        });

        $cleanupFailed = false;
        foreach (array_unique($filesToRemove) as $path) {
            if (is_file($path) && !unlink($path)) {
                $cleanupFailed = true;
                error_log('Unable to remove a private file for a deleted user account.');
            }
        }

        return [
            'farm_count' => $deletedFarmCount,
            'file_cleanup_failed' => $cleanupFailed,
        ];
    }

    private static function user(PDO $pdo, int $userId): array
    {
        if ($userId < 1) {
            throw new RuntimeException('Identifiant utilisateur invalide.');
        }
        $query = $pdo->prepare(
            'SELECT id, full_name, email, status, is_admin, email_verified_at, created_at
             FROM users WHERE id = :user_id'
        );
        $query->execute(['user_id' => $userId]);
        $user = $query->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('Le compte demandé est introuvable.');
        }
        return $user;
    }

    private static function ownedFarms(PDO $pdo, int $userId, bool $lock = false): array
    {
        $query = $pdo->prepare(
            'SELECT id, owner_user_id, name, country_code, currency_code, timezone, created_at
             FROM farms WHERE owner_user_id = :user_id ORDER BY id'
                . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['user_id' => $userId]);
        return $query->fetchAll();
    }

    private static function farmScopedTables(PDO $pdo): array
    {
        $query = $pdo->query(
            'SELECT t.TABLE_NAME
             FROM information_schema.TABLES t
             INNER JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
             WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = \'BASE TABLE\'
               AND c.COLUMN_NAME = \'farm_id\'
             ORDER BY t.TABLE_NAME'
        );
        $tables = array_map(
            static fn (array $row): string => (string) $row['TABLE_NAME'],
            $query->fetchAll()
        );
        foreach ($tables as $table) {
            self::quoteIdentifier($table);
        }
        return $tables;
    }

    private static function farmDeletionOrder(PDO $pdo, array $tables): array
    {
        $tableSet = array_fill_keys($tables, true);
        $query = $pdo->query(
            'SELECT TABLE_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $parents = [];
        foreach ($query->fetchAll() as $row) {
            $child = (string) $row['TABLE_NAME'];
            $parent = (string) $row['REFERENCED_TABLE_NAME'];
            if ($child !== $parent && isset($tableSet[$child], $tableSet[$parent])) {
                $parents[$child][$parent] = true;
            }
        }

        $remaining = $tableSet;
        $order = [];
        while ($remaining !== []) {
            $leaves = [];
            foreach ($remaining as $candidate => $_) {
                $isReferenced = false;
                foreach ($remaining as $child => $_childValue) {
                    if (isset($parents[$child][$candidate])) {
                        $isReferenced = true;
                        break;
                    }
                }
                if (!$isReferenced) {
                    $leaves[] = $candidate;
                }
            }
            if ($leaves === []) {
                throw new RuntimeException(
                    'La suppression est suspendue : les relations entre tables ne peuvent pas être ordonnées sans risque.'
                );
            }
            foreach ($leaves as $table) {
                $order[] = $table;
                unset($remaining[$table]);
            }
        }
        return $order;
    }

    private static function breakSelfReferences(PDO $pdo, array $tables, array $farmIds): void
    {
        $query = $pdo->query(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME, c.IS_NULLABLE
             FROM information_schema.KEY_COLUMN_USAGE k
             INNER JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = k.CONSTRAINT_SCHEMA
              AND c.TABLE_NAME = k.TABLE_NAME
              AND c.COLUMN_NAME = k.COLUMN_NAME
             WHERE k.CONSTRAINT_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_NAME = k.TABLE_NAME'
        );
        $farmIdPlaceholders = implode(',', array_fill(0, count($farmIds), '?'));
        foreach ($query->fetchAll() as $row) {
            $table = (string) $row['TABLE_NAME'];
            $column = (string) $row['COLUMN_NAME'];
            if (!in_array($table, $tables, true) || $row['IS_NULLABLE'] !== 'YES') {
                continue;
            }
            $update = $pdo->prepare(
                'UPDATE ' . self::quoteIdentifier($table)
                . ' SET ' . self::quoteIdentifier($column) . ' = NULL'
                . ' WHERE farm_id IN (' . $farmIdPlaceholders . ')'
                . ' AND ' . self::quoteIdentifier($column) . ' IS NOT NULL'
            );
            $update->execute($farmIds);
        }
    }

    private static function privateFarmFiles(PDO $pdo, int $farmId): array
    {
        $paths = [];
        $expenses = $pdo->prepare(
            'SELECT receipt_storage_key, receipt_mime_type
             FROM expenses WHERE farm_id = :farm_id AND receipt_storage_key IS NOT NULL'
        );
        $expenses->execute(['farm_id' => $farmId]);
        foreach ($expenses->fetchAll() as $receipt) {
            $extension = match ((string) $receipt['receipt_mime_type']) {
                'application/pdf' => '.pdf',
                'image/jpeg' => '.jpg',
                'image/png' => '.png',
                default => null,
            };
            if ($extension !== null && preg_match('/^[a-f0-9]{64}$/D', (string) $receipt['receipt_storage_key']) === 1) {
                $paths[] = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage'
                    . DIRECTORY_SEPARATOR . 'receipts' . DIRECTORY_SEPARATOR
                    . $receipt['receipt_storage_key'] . $extension;
            }
        }

        $reports = $pdo->prepare(
            'SELECT storage_key FROM farm_reports WHERE farm_id = :farm_id'
        );
        $reports->execute(['farm_id' => $farmId]);
        foreach ($reports->fetchAll() as $report) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) $report['storage_key']) === 1) {
                $paths[] = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage'
                    . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR
                    . $report['storage_key'] . '.pdf';
            }
        }
        return $paths;
    }

    private static function audit(
        PDO $pdo,
        int $actorId,
        int $userId,
        string $email,
        int $farmCount
    ): void {
        $query = $pdo->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, action, resource_type, resource_id, details)
             VALUES (:actor_id, \'user.deleted\', \'user\', :user_id, :details)'
        );
        $query->execute([
            'actor_id' => $actorId,
            'user_id' => $userId,
            'details' => json_encode([
                'email' => $email,
                'deleted_farms' => $farmCount,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Un identifiant de table de la base est invalide.');
        }
        return '`' . $identifier . '`';
    }

    private static function transaction(PDO $pdo, callable $operation): void
    {
        $pdo->beginTransaction();
        try {
            $operation();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
