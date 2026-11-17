<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Dashboard\DashboardService;
use MonElevage\Database\Connection;
use MonElevage\Flocks\FlockService;

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(5));
$emailOne = 'flock-owner-' . $suffix . '@example.test';
$emailTwo = 'flock-other-' . $suffix . '@example.test';
$userIds = [];
$farmIds = [];
$flockIds = [];
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Échec du test : ' . $message);
    }
    $passed++;
};
$expectFailure = static function (callable $operation, string $message) use ($assert): string {
    try {
        $operation();
    } catch (RuntimeException $exception) {
        $assert(true, $message);
        return $exception->getMessage();
    }
    $assert(false, $message);
    return '';
};

try {
    $createUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    foreach ([['Éleveur test', $emailOne], ['Autre utilisateur', $emailTwo]] as [$name, $email]) {
        $createUser->execute(['name' => $name, 'email' => $email]);
        $userIds[] = (int) $pdo->lastInsertId();
    }

    $createFarm = $pdo->prepare(
        'INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, :currency_code, :timezone)'
    );
    foreach ([[$userIds[0], 'Ferme gestion', 'XOF'], [$userIds[1], 'Ferme isolée', 'XOF']] as [$ownerId, $name, $currency]) {
        $createFarm->execute([
            'user_id' => $ownerId,
            'name' => $name . ' ' . $suffix,
            'currency_code' => $currency,
            'timezone' => 'UTC',
        ]);
        $farmIds[] = (int) $pdo->lastInsertId();
    }

    $membership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, 'active')"
    );
    $membership->execute([
        'farm_id' => $farmIds[0],
        'user_id' => $userIds[0],
        'role' => 'owner',
    ]);
    $membership->execute([
        'farm_id' => $farmIds[1],
        'user_id' => $userIds[1],
        'role' => 'owner',
    ]);
    $membership->execute([
        'farm_id' => $farmIds[0],
        'user_id' => $userIds[1],
        'role' => 'operator',
    ]);

    $valid = [
        'name' => 'Bande de démonstration',
        'code' => 'test-' . strtoupper($suffix),
        'flock_type' => 'broiler',
        'started_on' => '2026-10-01',
        'initial_count' => '120',
        'supplier_name' => 'Fournisseur local',
        'initial_cost' => '35000.50',
        'budget_amount' => '90000',
        'location' => 'Bâtiment A',
    ];
    $validation = FlockService::validate($valid, 'XOF', new DateTimeImmutable('2026-10-11'));
    $assert($validation['errors'] === [], 'accepte un formulaire valide');
    $sameLocalDay = FlockService::validate(
        $valid,
        'XOF',
        new DateTimeImmutable('2026-10-11 08:00:00', new DateTimeZone('Asia/Tokyo'))
    );
    $assert($sameLocalDay['errors'] === [], 'accepte le jour local courant dans le fuseau de l’exploitation');
    $assert(
        array_keys(FlockService::types()) === [
            'broiler',
            'layer',
            'cockerel',
            'free_range',
            'quail',
            'turkey',
            'duck',
            'guinea_fowl',
            'goose',
            'pigeon',
            'breeder',
        ],
        'propose les types avicoles prévus, y compris les six espèces ajoutées'
    );
    $assert(
        array_keys(FlockService::types($pdo)) === array_keys(FlockService::types()),
        'conserve le même ordre de types dans le référentiel MySQL'
    );
    $flockId = FlockService::create($pdo, $userIds[0], $farmIds[0], $valid);
    $flockIds[] = $flockId;
    foreach (['layer', 'cockerel', 'free_range', 'breeder'] as $type) {
        $typedFlock = $valid;
        $typedFlock['name'] = 'Bande ' . $type;
        $typedFlock['code'] = strtoupper($type . '-' . $suffix);
        $typedFlock['flock_type'] = $type;
        $typedFlock['initial_count'] = '1';
        $typedFlock['initial_cost'] = '';
        $typedFlock['budget_amount'] = '';
        $flockIds[] = FlockService::create($pdo, $userIds[0], $farmIds[0], $typedFlock);
    }
    foreach (['quail', 'turkey', 'duck', 'guinea_fowl', 'goose', 'pigeon'] as $type) {
        $typedFlock = $valid;
        $typedFlock['name'] = 'Bande ' . $type;
        $typedFlock['code'] = strtoupper($type . '-' . $suffix);
        $typedFlock['flock_type'] = $type;
        $typedFlock['initial_count'] = '1';
        $typedFlock['initial_cost'] = '';
        $typedFlock['budget_amount'] = '';
        $typedFlockId = FlockService::create($pdo, $userIds[0], $farmIds[0], $typedFlock);
        $flockIds[] = $typedFlockId;
        $typedFlockDetails = FlockService::find($pdo, $userIds[0], $farmIds[0], $typedFlockId);
        $expectedPoultryType = match ($type) {
            'turkey' => 'turkey',
            'duck' => 'duck',
            default => $type,
        };
        $assert(
            $typedFlockDetails['poultry_type'] === $expectedPoultryType,
            'enregistre l’espèce correspondante pour le type ' . $type
        );
    }
    foreach (['quail', 'turkey', 'duck', 'guinea_fowl', 'goose', 'pigeon'] as $type) {
        DashboardService::saveCycleSetting($pdo, $farmIds[0], $userIds[0], $type, 'meat', 60);
    }
    $cycleSettingCount = $pdo->prepare(
        'SELECT COUNT(*) FROM farm_cycle_settings
         WHERE farm_id = :farm_id AND production_type = \'meat\' AND target_cycle_days = 60'
    );
    $cycleSettingCount->execute(['farm_id' => $farmIds[0]]);
    $assert(
        (int) $cycleSettingCount->fetchColumn() === 6,
        'accepte les six nouvelles espèces dans les durées configurables du calendrier'
    );
    $flock = FlockService::find($pdo, $userIds[0], $farmIds[0], $flockId);
    $assert($flock['status'] === 'active', 'crée et active le nouvel élevage');
    $assert($flock['currency_code'] === 'XOF', 'fige la devise de l’exploitation sur la fiche');
    $assert($flock['initial_cost'] === '35000.50', 'conserve le coût initial saisi');
    $assert($flock['budget_amount'] === '90000.00', 'conserve le budget en DECIMAL');
    $assert($flock['supplier_name'] === 'Fournisseur local', 'conserve le fournisseur');
    $assert($flock['location'] === 'Bâtiment A', 'conserve la localisation');
    $assert(count($flock['history']) === 2, 'journalise la création puis la mise en activité');

    $emptyFlock = $valid;
    $emptyFlock['name'] = 'Élevage à supprimer';
    $emptyFlock['code'] = strtoupper('DELETE-' . $suffix);
    $emptyFlock['initial_cost'] = '';
    $emptyFlock['budget_amount'] = '';
    $emptyFlockId = FlockService::create($pdo, $userIds[0], $farmIds[0], $emptyFlock);
    $expectFailure(
        static fn () => FlockService::deleteIfEmpty($pdo, $userIds[1], $farmIds[0], $emptyFlockId),
        'interdit à un opérateur de supprimer un élevage'
    );
    FlockService::deleteIfEmpty($pdo, $userIds[0], $farmIds[0], $emptyFlockId);
    $emptyFlockLookup = $pdo->prepare(
        'SELECT COUNT(*) FROM flocks WHERE farm_id = :farm_id AND id = :flock_id'
    );
    $emptyFlockLookup->execute(['farm_id' => $farmIds[0], 'flock_id' => $emptyFlockId]);
    $assert((int) $emptyFlockLookup->fetchColumn() === 0, 'supprime un élevage sans opération liée');
    $deleteAudit = $pdo->prepare(
        'SELECT COUNT(*) FROM audit_logs
         WHERE farm_id = :farm_id AND actor_user_id = :actor_id
           AND action = \'flock.deleted\' AND resource_id = :resource_id'
    );
    $deleteAudit->execute([
        'farm_id' => $farmIds[0],
        'actor_id' => $userIds[0],
        'resource_id' => $emptyFlockId,
    ]);
    $assert((int) $deleteAudit->fetchColumn() === 1, 'journalise la suppression dans les actions d’audit');

    $expenseCount = $pdo->prepare(
        'SELECT COUNT(*) FROM expenses WHERE farm_id = :farm_id AND flock_id = :flock_id'
    );
    $expenseCount->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockId]);
    $assert((int) $expenseCount->fetchColumn() === 0, 'ne transforme pas le coût initial en dépense');

    $duplicate = $valid;
    $duplicate['name'] = 'Autre nom';
    $duplicate['code'] = strtolower($valid['code']);
    $duplicateMessage = $expectFailure(
        static fn () => FlockService::create($pdo, $userIds[0], $farmIds[0], $duplicate),
        'refuse un code déjà utilisé, sans distinction de casse'
    );
    $assert(str_contains($duplicateMessage, 'existe déjà'), 'renvoie un message précis sur le doublon');

    $invalid = $valid;
    $invalid['initial_count'] = '0';
    $invalid['started_on'] = '2026-10-12';
    $invalid['budget_amount'] = '-1';
    $invalidValidation = FlockService::validate(
        $invalid,
        'XOF',
        new DateTimeImmutable('2026-10-11')
    );
    $assert(count($invalidValidation['errors']) >= 3, 'signale l’effectif, la date future et le budget invalide');
    $expectFailure(
        static fn () => FlockService::create($pdo, $userIds[0], $farmIds[0], $invalid),
        'bloque côté serveur un formulaire invalide'
    );

    $updated = $valid;
    $updated['name'] = 'Bande modifiée';
    $updated['location'] = 'Bâtiment B';
    $updated['budget_amount'] = '100000';
    FlockService::update($pdo, $userIds[0], $farmIds[0], $flockId, $updated);
    $flock = FlockService::find($pdo, $userIds[0], $farmIds[0], $flockId);
    $assert($flock['name'] === 'Bande modifiée', 'modifie les informations autorisées');
    $assert($flock['budget_amount'] === '100000.00', 'actualise le budget sur la fiche');
    $duplicateUpdate = $updated;
    $duplicateUpdate['code'] = strtoupper('layer-' . $suffix);
    $expectFailure(
        static fn () => FlockService::update(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockId,
            $duplicateUpdate
        ),
        'refuse un code déjà affecté lors d’une modification'
    );
    $invalidUpdate = $updated;
    $invalidUpdate['initial_cost'] = '-25';
    $expectFailure(
        static fn () => FlockService::update(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockId,
            $invalidUpdate
        ),
        'valide aussi les montants pendant une modification'
    );

    $dashboard = DashboardService::load(
        $pdo,
        $farmIds[0],
        new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC')),
        true
    );
    $assert($dashboard['summary']['ongoing_flock_count'] === 11, 'fait apparaître les onze types créés au tableau de bord');
    $assert($dashboard['summary']['live_count'] === 130, 'reflète les effectifs initiaux au tableau de bord');
    $assert($dashboard['financials']['budgets'] === [[
        'currency_code' => 'XOF',
        'total_budget' => '100000.00',
        'spent_amount' => '0.00',
        'remaining_amount' => '100000.00',
        'flock_count' => 1,
    ]], 'calcule le budget restant sans dépenses inventées');

    $expectFailure(
        static fn () => FlockService::create($pdo, $userIds[1], $farmIds[0], $valid),
        'refuse la création au rôle opérateur'
    );
    $expectFailure(
        static fn () => FlockService::find($pdo, $userIds[1], $farmIds[1], $flockId),
        'isole une fiche d’une autre exploitation'
    );
    $assert(!FarmAccess::allows($pdo, $userIds[1], $farmIds[0], 'flock.manage'), 'ne donne pas le droit de gestion à un opérateur');

    $movement = $pdo->prepare(
        'INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at, reason, created_by_user_id)
         VALUES (:farm_id, :flock_id, :type, :quantity_delta, UTC_TIMESTAMP(), :reason, :user_id)'
    );
    $movement->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockId,
        'type' => 'entry',
        'quantity_delta' => 5,
        'reason' => 'Entrée de test',
        'user_id' => $userIds[0],
    ]);
    $deleteWithHistory = $expectFailure(
        static fn () => FlockService::deleteIfEmpty($pdo, $userIds[0], $farmIds[0], $flockId),
        'refuse de supprimer un élevage qui possède des mouvements liés'
    );
    $assert(str_contains($deleteWithHistory, 'opérations ou un historique'), 'explique pourquoi la suppression est refusée');
    $stillExists = $pdo->prepare(
        'SELECT COUNT(*) FROM flocks WHERE farm_id = :farm_id AND id = :flock_id'
    );
    $stillExists->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockId]);
    $assert((int) $stillExists->fetchColumn() === 1, 'conserve l’élevage après un refus de suppression');
    $historyStillExists = $pdo->prepare(
        'SELECT COUNT(*) FROM animal_movements WHERE farm_id = :farm_id AND flock_id = :flock_id'
    );
    $historyStillExists->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockId]);
    $assert((int) $historyStillExists->fetchColumn() === 1, 'conserve les mouvements après un refus de suppression');

    $withHistory = $updated;
    $withHistory['initial_count'] = '121';
    $expectFailure(
        static fn () => FlockService::update($pdo, $userIds[0], $farmIds[0], $flockId, $withHistory),
        'protège les données initiales après le début des opérations'
    );
    FlockService::changeStatus($pdo, $userIds[0], $farmIds[0], $flockId, 'pause');
    $paused = FlockService::find($pdo, $userIds[0], $farmIds[0], $flockId);
    $assert($paused['status'] === 'paused', 'suspend un élevage actif');
    $dashboardPaused = DashboardService::load(
        $pdo,
        $farmIds[0],
        new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC')),
        true
    );
    $assert($dashboardPaused['summary']['ongoing_flock_count'] === 11, 'conserve un élevage suspendu dans le tableau de bord');
    $assert($dashboardPaused['summary']['live_count'] === 135, 'actualise l’effectif depuis les mouvements');
    FlockService::changeStatus($pdo, $userIds[0], $farmIds[0], $flockId, 'resume');
    $resumed = FlockService::find($pdo, $userIds[0], $farmIds[0], $flockId);
    $assert($resumed['status'] === 'active', 'reprend un élevage suspendu');
    $expectFailure(
        static fn () => FlockService::changeStatus($pdo, $userIds[0], $farmIds[0], $flockId, 'close'),
        'exige un motif lors de la clôture'
    );
    FlockService::changeStatus(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockId,
        'close',
        'Fin du cycle de test'
    );
    $closed = FlockService::find($pdo, $userIds[0], $farmIds[0], $flockId);
    $assert($closed['status'] === 'closed', 'clôture et archive l’élevage sans le supprimer');
    $closure = $closed['history'][0];
    $assert($closure['event_type'] === 'closed' && (int) $closure['closing_count'] === 125, 'conserve l’effectif au moment de la clôture');
    $assert($closure['closing_result'] === '0.00', 'conserve le résultat financier exact en absence de transaction');
    $dashboardClosed = DashboardService::load(
        $pdo,
        $farmIds[0],
        new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC')),
        true
    );
    $assert($dashboardClosed['summary']['ongoing_flock_count'] === 10, 'retire uniquement l’élevage archivé des élevages en cours');
    $assert(count(FlockService::listForFarm($pdo, $userIds[0], $farmIds[0])) === 11, 'conserve les élevages dont celui archivé dans la liste');
    $expectFailure(
        static fn () => FlockService::update($pdo, $userIds[0], $farmIds[0], $flockId, $updated),
        'interdit la modification d’un élevage archivé'
    );

    echo 'OK — ' . $passed . ' assertions de gestion des élevages.' . PHP_EOL;
} finally {
    if ($flockIds !== []) {
        $placeholders = implode(',', array_fill(0, count($flockIds), '?'));
        foreach (['flock_lifecycle_events', 'animal_movements', 'daily_followups', 'expenses'] as $table) {
            $statement = $pdo->prepare(
                'DELETE FROM ' . $table . ' WHERE farm_id = ? AND flock_id IN (' . $placeholders . ')'
            );
            $statement->execute(array_merge([$farmIds[0]], $flockIds));
        }
        $deleteFlocks = $pdo->prepare(
            'DELETE FROM flocks WHERE farm_id = ? AND id IN (' . $placeholders . ')'
        );
        $deleteFlocks->execute(array_merge([$farmIds[0]], $flockIds));
    }
    if ($farmIds !== []) {
        $deleteCycleSettings = $pdo->prepare(
            'DELETE FROM farm_cycle_settings
             WHERE farm_id IN (' . implode(',', array_fill(0, count($farmIds), '?')) . ')'
        );
        $deleteCycleSettings->execute($farmIds);
        $deleteAuditLogs = $pdo->prepare(
            'DELETE FROM audit_logs WHERE farm_id IN (' . implode(',', array_fill(0, count($farmIds), '?')) . ')'
        );
        $deleteAuditLogs->execute($farmIds);
        $deleteMembers = $pdo->prepare(
            'DELETE FROM farm_members WHERE farm_id IN (' . implode(',', array_fill(0, count($farmIds), '?')) . ')'
        );
        $deleteMembers->execute($farmIds);
        $deleteFarms = $pdo->prepare(
            'DELETE FROM farms WHERE id IN (' . implode(',', array_fill(0, count($farmIds), '?')) . ')'
        );
        $deleteFarms->execute($farmIds);
    }
    if ($userIds !== []) {
        $deleteUsers = $pdo->prepare(
            'DELETE FROM users WHERE id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')'
        );
        $deleteUsers->execute($userIds);
    }
}
