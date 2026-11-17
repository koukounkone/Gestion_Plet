<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\AuthService;
use MonElevage\Auth\GoogleAccountService;
use MonElevage\Auth\SmtpMailSender;
use MonElevage\Database\Connection;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$mailConfig = is_array($config['mail'] ?? null) ? $config['mail'] : [];
$mailer = new SmtpMailSender($mailConfig);
$auth = new AuthService($pdo, $config, $mailer);
$view = $_GET['view'] ?? 'login';
$allowedViews = ['login', 'register', 'forgot', 'reset', 'verify', 'google-complete'];
if (!is_string($view) || !in_array($view, $allowedViews, true)) {
    http_response_code(404);
    $view = 'login';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');

$errors = [];
$values = [
    'full_name' => '',
    'email' => '',
    'farm_name' => '',
];
if ($view === 'verify' && is_string($_SESSION['pending_registration_email'] ?? null)) {
    $values['email'] = $_SESSION['pending_registration_email'];
}
if ($view === 'google-complete' && !is_array($_SESSION['google_pending_profile'] ?? null)) {
    AuthSession::flash('error', 'La demande d’inscription Google a expiré. Recommencez.');
    header('Location: auth.php?view=register', true, 303);
    exit;
}
$consents = [
    'terms' => false,
    'privacy' => false,
    'farm_authorization' => false,
];
$flash = AuthSession::takeFlash();
$token = '';
$tokenValid = false;
if (in_array($view, ['reset', 'verify'], true)) {
    $candidate = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['token'] ?? '')
        : ($_GET['token'] ?? '');
    $token = is_string($candidate) ? $candidate : '';
    $tokenValid = $view === 'reset'
        ? $auth->isPasswordResetTokenValid($token)
        : $auth->isEmailVerificationTokenValid($token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors['form'] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $values['full_name'] = is_string($_POST['full_name'] ?? null)
            ? trim($_POST['full_name'])
            : '';
        $values['email'] = is_string($_POST['email'] ?? null)
            ? trim($_POST['email'])
            : '';
        $values['farm_name'] = is_string($_POST['farm_name'] ?? null)
            ? trim($_POST['farm_name'])
            : '';
        $consents['terms'] = ($_POST['accept_terms'] ?? null) === '1';
        $consents['privacy'] = ($_POST['accept_privacy'] ?? null) === '1';
        $consents['farm_authorization'] = ($_POST['confirm_farm_authorization'] ?? null) === '1';

        try {
            switch ($view) {
                case 'register':
                    $password = is_string($_POST['password'] ?? null)
                        ? $_POST['password']
                        : '';
                    $auth->register(
                        $values['full_name'],
                        $values['email'],
                        $password,
                        $values['farm_name'],
                        $consents['terms'],
                        $consents['privacy'],
                        $consents['farm_authorization'],
                        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                    );
                    $_SESSION['pending_registration_email'] = AuthService::normalizeEmail(
                        $values['email']
                    );
                    AuthSession::flash(
                        'success',
                        'Un code de confirmation a été envoyé. Il est valable une minute ; vérifiez aussi vos courriers indésirables.'
                    );
                    header('Location: auth.php?view=verify', true, 303);
                    exit;

                case 'login':
                    $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
                    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                    $userId = $auth->authenticate(
                        $email,
                        $password,
                        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                    );
                    AuthSession::login($userId);
                    header('Location: ./', true, 303);
                    exit;

                case 'google-complete':
                    $profile = $_SESSION['google_pending_profile'] ?? null;
                    if (!is_array($profile)) {
                        throw new RuntimeException('La demande d’inscription Google a expiré. Recommencez.');
                    }
                    $farmName = is_string($_POST['farm_name'] ?? null)
                        ? $_POST['farm_name']
                        : '';
                    $userId = (new GoogleAccountService($pdo, $config))->createAccount(
                        $profile,
                        $farmName,
                        $consents['terms'],
                        $consents['privacy'],
                        $consents['farm_authorization'],
                        $values['full_name']
                    );
                    unset($_SESSION['google_pending_profile']);
                    AuthSession::login($userId);
                    AuthSession::flash('success', 'Votre compte a été créé avec succès.');
                    header('Location: ./', true, 303);
                    exit;

                case 'forgot':
                    $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
                    if (filter_var(AuthService::normalizeEmail($email), FILTER_VALIDATE_EMAIL) === false) {
                        throw new RuntimeException('Saisissez une adresse e-mail valide.');
                    }
                    $auth->requestPasswordReset(
                        $email,
                        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                        'auth.php'
                    );
                    AuthSession::flash(
                        'success',
                        'Si un compte actif correspond à cette adresse, un lien de réinitialisation y sera envoyé.'
                    );
                    header('Location: auth.php?view=forgot', true, 303);
                    exit;

                case 'reset':
                    $newPassword = is_string($_POST['password'] ?? null)
                        ? $_POST['password']
                        : '';
                    $confirmPassword = is_string($_POST['password_confirmation'] ?? null)
                        ? $_POST['password_confirmation']
                        : '';
                    if (!hash_equals($newPassword, $confirmPassword)) {
                        throw new RuntimeException('Les deux mots de passe ne correspondent pas.');
                    }
                    $auth->resetPassword($token, $newPassword);
                    AuthSession::flash(
                        'success',
                        'Votre mot de passe a été modifié. Vous pouvez vous connecter.'
                    );
                    header('Location: auth.php?view=login', true, 303);
                    exit;

                case 'verify':
                    if ($token !== '') {
                        $auth->verifyEmail($token);
                        AuthSession::flash(
                            'success',
                            'Adresse vérifiée. Votre compte est activé ; vous pouvez vous connecter.'
                        );
                        header('Location: auth.php?view=login', true, 303);
                        exit;
                    }
                    $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
                    if (($_POST['verification_action'] ?? 'confirm') === 'resend') {
                        $auth->resendPendingRegistration(
                            $email,
                            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                        );
                        $_SESSION['pending_registration_email'] = AuthService::normalizeEmail($email);
                        AuthSession::flash(
                            'success',
                            'Si une inscription en attente correspond à cette adresse, un nouveau code a été envoyé.'
                        );
                        header('Location: auth.php?view=verify', true, 303);
                        exit;
                    }
                    $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
                    $userId = $auth->verifyPendingRegistration($email, $code);
                    unset($_SESSION['pending_registration_email']);
                    AuthSession::login($userId);
                    AuthSession::flash(
                        'success',
                        'Votre compte a été créé avec succès.'
                    );
                    header('Location: ./', true, 303);
                    exit;
            }
        } catch (RuntimeException $exception) {
            $errors['form'] = $view === 'login'
                ? 'Adresse e-mail ou mot de passe incorrect, ou trop de tentatives. Réessayez plus tard.'
                : $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($view, ['reset', 'verify'], true)) {
    $tokenValid = $view === 'reset'
        ? $auth->isPasswordResetTokenValid($token)
        : $auth->isEmailVerificationTokenValid($token);
}
if (($view === 'reset' || ($view === 'verify' && $token !== ''))
    && !$tokenValid && $errors === []) {
    $errors['form'] = $view === 'reset'
        ? 'Ce lien de réinitialisation est invalide ou expiré.'
        : 'Ce lien de vérification est invalide ou expiré.';
}

$csrfToken = AuthSession::csrfToken();
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'auth.php';
