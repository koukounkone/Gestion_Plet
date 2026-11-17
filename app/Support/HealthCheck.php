<?php
declare(strict_types=1);

namespace MonElevage\Support;

use MonElevage\Database\Connection;
use PDOException;
use RuntimeException;
use Throwable;

final class HealthCheck
{
    private const REQUIRED_TABLES = [
        'users',
        'training_modules',
        'training_lessons',
        'training_progress',
        'user_consents',
        'oauth_identities',
        'farms',
        'farm_members',
        'farm_cycle_settings',
        'auth_tokens',
        'login_attempts',
        'flocks',
        'flock_stages',
        'flock_stage_observations',
        'flock_stage_history',
        'flock_lifecycle_events',
        'flock_transfers',
        'animal_movements',
        'daily_followups',
        'flock_weighings',
        'flock_egg_records',
        'flock_performance_targets',
        'products',
        'suppliers',
        'stock_lots',
        'purchases',
        'purchase_items',
        'inventory_counts',
        'financial_budgets',
        'financial_budget_lines',
        'expenses',
        'stock_movements',
        'clients',
        'sales',
        'sale_lines',
        'sale_payments',
        'audit_logs',
        'health_records',
        'farm_weather_settings',
        'weather_snapshots',
        'farm_notifications',
        'farm_reports',
        'system_role_permissions',
        'system_reference_items',
        'system_calendar_defaults',
        'system_settings',
        'system_alert_thresholds',
        'system_external_services',
    ];
    private const REQUIRED_COLUMNS = [
        'flocks' => [
            'flock_type',
            'currency_code',
            'supplier_name',
            'initial_cost',
            'budget_amount',
            'location',
        ],
        'users' => ['is_admin', 'profile_photo_data', 'profile_photo_mime_type'],
        'daily_followups' => [
            'entries_count',
            'sick_count',
            'mortality_count',
            'sales_count',
            'other_exits_count',
            'feed_consumed_kg',
            'humidity_percent',
        ],
        'animal_movements' => ['daily_followup_id'],
        'products' => ['product_type', 'default_unit_price', 'minimum_stock', 'unit_code'],
        'stock_movements' => ['lot_id', 'purchase_item_id', 'inventory_count_id'],
        'expenses' => [
            'expense_type',
            'receipt_file_name',
            'receipt_storage_key',
            'receipt_mime_type',
        ],
        'flock_weighings' => ['sample_size', 'total_weight_g', 'measured_on'],
        'flock_egg_records' => [
            'opening_stock_count',
            'collected_count',
            'broken_count',
            'sold_count',
            'closing_stock_count',
        ],
        'flock_performance_targets' => [
            'metric',
            'target_value',
            'source_title',
            'source_notes',
            'valid_from',
        ],
        'farm_reports' => [
            'created_by_user_id',
            'flock_id',
            'starts_on',
            'ends_on',
            'version_number',
            'storage_key',
            'file_size_bytes',
            'generated_at',
        ],
    ];

    public static function run(array $config): array
    {
        $checks = [
            'php' => [
                'label' => 'PHP',
                'ok' => PHP_VERSION_ID >= 80200,
                'value' => PHP_VERSION,
                'message' => PHP_VERSION_ID >= 80200
                    ? 'Version compatible (PHP 8.2 minimum).'
                    : 'PHP 8.2 ou ultérieur est requis.',
            ],
            'pdo_mysql' => [
                'label' => 'Extension PDO MySQL',
                'ok' => extension_loaded('pdo_mysql'),
                'value' => extension_loaded('pdo_mysql') ? 'Chargée' : 'Absente',
                'message' => extension_loaded('pdo_mysql')
                    ? 'Le pilote PDO MySQL est disponible.'
                    : 'Activez pdo_mysql dans la configuration PHP de WAMP.',
            ],
        ];

        if (!$checks['php']['ok'] || !$checks['pdo_mysql']['ok']) {
            return self::result($checks);
        }

        try {
            $pdo = Connection::get($config);
            $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $checks['database'] = [
                'label' => 'Connexion PDO',
                'ok' => true,
                'value' => $serverVersion,
                'message' => 'Connexion MySQL/MariaDB réussie.',
            ];

            $statement = $pdo->prepare(
                'SELECT TABLE_NAME
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_TYPE = :table_type'
            );
            $statement->execute(['table_type' => 'BASE TABLE']);
            $existingTables = $statement->fetchAll(\PDO::FETCH_COLUMN);
            $missingTables = array_values(array_diff(self::REQUIRED_TABLES, $existingTables));
            $missingColumns = [];
            foreach (self::REQUIRED_COLUMNS as $table => $requiredColumns) {
                if (!in_array($table, $existingTables, true)) {
                    continue;
                }
                $columnStatement = $pdo->prepare(
                    'SELECT COLUMN_NAME
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
                );
                $columnStatement->execute(['table_name' => $table]);
                $existingColumns = $columnStatement->fetchAll(\PDO::FETCH_COLUMN);
                foreach (array_diff($requiredColumns, $existingColumns) as $column) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
            $schemaValid = $missingTables === [] && $missingColumns === [];

            $checks['schema'] = [
                'label' => 'Structure initiale',
                'ok' => $schemaValid,
                'value' => count($existingTables) . ' table(s) détectée(s)',
                'message' => $schemaValid
                    ? 'Toutes les tables requises sont présentes.'
                    : ($existingTables === []
                        ? 'La base répond, mais le schéma n’a pas encore été importé.'
                        : implode(' ', array_filter([
                            $missingTables === []
                                ? null
                                : sprintf('Tables manquantes : %s.', implode(', ', $missingTables)),
                            $missingColumns === []
                                ? null
                                : sprintf('Colonnes manquantes : %s.', implode(', ', $missingColumns)),
                        ]))),
            ];
        } catch (PDOException $exception) {
            error_log(sprintf('Health check query failure: %s', $exception->getMessage()));
            $checks['database'] = [
                'label' => 'Connexion PDO',
                'ok' => false,
                'value' => 'Erreur de requête',
                'message' => 'La base a répondu, mais la vérification a échoué. Consultez le journal local.',
            ];
            $checks['schema'] = [
                'label' => 'Structure initiale',
                'ok' => false,
                'value' => 'Non vérifiée',
                'message' => 'La vérification du schéma a échoué. Consultez le journal local.',
            ];
        } catch (RuntimeException $exception) {
            error_log(sprintf(
                'Health check database failure: %s; previous=%s',
                $exception->getMessage(),
                $exception->getPrevious()?->getMessage() ?? 'none'
            ));
            $checks['database'] = [
                'label' => 'Connexion PDO',
                'ok' => false,
                'value' => 'Indisponible',
                'message' => 'Connexion impossible. Vérifiez le service MySQL et config/local.php ; le détail reste dans le journal local.',
            ];
            $checks['schema'] = [
                'label' => 'Structure initiale',
                'ok' => false,
                'value' => 'Non vérifiée',
                'message' => 'La vérification du schéma nécessite une connexion à la base.',
            ];
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Health check unexpected failure: %s: %s',
                $exception::class,
                $exception->getMessage()
            ));
            $checks['database'] = [
                'label' => 'Connexion PDO',
                'ok' => false,
                'value' => 'Vérification interrompue',
                'message' => 'Une erreur inattendue est survenue. Consultez le journal local.',
            ];
            $checks['schema'] = [
                'label' => 'Structure initiale',
                'ok' => false,
                'value' => 'Non vérifiée',
                'message' => 'La vérification du schéma a été interrompue.',
            ];
        }

        return self::result($checks);
    }

    private static function result(array $checks): array
    {
        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
        }

        return ['ok' => $ok, 'checks' => $checks];
    }
}
