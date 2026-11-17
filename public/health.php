<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Support\HealthCheck;

$result = HealthCheck::run($config);
http_response_code($result['ok'] ? 200 : 503);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vérification technique — Mon Élevage</title>
    <style>
        body{margin:0;padding:24px;background:#f7f8f6;color:#1d2d27;font:14px/1.55 "Segoe UI",Arial,sans-serif}
        main{max-width:760px;margin:5vh auto;padding:26px;border:1px solid #e8ece9;border-radius:10px;background:#fff}
        h1{margin-top:0;color:#153b30}.intro{color:#78847e}
        .check{display:grid;grid-template-columns:180px 150px 1fr;gap:12px;align-items:start;padding:14px 0;border-top:1px solid #e8ece9}
        .label{font-weight:700}.value{font-weight:600}
        .ok{color:#287451}.fail{color:#b45349}
        .note{margin-top:18px;padding:12px;border-radius:6px;background:#f3f8f4;color:#536158}
        a{color:#287451}
        @media(max-width:600px){body{padding:12px}.check{grid-template-columns:1fr;gap:3px}main{padding:18px}}
    </style>
</head>
<body>
<main>
    <h1>Vérification technique</h1>
    <p class="intro">Page locale de diagnostic. Aucun mot de passe ni détail SQL n’est affiché.</p>
    <?php foreach ($result['checks'] as $check): ?>
        <section class="check">
            <span class="label"><?= escape($check['label']) ?></span>
            <span class="value <?= $check['ok'] ? 'ok' : 'fail' ?>">
                <?= $check['ok'] ? 'OK' : 'À vérifier' ?> · <?= escape($check['value']) ?>
            </span>
            <span><?= escape($check['message']) ?></span>
        </section>
    <?php endforeach; ?>
    <p class="note">
        Si la connexion échoue, consultez <code>storage/logs/app.log</code> sur cet ordinateur.
        Le détail technique reste côté serveur.
    </p>
    <p><a href="./">Retour à l’accueil local</a></p>
</main>
</body>
</html>
