<?php
declare(strict_types=1);

namespace MonElevage\Health;

use MonElevage\Admin\AdminService;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;
use MonElevage\Stock\StockService;

final class HealthMonitoringService
{
    private const EVENT_TYPES = [
        'symptom',
        'veterinary_visit',
        'vaccination',
        'prescribed_treatment',
    ];

    public static function flocks(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $statement = $pdo->prepare(
            "SELECT id, code, name, started_on, status
             FROM flocks
             WHERE farm_id = :farm_id
             ORDER BY FIELD(status, 'active', 'paused', 'closed'), name, id"
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function validateRecord(array $input, DateTimeImmutable $today): array
    {
        $errors = [];
        $flockId = filter_var($input['flock_id'] ?? null, FILTER_VALIDATE_INT);
        $type = self::text($input['event_type'] ?? null);
        $date = self::date($input['observed_on'] ?? null);
        $affectedRaw = self::text($input['affected_count'] ?? null);
        $affected = $affectedRaw === '' ? null : filter_var($affectedRaw, FILTER_VALIDATE_INT);
        $symptoms = self::text($input['symptoms'] ?? null);
        $details = self::text($input['details'] ?? null);
        $professional = self::text($input['professional_name'] ?? null);
        $prescription = self::text($input['prescription_details'] ?? null);
        $dueRaw = self::text($input['next_due_on'] ?? null);
        $dueDate = $dueRaw === '' ? null : self::date($dueRaw);

        if (!is_int($flockId) || $flockId < 1) {
            $errors[] = 'Choisissez un élevage valide.';
        }
        if (!in_array($type, self::EVENT_TYPES, true)) {
            $errors[] = 'Choisissez un type d’observation ou d’intervention valide.';
        }
        if ($date === null || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date de l’observation ne peut pas être invalide ou future.';
        }
        if ($affectedRaw !== '' && (!is_int($affected) || $affected < 1 || $affected > 2147483647)) {
            $errors[] = 'Le nombre d’animaux concernés doit être un entier positif.';
        }
        if ($type === 'symptom' && $symptoms === '') {
            $errors[] = 'Décrivez les signes observés sans tenter de poser un diagnostic.';
        }
        if ($details === '' || mb_strlen($details, 'UTF-8') > 5000) {
            $errors[] = 'Ajoutez une observation ou un compte rendu (5 000 caractères maximum).';
        }
        if (mb_strlen($symptoms, 'UTF-8') > 3000
            || mb_strlen($professional, 'UTF-8') > 160
            || mb_strlen($prescription, 'UTF-8') > 5000) {
            $errors[] = 'Un des champs du compte rendu dépasse la longueur autorisée.';
        }
        if ($type === 'prescribed_treatment' && ($professional === '' || $prescription === '')) {
            $errors[] = 'Un traitement doit être saisi à partir de la prescription d’un professionnel, dont le nom et les consignes sont requis.';
        }
        if ($dueRaw !== '' && ($dueDate === null || ($date !== null && $dueDate < $date))) {
            $errors[] = 'La prochaine échéance doit être une date valide postérieure ou égale à l’observation.';
        }
        if ($type === 'vaccination' && $dueRaw === '') {
            $errors[] = 'Indiquez la prochaine échéance de vaccination prévue par le professionnel, ou la date de révision du calendrier.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'flock_id' => is_int($flockId) ? $flockId : null,
                'event_type' => $type,
                'observed_on' => $date ?? '',
                'affected_count' => is_int($affected) ? $affected : null,
                'symptoms' => $symptoms !== '' ? $symptoms : null,
                'details' => $details,
                'professional_name' => $professional !== '' ? $professional : null,
                'prescription_details' => $prescription !== '' ? $prescription : null,
                'next_due_on' => $dueDate,
            ],
        ];
    }

    public static function saveRecord(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.manage');
        $validated = self::validateRecord($input, $today);
        if ($validated['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validated['errors']));
        }
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            $flock = $pdo->prepare(
                'SELECT started_on, status FROM flocks
                 WHERE farm_id = :farm_id AND id = :flock_id FOR UPDATE'
            );
            $flock->execute(['farm_id' => $farmId, 'flock_id' => $values['flock_id']]);
            $flockRow = $flock->fetch();
            if (!is_array($flockRow)
                || !in_array($flockRow['status'], ['active', 'paused'], true)
                || (string) $flockRow['started_on'] > $values['observed_on']) {
                throw new RuntimeException('L’élevage doit être actif ou en pause et avoir commencé avant cette date.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO health_records
                    (farm_id, flock_id, event_type, observed_on, affected_count,
                     symptoms, details, professional_name, prescription_details,
                     next_due_on, created_by_user_id)
                 VALUES
                    (:farm_id, :flock_id, :event_type, :observed_on, :affected_count,
                     :symptoms, :details, :professional_name, :prescription_details,
                     :next_due_on, :user_id)'
            );
            $insert->execute($values + ['farm_id' => $farmId, 'user_id' => $userId]);
            $recordId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $recordId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function records(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $statement = $pdo->prepare(
            'SELECT h.*, f.name AS flock_name
             FROM health_records h
             INNER JOIN flocks f ON f.farm_id = h.farm_id AND f.id = h.flock_id
             WHERE h.farm_id = :farm_id
             ORDER BY h.observed_on DESC, h.id DESC
             LIMIT 200'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function weatherSettings(PDO $pdo, int $userId, int $farmId): ?array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $statement = $pdo->prepare(
            'SELECT latitude, longitude, updated_at
             FROM farm_weather_settings WHERE farm_id = :farm_id'
        );
        $statement->execute(['farm_id' => $farmId]);
        $settings = $statement->fetch();
        return is_array($settings) ? $settings : null;
    }

    public static function saveWeatherSettings(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.manage');
        $latitudeRaw = self::text($input['latitude'] ?? null);
        $longitudeRaw = self::text($input['longitude'] ?? null);
        $latitude = self::coordinate($latitudeRaw);
        $longitude = self::coordinate($longitudeRaw);
        if ($latitude === null || $longitude === null
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            throw new RuntimeException('Saisissez une latitude de -90 à 90 et une longitude de -180 à 180.');
        }
        $statement = $pdo->prepare(
            'INSERT INTO farm_weather_settings (farm_id, latitude, longitude, updated_by_user_id)
             VALUES (:farm_id, :latitude, :longitude, :user_id)
             ON DUPLICATE KEY UPDATE latitude = VALUES(latitude),
                 longitude = VALUES(longitude), updated_by_user_id = VALUES(updated_by_user_id),
                 updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'latitude' => number_format($latitude, 6, '.', ''),
            'longitude' => number_format($longitude, 6, '.', ''),
            'user_id' => $userId,
        ]);
    }

    public static function weather(
        PDO $pdo,
        int $userId,
        int $farmId,
        DateTimeImmutable $now,
        ?callable $fetcher = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $settings = self::weatherSettings($pdo, $userId, $farmId);
        $latest = self::latestWeatherSnapshot($pdo, $farmId);
        if ($settings === null) {
            return [
                'status' => 'not_configured',
                'configured' => false,
                'error' => null,
                'last_updated' => $latest['fetched_at'] ?? null,
                'timezone' => $latest['payload']['timezone'] ?? null,
                'daily' => $latest['payload']['daily'] ?? [],
            ];
        }
        $service = AdminService::externalService($pdo, 'weather');
        if (is_array($service) && (int) $service['is_enabled'] !== 1) {
            return [
                'status' => 'disabled',
                'configured' => true,
                'error' => 'Le service météo externe est désactivé par l’administrateur.',
                'last_updated' => $latest['fetched_at'] ?? null,
                'timezone' => $latest['payload']['timezone'] ?? null,
                'daily' => $latest['payload']['daily'] ?? [],
            ];
        }
        $fetchedAt = $latest === null ? null : new DateTimeImmutable(
            (string) $latest['fetched_at'],
            new DateTimeZone('UTC')
        );
        $isFresh = $fetchedAt !== null
            && $fetchedAt >= $now->setTimezone(new DateTimeZone('UTC'))->modify('-3 hours');
        if ($isFresh) {
            return [
                'status' => 'cached',
                'configured' => true,
                'error' => null,
                'last_updated' => $latest['fetched_at'],
                'timezone' => $latest['payload']['timezone'] ?? null,
                'daily' => $latest['payload']['daily'],
            ];
        }

        try {
            $payload = $fetcher === null
                ? self::requestForecast(
                    (float) $settings['latitude'],
                    (float) $settings['longitude'],
                    is_array($service) ? (string) $service['endpoint_url'] : null
                )
                : $fetcher((float) $settings['latitude'], (float) $settings['longitude']);
            $normalized = self::validateForecast($payload);
            $encoded = json_encode($normalized, JSON_THROW_ON_ERROR);
            $insert = $pdo->prepare(
                'INSERT INTO weather_snapshots (farm_id, fetched_at, payload, created_by_user_id)
                 VALUES (:farm_id, :fetched_at, :payload, :user_id)'
            );
            $insert->execute([
                'farm_id' => $farmId,
                'fetched_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'payload' => $encoded,
                'user_id' => $userId,
            ]);
            return [
                'status' => 'fresh',
                'configured' => true,
                'error' => null,
                'last_updated' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'timezone' => $normalized['timezone'],
                'daily' => $normalized['daily'],
            ];
        } catch (RuntimeException | JsonException $exception) {
            error_log(sprintf('Weather forecast unavailable: %s', $exception->getMessage()));
            return [
                'status' => $latest === null ? 'unavailable' : 'stale',
                'configured' => true,
                'error' => 'Prévisions indisponibles pour le moment. '
                    . ($latest === null
                        ? 'Aucune donnée météo précédente n’est enregistrée.'
                        : 'Les dernières données enregistrées sont affichées avec leur date.'),
                'last_updated' => $latest['fetched_at'] ?? null,
                'timezone' => $latest['payload']['timezone'] ?? null,
                'daily' => $latest['payload']['daily'] ?? [],
            ];
        }
    }

    public static function validateForecast(mixed $payload): array
    {
        if (!is_array($payload) || !is_array($payload['daily'] ?? null)) {
            throw new RuntimeException('La réponse météo ne contient pas les prévisions journalières attendues.');
        }
        $daily = $payload['daily'];
        $fields = ['time', 'temperature_2m_max', 'temperature_2m_min'];
        foreach ($fields as $field) {
            if (!is_array($daily[$field] ?? null)) {
                throw new RuntimeException('Les données météo reçues sont incomplètes.');
            }
        }
        $count = count($daily['time']);
        if ($count < 1 || $count > 7
            || count($daily['temperature_2m_max']) !== $count
            || count($daily['temperature_2m_min']) !== $count) {
            throw new RuntimeException('La période des prévisions météo est invalide.');
        }
        $precipitation = $daily['precipitation_probability_max'] ?? array_fill(0, $count, null);
        if (!is_array($precipitation) || count($precipitation) !== $count) {
            throw new RuntimeException('Les probabilités de pluie reçues sont invalides.');
        }
        $result = [];
        for ($index = 0; $index < $count; $index++) {
            $date = self::date($daily['time'][$index]);
            $maximum = $daily['temperature_2m_max'][$index];
            $minimum = $daily['temperature_2m_min'][$index];
            $rainChance = $precipitation[$index];
            if ($date === null || !is_numeric($maximum) || !is_numeric($minimum)
                || (float) $maximum < -100 || (float) $maximum > 70
                || (float) $minimum < -100 || (float) $minimum > 70
                || ($rainChance !== null
                    && (!is_numeric($rainChance) || (float) $rainChance < 0 || (float) $rainChance > 100))) {
                throw new RuntimeException('Une valeur de la prévision météo est invalide.');
            }
            $result[] = [
                'date' => $date,
                'temperature_max' => round((float) $maximum, 1),
                'temperature_min' => round((float) $minimum, 1),
                'precipitation_probability_max' => $rainChance === null
                    ? null
                    : round((float) $rainChance, 0),
            ];
        }
        return [
            'timezone' => is_string($payload['timezone'] ?? null)
                ? $payload['timezone']
                : 'UTC',
            'daily' => $result,
        ];
    }

    public static function alerts(
        PDO $pdo,
        int $userId,
        int $farmId,
        DateTimeImmutable $today,
        array $weather
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $date = $today->format('Y-m-d');
        $alerts = [];
        $healthQuery = $pdo->prepare(
            "SELECT h.id, h.flock_id, h.event_type, h.observed_on, h.affected_count,
                    h.symptoms, h.details, h.next_due_on, f.name AS flock_name
             FROM health_records h
             INNER JOIN flocks f ON f.farm_id = h.farm_id AND f.id = h.flock_id
             WHERE h.farm_id = :farm_id
               AND ((h.event_type = 'symptom' AND h.observed_on >= :recent_date)
                    OR (h.next_due_on IS NOT NULL AND h.next_due_on <= :due_date))
             ORDER BY h.observed_on DESC, h.id DESC"
        );
        $healthQuery->execute([
            'farm_id' => $farmId,
            'recent_date' => $today->modify('-7 days')->format('Y-m-d'),
            'due_date' => $today->modify('+7 days')->format('Y-m-d'),
        ]);
        foreach ($healthQuery->fetchAll() as $row) {
            if ($row['event_type'] === 'symptom') {
                $alerts[] = self::alert(
                    'health-symptom-' . $row['id'],
                    'high',
                    'Sanitaire',
                    (string) $row['observed_on'],
                    'Signes observés — ' . $row['flock_name'],
                    (string) $row['symptoms'] . ($row['affected_count'] === null
                        ? ''
                        : ' · ' . (int) $row['affected_count'] . ' animal(aux) concernés.'),
                    'Consignez l’évolution et contactez un vétérinaire si la situation est préoccupante ; aucun diagnostic automatique n’est effectué.'
                );
            }
            if ($row['next_due_on'] !== null) {
                $alerts[] = self::alert(
                    'health-due-' . $row['id'],
                    (string) $row['next_due_on'] < $date ? 'high' : 'normal',
                    'Prévention',
                    (string) $row['next_due_on'],
                    'Échéance à vérifier — ' . $row['flock_name'],
                    ucfirst((string) $row['event_type']) . ' · échéance prévue le '
                        . $row['next_due_on'] . '.',
                    'Vérifiez le calendrier établi par le professionnel de santé animale et contactez-le en cas de doute.'
                );
            }
        }

        $indoorTemperatureHigh = AdminService::thresholdValue($pdo, 'indoor_temperature_high');
        $indoorTemperatureLow = AdminService::thresholdValue($pdo, 'indoor_temperature_low');
        $indoorHumidityHigh = AdminService::thresholdValue($pdo, 'indoor_humidity_high');
        $indoorHumidityLow = AdminService::thresholdValue($pdo, 'indoor_humidity_low');
        $outdoorTemperatureHigh = AdminService::thresholdValue($pdo, 'outdoor_temperature_high');
        $outdoorTemperatureLow = AdminService::thresholdValue($pdo, 'outdoor_temperature_low');
        $followupQuery = $pdo->prepare(
            "SELECT d.id, d.flock_id, d.followup_date, d.sick_count, d.mortality_count,
                    d.temperature_celsius, d.humidity_percent, f.name AS flock_name
             FROM daily_followups d
             INNER JOIN flocks f ON f.farm_id = d.farm_id AND f.id = d.flock_id
             WHERE d.farm_id = :farm_id
               AND d.followup_date >= :recent_date
             ORDER BY d.followup_date DESC"
        );
        $followupQuery->execute([
            'farm_id' => $farmId,
            'recent_date' => $today->modify('-2 days')->format('Y-m-d'),
        ]);
        foreach ($followupQuery->fetchAll() as $row) {
            if ((int) $row['sick_count'] > 0 || (int) $row['mortality_count'] > 0) {
                $alerts[] = self::alert(
                    'health-followup-' . $row['id'],
                    'high',
                    'Suivi sanitaire réel',
                    (string) $row['followup_date'],
                    'Signalement à examiner — ' . $row['flock_name'],
                    (int) $row['sick_count'] . ' malade(s), '
                        . (int) $row['mortality_count'] . ' mortalité(s) signalée(s).',
                    'Vérifiez les animaux et l’évolution des observations ; contactez un vétérinaire si des signes vous préoccupent.'
                );
            }
            if ($row['temperature_celsius'] !== null
                && ((float) $row['temperature_celsius'] >= $indoorTemperatureHigh
                    || (float) $row['temperature_celsius'] <= $indoorTemperatureLow)) {
                $alerts[] = self::alert(
                    'climate-temp-' . $row['id'],
                    'high',
                    'Mesure réelle du poulailler',
                    (string) $row['followup_date'],
                    'Température intérieure à vérifier — ' . $row['flock_name'],
                    (string) $row['temperature_celsius'] . ' °C mesurés dans le poulailler.',
                    'Vérifiez le thermomètre, la ventilation et l’abreuvement ; ces seuils sont des repères de vigilance, pas un diagnostic.'
                );
            }
            if ($row['humidity_percent'] !== null
                && ((float) $row['humidity_percent'] >= $indoorHumidityHigh
                    || (float) $row['humidity_percent'] <= $indoorHumidityLow)) {
                $alerts[] = self::alert(
                    'climate-humidity-' . $row['id'],
                    'normal',
                    'Mesure réelle du poulailler',
                    (string) $row['followup_date'],
                    'Humidité intérieure à vérifier — ' . $row['flock_name'],
                    (string) $row['humidity_percent'] . ' % mesurés dans le poulailler.',
                    'Vérifiez l’instrument de mesure, la ventilation et l’état de la litière.'
                );
            }
        }

        foreach ($weather['daily'] ?? [] as $forecast) {
            if ($forecast['temperature_max'] >= $outdoorTemperatureHigh) {
                $alerts[] = self::alert(
                    'weather-heat-' . $forecast['date'],
                    'high',
                    'Prévision extérieure — Open-Meteo',
                    $forecast['date'],
                    'Forte chaleur extérieure prévue',
                    'Maximum extérieur prévu : ' . $forecast['temperature_max'] . ' °C. '
                        . 'Ce n’est pas une mesure du poulailler.',
                    'Vérifiez les mesures intérieures, l’ombrage, la ventilation et l’accès à l’eau ; contactez un vétérinaire en cas de signes préoccupants.'
                );
            }
            if ($forecast['temperature_min'] <= $outdoorTemperatureLow) {
                $alerts[] = self::alert(
                    'weather-cold-' . $forecast['date'],
                    'normal',
                    'Prévision extérieure — Open-Meteo',
                    $forecast['date'],
                    'Température extérieure basse prévue',
                    'Minimum extérieur prévu : ' . $forecast['temperature_min'] . ' °C. '
                        . 'Ce n’est pas une mesure du poulailler.',
                    'Vérifiez l’abri et les mesures intérieures ; adaptez les protections du bâtiment selon les conditions locales.'
                );
            }
        }

        foreach (StockService::lowStock($pdo, $userId, $farmId) as $product) {
            $alerts[] = self::alert(
                'stock-low-' . $product['id'] . '-' . $date,
                'normal',
                'Stock',
                $date,
                'Seuil de stock atteint — ' . $product['name'],
                'Disponible : ' . $product['current_stock'] . ' ' . $product['unit_code']
                    . ' · seuil : ' . $product['minimum_stock'] . '.',
                'Contrôlez les besoins et enregistrez une réception si nécessaire.'
            );
        }
        foreach (StockService::expiryAlerts($pdo, $userId, $farmId, $today) as $lot) {
            $alerts[] = self::alert(
                'stock-expiry-' . $lot['id'],
                $lot['expired'] ? 'high' : 'normal',
                'Stock et péremption',
                (string) $lot['expires_on'],
                ($lot['expired'] ? 'Lot périmé' : 'Péremption proche') . ' — ' . $lot['product_name'],
                'Lot ' . ($lot['lot_code'] ?? 'sans numéro') . ' · disponible : '
                    . $lot['quantity_available'] . ' ' . $lot['unit_code'] . '.',
                'Vérifiez l’étiquetage et les consignes du fabricant avant toute utilisation.'
            );
        }

        $stageQuery = $pdo->prepare(
            "SELECT s.id, s.title, s.planned_on, s.priority, f.name AS flock_name
             FROM flock_stages s
             INNER JOIN flocks f ON f.farm_id = s.farm_id AND f.id = s.flock_id
             WHERE s.farm_id = :farm_id AND s.status <> 'completed'
               AND s.planned_on BETWEEN :from_date AND :to_date
             ORDER BY s.planned_on, s.id"
        );
        $stageQuery->execute([
            'farm_id' => $farmId,
            'from_date' => $today->modify('-7 days')->format('Y-m-d'),
            'to_date' => $today->modify('+7 days')->format('Y-m-d'),
        ]);
        foreach ($stageQuery->fetchAll() as $stage) {
            $alerts[] = self::alert(
                'calendar-' . $stage['id'],
                $stage['priority'] === 'urgent' ? 'high' : 'normal',
                'Calendrier',
                (string) $stage['planned_on'],
                (string) $stage['title'] . ' — ' . $stage['flock_name'],
                'Étape planifiée le ' . $stage['planned_on'] . '.',
                'Vérifiez la préparation et mettez à jour l’étape dans le calendrier de l’élevage.'
            );
        }
        usort($alerts, static function (array $left, array $right): int {
            $priority = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
            return ($priority[$left['priority']] <=> $priority[$right['priority']])
                ?: ($left['date'] <=> $right['date']);
        });
        return $alerts;
    }

    public static function syncNotifications(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $alerts
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO farm_notifications
                    (farm_id, user_id, alert_key, title, body, priority, source,
                     occurred_on, suggested_action)
                 VALUES
                    (:farm_id, :user_id, :alert_key, :title, :body, :priority,
                     :source, :occurred_on, :suggested_action)'
            );
            foreach ($alerts as $alert) {
                $insert->execute([
                    'farm_id' => $farmId,
                    'user_id' => $userId,
                    'alert_key' => hash('sha256', (string) $alert['key']),
                    'title' => mb_substr((string) $alert['title'], 0, 180, 'UTF-8'),
                    'body' => mb_substr((string) $alert['body'], 0, 500, 'UTF-8'),
                    'priority' => $alert['priority'],
                    'source' => mb_substr((string) $alert['source'], 0, 40, 'UTF-8'),
                    'occurred_on' => $alert['date'],
                    'suggested_action' => mb_substr((string) $alert['action'], 0, 500, 'UTF-8'),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function notifications(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $statement = $pdo->prepare(
            'SELECT id, title, body, priority, source, occurred_on,
                    suggested_action, read_at, created_at
             FROM farm_notifications
             WHERE farm_id = :farm_id AND user_id = :user_id
             ORDER BY read_at IS NULL DESC, created_at DESC, id DESC
             LIMIT 100'
        );
        $statement->execute(['farm_id' => $farmId, 'user_id' => $userId]);
        return $statement->fetchAll();
    }

    public static function markNotificationRead(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $notificationId
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'health.view');
        $statement = $pdo->prepare(
            'UPDATE farm_notifications SET read_at = UTC_TIMESTAMP()
             WHERE farm_id = :farm_id AND user_id = :user_id AND id = :id AND read_at IS NULL'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'user_id' => $userId,
            'id' => $notificationId,
        ]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare(
                'SELECT id FROM farm_notifications
                 WHERE farm_id = :farm_id AND user_id = :user_id AND id = :id'
            );
            $exists->execute(['farm_id' => $farmId, 'user_id' => $userId, 'id' => $notificationId]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('Cette notification est introuvable.');
            }
        }
    }

    private static function latestWeatherSnapshot(PDO $pdo, int $farmId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT fetched_at, payload FROM weather_snapshots
             WHERE farm_id = :farm_id ORDER BY fetched_at DESC, id DESC LIMIT 1'
        );
        $statement->execute(['farm_id' => $farmId]);
        $snapshot = $statement->fetch();
        if (!is_array($snapshot)) {
            return null;
        }
        try {
            $payload = json_decode((string) $snapshot['payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Les dernières données météo enregistrées sont illisibles.', 0, $exception);
        }
        if (!is_array($payload) || !is_array($payload['daily'] ?? null)) {
            throw new RuntimeException('Les dernières données météo enregistrées sont invalides.');
        }
        return ['fetched_at' => (string) $snapshot['fetched_at'], 'payload' => $payload];
    }

    private static function requestForecast(
        float $latitude,
        float $longitude,
        ?string $endpoint = null
    ): array
    {
        $query = http_build_query([
            'latitude' => number_format($latitude, 6, '.', ''),
            'longitude' => number_format($longitude, 6, '.', ''),
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'forecast_days' => 7,
            'timezone' => 'auto',
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: MonElevage/1.0\r\n",
            ],
        ]);
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException('Connexion météo impossible : ' . $message);
        });
        try {
            $response = file_get_contents(
                ($endpoint ?? 'https://api.open-meteo.com/v1/forecast') . '?' . $query,
                false,
                $context
            );
            $headers = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }
        if (!is_string($response)) {
            throw new RuntimeException('Le service météo n’a retourné aucune réponse.');
        }
        $statusLine = $headers[0] ?? '';
        if (!is_string($statusLine) || !preg_match('/\s2\d\d\s/', $statusLine)) {
            throw new RuntimeException('Le service météo a retourné une erreur HTTP.');
        }
        try {
            $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Le service météo a retourné un document illisible.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('La réponse météo est invalide.');
        }
        return $payload;
    }

    private static function alert(
        string $key,
        string $priority,
        string $source,
        string $date,
        string $title,
        string $body,
        string $action
    ): array {
        return compact('key', 'priority', 'source', 'date', 'title', 'body', 'action');
    }

    private static function coordinate(string $value): ?float
    {
        if (!preg_match('/^-?\d{1,3}(?:[.,]\d{1,6})?$/D', $value)) {
            return null;
        }
        $coordinate = (float) str_replace(',', '.', $value);
        return is_finite($coordinate) ? $coordinate : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
