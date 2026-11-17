<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Support\AuthSession;

AuthSession::start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Méthode non autorisée.');
}

if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Le formulaire a expiré. Rechargez la page.');
}

AuthSession::logout();
header('Location: auth.php?view=login', true, 303);
