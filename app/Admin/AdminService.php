<?php
declare(strict_types=1);

namespace MonElevage\Admin;

use MonElevage\Auth\FarmAccess;
use MonElevage\Training\TrainingService;
use PDO;
use RuntimeException;
use Throwable;

final class AdminService
{
    private const FARM_ROLES = ['owner', 'manager', 'operator', 'accountant'];
    private const MEMBER_STATUSES = ['active', 'invited', 'suspended'];
    private const USER_STATUSES = ['active', 'pending', 'disabled'];
    private const REFERENCE_TYPES = [
        'flock_type',
        'poultry_type',
        'production_type',
        'product_category',
        'expense_category',
    ];
    private const FIXED_REFERENCE_CODES = [
        'flock_type' => [
            'broiler',
            'layer',
            'cockerel',
            'free_range',
            'breeder',
            'quail',
            'turkey',
            'duck',
            'guinea_fowl',
            'goose',
            'pigeon',
        ],
        'poultry_type' => [
            'chicken',
            'turkey',
            'duck',
            'quail',
            'guinea_fowl',
            'goose',
            'pigeon',
            'other',
        ],
        'production_type' => ['meat', 'eggs', 'breeding', 'other'],
    ];
    private const SETTINGS = [
        'application_name' => ['label' => 'Nom du site', 'max' => 80],
        'default_currency' => ['label' => 'Devise par défaut', 'max' => 3],
        'support_email' => ['label' => 'E-mail d’assistance', 'max' => 254],
    ];
    private const THRESHOLDS = [
        'indoor_temperature_high' => ['label' => 'Température intérieure haute', 'unit' => '°C', 'min' => 10, 'max' => 60],
        'indoor_temperature_low' => ['label' => 'Température intérieure basse', 'unit' => '°C', 'min' => -20, 'max' => 35],
        'indoor_humidity_high' => ['label' => 'Humidité intérieure haute', 'unit' => '%', 'min' => 30, 'max' => 100],
        'indoor_humidity_low' => ['label' => 'Humidité intérieure basse', 'unit' => '%', 'min' => 0, 'max' => 80],
        'outdoor_temperature_high' => ['label' => 'Température extérieure haute', 'unit' => '°C', 'min' => 10, 'max' => 60],
        'outdoor_temperature_low' => ['label' => 'Température extérieure basse', 'unit' => '°C', 'min' => -40, 'max' => 20],
    ];
    private const WEATHER_ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    public static function requireAdministrator(PDO $pdo, int $userId): void
    {
        TrainingService::requireAdministrator($pdo, $userId);
    }

    public static function permissions(): array
    {
        return FarmAccess::permissionCatalog();
    }

    public static function roles(): array
    {
        return self::FARM_ROLES;
    }

    public static function referenceTypes(): array
    {
        return self::REFERENCE_TYPES;
    }

    public static function settingsCatalog(): array
    {
        return self::SETTINGS;
    }

    public static function thresholdCatalog(): array
    {
        return self::THRESHOLDS;
    }

    public static function overview(PDO $pdo, int $actorId): array
    {
        self::requireAdministrator($pdo, $actorId);

        return [
            'users' => $pdo->query(
                'SELECT id, full_name, email, status, is_admin, created_at
                 FROM users ORDER BY created_at DESC, id DESC LIMIT 300'
            )->fetchAll(),
            'memberships' => $pdo->query(
                'SELECT fm.farm_id, fm.user_id, f.name AS farm_name, f.owner_user_id,
                        u.full_name, u.email, fm.role, fm.status
                 FROM farm_members fm
                 INNER JOIN farms f ON f.id = fm.farm_id
                 INNER JOIN users u ON u.id = fm.user_id
                 ORDER BY f.name, u.full_name, fm.user_id LIMIT 500'
            )->fetchAll(),
            'role_permissions' => self::rolePermissionMatrix($pdo),
            'references' => $pdo->query(
                'SELECT id, reference_type, code, label, description, position, status
                 FROM system_reference_items
                 ORDER BY reference_type, position, label, id'
            )->fetchAll(),
            'calendar' => $pdo->query(
                'SELECT poultry_type, production_type, target_cycle_days
                 FROM system_calendar_defaults ORDER BY poultry_type, production_type'
            )->fetchAll(),
            'settings' => $pdo->query(
                'SELECT setting_key, setting_value, updated_at
                 FROM system_settings ORDER BY setting_key'
            )->fetchAll(),
            'thresholds' => $pdo->query(
                'SELECT threshold_key, threshold_value, updated_at
                 FROM system_alert_thresholds ORDER BY threshold_key'
            )->fetchAll(),
            'services' => $pdo->query(
                'SELECT service_key, is_enabled, endpoint_url, updated_at
                 FROM system_external_services ORDER BY service_key'
            )->fetchAll(),
            'audit_logs' => $pdo->query(
                'SELECT a.id, a.action, a.resource_type, a.resource_id, a.farm_id,
                        a.details, a.created_at, u.full_name AS actor_name,
                        u.email AS actor_email, f.name AS farm_name
                 FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.actor_user_id
                 LEFT JOIN farms f ON f.id = a.farm_id
                 ORDER BY a.created_at DESC, a.id DESC LIMIT 150'
            )->fetchAll(),
        ];
    }

    public static function memberPermissions(
        PDO $pdo,
        int $actorId,
        int $userId,
        ?int $farmId = null
    ): array {
        self::requireAdministrator($pdo, $actorId);
        if ($userId < 1) {
            throw new RuntimeException('Identifiant utilisateur invalide.');
        }

        $userQuery = $pdo->prepare(
            'SELECT id, full_name, email, status, is_admin
             FROM users WHERE id = :user_id'
        );
        $userQuery->execute(['user_id' => $userId]);
        $user = $userQuery->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('Le compte demandé est introuvable.');
        }

        $membershipQuery = $pdo->prepare(
            'SELECT fm.farm_id, fm.role, fm.status, f.name AS farm_name
             FROM farm_members fm
             INNER JOIN farms f ON f.id = fm.farm_id
             WHERE fm.user_id = :user_id
             ORDER BY f.name, fm.farm_id'
        );
        $membershipQuery->execute(['user_id' => $userId]);
        $memberships = $membershipQuery->fetchAll();
        $selectedMembership = null;
        if ($farmId !== null) {
            foreach ($memberships as $membership) {
                if ((int) $membership['farm_id'] === $farmId) {
                    $selectedMembership = $membership;
                    break;
                }
            }
            if ($selectedMembership === null) {
                throw new RuntimeException('Cette exploitation n’est pas associée à ce compte.');
            }
        } elseif ($memberships !== []) {
            $selectedMembership = $memberships[0];
        }

        $permissions = [];
        if ((int) $user['is_admin'] === 1) {
            $permissions = array_fill_keys(array_keys(self::permissions()), true);
        } elseif ($selectedMembership !== null) {
            $permissions = FarmAccess::permissionsForMember(
                $pdo,
                $userId,
                (int) $selectedMembership['farm_id']
            );
        }

        return [
            'user' => $user,
            'memberships' => $memberships,
            'selected_membership' => $selectedMembership,
            'permissions' => $permissions,
        ];
    }

    public static function updateUser(
        PDO $pdo,
        int $actorId,
        int $userId,
        array $input
    ): void {
        self::requireAdministrator($pdo, $actorId);
        $name = self::text($input['full_name'] ?? null);
        $status = self::text($input['status'] ?? null);
        if ($userId < 1 || $name === '' || mb_strlen($name, 'UTF-8') > 120) {
            throw new RuntimeException('Le nom de compte doit contenir de 1 à 120 caractères.');
        }
        if (!in_array($status, self::USER_STATUSES, true)) {
            throw new RuntimeException('Le statut de compte demandé est invalide.');
        }
        self::transaction($pdo, static function () use ($pdo, $actorId, $userId, $name, $status): void {
            $query = $pdo->prepare(
                'SELECT full_name, status, is_admin FROM users WHERE id = :id FOR UPDATE'
            );
            $query->execute(['id' => $userId]);
            $existing = $query->fetch();
            if (!is_array($existing)) {
                throw new RuntimeException('Le compte demandé est introuvable.');
            }
            if ($userId === $actorId && $status !== 'active') {
                throw new RuntimeException('Vous ne pouvez pas désactiver votre propre compte administrateur.');
            }
            if ((int) $existing['is_admin'] === 1 && $status !== 'active') {
                throw new RuntimeException('Un compte administrateur ne peut pas être désactivé depuis cette page.');
            }
            $update = $pdo->prepare(
                'UPDATE users SET full_name = :name, status = :status WHERE id = :id'
            );
            $update->execute(['name' => $name, 'status' => $status, 'id' => $userId]);
            self::audit($pdo, $actorId, 'user.updated', 'user', $userId, [
                'previous_status' => (string) $existing['status'],
                'status' => $status,
                'name_changed' => $name !== (string) $existing['full_name'],
            ]);
        });
    }

    public static function updateMembership(
        PDO $pdo,
        int $actorId,
        int $farmId,
        int $userId,
        array $input
    ): void {
        self::requireAdministrator($pdo, $actorId);
        $role = self::text($input['role'] ?? null);
        $status = self::text($input['status'] ?? null);
        if ($farmId < 1 || $userId < 1
            || !in_array($role, self::FARM_ROLES, true)
            || !in_array($status, self::MEMBER_STATUSES, true)) {
            throw new RuntimeException('Le rôle ou le statut d’adhésion est invalide.');
        }
        self::transaction($pdo, static function () use ($pdo, $actorId, $farmId, $userId, $role, $status): void {
            $query = $pdo->prepare(
                'SELECT fm.role, fm.status, f.owner_user_id
                 FROM farm_members fm
                 INNER JOIN farms f ON f.id = fm.farm_id
                 WHERE fm.farm_id = :farm_id AND fm.user_id = :user_id
                 FOR UPDATE'
            );
            $query->execute(['farm_id' => $farmId, 'user_id' => $userId]);
            $existing = $query->fetch();
            if (!is_array($existing)) {
                throw new RuntimeException('Cette adhésion est introuvable.');
            }
            if ((int) $existing['owner_user_id'] === $userId
                && ($role !== 'owner' || $status !== 'active')) {
                throw new RuntimeException('Le propriétaire doit conserver son rôle et son accès actif.');
            }
            $update = $pdo->prepare(
                'UPDATE farm_members SET role = :role, status = :status
                 WHERE farm_id = :farm_id AND user_id = :user_id'
            );
            $update->execute([
                'role' => $role,
                'status' => $status,
                'farm_id' => $farmId,
                'user_id' => $userId,
            ]);
            self::audit($pdo, $actorId, 'membership.updated', 'farm_member', $userId, [
                'farm_id' => $farmId,
                'previous_role' => (string) $existing['role'],
                'role' => $role,
                'previous_status' => (string) $existing['status'],
                'status' => $status,
            ], $farmId);
        });
    }

    public static function saveRolePermissions(
        PDO $pdo,
        int $actorId,
        string $role,
        array $grantedPermissions
    ): void {
        self::requireAdministrator($pdo, $actorId);
        if (!in_array($role, self::FARM_ROLES, true)) {
            throw new RuntimeException('Ce rôle ne peut pas être configuré.');
        }
        $knownPermissions = array_keys(self::permissions());
        foreach ($grantedPermissions as $permission) {
            if (!is_string($permission) || !in_array($permission, $knownPermissions, true)) {
                throw new RuntimeException('Une permission inconnue a été transmise.');
            }
        }
        $grantedPermissions = array_values(array_unique($grantedPermissions));

        self::transaction($pdo, static function () use ($pdo, $actorId, $role, $grantedPermissions, $knownPermissions): void {
            $delete = $pdo->prepare(
                'DELETE FROM system_role_permissions WHERE role_name = :role'
            );
            $delete->execute(['role' => $role]);
            $insert = $pdo->prepare(
                'INSERT INTO system_role_permissions
                    (role_name, permission_key, is_allowed, updated_by_user_id)
                 VALUES (:role, :permission, :allowed, :actor_id)'
            );
            foreach ($knownPermissions as $permission) {
                $insert->execute([
                    'role' => $role,
                    'permission' => $permission,
                    'allowed' => in_array($permission, $grantedPermissions, true) ? 1 : 0,
                    'actor_id' => $actorId,
                ]);
            }
            self::audit($pdo, $actorId, 'role.permissions_updated', 'role', null, [
                'role' => $role,
                'granted_permissions' => $grantedPermissions,
            ]);
        });
    }

    public static function saveMemberPermissions(
        PDO $pdo,
        int $actorId,
        int $farmId,
        int $userId,
        array $grantedPermissions
    ): void {
        self::requireAdministrator($pdo, $actorId);
        if ($farmId < 1 || $userId < 1) {
            throw new RuntimeException('L’utilisateur et l’exploitation sont obligatoires.');
        }

        $knownPermissions = array_keys(self::permissions());
        foreach ($grantedPermissions as $permission) {
            if (!is_string($permission) || !in_array($permission, $knownPermissions, true)) {
                throw new RuntimeException('Une permission inconnue a été transmise.');
            }
        }
        $grantedPermissions = array_values(array_unique($grantedPermissions));

        self::transaction($pdo, static function () use (
            $pdo,
            $actorId,
            $farmId,
            $userId,
            $grantedPermissions,
            $knownPermissions
        ): void {
            $membership = $pdo->prepare(
                'SELECT fm.role, u.is_admin
                 FROM farm_members fm
                 INNER JOIN users u ON u.id = fm.user_id
                 WHERE fm.farm_id = :farm_id AND fm.user_id = :user_id
                 FOR UPDATE'
            );
            $membership->execute(['farm_id' => $farmId, 'user_id' => $userId]);
            $existing = $membership->fetch();
            if (!is_array($existing)) {
                throw new RuntimeException('Cette adhésion à l’exploitation est introuvable.');
            }
            if ((int) $existing['is_admin'] === 1) {
                throw new RuntimeException('Les permissions d’un administrateur du site ne peuvent pas être restreintes.');
            }

            $delete = $pdo->prepare(
                'DELETE FROM farm_member_permission_overrides
                 WHERE farm_id = :farm_id AND user_id = :user_id'
            );
            $delete->execute(['farm_id' => $farmId, 'user_id' => $userId]);
            $insert = $pdo->prepare(
                'INSERT INTO farm_member_permission_overrides
                    (farm_id, user_id, permission_key, is_allowed, updated_by_user_id)
                 VALUES (:farm_id, :user_id, :permission, :allowed, :actor_id)'
            );
            foreach ($knownPermissions as $permission) {
                $insert->execute([
                    'farm_id' => $farmId,
                    'user_id' => $userId,
                    'permission' => $permission,
                    'allowed' => in_array($permission, $grantedPermissions, true) ? 1 : 0,
                    'actor_id' => $actorId,
                ]);
            }
            self::audit($pdo, $actorId, 'member.permissions_updated', 'farm_member', $userId, [
                'role' => (string) $existing['role'],
                'granted_permissions' => $grantedPermissions,
                'denied_permissions' => array_values(array_diff($knownPermissions, $grantedPermissions)),
            ], $farmId);
        });
    }

    public static function saveReference(
        PDO $pdo,
        int $actorId,
        array $input,
        ?int $itemId = null
    ): int {
        self::requireAdministrator($pdo, $actorId);
        $type = self::text($input['reference_type'] ?? null);
        $code = strtolower(self::text($input['code'] ?? null));
        $label = self::text($input['label'] ?? null);
        $description = self::text($input['description'] ?? null);
        $position = filter_var($input['position'] ?? 0, FILTER_VALIDATE_INT);
        $status = self::text($input['status'] ?? 'active');
        if (!in_array($type, self::REFERENCE_TYPES, true)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code)
            || (in_array($type, ['product_category', 'expense_category'], true)
                && strlen($code) > 60)
            || $label === '' || mb_strlen($label, 'UTF-8') > 120
            || mb_strlen($description, 'UTF-8') > 500
            || !is_int($position) || $position < 0 || $position > 65535
            || !in_array($status, ['active', 'archived'], true)) {
            throw new RuntimeException('Les champs du référentiel sont invalides.');
        }
        if (isset(self::FIXED_REFERENCE_CODES[$type])
            && !in_array($code, self::FIXED_REFERENCE_CODES[$type], true)) {
            throw new RuntimeException('Ce code n’existe pas dans le type de données pris en charge.');
        }

        return self::transaction($pdo, static function () use (
            $pdo,
            $actorId,
            $type,
            $code,
            $label,
            $description,
            $position,
            $status,
            $itemId
        ): int {
            $duplicate = $pdo->prepare(
                'SELECT id FROM system_reference_items
                 WHERE reference_type = :type AND code = :code
                 LIMIT 1 FOR UPDATE'
            );
            $duplicate->execute(['type' => $type, 'code' => $code]);
            $existingId = $duplicate->fetchColumn();
            if ($existingId !== false && (int) $existingId !== $itemId) {
                throw new RuntimeException('Ce code est déjà utilisé dans ce type de référence.');
            }
            if ($itemId === null) {
                $save = $pdo->prepare(
                    'INSERT INTO system_reference_items
                        (reference_type, code, label, description, position, status, updated_by_user_id)
                     VALUES (:type, :code, :label, :description, :position, :status, :actor_id)'
                );
                $save->execute([
                    'type' => $type,
                    'code' => $code,
                    'label' => $label,
                    'description' => $description,
                    'position' => $position,
                    'status' => $status,
                    'actor_id' => $actorId,
                ]);
                $savedId = (int) $pdo->lastInsertId();
            } else {
                $save = $pdo->prepare(
                    'UPDATE system_reference_items
                     SET reference_type = :type, code = :code, label = :label,
                         description = :description, position = :position,
                         status = :status, updated_by_user_id = :actor_id
                     WHERE id = :id'
                );
                $save->execute([
                    'type' => $type,
                    'code' => $code,
                    'label' => $label,
                    'description' => $description,
                    'position' => $position,
                    'status' => $status,
                    'actor_id' => $actorId,
                    'id' => $itemId,
                ]);
                if ($save->rowCount() === 0 && !self::referenceExists($pdo, $itemId)) {
                    throw new RuntimeException('L’entrée de référentiel demandée est introuvable.');
                }
                $savedId = $itemId;
            }
            self::audit($pdo, $actorId, 'reference.saved', 'reference_item', $savedId, [
                'type' => $type,
                'code' => $code,
                'status' => $status,
            ]);
            return $savedId;
        });
    }

    public static function saveCalendarDefault(
        PDO $pdo,
        int $actorId,
        array $input
    ): void {
        self::requireAdministrator($pdo, $actorId);
        $poultryType = self::text($input['poultry_type'] ?? null);
        $productionType = self::text($input['production_type'] ?? null);
        $days = filter_var($input['target_cycle_days'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($poultryType, ['chicken', 'turkey', 'duck', 'other'], true)
            || !in_array($productionType, ['meat', 'eggs', 'breeding', 'other'], true)
            || !is_int($days) || $days < 1 || $days > 2000) {
            throw new RuntimeException('La combinaison ou la durée du calendrier est invalide.');
        }
        self::transaction($pdo, static function () use ($pdo, $actorId, $poultryType, $productionType, $days): void {
            $save = $pdo->prepare(
                'INSERT INTO system_calendar_defaults
                    (poultry_type, production_type, target_cycle_days, updated_by_user_id)
                 VALUES (:poultry, :production, :days, :actor_id)
                 ON DUPLICATE KEY UPDATE
                    target_cycle_days = VALUES(target_cycle_days),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = UTC_TIMESTAMP()'
            );
            $save->execute([
                'poultry' => $poultryType,
                'production' => $productionType,
                'days' => $days,
                'actor_id' => $actorId,
            ]);
            self::audit($pdo, $actorId, 'calendar_default.updated', 'calendar_default', null, [
                'poultry_type' => $poultryType,
                'production_type' => $productionType,
                'target_cycle_days' => $days,
            ]);
        });
    }

    public static function saveSetting(
        PDO $pdo,
        int $actorId,
        string $key,
        string $value
    ): void {
        self::requireAdministrator($pdo, $actorId);
        if (!isset(self::SETTINGS[$key])) {
            throw new RuntimeException('Ce paramètre global ne peut pas être modifié.');
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > self::SETTINGS[$key]['max']) {
            throw new RuntimeException('La valeur du paramètre dépasse la longueur autorisée.');
        }
        if ($key === 'default_currency' && !preg_match('/^[A-Z]{3}$/', $value)) {
            throw new RuntimeException('La devise doit être un code ISO 4217 de trois lettres majuscules.');
        }
        if ($key === 'support_email' && $value !== ''
            && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('L’adresse d’assistance doit être une adresse e-mail valide.');
        }
        if ($key === 'application_name' && $value === '') {
            throw new RuntimeException('Le nom du site ne peut pas être vide.');
        }
        self::transaction($pdo, static function () use ($pdo, $actorId, $key, $value): void {
            $save = $pdo->prepare(
                'INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
                 VALUES (:setting_key, :setting_value, :actor_id)
                 ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = UTC_TIMESTAMP()'
            );
            $save->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'actor_id' => $actorId,
            ]);
            self::audit($pdo, $actorId, 'setting.updated', 'setting', null, ['key' => $key]);
        });
    }

    public static function saveThreshold(
        PDO $pdo,
        int $actorId,
        string $key,
        mixed $value
    ): void {
        self::requireAdministrator($pdo, $actorId);
        if (!isset(self::THRESHOLDS[$key]) || !is_numeric($value)) {
            throw new RuntimeException('Le seuil d’alerte demandé est invalide.');
        }
        $number = (float) $value;
        $definition = self::THRESHOLDS[$key];
        if (!is_finite($number) || $number < $definition['min'] || $number > $definition['max']) {
            throw new RuntimeException('La valeur du seuil est hors de la plage autorisée.');
        }
        $thresholdPairs = [
            'indoor_temperature_high' => ['indoor_temperature_low', 'high'],
            'indoor_temperature_low' => ['indoor_temperature_high', 'low'],
            'indoor_humidity_high' => ['indoor_humidity_low', 'high'],
            'indoor_humidity_low' => ['indoor_humidity_high', 'low'],
            'outdoor_temperature_high' => ['outdoor_temperature_low', 'high'],
            'outdoor_temperature_low' => ['outdoor_temperature_high', 'low'],
        ];
        [$pairedKey, $side] = $thresholdPairs[$key];
        $pairedValue = self::thresholdValue($pdo, $pairedKey);
        if (($side === 'high' && $number <= $pairedValue)
            || ($side === 'low' && $number >= $pairedValue)) {
            throw new RuntimeException('Le seuil haut doit rester supérieur au seuil bas correspondant.');
        }
        self::transaction($pdo, static function () use ($pdo, $actorId, $key, $number): void {
            $save = $pdo->prepare(
                'INSERT INTO system_alert_thresholds
                    (threshold_key, threshold_value, updated_by_user_id)
                 VALUES (:key, :value, :actor_id)
                 ON DUPLICATE KEY UPDATE
                    threshold_value = VALUES(threshold_value),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = UTC_TIMESTAMP()'
            );
            $save->execute(['key' => $key, 'value' => $number, 'actor_id' => $actorId]);
            self::audit($pdo, $actorId, 'alert_threshold.updated', 'alert_threshold', null, [
                'key' => $key,
                'value' => $number,
            ]);
        });
    }

    public static function saveExternalService(
        PDO $pdo,
        int $actorId,
        string $serviceKey,
        bool $enabled,
        string $endpoint
    ): void {
        self::requireAdministrator($pdo, $actorId);
        if ($serviceKey !== 'weather') {
            throw new RuntimeException('Ce service externe n’est pas pris en charge.');
        }
        $endpoint = trim($endpoint);
        if ($endpoint !== self::WEATHER_ENDPOINT) {
            throw new RuntimeException('L’URL doit être l’endpoint HTTPS documenté d’Open-Meteo.');
        }
        self::transaction($pdo, static function () use ($pdo, $actorId, $enabled, $endpoint): void {
            $save = $pdo->prepare(
                'INSERT INTO system_external_services
                    (service_key, is_enabled, endpoint_url, updated_by_user_id)
                 VALUES (\'weather\', :enabled, :endpoint, :actor_id)
                 ON DUPLICATE KEY UPDATE
                    is_enabled = VALUES(is_enabled),
                    endpoint_url = VALUES(endpoint_url),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = UTC_TIMESTAMP()'
            );
            $save->execute([
                'enabled' => $enabled ? 1 : 0,
                'endpoint' => $endpoint,
                'actor_id' => $actorId,
            ]);
            self::audit($pdo, $actorId, 'external_service.updated', 'external_service', null, [
                'service' => 'weather',
                'enabled' => $enabled,
            ]);
        });
    }

    public static function thresholdValue(PDO $pdo, string $key): float
    {
        if (!isset(self::THRESHOLDS[$key])) {
            throw new RuntimeException('Seuil d’alerte inconnu.');
        }
        $query = $pdo->prepare(
            'SELECT threshold_value FROM system_alert_thresholds WHERE threshold_key = :key'
        );
        $query->execute(['key' => $key]);
        $value = $query->fetchColumn();
        if ($value === false) {
            $defaults = [
                'indoor_temperature_high' => 35.0,
                'indoor_temperature_low' => 10.0,
                'indoor_humidity_high' => 80.0,
                'indoor_humidity_low' => 30.0,
                'outdoor_temperature_high' => 35.0,
                'outdoor_temperature_low' => 5.0,
            ];
            return $defaults[$key];
        }
        return (float) $value;
    }

    public static function externalService(PDO $pdo, string $key): ?array
    {
        $query = $pdo->prepare(
            'SELECT service_key, is_enabled, endpoint_url
             FROM system_external_services WHERE service_key = :key'
        );
        $query->execute(['key' => $key]);
        $row = $query->fetch();
        if (is_array($row) && $key === 'weather'
            && (string) $row['endpoint_url'] !== self::WEATHER_ENDPOINT) {
            throw new RuntimeException('La configuration météo ne correspond pas à l’endpoint autorisé.');
        }
        return is_array($row) ? $row : null;
    }

    public static function settingValue(PDO $pdo, string $key, string $default): string
    {
        if (!isset(self::SETTINGS[$key])) {
            throw new RuntimeException('Paramètre global inconnu.');
        }
        $query = $pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :key'
        );
        $query->execute(['key' => $key]);
        $value = $query->fetchColumn();
        return is_string($value) ? $value : $default;
    }

    private static function rolePermissionMatrix(PDO $pdo): array
    {
        $overrides = $pdo->query(
            'SELECT role_name, permission_key, is_allowed FROM system_role_permissions'
        )->fetchAll();
        $configuredRoles = [];
        foreach ($overrides as $override) {
            $configuredRoles[(string) $override['role_name']][(string) $override['permission_key']]
                = (int) $override['is_allowed'] === 1;
        }
        $matrix = [];
        foreach (self::FARM_ROLES as $role) {
            foreach (array_keys(self::permissions()) as $permission) {
                $matrix[$role][$permission] = array_key_exists($role, $configuredRoles)
                    ? (bool) ($configuredRoles[$role][$permission] ?? false)
                    : in_array($permission, FarmAccess::defaultPermissionsForRole($role), true);
            }
        }
        return $matrix;
    }

    private static function referenceExists(PDO $pdo, int $id): bool
    {
        $query = $pdo->prepare('SELECT 1 FROM system_reference_items WHERE id = :id');
        $query->execute(['id' => $id]);
        return $query->fetchColumn() !== false;
    }

    private static function audit(
        PDO $pdo,
        int $actorId,
        string $action,
        string $resourceType,
        ?int $resourceId,
        array $details,
        ?int $farmId = null
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO audit_logs
                (farm_id, actor_user_id, action, resource_type, resource_id, details)
             VALUES (:farm_id, :actor_id, :action, :resource_type, :resource_id, :details)'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'actor_id' => $actorId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private static function transaction(PDO $pdo, callable $operation): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $operation();
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
