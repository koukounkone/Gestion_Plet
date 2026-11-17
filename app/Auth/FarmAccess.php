<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use PDO;
use RuntimeException;

final class FarmAccess
{
    private const ROLE_PERMISSIONS = [
        'owner' => [
            'farm.view', 'flock.view', 'flock.manage', 'followup.write',
            'stock.view', 'stock.manage', 'expense.view', 'expense.manage',
            'sale.view', 'sale.manage', 'payment.manage', 'report.view',
            'member.manage', 'health.view', 'health.manage',
        ],
        'manager' => [
            'farm.view', 'flock.view', 'flock.manage', 'followup.write',
            'stock.view', 'stock.manage', 'expense.view', 'expense.manage',
            'sale.view', 'sale.manage', 'payment.manage', 'report.view',
            'member.manage', 'health.view', 'health.manage',
        ],
        'operator' => [
            'farm.view', 'flock.view', 'followup.write', 'stock.view',
        ],
        'accountant' => [
            'farm.view', 'flock.view', 'stock.view', 'expense.view',
            'sale.view', 'payment.manage', 'report.view',
        ],
    ];

    public static function permissionCatalog(): array
    {
        return [
            'farm.view' => 'Consulter l’exploitation',
            'flock.view' => 'Consulter les élevages',
            'flock.manage' => 'Gérer les élevages',
            'followup.write' => 'Enregistrer les suivis',
            'stock.view' => 'Consulter le catalogue et les stocks',
            'stock.manage' => 'Gérer le catalogue et les stocks',
            'expense.view' => 'Consulter les finances',
            'expense.manage' => 'Gérer les dépenses et budgets',
            'sale.view' => 'Consulter les ventes',
            'sale.manage' => 'Gérer les clients et les ventes',
            'payment.manage' => 'Enregistrer les paiements',
            'report.view' => 'Générer et consulter les rapports',
            'member.manage' => 'Gérer les membres de l’exploitation',
            'health.view' => 'Consulter les données sanitaires',
            'health.manage' => 'Enregistrer le suivi sanitaire',
        ];
    }

    public static function defaultPermissionsForRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    public static function role(PDO $pdo, int $userId, int $farmId): string
    {
        $statement = $pdo->prepare(
            "SELECT fm.role
             FROM farm_members fm
             INNER JOIN users u ON u.id = fm.user_id
             WHERE fm.user_id = :user_id
               AND fm.farm_id = :farm_id
               AND fm.status = 'active'
               AND u.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId, 'farm_id' => $farmId]);
        $role = $statement->fetchColumn();
        if (!is_string($role)) {
            throw new RuntimeException('Accès à cette exploitation refusé.');
        }

        return $role;
    }

    public static function allows(PDO $pdo, int $userId, int $farmId, string $permission): bool
    {
        $role = self::role($pdo, $userId, $farmId);
        $administrator = $pdo->prepare(
            "SELECT is_admin FROM users
             WHERE id = :user_id AND status = 'active'
             LIMIT 1"
        );
        $administrator->execute(['user_id' => $userId]);
        if ((int) $administrator->fetchColumn() === 1) {
            return true;
        }

        $permissions = self::rolePermissions($pdo, $role);
        $permissions = self::applyMemberPermissions($pdo, $userId, $farmId, $permissions);

        return $permissions[$permission] ?? false;
    }

    public static function permissionsForMember(
        PDO $pdo,
        int $userId,
        int $farmId
    ): array {
        $membership = $pdo->prepare(
            'SELECT role FROM farm_members
             WHERE farm_id = :farm_id AND user_id = :user_id'
        );
        $membership->execute(['farm_id' => $farmId, 'user_id' => $userId]);
        $role = $membership->fetchColumn();
        if (!is_string($role)) {
            throw new RuntimeException('Cette adhésion à l’exploitation est introuvable.');
        }

        return self::applyMemberPermissions(
            $pdo,
            $userId,
            $farmId,
            self::rolePermissions($pdo, $role)
        );
    }

    public static function requirePermission(
        PDO $pdo,
        int $userId,
        int $farmId,
        string $permission
    ): void {
        if (!self::allows($pdo, $userId, $farmId, $permission)) {
            throw new RuntimeException('Permission insuffisante pour cette opération.');
        }
    }

    public static function memberships(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            "SELECT f.id, f.name, f.currency_code, f.timezone, fm.role
             FROM farm_members fm
             INNER JOIN farms f ON f.id = fm.farm_id
             INNER JOIN users u ON u.id = fm.user_id
             WHERE fm.user_id = :user_id
               AND fm.status = 'active'
               AND u.status = 'active'
             ORDER BY f.name, f.id"
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    private static function rolePermissions(PDO $pdo, string $role): array
    {
        $permissions = array_fill_keys(array_keys(self::permissionCatalog()), false);
        foreach (self::ROLE_PERMISSIONS[$role] ?? [] as $permission) {
            $permissions[$permission] = true;
        }

        $configured = $pdo->prepare(
            'SELECT permission_key, is_allowed
             FROM system_role_permissions
             WHERE role_name = :role'
        );
        $configured->execute(['role' => $role]);
        $overrides = $configured->fetchAll();
        if ($overrides !== []) {
            $permissions = array_fill_keys(array_keys($permissions), false);
            foreach ($overrides as $override) {
                if (array_key_exists($override['permission_key'], $permissions)) {
                    $permissions[$override['permission_key']] = (int) $override['is_allowed'] === 1;
                }
            }
        }

        return $permissions;
    }

    private static function applyMemberPermissions(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $permissions
    ): array {
        $configured = $pdo->prepare(
            'SELECT permission_key, is_allowed
             FROM farm_member_permission_overrides
             WHERE farm_id = :farm_id AND user_id = :user_id'
        );
        $configured->execute(['farm_id' => $farmId, 'user_id' => $userId]);
        foreach ($configured->fetchAll() as $override) {
            if (array_key_exists($override['permission_key'], $permissions)) {
                $permissions[$override['permission_key']] = (int) $override['is_allowed'] === 1;
            }
        }

        return $permissions;
    }
}
