<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Followups\DailyFollowupService;
use MonElevage\Health\HealthMonitoringService;

$pdo = Connection::get($config);
$suffix = strtoupper(bin2hex(random_bytes(5)));
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$userIds = [];
$farmId = null;
$flockId = null;
$passed = 0;
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
$symptomInput = [
    'flock_id' => '0',
    'event_type' => 'symptom',
    'observed_on' => $today->format('Y-m-d'),
    'affected_count' => '3',
    'symptoms' => 'Baisse d’activité observée',
    'details' => 'Trois sujets isolés pour observation.',
];

try {
    foreach (['Santé propriétaire', 'Santé comptable', 'Santé opérateur', 'Santé isolé'] as $index => $name) {
        $createUser = $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
             VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
        );
        $createUser->execute([
            'name' => $name,
            'email' => 'health-' . strtolower($suffix) . '-' . $index . '@example.test',
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }
    $createFarm = $pdo->prepare(
        "INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, 'XOF', 'UTC')"
    );
    $createFarm->execute(['user_id' => $userIds[0], 'name' => 'Ferme santé ' . $suffix]);
    $farmId = (int) $pdo->lastInsertId();
    $membership = $pdo->prepare(
        'INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, \'active\')'
    );
    foreach ([[$userIds[0], 'owner'], [$userIds[1], 'accountant'], [$userIds[2], 'operator']] as [$userId, $role]) {
        $membership->execute(['farm_id' => $farmId, 'user_id' => $userId, 'role' => $role]);
    }
    $createFlock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, flock_type, poultry_type, production_type,
             currency_code, started_on, initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, 'Bande test sanitaire', 'broiler', 'chicken', 'meat',
             'XOF', :started_on, 100, 'active', :user_id)"
    );
    $createFlock->execute([
        'farm_id' => $farmId,
        'code' => 'HEALTH-' . $suffix,
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userIds[0],
    ]);
    $flockId = (int) $pdo->lastInsertId();

    $assert(
        HealthMonitoringService::validateRecord(
            array_merge($symptomInput, ['flock_id' => (string) $flockId]),
            $today
        )['errors'] === [],
        'valide une observation datée et attribuée à un élevage'
    );
    $assert(
        HealthMonitoringService::validateRecord(
            array_merge($symptomInput, ['flock_id' => (string) $flockId, 'symptoms' => '']),
            $today
        )['errors'] !== [],
        'refuse les observations sans description des signes'
    );
    $assert(
        HealthMonitoringService::validateRecord(
            array_merge($symptomInput, ['flock_id' => (string) $flockId, 'observed_on' => '2030-02-31']),
            $today
        )['errors'] !== [],
        'refuse une date manquante, impossible ou future'
    );
    $assert(
        HealthMonitoringService::validateRecord(
            array_merge($symptomInput, [
                'flock_id' => (string) $flockId,
                'event_type' => 'prescribed_treatment',
                'professional_name' => '',
                'prescription_details' => '',
            ]),
            $today
        )['errors'] !== [],
        'exige un professionnel et ses consignes pour un traitement'
    );
    $expectFailure(
        static fn () => HealthMonitoringService::saveRecord(
            $pdo,
            $userIds[0],
            $farmId,
            $symptomInput,
            $today
        ),
        'refuse l’association à un élevage inexistant'
    );

    $symptomId = HealthMonitoringService::saveRecord(
        $pdo,
        $userIds[0],
        $farmId,
        array_merge($symptomInput, ['flock_id' => (string) $flockId]),
        $today
    );
    $vaccinationId = HealthMonitoringService::saveRecord(
        $pdo,
        $userIds[0],
        $farmId,
        [
            'flock_id' => (string) $flockId,
            'event_type' => 'vaccination',
            'observed_on' => $today->format('Y-m-d'),
            'affected_count' => '',
            'symptoms' => '',
            'details' => 'Vaccination suivant le calendrier du professionnel.',
            'professional_name' => 'Professionnel de santé animale',
            'prescription_details' => '',
            'next_due_on' => $today->modify('+3 days')->format('Y-m-d'),
        ],
        $today
    );
    $treatmentId = HealthMonitoringService::saveRecord(
        $pdo,
        $userIds[0],
        $farmId,
        [
            'flock_id' => (string) $flockId,
            'event_type' => 'prescribed_treatment',
            'observed_on' => $today->format('Y-m-d'),
            'affected_count' => '2',
            'symptoms' => '',
            'details' => 'Consignation des consignes reçues.',
            'professional_name' => 'Vétérinaire de test',
            'prescription_details' => 'Instructions telles que communiquées par le vétérinaire.',
            'next_due_on' => '',
        ],
        $today
    );
    $assert(count(HealthMonitoringService::records($pdo, $userIds[0], $farmId)) === 3, 'conserve observations, vaccinations et traitements dans l’historique');
    $assert($symptomId !== $vaccinationId && $vaccinationId !== $treatmentId, 'attribue un identifiant unique à chaque événement sanitaire');

    HealthMonitoringService::saveWeatherSettings($pdo, $userIds[0], $farmId, [
        'latitude' => '5.35',
        'longitude' => '-4.01',
    ]);
    $expectFailure(
        static fn () => HealthMonitoringService::saveWeatherSettings(
            $pdo,
            $userIds[0],
            $farmId,
            ['latitude' => '91', 'longitude' => '0']
        ),
        'refuse des coordonnées hors limites'
    );
    $unavailable = HealthMonitoringService::weather(
        $pdo,
        $userIds[0],
        $farmId,
        $now,
        static function (): array {
            throw new RuntimeException('API météo hors ligne');
        }
    );
    $assert($unavailable['status'] === 'unavailable' && $unavailable['daily'] === [], 'signale explicitement l’indisponibilité sans données antérieures');

    $forecastPayload = [
        'timezone' => 'Africa/Abidjan',
        'daily' => [
            'time' => [$today->modify('+1 day')->format('Y-m-d')],
            'temperature_2m_max' => [38],
            'temperature_2m_min' => [1],
            'precipitation_probability_max' => [60],
        ],
    ];
    $fetchCalls = 0;
    $fetcher = static function (float $latitude, float $longitude) use (&$fetchCalls, $forecastPayload): array {
        $fetchCalls++;
        if ($latitude !== 5.35 || $longitude !== -4.01) {
            throw new RuntimeException('Coordonnées inattendues.');
        }
        return $forecastPayload;
    };
    $weather = HealthMonitoringService::weather($pdo, $userIds[0], $farmId, $now, $fetcher);
    $assert($weather['status'] === 'fresh' && $weather['daily'][0]['temperature_max'] === 38.0, 'valide, normalise et enregistre une prévision météo');
    $cached = HealthMonitoringService::weather($pdo, $userIds[0], $farmId, $now, $fetcher);
    $assert($cached['status'] === 'cached' && $fetchCalls === 1, 'sert le cache météo de trois heures sans nouvel appel');
    $stale = HealthMonitoringService::weather(
        $pdo,
        $userIds[0],
        $farmId,
        $now->modify('+4 hours'),
        static function (): array {
            throw new RuntimeException('API météo hors ligne');
        }
    );
    $assert(
        $stale['status'] === 'stale' && $stale['daily'] == $weather['daily']
            && $stale['last_updated'] === $weather['last_updated'],
        'conserve et date les dernières données si l’API échoue'
    );
    $expectFailure(
        static fn () => HealthMonitoringService::validateForecast(['daily' => ['time' => []]]),
        'rejette les champs météo manquants'
    );
    $expectFailure(
        static fn () => HealthMonitoringService::validateForecast([
            'daily' => [
                'time' => [$today->format('Y-m-d')],
                'temperature_2m_max' => [100],
                'temperature_2m_min' => [15],
            ],
        ]),
        'rejette les valeurs météo hors bornes'
    );
    $expectFailure(
        static fn () => HealthMonitoringService::validateForecast([
            'daily' => [
                'time' => ['2026-02-30'],
                'temperature_2m_max' => [20],
                'temperature_2m_min' => [10],
            ],
        ]),
        'rejette les dates météo malformées'
    );

    DailyFollowupService::save(
        $pdo,
        $userIds[0],
        $farmId,
        $flockId,
        [
            'followup_date' => $today->format('Y-m-d'),
            'entries_count' => '0',
            'sick_count' => '1',
            'mortality_count' => '0',
            'sales_count' => '0',
            'other_exits_count' => '0',
            'feed_consumed_kg' => '',
            'water_consumed_liters' => '',
            'temperature_celsius' => '36',
            'humidity_percent' => '85',
            'average_weight_g' => '',
            'notes' => 'Mesures intérieures de test',
        ],
        null,
        $today
    );
    $alerts = HealthMonitoringService::alerts($pdo, $userIds[0], $farmId, $today, $weather);
    $sources = array_column($alerts, 'source');
    $assert(in_array('Sanitaire', $sources, true), 'génère une alerte à partir des symptômes observés');
    $assert(in_array('Prévention', $sources, true), 'signale une échéance de vaccination prochaine');
    $assert(in_array('Mesure réelle du poulailler', $sources, true), 'détecte les seuils de température et d’humidité réellement mesurés');
    $assert(in_array('Prévision extérieure — Open-Meteo', $sources, true), 'détecte les seuils météo en les distinguant des mesures intérieures');
    foreach ($alerts as $alert) {
        $assert(
            $alert['source'] !== '' && $alert['date'] !== '' && $alert['action'] !== '',
            'précise l’origine, la date et une action pour chaque alerte'
        );
    }

    HealthMonitoringService::syncNotifications($pdo, $userIds[0], $farmId, $alerts);
    $firstNotifications = HealthMonitoringService::notifications($pdo, $userIds[0], $farmId);
    HealthMonitoringService::syncNotifications($pdo, $userIds[0], $farmId, $alerts);
    $secondNotifications = HealthMonitoringService::notifications($pdo, $userIds[0], $farmId);
    $assert(count($firstNotifications) > 0 && count($firstNotifications) === count($secondNotifications), 'déduplique les notifications identiques');
    $notificationId = (int) $firstNotifications[0]['id'];
    HealthMonitoringService::markNotificationRead($pdo, $userIds[0], $farmId, $notificationId);
    $readNotifications = HealthMonitoringService::notifications($pdo, $userIds[0], $farmId);
    $read = array_values(array_filter(
        $readNotifications,
        static fn (array $notification): bool => (int) $notification['id'] === $notificationId
    ));
    $assert(count($read) === 1 && $read[0]['read_at'] !== null, 'marque comme lue une notification autorisée');
    $expectFailure(
        static fn () => HealthMonitoringService::records($pdo, $userIds[1], $farmId),
        'interdit l’accès sanitaire au rôle comptable'
    );
    $expectFailure(
        static fn () => HealthMonitoringService::saveRecord(
            $pdo,
            $userIds[2],
            $farmId,
            array_merge($symptomInput, ['flock_id' => (string) $flockId]),
            $today
        ),
        'interdit l’écriture sanitaire au rôle opérateur'
    );
    $expectFailure(
        static fn () => HealthMonitoringService::notifications($pdo, $userIds[3], $farmId),
        'isole les notifications d’une exploitation des comptes étrangers'
    );
} finally {
    if ($farmId !== null) {
        $pdo->prepare('DELETE FROM farm_notifications WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM health_records WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM weather_snapshots WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farm_weather_settings WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        if ($flockId !== null) {
            $pdo->prepare('DELETE FROM animal_movements WHERE farm_id = :farm_id AND flock_id = :flock_id')
                ->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
            $pdo->prepare('DELETE FROM daily_followups WHERE farm_id = :farm_id AND flock_id = :flock_id')
                ->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
            $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id AND id = :flock_id')
                ->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
        }
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions du suivi sanitaire et météo.\n", $passed);
