<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\ConsentPolicy;

$documentKey = $_GET['document'] ?? '';
$documents = [
    'terms' => [
        'title' => 'Conditions d’utilisation',
        'version' => ConsentPolicy::TERMS_VERSION,
        'sections' => [
            [
                'title' => 'Objet du service',
                'paragraphs' => [
                    'Mon Élevage est un outil de suivi d’exploitations avicoles. Les fonctions disponibles dépendent de la version effectivement installée.',
                    'Le service est en phase de développement. Sa disponibilité, la sauvegarde des données et les fonctionnalités peuvent évoluer pendant cette phase.',
                ],
            ],
            [
                'title' => 'Compte et sécurité',
                'paragraphs' => [
                    'Vous devez fournir des informations exactes, garder vos identifiants confidentiels et nous signaler toute utilisation non autorisée dès qu’un canal de contact officiel aura été publié.',
                    'Un compte n’est activé qu’après vérification de son adresse e-mail. Les accès aux exploitations sont limités aux adhésions et rôles attribués.',
                ],
            ],
            [
                'title' => 'Utilisation et données saisies',
                'paragraphs' => [
                    'Vous restez responsable de l’exactitude et de la licéité des informations saisies, ainsi que des décisions prises à partir des résultats affichés.',
                    'Les budgets, statistiques et calculs présentés sont des outils d’aide au suivi. Ils ne remplacent pas une expertise comptable, vétérinaire, sanitaire ou juridique.',
                ],
            ],
            [
                'title' => 'Limites et évolution',
                'paragraphs' => [
                    'Aucun engagement de disponibilité continue ni de conservation permanente n’est établi pour cette version de pré-lancement. Gardez vos propres copies des données importantes.',
                    'Les modalités définitives de suspension, de résiliation, de responsabilité et de droit applicable restent à valider avant l’ouverture publique.',
                ],
            ],
        ],
    ],
    'privacy' => [
        'title' => 'Politique de confidentialité',
        'version' => ConsentPolicy::PRIVACY_VERSION,
        'sections' => [
            [
                'title' => 'Données utilisées',
                'paragraphs' => [
                    'Le service traite les informations de compte (nom, adresse e-mail et empreinte du mot de passe), les noms d’exploitation et, à mesure que les modules sont activés, les données de suivi et de gestion que vous saisissez.',
                    'Pour protéger les comptes, le service utilise des cookies de session, des jetons de formulaire et des empreintes de limitation des tentatives. Les jetons de vérification et de réinitialisation sont enregistrés sous forme d’empreinte et expirent.',
                ],
            ],
            [
                'title' => 'Finalités et destinataires',
                'paragraphs' => [
                    'Ces données servent à créer et sécuriser le compte, vérifier l’adresse, réinitialiser le mot de passe et fournir les fonctions de gestion demandées.',
                    'Les courriels nécessaires transitent par le service de messagerie configuré par l’exploitant de l’installation. L’hébergeur peut traiter les données pour faire fonctionner le serveur. Le service ne met pas en place de publicité ciblée ni de paiement en ligne dans cette version.',
                ],
            ],
            [
                'title' => 'Conservation et droits',
                'paragraphs' => [
                    'Les durées définitives de conservation, la procédure de suppression d’un compte, l’identité et les coordonnées du responsable du traitement, ainsi que les modalités d’exercice des droits doivent encore être renseignées et validées pour le pays d’exploitation.',
                    'Avant l’ouverture publique, le responsable devra compléter ces informations, préciser les éventuels sous-traitants et publier un canal de contact permettant les demandes d’accès, de rectification et de suppression.',
                ],
            ],
            [
                'title' => 'Version de pré-lancement',
                'paragraphs' => [
                    'Cette page décrit les traitements actuellement prévus par le projet, mais ne constitue pas un avis juridique ni une politique définitive. Elle doit être vérifiée et complétée au regard de la législation applicable avant toute ouverture publique.',
                ],
            ],
        ],
    ],
];

if (!is_string($documentKey) || !isset($documents[$documentKey])) {
    http_response_code(404);
    $documentKey = 'terms';
}
$document = $documents[$documentKey];
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($document['title']) ?> — Mon Élevage</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-body">
<header class="auth-topbar">
    <a class="brand auth-brand" href="auth.php?view=register" aria-label="Retour à la création de compte">
        <span class="brand-mark" aria-hidden="true">M</span>
        <span class="brand-name">Mon Élevage<small>GESTION AVICOLE</small></span>
    </a>
    <a class="auth-top-link" href="auth.php?view=register">Créer un compte</a>
</header>
<main class="legal-main">
    <article class="legal-card">
        <p class="auth-eyebrow">DOCUMENT <?= $documentKey === 'terms' ? 'CONTRACTUEL' : 'D’INFORMATION' ?></p>
        <h1><?= $escape($document['title']) ?></h1>
        <p>Version <?= $escape($document['version']) ?> · Projet « Mon Élevage »</p>
        <aside class="legal-draft" role="note">
            <strong>Document provisoire — pré-lancement.</strong>
            L’identité du responsable, ses coordonnées, le pays et certaines modalités
            (notamment les durées de conservation) restent à compléter et à faire valider
            avant toute ouverture publique. Ces pages ne constituent pas un avis juridique.
        </aside>
        <?php foreach ($document['sections'] as $section): ?>
            <section>
                <h2><?= $escape($section['title']) ?></h2>
                <?php foreach ($section['paragraphs'] as $paragraph): ?>
                    <p><?= $escape($paragraph) ?></p>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <nav class="legal-nav" aria-label="Documents et inscription">
            <a href="legal.php?document=terms">Conditions d’utilisation</a>
            <a href="legal.php?document=privacy">Politique de confidentialité</a>
            <a href="auth.php?view=register">Retour à l’inscription</a>
        </nav>
    </article>
</main>
</body>
</html>
