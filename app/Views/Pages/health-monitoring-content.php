<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$fieldValue = static function (string $key, mixed $fallback = '') use ($recordValues): string {
    $value = $recordValues[$key] ?? $fallback;
    return is_string($value) || is_numeric($value) ? (string) $value : '';
};
$eventLabels = [
    'symptom' => 'Signes observés',
    'veterinary_visit' => 'Intervention vétérinaire',
    'vaccination' => 'Vaccination',
    'prescribed_treatment' => 'Traitement prescrit par un professionnel',
];
$priorityLabels = [
    'urgent' => 'Urgent',
    'high' => 'Priorité élevée',
    'normal' => 'À surveiller',
    'low' => 'Information',
];
$weatherValue = static fn (mixed $value): string =>
    is_numeric($value) ? number_format((float) $value, 1, ',', ' ') : '—';
$unreadCount = count(array_filter(
    $notifications,
    static fn (array $notification): bool => $notification['read_at'] === null
));
$baseUrl = 'health-monitoring.php?farm_id=' . $farmId;
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= $escape((string) $error) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<div class="health-safety-note" role="note">
    <strong>Outil de suivi, pas de diagnostic.</strong>
    Les observations ne déterminent aucune maladie et ne prescrivent aucun médicament.
    Les traitements consignés doivent provenir d’un professionnel de santé animale.
    Pour une situation préoccupante, contactez un vétérinaire.
</div>

<section class="health-weather-grid" aria-label="Météo extérieure">
    <article class="card health-panel">
        <div class="card-heading">
            <div>
                <h2>Prévisions météo extérieures</h2>
                <p>Source : <a href="https://open-meteo.com/en/docs" target="_blank" rel="noopener noreferrer">Open-Meteo, documentation de l’API</a> · prévisions extérieures, jamais des mesures du poulailler.</p>
            </div>
        </div>
        <?php if ($weather['status'] === 'not_configured'): ?>
            <p class="health-empty">Configurez les coordonnées de l’exploitation pour consulter les prévisions. Aucune clé API n’est requise.</p>
        <?php elseif ($weather['daily'] === []): ?>
            <p class="health-empty">Aucune prévision disponible pour le moment.</p>
        <?php else: ?>
            <p class="health-weather-updated">
                Dernière donnée reçue :
                <?= $weather['last_updated'] === null ? 'inconnue' : $escape((string) $weather['last_updated']) ?> UTC
                · Fuseau des prévisions : <?= $escape((string) ($weather['timezone'] ?? 'UTC')) ?>
            </p>
            <div class="table-scroll">
                <table class="data-table health-table">
                    <thead><tr><th>Date</th><th>Min. extérieure</th><th>Max. extérieure</th><th>Probabilité max. de pluie</th></tr></thead>
                    <tbody>
                    <?php foreach ($weather['daily'] as $day): ?>
                        <tr>
                            <td><?= $escape((string) $day['date']) ?></td>
                            <td><?= $weatherValue($day['temperature_min']) ?> °C</td>
                            <td><?= $weatherValue($day['temperature_max']) ?> °C</td>
                            <td><?= $day['precipitation_probability_max'] === null
                                ? '—'
                                : $weatherValue($day['precipitation_probability_max']) . ' %' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($weather['error'] !== null): ?>
            <div class="alert alert-warning" role="status"><?= $escape((string) $weather['error']) ?></div>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $escape($baseUrl) ?>" class="health-location-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_weather_settings">
                <label class="field"><span>Latitude</span>
                    <input type="number" name="latitude" min="-90" max="90" step="0.000001" required
                           value="<?= $escape((string) ($weatherSettings['latitude'] ?? '')) ?>">
                </label>
                <label class="field"><span>Longitude</span>
                    <input type="number" name="longitude" min="-180" max="180" step="0.000001" required
                           value="<?= $escape((string) ($weatherSettings['longitude'] ?? '')) ?>">
                </label>
                <button class="button button-secondary" type="submit">Enregistrer la localisation</button>
            </form>
        <?php endif; ?>
    </article>
    <aside class="card health-panel health-measurement-note">
        <h2>Mesures réelles du poulailler</h2>
        <p>La température et l’humidité saisies dans le <a href="followups.php?farm_id=<?= $farmId ?>">suivi quotidien</a> sont des observations intérieures.</p>
        <p>La météo ci-contre provient d’un modèle extérieur. Elle ne remplace ni un thermomètre ni un hygromètre installés dans le bâtiment.</p>
        <p>Les repères de vigilance affichés ne sont pas des prescriptions et doivent être interprétés selon l’élevage et les conseils vétérinaires locaux.</p>
    </aside>
</section>

<section class="health-alert-section" aria-labelledby="health-alert-heading">
    <div class="dashboard-section-heading">
        <div>
            <p class="eyebrow">VIGILANCE DE L’EXPLOITATION</p>
            <h2 id="health-alert-heading">Alertes avec origine, date et action suggérée</h2>
        </div>
    </div>
    <?php if ($alerts === []): ?>
        <div class="card health-empty">Aucune alerte active n’a été générée à partir des données disponibles.</div>
    <?php else: ?>
        <div class="health-alert-list">
            <?php foreach ($alerts as $alert): ?>
                <article class="card health-alert health-priority-<?= $escape($alert['priority']) ?>">
                    <div class="health-alert-heading">
                        <span class="health-priority-badge"><?= $escape($priorityLabels[$alert['priority']] ?? $alert['priority']) ?></span>
                        <span><?= $escape($alert['source']) ?> · <?= $escape($alert['date']) ?></span>
                    </div>
                    <h3><?= $escape($alert['title']) ?></h3>
                    <p><?= $escape($alert['body']) ?></p>
                    <p><strong>Action suggérée :</strong> <?= $escape($alert['action']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($canManage && $flocks !== []): ?>
    <section class="card health-panel" aria-labelledby="health-record-heading">
        <div class="card-heading">
            <div>
                <h2 id="health-record-heading">Consigner une observation ou intervention</h2>
                <p>Pour les traitements, recopiez uniquement les consignes données par un professionnel.</p>
            </div>
        </div>
        <form method="post" action="<?= $escape($baseUrl) ?>" class="health-record-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save_record">
            <label class="field"><span>Élevage</span>
                <select name="flock_id" required>
                    <option value="">Choisir un élevage</option>
                    <?php foreach ($flocks as $flock): ?>
                        <?php if (!in_array($flock['status'], ['active', 'paused'], true)) { continue; } ?>
                        <option value="<?= (int) $flock['id'] ?>"
                            <?= $fieldValue('flock_id') === (string) $flock['id'] ? 'selected' : '' ?>>
                            <?= $escape((string) $flock['name']) ?> · <?= $escape((string) $flock['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>Type</span>
                <select name="event_type" required>
                    <?php foreach ($eventLabels as $key => $label): ?>
                        <option value="<?= $escape($key) ?>"
                            <?= $fieldValue('event_type', 'symptom') === $key ? 'selected' : '' ?>>
                            <?= $escape($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>Date observée</span>
                <input type="date" name="observed_on" max="<?= $today->format('Y-m-d') ?>"
                       value="<?= $escape($fieldValue('observed_on', $today->format('Y-m-d'))) ?>" required>
            </label>
            <label class="field"><span>Nombre d’animaux concernés (facultatif)</span>
                <input type="number" name="affected_count" min="1" max="2147483647" step="1"
                       value="<?= $escape($fieldValue('affected_count')) ?>">
            </label>
            <label class="field health-field-wide"><span>Signes observés (sans diagnostic)</span>
                <textarea name="symptoms" rows="2" maxlength="3000"><?= $escape($fieldValue('symptoms')) ?></textarea>
            </label>
            <label class="field health-field-wide"><span>Observation ou compte rendu</span>
                <textarea name="details" rows="3" maxlength="5000" required><?= $escape($fieldValue('details')) ?></textarea>
            </label>
            <label class="field"><span>Professionnel consulté (si applicable)</span>
                <input type="text" name="professional_name" maxlength="160"
                       value="<?= $escape($fieldValue('professional_name')) ?>">
            </label>
            <label class="field"><span>Prochaine échéance (vaccination ou suivi)</span>
                <input type="date" name="next_due_on" min="<?= $today->format('Y-m-d') ?>"
                       value="<?= $escape($fieldValue('next_due_on')) ?>">
            </label>
            <label class="field health-field-wide"><span>Consignes prescrites par un professionnel (obligatoires pour un traitement)</span>
                <textarea name="prescription_details" rows="3" maxlength="5000"><?= $escape($fieldValue('prescription_details')) ?></textarea>
            </label>
            <div class="health-field-wide">
                <button class="button button-primary" type="submit">Enregistrer dans l’historique sanitaire</button>
            </div>
        </form>
    </section>
<?php elseif ($canManage): ?>
    <div class="card health-empty">
        Créez d’abord un élevage pour lui associer des observations et des interventions
        dans le <a href="flocks.php?farm_id=<?= $farmId ?>">module des élevages</a>.
    </div>
<?php endif; ?>

<section class="card health-panel" aria-labelledby="health-history-heading">
    <div class="card-heading">
        <div>
            <h2 id="health-history-heading">Historique sanitaire</h2>
            <p>Les informations sont conservées dans l’exploitation et visibles aux responsables autorisés.</p>
        </div>
    </div>
    <?php if ($records === []): ?>
        <p class="health-empty">Aucune observation ou intervention n’a encore été consignée.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table health-table">
                <thead><tr><th>Date</th><th>Élevage</th><th>Événement</th><th>Signes et compte rendu</th><th>Professionnel / consignes</th><th>Échéance</th></tr></thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= $escape((string) $record['observed_on']) ?></td>
                        <td><?= $escape((string) $record['flock_name']) ?></td>
                        <td><?= $escape($eventLabels[$record['event_type']] ?? $record['event_type']) ?>
                            <?php if ($record['affected_count'] !== null): ?><small><?= (int) $record['affected_count'] ?> concerné(s)</small><?php endif; ?>
                        </td>
                        <td><?= $escape(trim(implode(' · ', array_filter([
                            (string) ($record['symptoms'] ?? ''),
                            (string) $record['details'],
                        ])))) ?></td>
                        <td><?= $record['professional_name'] === null ? '—' : $escape((string) $record['professional_name']) ?>
                            <?php if ($record['prescription_details'] !== null): ?><small><?= $escape((string) $record['prescription_details']) ?></small><?php endif; ?>
                        </td>
                        <td><?= $record['next_due_on'] === null ? '—' : $escape((string) $record['next_due_on']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card health-panel" aria-labelledby="notifications-heading">
    <div class="card-heading">
        <div>
            <h2 id="notifications-heading">Notifications</h2>
            <p><?= number_format($unreadCount, 0, ',', ' ') ?> non lue(s) · générées lors de la consultation de cette page.</p>
        </div>
    </div>
    <?php if ($notifications === []): ?>
        <p class="health-empty">Aucune notification.</p>
    <?php else: ?>
        <ul class="health-notification-list">
            <?php foreach ($notifications as $notification): ?>
                <li class="<?= $notification['read_at'] === null ? 'is-unread' : '' ?>">
                    <div>
                        <strong><?= $escape((string) $notification['title']) ?></strong>
                        <p><?= $escape((string) $notification['body']) ?></p>
                        <p><strong>Action suggérée :</strong> <?= $escape((string) $notification['suggested_action']) ?></p>
                        <span class="health-priority-badge"><?= $escape($priorityLabels[$notification['priority']] ?? $notification['priority']) ?></span>
                        <small><?= $escape((string) $notification['source']) ?> · <?= $escape((string) $notification['occurred_on']) ?></small>
                    </div>
                    <?php if ($notification['read_at'] === null): ?>
                        <form method="post" action="<?= $escape($baseUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="mark_notification_read">
                            <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                            <button class="button button-secondary button-small" type="submit">Marquer comme lue</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
