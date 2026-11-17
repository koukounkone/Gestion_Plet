<?php
declare(strict_types=1);

namespace MonElevage\Reports;

use RuntimeException;

final class AccountArchivePdfRenderer
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

        $storage = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage';
        foreach (['pdf-font-cache', 'pdf-temp'] as $directory) {
            $path = $storage . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException('Impossible de préparer le stockage privé du générateur PDF.');
            }
        }

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->set_option('defaultFont', 'DejaVu Sans');
        $dompdf->set_option('isRemoteEnabled', false);
        $dompdf->set_option('isPhpEnabled', false);
        $dompdf->set_option('isJavascriptEnabled', false);
        $dompdf->set_option('fontCache', $storage . DIRECTORY_SEPARATOR . 'pdf-font-cache');
        $dompdf->set_option('tempDir', $storage . DIRECTORY_SEPARATOR . 'pdf-temp');
        $dompdf->loadHtml(self::html($data), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();
        if (!str_starts_with($pdf, '%PDF-') || !str_contains($pdf, '%%EOF')) {
            throw new RuntimeException('La bibliothèque n’a pas produit un document PDF valide.');
        }
        return $pdf;
    }

    public static function html(array $data): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(
            is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $html = '<!doctype html><html lang="fr"><meta charset="UTF-8"><title>Archive de compte</title>'
            . '<style>@page{margin:28px 30px}body{font-family:"DejaVu Sans",sans-serif;font-size:9px;color:#25332c;line-height:1.4}'
            . 'h1,h2,h3{color:#194638}h1{font-size:20px}h2{font-size:15px;border-bottom:1px solid #dfe8e2;padding-bottom:5px;margin-top:22px;page-break-after:avoid}'
            . 'h3{font-size:11px;margin:12px 0 5px;page-break-after:avoid}.notice{background:#fff5e8;padding:10px;border:1px solid #f0d7b4}'
            . 'table{width:100%;border-collapse:collapse;margin:6px 0 12px;table-layout:fixed}th,td{border:1px solid #dfe6e1;padding:4px 5px;text-align:left;vertical-align:top;word-wrap:break-word}'
            . 'th{background:#edf4ef;color:#194638;width:27%}.record{page-break-inside:avoid}.muted{color:#69766f}</style><body>'
            . '<h1>Archive des données avant suppression définitive</h1>'
            . '<p class="notice">Document confidentiel généré pour permettre à l’administrateur de conserver les données '
            . 'avant la suppression définitive du compte et de ses exploitations.</p>'
            . '<p><strong>Exporté le :</strong> ' . $escape($data['generated_at'] ?? '')
            . '<br><strong>Compte :</strong> ' . $escape($data['user']['full_name'] ?? '')
            . '<br><strong>E-mail :</strong> ' . $escape($data['user']['email'] ?? '')
            . '<br><strong>Statut :</strong> ' . $escape($data['user']['status'] ?? '')
            . '<br><strong>Créé le :</strong> ' . $escape($data['user']['created_at'] ?? '') . '</p>';

        if (($data['farms'] ?? []) === []) {
            $html .= '<p>Aucune exploitation détenue par ce compte.</p>';
        }
        foreach ($data['farms'] ?? [] as $farmData) {
            $farm = $farmData['farm'];
            $html .= '<h2>Exploitation : ' . $escape($farm['name']) . '</h2>'
                . '<p><strong>Identifiant :</strong> ' . $escape($farm['id'])
                . ' · <strong>Devise :</strong> ' . $escape($farm['currency_code'])
                . ' · <strong>Fuseau horaire :</strong> ' . $escape($farm['timezone'])
                . '<br><strong>Pays :</strong> ' . $escape($farm['country_code'] ?? '—')
                . ' · <strong>Créée le :</strong> ' . $escape($farm['created_at']) . '</p>'
                . '<h3>Membres</h3>';
            if ($farmData['members'] === []) {
                $html .= '<p class="muted">Aucun membre enregistré.</p>';
            } else {
                $html .= '<table><thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>État</th><th>Depuis</th></tr></thead><tbody>';
                foreach ($farmData['members'] as $member) {
                    $html .= '<tr><td>' . $escape($member['full_name']) . '</td><td>'
                        . $escape($member['email']) . '</td><td>' . $escape($member['role'])
                        . '</td><td>' . $escape($member['status']) . '</td><td>'
                        . $escape($member['joined_at']) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }

            if ($farmData['tables'] === []) {
                $html .= '<p class="muted">Aucune autre donnée enregistrée.</p>';
            }
            foreach ($farmData['tables'] as $table => $rows) {
                $html .= '<h3>' . $escape($table) . ' — ' . count($rows) . ' enregistrement(s)</h3>';
                foreach ($rows as $row) {
                    $html .= '<table class="record"><tbody>';
                    foreach ($row as $column => $value) {
                        if ($value === null) {
                            $display = '—';
                        } elseif (is_scalar($value)) {
                            $display = (string) $value;
                        } else {
                            $display = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                        }
                        $html .= '<tr><th>' . $escape($column) . '</th><td>'
                            . $escape($display) . '</td></tr>';
                    }
                    $html .= '</tbody></table>';
                }
            }
        }
        return $html . '</body></html>';
    }
}
