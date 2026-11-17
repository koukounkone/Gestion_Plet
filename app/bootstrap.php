<?php
declare(strict_types=1);

use MonElevage\Support\HealthCheck;

define('MON_ELEVAGE_ROOT', dirname(__DIR__));

$composerAutoloadPath = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'vendor'
    . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($composerAutoloadPath)) {
    require_once $composerAutoloadPath;
}

$localConfigPath = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'local.php';

if (!is_file($localConfigPath)) {
    throw new RuntimeException(
        'Configuration locale absente. Copiez config/local.php.example vers config/local.php.'
    );
}

$config = require $localConfigPath;
if (!is_array($config)) {
    throw new RuntimeException('La configuration locale doit retourner un tableau PHP.');
}

if (isset($config['mail']) && !is_array($config['mail'])) {
    throw new RuntimeException('La configuration mail doit être un tableau.');
}
$config['mail'] = $config['mail'] ?? [];
$mailTransport = getenv('MON_ELEVAGE_MAIL_TRANSPORT');
$config['mail']['transport'] = is_string($mailTransport) && $mailTransport !== ''
    ? $mailTransport
    : ($config['mail']['transport'] ?? 'smtp');
$mailEnvironment = [
    'MON_ELEVAGE_MAIL_FROM' => ['from'],
    'MON_ELEVAGE_MAIL_FROM_NAME' => ['from_name'],
    'MON_ELEVAGE_SMTP_HOST' => ['smtp', 'host'],
    'MON_ELEVAGE_SMTP_PORT' => ['smtp', 'port'],
    'MON_ELEVAGE_SMTP_USERNAME' => ['smtp', 'username'],
    'MON_ELEVAGE_SMTP_PASSWORD' => ['smtp', 'password'],
    'MON_ELEVAGE_SMTP_ENCRYPTION' => ['smtp', 'encryption'],
];
foreach ($mailEnvironment as $variable => $path) {
    $value = getenv($variable);
    if (!is_string($value) || $value === '') {
        continue;
    }

    if (count($path) === 1) {
        $config['mail'][$path[0]] = $value;
        continue;
    }

    $config['mail'][$path[0]] = $config['mail'][$path[0]] ?? [];
    if (!is_array($config['mail'][$path[0]])) {
        throw new RuntimeException('La configuration SMTP doit être un tableau.');
    }
    $config['mail'][$path[0]][$path[1]] = $value;
}
$config['google_oauth'] = is_array($config['google_oauth'] ?? null)
    ? $config['google_oauth']
    : [];
foreach ([
    'MON_ELEVAGE_GOOGLE_CLIENT_ID' => 'client_id',
    'MON_ELEVAGE_GOOGLE_CLIENT_SECRET' => 'client_secret',
    'MON_ELEVAGE_GOOGLE_REDIRECT_URI' => 'redirect_uri',
] as $variable => $key) {
    $value = getenv($variable);
    if (is_string($value) && $value !== '') {
        $config['google_oauth'][$key] = $value;
    }
}

$environment = $config['app_env'] ?? null;
if (!is_string($environment)
    || !in_array($environment, ['development', 'staging', 'production'], true)) {
    throw new RuntimeException(
        'La configuration app_env doit être development, staging ou production.'
    );
}
$logDirectory = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'logs';

if (!is_dir($logDirectory) && !mkdir($logDirectory, 0770, true) && !is_dir($logDirectory)) {
    throw new RuntimeException('Impossible de préparer le journal local.');
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $logDirectory . DIRECTORY_SEPARATOR . 'app.log');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $exception) use ($environment): void {
    error_log(sprintf(
        'Unhandled exception: %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        $message = $environment === 'development'
            ? 'Erreur interne. Consultez le journal local pour le diagnostic.'
            : 'Une erreur est survenue. Veuillez réessayer ultérieurement.';
        echo '<!doctype html><html lang="fr"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Erreur — Mon Élevage</title><body><main><h1>Service indisponible</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p></main></body></html>';
    } elseif (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Erreur interne. Consultez storage/logs/app.log.\n");
        exit(1);
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'MonElevage\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'app'
        . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
        . '.php';

    if (is_file($file)) {
        require $file;
    }
});

if (PHP_SAPI === 'cli') {
    return;
}

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if ($environment === 'development'
    && !in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
