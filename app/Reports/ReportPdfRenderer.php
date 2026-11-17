<?php
declare(strict_types=1);

namespace MonElevage\Reports;

use RuntimeException;
use MonElevage\Finance\FinanceService;

final class ReportPdfRenderer
{
    public static function render(array $data): string
    {
        $autoload = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('La bibliothèque PDF est absente. Exécutez composer install.');
        }
        require_once $autoload;
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new RuntimeException('La bibliothèque Dompdf ne peut pas être chargée.');
        }

        $cacheDirectory = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'pdf-font-cache';
        $tempDirectory = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'pdf-temp';
        self::ensurePrivateDirectory($cacheDirectory);
        self::ensurePrivateDirectory($tempDirectory);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->set_option('defaultFont', 'DejaVu Sans');
        $dompdf->set_option('isRemoteEnabled', false);
        $dompdf->set_option('isPhpEnabled', false);
        $dompdf->set_option('isJavascriptEnabled', false);
        $dompdf->set_option('fontCache', $cacheDirectory);
        $dompdf->set_option('tempDir', $tempDirectory);
        $dompdf->loadHtml(self::html($data), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $dompdf->getCanvas()->page_text(
            490,
            820,
            'Page {PAGE_NUM} / {PAGE_COUNT}',
            $font,
            8,
            [0.35, 0.4, 0.37]
        );
        $pdf = $dompdf->output();
        if (!str_starts_with($pdf, '%PDF-') || !str_contains($pdf, '%%EOF')) {
            throw new RuntimeException('La bibliothèque n’a pas produit un document PDF valide.');
        }
        return $pdf;
    }

    private static function ensurePrivateDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Impossible de préparer le stockage privé du générateur PDF.');
        }
    }

    public static function html(array $data): string
    {
        $flock = $data['flock'];
        $finance = $data['finance'];
        $headcount = $data['headcount'];
        $typeLabels = [
            'broiler' => 'Poulet de chair',
            'layer' => 'Pondeuses',
            'cockerel' => 'Coquelets',
            'free_range' => 'Élevage en plein air',
            'breeder' => 'Reproducteurs',
            'quail' => 'Cailles',
            'turkey' => 'Dindes',
            'duck' => 'Canards',
            'guinea_fowl' => 'Pintades',
            'goose' => 'Oies',
            'pigeon' => 'Pigeons',
        ];
        $poultryLabels = [
            'chicken' => 'Poulet',
            'turkey' => 'Dinde',
            'duck' => 'Canard',
            'quail' => 'Caille',
            'guinea_fowl' => 'Pintade',
            'goose' => 'Oie',
            'pigeon' => 'Pigeon',
            'other' => 'Autre',
        ];
        $productionLabels = [
            'meat' => 'Viande',
            'eggs' => 'Œufs',
            'breeding' => 'Reproduction',
            'other' => 'Autre',
        ];
        $eventLabels = [
            'symptom' => 'Signes observés',
            'veterinary_visit' => 'Intervention vétérinaire',
            'vaccination' => 'Vaccination',
            'prescribed_treatment' => 'Traitement prescrit',
        ];
        $categoryLabels = is_array($data['category_labels'] ?? null)
            ? $data['category_labels']
            : FinanceService::CATEGORIES;
        $e = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $money = static fn (int|float|string $amount): string =>
            number_format((float) $amount, 0, ',', ' ') . ' FCFA';
        $quantity = static fn (int|float|string $amount, int $precision = 2): string =>
            number_format((float) $amount, $precision, ',', ' ');
        $date = static function (string $value): string {
            $parsed = date_create_immutable($value);
            return $parsed === false ? $value : $parsed->format('d/m/Y');
        };
        $paragraph = static function (mixed $value) use ($e): string {
            $text = trim((string) $value);
            return $text === '' ? '—' : nl2br($e($text), false);
        };
        $barChart = static function (string $title, array $items) use ($e, $money, $quantity, $date): string {
            if ($items === []) {
                return '<div class="chart"><h3>' . $e($title)
                    . '</h3><p class="muted">Données insuffisantes pour cette période.</p></div>';
            }
            $maximum = max(1.0, ...array_map(
                static fn (array $item): float => abs((float) $item['value']),
                $items
            ));
            $rows = '';
            foreach ($items as $item) {
                $value = (float) $item['value'];
                $width = max(1, min(100, abs($value) * 100 / $maximum));
                $formatted = ($item['unit'] ?? '') === 'g'
                    ? $quantity($value, 1) . ' g'
                    : (($item['unit'] ?? '') === 'kg' ? $quantity($value, 2) . ' kg' : $money($value));
                $rows .= '<tr><td class="chart-label">' . $e($item['label']) . '</td><td>'
                    . '<div class="bar-track"><span class="bar'
                    . ($value < 0 ? ' negative' : '')
                    . '" style="width:' . number_format($width, 2, '.', '') . '%"></span></div>'
                    . '</td><td class="chart-value">' . $e($formatted) . '</td></tr>';
            }
            return '<div class="chart"><h3>' . $e($title)
                . '</h3><table class="chart-table"><tbody>' . $rows . '</tbody></table></div>';
        };

        $generated = date_create_immutable((string) $data['generated_at']);
        $generationLabel = $generated === false
            ? (string) $data['generated_at']
            : $generated->setTimezone(new \DateTimeZone((string) $data['farm']['timezone']))
                ->format('d/m/Y à H:i');

        $html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8">'
            . '<title>Rapport d’élevage — ' . $e($flock['name']) . '</title><style>'
            . ' @page{margin:28px 34px 42px}body{font-family:"DejaVu Sans",sans-serif;font-size:9px;color:#25332c;line-height:1.45}'
            . ' h1{font-size:21px;color:#194638;margin:0 0 4px}h2{font-size:14px;color:#194638;border-bottom:1px solid #dfe8e2;padding-bottom:5px;margin:18px 0 8px;page-break-after:avoid}'
            . ' h3{font-size:10px;color:#205a46;margin:0 0 7px}p{margin:4px 0}.muted{color:#69766f}.small{font-size:8px}.hero{border-bottom:2px solid #287451;padding-bottom:12px;margin-bottom:12px}'
            . ' .hero-meta{color:#536158;font-size:9px}.summary{width:100%;border-collapse:separate;border-spacing:5px;margin:4px 0 12px}.summary td{width:25%;background:#f2f7f3;border:1px solid #e0ebe3;padding:8px;vertical-align:top}.summary strong{display:block;color:#194638;font-size:13px;margin-top:3px}'
            . ' table.data{width:100%;border-collapse:collapse;margin:5px 0 10px}table.data thead{display:table-header-group}table.data th{background:#edf4ef;color:#194638;text-align:left;font-weight:bold}table.data th,table.data td{padding:5px 6px;border:1px solid #dfe6e1;vertical-align:top;word-wrap:break-word}table.data tr{page-break-inside:avoid}'
            . ' .chart-grid{width:100%;border-collapse:separate;border-spacing:5px;margin:5px 0}.chart-grid td{width:50%;vertical-align:top}.chart{border:1px solid #dfe6e1;padding:8px;page-break-inside:avoid}.chart-table{width:100%;border-collapse:collapse}.chart-table td{padding:3px 2px;vertical-align:middle}.chart-label{width:32%;font-size:8px}.chart-value{width:28%;text-align:right;font-size:8px;white-space:nowrap}.bar-track{width:100%;height:9px;background:#edf1ee}.bar{display:block;height:9px;background:#32885e}.bar.negative{background:#b45349}'
            . ' .note{border:1px solid #dfe6e1;background:#f8faf8;padding:9px;white-space:normal}.empty{color:#78847e;font-style:italic}.pill{color:#8f4a12;font-weight:bold}.new-page{page-break-before:always}'
            . '</style></head><body>';
        $html .= '<div class="hero"><h1>Rapport de suivi d’élevage</h1>'
            . '<p><strong>' . $e($data['farm']['name']) . '</strong> · Généré le '
            . $e($generationLabel) . ' · Version ' . (int) $data['version_number'] . '</p>'
            . '<p class="hero-meta">Période du ' . $e($date($data['period']['from'])) . ' au '
            . $e($date($data['period']['to'])) . '</p></div>';

        $html .= '<h2>Informations de l’élevage</h2><table class="data"><tbody>'
            . '<tr><th>Élevage</th><td>' . $e($flock['name']) . '</td><th>Code</th><td>' . $e($flock['code']) . '</td></tr>'
            . '<tr><th>Type</th><td>' . $e($typeLabels[$flock['flock_type']] ?? $flock['flock_type'])
            . '</td><th>Espèce / production</th><td>'
            . $e($poultryLabels[$flock['poultry_type']] ?? $flock['poultry_type']) . ' · '
            . $e($productionLabels[$flock['production_type']] ?? $flock['production_type']) . '</td></tr>'
            . '<tr><th>Race</th><td>' . $e($flock['breed'] ?: 'Non renseignée')
            . '</td><th>Lieu</th><td>' . $e($flock['location'] ?: 'Non renseigné') . '</td></tr>'
            . '<tr><th>Début de l’élevage</th><td>' . $e($date((string) $flock['started_on']))
            . '</td><th>Statut</th><td>' . $e($flock['status']) . '</td></tr>'
            . '</tbody></table>';

        $html .= '<h2>Effectifs et mortalité</h2><table class="summary"><tr>'
            . '<td>Effectif au début de la période<strong>' . number_format($headcount['initial'], 0, ',', ' ') . '</strong></td>'
            . '<td>Effectif à la fin de la période<strong>' . number_format($headcount['final'], 0, ',', ' ') . '</strong></td>'
            . '<td>Mortalités enregistrées<strong>' . number_format($headcount['mortality'], 0, ',', ' ') . '</strong></td>'
            . '<td>Écart de poids moyen<strong>'
            . ($data['growth']['change_g'] === null
                ? 'Indisponible'
                : $quantity($data['growth']['change_g'], 1) . ' g')
            . '</strong></td></tr></table>';
        $html .= '<table class="chart-grid"><tr><td>'
            . $barChart('Effectifs et mortalités', [
                ['label' => 'Effectif initial', 'value' => $headcount['initial']],
                ['label' => 'Effectif final', 'value' => $headcount['final']],
                ['label' => 'Mortalités', 'value' => $headcount['mortality']],
            ])
            . '</td><td>' . $barChart('Évolution du poids moyen', array_map(
                static fn (array $weight): array => [
                    'label' => $date((string) $weight['measured_date']) . ' · ' . $weight['source'],
                    'value' => (float) $weight['average_weight_g'],
                    'unit' => 'g',
                ],
                $data['growth']['weights']
            )) . '</td></tr></table>';

        $html .= '<h2>Croissance et alimentation</h2>';
        if ($data['growth']['weights'] === []) {
            $html .= '<p class="empty">Aucune mesure de poids enregistrée sur cette période.</p>';
        } else {
            $rows = '';
            foreach ($data['growth']['weights'] as $weight) {
                $rows .= '<tr><td>' . $e($date((string) $weight['measured_date'])) . '</td><td>'
                    . $e($weight['source']) . '</td><td>' . $e($quantity((float) $weight['average_weight_g'], 1))
                    . ' g</td></tr>';
            }
            $html .= '<table class="data"><thead><tr><th>Date</th><th>Source</th><th>Poids moyen</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }
        $html .= '<p><strong>Aliment suivi quotidiennement :</strong> '
            . $e($quantity($data['feed']['daily_total_kg'], 3)) . ' kg</p>';
        if ($data['feed']['daily_rows'] !== []) {
            $rows = '';
            foreach ($data['feed']['daily_rows'] as $feed) {
                $rows .= '<tr><td>' . $e($date((string) $feed['followup_date'])) . '</td><td>'
                    . $e($quantity((float) $feed['feed_consumed_kg'], 3)) . ' kg</td></tr>';
            }
            $html .= '<table class="data"><thead><tr><th>Suivi quotidien</th><th>Aliment consommé</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }
        if ($data['feed']['stock_rows'] !== []) {
            $rows = '';
            foreach ($data['feed']['stock_rows'] as $feed) {
                $rows .= '<tr><td>' . $e($feed['name']) . '</td><td>'
                    . $e($quantity((float) $feed['consumed_quantity'], 3)) . ' '
                    . $e($feed['unit_code']) . '</td></tr>';
            }
            $html .= '<p><strong>Sorties de consommables enregistrées pour cet élevage :</strong></p>'
                . '<table class="data"><thead><tr><th>Produit</th><th>Quantité</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }
        $html .= '<p class="small muted">Les aliments des suivis quotidiens et les sorties de stock sont deux sources distinctes et ne sont pas additionnés entre eux.</p>';

        $html .= '<h2>Suivi sanitaire enregistré</h2>';
        if (!$data['health']['available']) {
            $html .= '<p class="empty">Les informations sanitaires sont masquées : votre rôle ne permet pas de consulter ce module.</p>';
        } elseif ($data['health']['records'] === []) {
            $html .= '<p class="empty">Aucun suivi sanitaire enregistré sur cette période.</p>';
        } else {
            foreach ($data['health']['records'] as $record) {
                $html .= '<div class="note"><strong>' . $e($date((string) $record['observed_on']))
                    . ' · ' . $e($eventLabels[$record['event_type']] ?? $record['event_type']) . '</strong>';
                if ($record['affected_count'] !== null) {
                    $html .= ' · ' . number_format((int) $record['affected_count'], 0, ',', ' ')
                        . ' animal(aux) concerné(s)';
                }
                if ($record['symptoms'] !== null && $record['symptoms'] !== '') {
                    $html .= '<p><strong>Signes observés :</strong> ' . $paragraph($record['symptoms']) . '</p>';
                }
                $html .= '<p>' . $paragraph($record['details']) . '</p>';
                if ($record['professional_name'] !== null) {
                    $html .= '<p><strong>Professionnel :</strong> ' . $e($record['professional_name']) . '</p>';
                }
                if ($record['prescription_details'] !== null) {
                    $html .= '<p><strong>Consignes prescrites :</strong> '
                        . $paragraph($record['prescription_details']) . '</p>';
                }
                if ($record['next_due_on'] !== null) {
                    $html .= '<p><strong>Prochaine échéance :</strong> '
                        . $e($date((string) $record['next_due_on'])) . '</p>';
                }
                $html .= '</div><br>';
            }
            $html .= '<p class="small muted">Ce rapport restitue les informations saisies ; il ne constitue ni un diagnostic ni une prescription.</p>';
        }

        $html .= '<h2>Achats, dépenses et matériel réutilisable</h2>';
        if ($data['purchases'] === []) {
            $html .= '<p class="empty">Aucun achat associé à cet élevage sur la période.</p>';
        } else {
            $rows = '';
            $purchaseTotal = 0;
            foreach ($data['purchases'] as $purchase) {
                $purchaseTotal += (int) $purchase['line_total'];
                $rows .= '<tr><td>' . $e($date((string) $purchase['purchased_on'])) . '</td><td>'
                    . $e($purchase['supplier_name']) . '</td><td>' . $e($purchase['product_name'])
                    . ($purchase['product_type'] === 'equipment' ? ' <span class="pill">(réutilisable)</span>' : '')
                    . '</td><td>' . $e($quantity((float) $purchase['quantity'], 3)) . ' '
                    . $e($purchase['unit_code']) . '</td><td>' . $e($money($purchase['line_total'])) . '</td></tr>';
            }
            $rows .= '<tr><th colspan="4">Total des achats de la période</th><th>'
                . $e($money($purchaseTotal)) . '</th></tr>';
            $html .= '<table class="data"><thead><tr><th>Date</th><th>Fournisseur</th><th>Produit</th><th>Quantité</th><th>Montant</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }
        if ($data['expenses'] === []) {
            $html .= '<p class="empty">Aucune dépense directe enregistrée sur la période.</p>';
        } else {
            $rows = '';
            $expenseTotal = 0;
            foreach ($data['expenses'] as $expense) {
                $expenseTotal += (int) $expense['amount'];
                $rows .= '<tr><td>' . $e($categoryLabels[$expense['category']] ?? $expense['category'])
                    . '</td><td>' . $e($money($expense['amount'])) . '</td></tr>';
            }
            $rows .= '<tr><th>Total des dépenses directes</th><th>' . $e($money($expenseTotal)) . '</th></tr>';
            $html .= '<table class="data"><thead><tr><th>Dépenses directes par catégorie</th><th>Montant</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }
        $html .= '<p><strong>Matériel réutilisable acheté :</strong> '
            . $e($money($finance['equipment_purchases'])) . ' · <strong>Investissements directs :</strong> '
            . $e($money($finance['direct_investments'])) . ' · <strong>Total des investissements :</strong> '
            . $e($money($finance['reusable_investments'])) . '</p>';

        $html .= '<h2>Ventes et paiements</h2>';
        $html .= '<table class="summary"><tr>'
            . '<td>Ventes comptabilisées<strong>' . $e($money($finance['revenue_total'])) . '</strong></td>'
            . '<td>Recettes encaissées sur la période<strong>' . $e($money($finance['cash_collected'])) . '</strong></td>'
            . '<td>Soldes impayés<strong>' . $e($money($finance['receivables'])) . '</strong></td>'
            . '<td>Ventes non annulées<strong>' . count(array_filter(
                $data['sales'],
                static fn (array $sale): bool => $sale['status'] === 'posted'
            )) . '</strong></td></tr></table>';
        if ($data['sales'] === []) {
            $html .= '<p class="empty">Aucune vente de cet élevage sur la période.</p>';
        } else {
            $rows = '';
            foreach ($data['sales'] as $sale) {
                $items = [];
                foreach ($sale['items'] as $item) {
                    $items[] = $item['name'] . ' (' . $quantity($item['quantity'], 3) . ')';
                }
                $rows .= '<tr><td>' . $e($date(substr((string) $sale['sold_at'], 0, 10))) . '</td><td>'
                    . $e($sale['client_name'] ?: 'Client non renseigné') . '</td><td>'
                    . $e(implode(', ', $items)) . '</td><td>'
                    . ($sale['status'] === 'posted' ? '' : '<span class="pill">Annulée</span> ')
                    . $e($money($sale['amount'])) . '</td><td>' . $e($money($sale['receivable'])) . '</td></tr>';
            }
            $html .= '<table class="data"><thead><tr><th>Date</th><th>Client</th><th>Produits</th><th>Montant</th><th>Solde</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }

        $html .= '<h2>Bilan financier estimatif</h2>'
            . '<table class="data"><thead><tr><th>Indicateur</th><th>Montant</th></tr></thead><tbody>'
            . '<tr><td>Recettes des ventes</td><td>' . $e($money($finance['revenue_total'])) . '</td></tr>'
            . '<tr><td>Encaissements nets pendant la période</td><td>' . $e($money($finance['cash_collected'])) . '</td></tr>'
            . '<tr><td>Créances des ventes de la période</td><td>' . $e($money($finance['receivables'])) . '</td></tr>'
            . '<tr><td>Dépenses enregistrées</td><td>' . $e($money($finance['expenses_total'])) . '</td></tr>'
            . '<tr><td>Coûts économiques consommés</td><td>' . $e($money($finance['economic_costs'])) . '</td></tr>'
            . '<tr><td>Valeur estimée du stock restant</td><td>' . $e($money($finance['remaining_stock_value'])) . '</td></tr>'
            . '<tr><td>Coût affecté par animal vendu</td><td>'
            . ($finance['cost_per_animal_sold'] === null ? 'Indisponible' : $e($money($finance['cost_per_animal_sold'])))
            . '</td></tr><tr><td><strong>Bénéfice / perte estimé(e)</strong></td><td><strong>'
            . $e($money($finance['estimated_profit'])) . '</strong></td></tr>'
            . '<tr><td>Marge estimée</td><td>'
            . ($finance['margin_percent'] === null
                ? 'Non calculable sans recette'
                : $e(number_format((float) $finance['margin_percent'], 2, ',', ' ') . ' %'))
            . '</td></tr><tr><td>Flux net de trésorerie</td><td>'
            . $e($money($finance['net_cash_flow'])) . '</td></tr></tbody></table>';
        $html .= '<table class="chart-grid"><tr><td>'
            . $barChart('Résultat économique', [
                ['label' => 'Recettes', 'value' => $finance['revenue_total']],
                ['label' => 'Coûts économiques', 'value' => $finance['economic_costs']],
                ['label' => 'Bénéfice / perte', 'value' => $finance['estimated_profit']],
            ])
            . '</td><td>' . $barChart('Flux de trésorerie', [
                ['label' => 'Encaissements nets', 'value' => $finance['cash_collected']],
                ['label' => 'Dépenses enregistrées', 'value' => $finance['cash_outflow']],
                ['label' => 'Flux net', 'value' => $finance['net_cash_flow']],
            ]) . '</td></tr></table>';
        $html .= '<p class="small muted">Résultat estimé = recettes des ventes − charges directes − coûts des consommations de stock − coût des produits vendus. Les achats de stock sont comptés en dépenses à l’achat ; seuls les coûts consommés ou vendus entrent dans le résultat. Les stocks restants et équipements réutilisables sont présentés séparément. Le flux net soustrait les dépenses saisies des encaissements nets. Les valeurs sont estimatives et peuvent être incomplètes si des coûts de lots ne sont pas connus.</p>';
        if (!$finance['is_complete']) {
            $html .= '<div class="note"><strong>Limites et données incomplètes</strong><ul>';
            foreach ($finance['incomplete_reasons'] as $reason) {
                $html .= '<li>' . $e($reason) . '</li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '<h2>Observations</h2><div class="note">'
            . $paragraph($data['observation'] ?? '') . '</div>'
            . '<p class="small muted">Rapport généré le ' . $e($generationLabel)
            . '. Version ' . (int) $data['version_number'] . ' · Les données présentées correspondent à l’état enregistré au moment de la génération.</p>'
            . '</body></html>';
        return $html;
    }
}
