<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Admin\AdminService;
use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Finance\FinanceService;
use MonElevage\Flocks\FlockService;

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(5));
$userIds = [];
$farmId = 0;
$referenceId = 0;
$expenseReferenceId = 0;
$flockReferenceBefore = null;
$passed = 0;
$savedOverrides = $pdo->prepare(
    'SELECT permission_key, is_allowed, updated_by_user_id
     FROM system_role_permissions WHERE role_name = :role'
);
$savedOverrides->execute(['role' => 'operator']);
$operatorOverridesBefore = $savedOverrides->fetchAll();
$savedSettings = $pdo->prepare(
    'SELECT setting_value, updated_by_user_id FROM system_settings WHERE setting_key = :key'
);
$savedSettings->execute(['key' => 'default_currency']);
$currencyBefore = $savedSettings->fetch() ?: null;
$savedThreshold = $pdo->prepare(
    'SELECT threshold_value, updated_by_user_id
     FROM system_alert_thresholds WHERE threshold_key = :key'
);
$savedThreshold->execute(['key' => 'indoor_temperature_high']);
$thresholdBefore = $savedThreshold->fetch() ?: null;
$savedCalendar = $pdo->prepare(
    'SELECT target_cycle_days, updated_by_user_id
     FROM system_calendar_defaults WHERE poultry_type = :poultry AND production_type = :production'
);
$savedCalendar->execute(['poultry' => 'chicken', 'production' => 'meat']);
$calendarBefore = $savedCalendar->fetch() ?: null;
$savedService = $pdo->query(
    "SELECT is_enabled, endpoint_url, updated_by_user_id
     FROM system_external_services WHERE service_key = 'weather'"
)->fetch() ?: null;
$flockReferenceQuery = $pdo->query(
    "SELECT id, label, description, position, status, updated_by_user_id
     FROM system_reference_items
     WHERE reference_type = 'flock_type' AND code = 'broiler'"
);
$flockReferenceBefore = $flockReferenceQuery->fetch() ?: null;

$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Échec du test : ' . $message);
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

try {
    $createUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, is_admin, email_verified_at)
         VALUES (:name, :email, 'test-hash', 'active', :is_admin, UTC_TIMESTAMP())"
    );
    foreach ([['Administratrice test', 1], ['Compte standard test', 0], ['Membre test', 0]] as [$name, $isAdmin]) {
        $createUser->execute([
            'name' => $name,
            'email' => 'admin-test-' . $suffix . '-' . count($userIds) . '@example.test',
            'is_admin' => $isAdmin,
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }
    [$adminId, $userId, $memberId] = $userIds;

    $farm = $pdo->prepare(
        'INSERT INTO farms (owner_user_id, name, currency_code)
         VALUES (:owner_id, :name, :currency)'
    );
    $farm->execute([
        'owner_id' => $userId,
        'name' => 'Ferme admin ' . $suffix,
        'currency' => 'XOF',
    ]);
    $farmId = (int) $pdo->lastInsertId();
    $membership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, 'active')"
    );
    $membership->execute(['farm_id' => $farmId, 'user_id' => $userId, 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmId, 'user_id' => $memberId, 'role' => 'operator']);

    $data = AdminService::overview($pdo, $adminId);
    $assert($data['users'] !== [], 'autorise la consultation de l’administration');
    $csrfToken = 'admin-render-test';
    $errors = [];
    $flash = null;
    ob_start();
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
        . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
        . DIRECTORY_SEPARATOR . 'admin-content.php';
    $adminHtml = (string) ob_get_clean();
    $assert(
        str_contains($adminHtml, 'Journaux d’audit')
            && str_contains($adminHtml, 'name="csrf_token"')
            && !str_contains($adminHtml, 'name="is_admin"'),
        'rend les formulaires protégés sans champ d’attribution du statut administrateur'
    );
    $htmlDocument = new DOMDocument();
    libxml_use_internal_errors(true);
    $htmlDocument->loadHTML('<!doctype html><html><body>' . $adminHtml . '</body></html>');
    libxml_clear_errors();
    $htmlXPath = new DOMXPath($htmlDocument);
    $unassociatedControls = 0;
    foreach ($htmlXPath->query('//input[@name] | //select[@name] | //textarea[@name] | //button[@type="submit"]') as $control) {
        $formId = $control->getAttribute('form');
        if ($formId !== '') {
            $matchingForm = false;
            foreach ($htmlXPath->query('//form[@id]') as $form) {
                if ($form->getAttribute('id') === $formId) {
                    $matchingForm = true;
                    break;
                }
            }
            if (!$matchingForm) {
                $unassociatedControls++;
            }
            continue;
        }
        $parent = $control->parentNode;
        $insideForm = false;
        while ($parent !== null) {
            if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'form') {
                $insideForm = true;
                break;
            }
            $parent = $parent->parentNode;
        }
        if (!$insideForm) {
            $unassociatedControls++;
        }
    }
    $assert($unassociatedControls === 0, 'associe chaque contrôle de formulaire à un formulaire valide');
    $expectFailure(
        static fn () => AdminService::overview($pdo, $userId),
        'interdit à un compte ordinaire de consulter l’administration'
    );
    $expectFailure(
        static fn () => AdminService::updateUser($pdo, $userId, $memberId, ['status' => 'disabled']),
        'interdit à un compte ordinaire de modifier les utilisateurs'
    );

    AdminService::updateUser($pdo, $adminId, $userId, [
        'full_name' => 'Compte standard modifié',
        'status' => 'active',
        'is_admin' => '1',
    ]);
    $userState = $pdo->prepare('SELECT full_name, is_admin FROM users WHERE id = :id');
    $userState->execute(['id' => $userId]);
    $updatedUser = $userState->fetch();
    $assert($updatedUser['full_name'] === 'Compte standard modifié', 'enregistre la modification du compte');
    $assert((int) $updatedUser['is_admin'] === 0, 'ignore une tentative de s’attribuer le rôle administrateur');
    $expectFailure(
        static fn () => AdminService::updateUser($pdo, $adminId, $adminId, [
            'full_name' => 'Administratrice test',
            'status' => 'disabled',
        ]),
        'empêche la désactivation du dernier compte administrateur depuis l’interface'
    );

    AdminService::updateMembership($pdo, $adminId, $farmId, $memberId, [
        'role' => 'accountant',
        'status' => 'suspended',
    ]);
    $memberState = $pdo->prepare(
        'SELECT role, status FROM farm_members WHERE farm_id = :farm_id AND user_id = :user_id'
    );
    $memberState->execute(['farm_id' => $farmId, 'user_id' => $memberId]);
    $updatedMembership = $memberState->fetch();
    $assert(
        $updatedMembership['role'] === 'accountant' && $updatedMembership['status'] === 'suspended',
        'modifie le rôle et le statut d’une adhésion'
    );
    $expectFailure(
        static fn () => AdminService::updateMembership($pdo, $adminId, $farmId, $userId, [
            'role' => 'manager',
            'status' => 'active',
        ]),
        'protège le rôle et l’accès actif du propriétaire'
    );
    $expectFailure(
        static fn () => AdminService::updateMembership($pdo, $adminId, $farmId, $memberId, [
            'role' => 'administrator',
            'status' => 'active',
        ]),
        'rejette le rôle administrateur comme rôle d’exploitation'
    );
    AdminService::updateMembership($pdo, $adminId, $farmId, $memberId, [
        'role' => 'operator',
        'status' => 'active',
    ]);

    AdminService::saveRolePermissions($pdo, $adminId, 'operator', [
        'farm.view',
        'stock.view',
        'sale.manage',
    ]);
    $assert(
        FarmAccess::allows($pdo, $memberId, $farmId, 'sale.manage'),
        'applique les permissions enregistrées pour un rôle'
    );
    $expectFailure(
        static fn () => AdminService::saveRolePermissions($pdo, $adminId, 'operator', ['unknown.admin']),
        'rejette toute permission hors catalogue'
    );
    $expectFailure(
        static fn () => AdminService::saveRolePermissions($pdo, $userId, 'operator', ['sale.manage']),
        'interdit la modification des permissions sans rôle administrateur'
    );

    $referenceId = AdminService::saveReference($pdo, $adminId, [
        'reference_type' => 'product_category',
        'code' => 'test-' . $suffix,
        'label' => 'Catégorie de test',
        'description' => 'Description actualisable',
        'position' => '2',
        'status' => 'active',
    ]);
    AdminService::saveReference($pdo, $adminId, [
        'reference_type' => 'product_category',
        'code' => 'test-' . $suffix,
        'label' => 'Catégorie modifiée',
        'description' => 'Description actualisable',
        'position' => '3',
        'status' => 'archived',
    ], $referenceId);
    $reference = $pdo->prepare(
        'SELECT label, status FROM system_reference_items WHERE id = :id'
    );
    $reference->execute(['id' => $referenceId]);
    $savedReference = $reference->fetch();
    $assert(
        $savedReference['label'] === 'Catégorie modifiée' && $savedReference['status'] === 'archived',
        'crée, modifie et archive un élément de référence'
    );
    if (!is_array($flockReferenceBefore)) {
        throw new RuntimeException('Le type d’élevage de référence attendu est manquant.');
    }
    AdminService::saveReference($pdo, $adminId, [
        'reference_type' => 'flock_type',
        'code' => 'broiler',
        'label' => 'Poulet de chair administré',
        'description' => (string) $flockReferenceBefore['description'],
        'position' => (string) $flockReferenceBefore['position'],
        'status' => 'active',
    ], (int) $flockReferenceBefore['id']);
    $assert(
        FlockService::types($pdo)['broiler'] === 'Poulet de chair administré',
        'applique les libellés administrés dans le formulaire des élevages'
    );
    AdminService::saveReference($pdo, $adminId, [
        'reference_type' => 'flock_type',
        'code' => 'broiler',
        'label' => 'Poulet de chair administré',
        'description' => (string) $flockReferenceBefore['description'],
        'position' => (string) $flockReferenceBefore['position'],
        'status' => 'archived',
    ], (int) $flockReferenceBefore['id']);
    $typeValidation = FlockService::validate([
        'name' => 'Bande de test',
        'code' => 'TEST',
        'flock_type' => 'broiler',
        'started_on' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'initial_count' => '1',
    ], 'XOF', new DateTimeImmutable('today'), FlockService::types($pdo));
    $assert(
        in_array('Choisissez un type d’élevage valide.', $typeValidation['errors'], true),
        'interdit la création avec un type d’élevage archivé'
    );
    $expectFailure(
        static fn () => AdminService::saveReference($pdo, $adminId, [
            'reference_type' => 'poultry_type',
            'code' => 'administrator',
            'label' => 'Type invalide',
        ]),
        'empêche l’ajout de types incompatibles avec les valeurs du schéma'
    );
    $expenseCategoryCode = 'test-cost-' . $suffix;
    $expenseReferenceId = AdminService::saveReference($pdo, $adminId, [
        'reference_type' => 'expense_category',
        'code' => $expenseCategoryCode,
        'label' => 'Catégorie de charge personnalisée',
        'description' => '',
        'position' => '99',
        'status' => 'active',
    ]);
    $dynamicCategories = FinanceService::categories($pdo);
    $expenseValidation = FinanceService::validateExpense([
        'amount' => '2500',
        'category' => $expenseCategoryCode,
        'expense_type' => 'charge',
        'description' => 'Dépense avec catégorie administrée',
        'incurred_on' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'flock_id' => '',
    ], new DateTimeImmutable('today'), $dynamicCategories);
    $assert($expenseValidation['errors'] === [], 'permet la saisie financière dans une catégorie administrée');
    AdminService::saveReference($pdo, $adminId, [
        'reference_type' => 'expense_category',
        'code' => $expenseCategoryCode,
        'label' => 'Catégorie de charge personnalisée',
        'description' => '',
        'position' => '99',
        'status' => 'archived',
    ], $expenseReferenceId);
    $assert(
        !array_key_exists($expenseCategoryCode, FinanceService::categories($pdo)),
        'retire les catégories archivées des choix de dépense'
    );

    AdminService::saveCalendarDefault($pdo, $adminId, [
        'poultry_type' => 'chicken',
        'production_type' => 'meat',
        'target_cycle_days' => '56',
    ]);
    $calendar = $pdo->prepare(
        'SELECT target_cycle_days FROM system_calendar_defaults
         WHERE poultry_type = :poultry AND production_type = :production'
    );
    $calendar->execute(['poultry' => 'chicken', 'production' => 'meat']);
    $assert((int) $calendar->fetchColumn() === 56, 'enregistre une durée de calendrier de référence');
    $expectFailure(
        static fn () => AdminService::saveCalendarDefault($pdo, $adminId, [
            'poultry_type' => 'chicken',
            'production_type' => 'meat',
            'target_cycle_days' => '0',
        ]),
        'refuse une durée de calendrier hors limites'
    );

    AdminService::saveSetting($pdo, $adminId, 'default_currency', 'EUR');
    $assert(
        AdminService::settingValue($pdo, 'default_currency', 'XOF') === 'EUR',
        'modifie un paramètre global consommé lors de la création d’une exploitation'
    );
    $expectFailure(
        static fn () => AdminService::saveSetting($pdo, $adminId, 'is_admin', '1'),
        'refuse un paramètre global inconnu'
    );

    AdminService::saveThreshold($pdo, $adminId, 'indoor_temperature_high', 37.5);
    $assert(
        AdminService::thresholdValue($pdo, 'indoor_temperature_high') === 37.5,
        'modifie et relit un seuil d’alerte'
    );
    $expectFailure(
        static fn () => AdminService::saveThreshold($pdo, $adminId, 'indoor_temperature_high', 100),
        'refuse un seuil d’alerte dangereux ou hors limites'
    );
    $expectFailure(
        static fn () => AdminService::saveThreshold($pdo, $adminId, 'indoor_temperature_low', 45),
        'garantit la cohérence entre seuil bas et seuil haut'
    );

    AdminService::saveExternalService(
        $pdo,
        $adminId,
        'weather',
        false,
        'https://api.open-meteo.com/v1/forecast'
    );
    $service = AdminService::externalService($pdo, 'weather');
    $assert(is_array($service) && (int) $service['is_enabled'] === 0, 'désactive un service externe autorisé');
    $expectFailure(
        static fn () => AdminService::saveExternalService(
            $pdo,
            $adminId,
            'weather',
            true,
            'http://127.0.0.1/internal'
        ),
        'bloque les URL de service susceptibles de cibler des ressources internes'
    );
    $expectFailure(
        static fn () => AdminService::saveExternalService(
            $pdo,
            $userId,
            'weather',
            true,
            'https://api.open-meteo.com/v1/forecast'
        ),
        'protège la configuration des services externes'
    );

    $auditCount = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE actor_user_id = :actor_id AND action IN
           ('user.updated', 'membership.updated', 'role.permissions_updated',
            'reference.saved', 'calendar_default.updated', 'setting.updated',
            'alert_threshold.updated', 'external_service.updated')"
    );
    $auditCount->execute(['actor_id' => $adminId]);
    $assert((int) $auditCount->fetchColumn() >= 11, 'journalise les opérations sensibles');
    $expectFailure(
        static fn () => AdminService::updateUser($pdo, $adminId, 999999999, [
            'full_name' => 'Compte absent',
            'status' => 'active',
        ]),
        'signale explicitement un utilisateur introuvable'
    );
} finally {
    if ($referenceId > 0) {
        $pdo->prepare('DELETE FROM system_reference_items WHERE id = :id')->execute(['id' => $referenceId]);
    }
    if ($expenseReferenceId > 0) {
        $pdo->prepare('DELETE FROM system_reference_items WHERE id = :id')->execute(['id' => $expenseReferenceId]);
    }
    if (is_array($flockReferenceBefore)) {
        $pdo->prepare(
            'UPDATE system_reference_items
             SET label = :label, description = :description, position = :position,
                 status = :status, updated_by_user_id = :actor_id
             WHERE id = :id'
        )->execute([
            'label' => $flockReferenceBefore['label'],
            'description' => $flockReferenceBefore['description'],
            'position' => $flockReferenceBefore['position'],
            'status' => $flockReferenceBefore['status'],
            'actor_id' => $flockReferenceBefore['updated_by_user_id'],
            'id' => $flockReferenceBefore['id'],
        ]);
    }
    $pdo->prepare("DELETE FROM system_role_permissions WHERE role_name = 'operator'")->execute();
    if ($operatorOverridesBefore !== []) {
        $restore = $pdo->prepare(
            'INSERT INTO system_role_permissions (role_name, permission_key, is_allowed, updated_by_user_id)
             VALUES (\'operator\', :permission, :allowed, :actor)'
        );
        foreach ($operatorOverridesBefore as $permission) {
            $restore->execute([
                'permission' => $permission['permission_key'],
                'allowed' => $permission['is_allowed'],
                'actor' => $permission['updated_by_user_id'],
            ]);
        }
    }
    $restoreSetting = $pdo->prepare(
        'DELETE FROM system_settings WHERE setting_key = \'default_currency\''
    );
    $restoreSetting->execute();
    if ($currencyBefore !== null) {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
             VALUES (\'default_currency\', :value, :actor)'
        )->execute([
            'value' => $currencyBefore['setting_value'],
            'actor' => $currencyBefore['updated_by_user_id'],
        ]);
    }
    $pdo->prepare(
        'DELETE FROM system_alert_thresholds WHERE threshold_key = \'indoor_temperature_high\''
    )->execute();
    if ($thresholdBefore !== null) {
        $pdo->prepare(
            'INSERT INTO system_alert_thresholds (threshold_key, threshold_value, updated_by_user_id)
             VALUES (\'indoor_temperature_high\', :value, :actor)'
        )->execute([
            'value' => $thresholdBefore['threshold_value'],
            'actor' => $thresholdBefore['updated_by_user_id'],
        ]);
    }
    $pdo->prepare(
        'DELETE FROM system_calendar_defaults
         WHERE poultry_type = \'chicken\' AND production_type = \'meat\''
    )->execute();
    if ($calendarBefore !== null) {
        $pdo->prepare(
            'INSERT INTO system_calendar_defaults
                (poultry_type, production_type, target_cycle_days, updated_by_user_id)
             VALUES (\'chicken\', \'meat\', :days, :actor)'
        )->execute([
            'days' => $calendarBefore['target_cycle_days'],
            'actor' => $calendarBefore['updated_by_user_id'],
        ]);
    }
    $pdo->prepare("DELETE FROM system_external_services WHERE service_key = 'weather'")->execute();
    if ($savedService !== null) {
        $pdo->prepare(
            'INSERT INTO system_external_services
                (service_key, is_enabled, endpoint_url, updated_by_user_id)
             VALUES (\'weather\', :enabled, :endpoint, :actor)'
        )->execute([
            'enabled' => $savedService['is_enabled'],
            'endpoint' => $savedService['endpoint_url'],
            'actor' => $savedService['updated_by_user_id'],
        ]);
    }
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id IN ($placeholders) OR farm_id = ?")
            ->execute([...$userIds, $farmId]);
        if ($farmId > 0) {
            $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
            $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
        }
        $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($userIds);
    }
}

printf("OK — %d assertions d’administration.\n", $passed);
