<?php
declare(strict_types=1);

use MonElevage\Support\HealthCheck;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$configPath = $root . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'local.php';
$schemaPath = $root . DIRECTORY_SEPARATOR . 'database'
    . DIRECTORY_SEPARATOR . 'schema.sql';

if (!is_file($configPath) || !is_file($schemaPath)) {
    fwrite(STDERR, "Configuration locale ou schéma SQL introuvable.\n");
    exit(1);
}

$config = require $configPath;
$placeholder = 'CHANGE_THIS_LOCAL_PASSWORD';
if (!is_array($config) || ($config['db']['password'] ?? null) !== $placeholder) {
    fwrite(STDERR, "Refus : config/local.php a déjà été personnalisé.\n");
    fwrite(STDERR, "Ce script ne remplace jamais une configuration locale existante.\n");
    exit(1);
}

$dbConfig = $config['db'];
$databaseName = 'mon_elevage_dev';
$applicationUser = 'mon_elevage_app';

if (($dbConfig['database'] ?? null) !== $databaseName
    || ($dbConfig['username'] ?? null) !== $applicationUser) {
    fwrite(STDERR, "Les paramètres locaux ne correspondent pas à la base de développement attendue.\n");
    exit(1);
}

$adminUser = getenv('MON_ELEVAGE_MYSQL_ADMIN_USER');
if ($adminUser === false || $adminUser === '') {
    $adminUser = 'root';
}
$adminPassword = getenv('MON_ELEVAGE_MYSQL_ADMIN_PASSWORD');
if ($adminPassword === false) {
    $adminPassword = '';
}

try {
    $admin = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $dbConfig['host'],
            (int) $dbConfig['port']
        ),
        $adminUser,
        $adminPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    fwrite(STDERR, "Connexion administrateur MySQL impossible. Vérifiez le service et les identifiants locaux.\n");
    error_log(sprintf('Local DB initialization admin connection failed: %s', $exception->getMessage()));
    exit(1);
}

$databaseCheck = $admin->prepare(
    'SELECT COUNT(*)
     FROM information_schema.SCHEMATA
     WHERE SCHEMA_NAME = :database_name'
);
$databaseCheck->execute(['database_name' => $databaseName]);
if ((int) $databaseCheck->fetchColumn() !== 0) {
    fwrite(STDERR, "Refus : la base mon_elevage_dev existe déjà ; aucune donnée n’a été modifiée.\n");
    exit(1);
}

$userCheck = $admin->prepare(
    'SELECT COUNT(*)
     FROM mysql.user
     WHERE User = :user_name
       AND Host = :user_host'
);
$userCheck->execute([
    'user_name' => $applicationUser,
    'user_host' => $dbConfig['host'],
]);
if ((int) $userCheck->fetchColumn() !== 0) {
    fwrite(STDERR, "Refus : l’utilisateur MySQL mon_elevage_app existe déjà ; aucune donnée n’a été modifiée.\n");
    exit(1);
}

$secret = bin2hex(random_bytes(32));
$quotedSecret = $admin->quote($secret);
$admin->exec(
    'CREATE DATABASE mon_elevage_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
$admin->exec(
    "CREATE USER 'mon_elevage_app'@'127.0.0.1' IDENTIFIED BY $quotedSecret"
);
$admin->exec(
    "GRANT ALL PRIVILEGES ON mon_elevage_dev.* TO 'mon_elevage_app'@'127.0.0.1'"
);

$config['db']['password'] = $secret;
$localConfig = "<?php\n"
    . "declare(strict_types=1);\n\n"
    . 'return ' . var_export($config, true) . ";\n";
if (file_put_contents($configPath, $localConfig, LOCK_EX) !== strlen($localConfig)) {
    fwrite(STDERR, "La base et l’utilisateur ont été créés, mais config/local.php n’a pas pu être écrit.\n");
    fwrite(STDERR, "Ne relancez pas le script ; corrigez les droits du fichier et demandez de l’aide.\n");
    exit(1);
}

$clientConfigPath = tempnam(sys_get_temp_dir(), 'mon-elevage-mysql-');
if ($clientConfigPath === false) {
    fwrite(STDERR, "La configuration temporaire du client MySQL n’a pas pu être créée.\n");
    exit(1);
}

try {
    $clientConfig = "[client]\r\n"
        . "user=$applicationUser\r\n"
        . "password=$secret\r\n"
        . "host=127.0.0.1\r\n"
        . "port=" . (int) $dbConfig['port'] . "\r\n"
        . "protocol=tcp\r\n";
    if (file_put_contents($clientConfigPath, $clientConfig, LOCK_EX) !== strlen($clientConfig)) {
        throw new RuntimeException('Écriture du fichier temporaire MySQL impossible.');
    }

    $mysqlClient = getenv('MON_ELEVAGE_MYSQL_CLIENT');
    if ($mysqlClient === false || $mysqlClient === '') {
        $mysqlClient = 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe';
    }
    if (!is_file($mysqlClient)) {
        throw new RuntimeException('Client MySQL WAMP introuvable.');
    }

    $command = [
        $mysqlClient,
        '--defaults-extra-file=' . $clientConfigPath,
        '--database=' . $databaseName,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Le client MySQL n’a pas pu être lancé.');
    }

    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Lecture du schéma SQL impossible.');
    }

    $trainingSeedPath = $root . DIRECTORY_SEPARATOR . 'database'
        . DIRECTORY_SEPARATOR . 'migrations'
        . DIRECTORY_SEPARATOR . '007_create_training_guide.sql';
    $trainingSeed = file_get_contents($trainingSeedPath);
    if ($trainingSeed === false) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Lecture des données initiales de formation impossible.');
    }

    fwrite($pipes[0], $schema . PHP_EOL . $trainingSeed);
    fclose($pipes[0]);
    $clientOutput = stream_get_contents($pipes[1]);
    $clientErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $clientExitCode = proc_close($process);

    if ($clientExitCode !== 0) {
        error_log(sprintf(
            'Local schema and seed import failed with exit code %d: %s%s',
            $clientExitCode,
            $clientOutput,
            $clientErrors
        ));
        throw new RuntimeException(
            'L’import du schéma ou des données initiales a échoué. Consultez le journal PHP local ; la base peut contenir des tables partielles.'
        );
    }
} catch (Throwable $exception) {
    error_log(sprintf(
        'Local DB initialization failed: %s: %s',
        $exception::class,
        $exception->getMessage()
    ));
    fwrite(STDERR, $exception->getMessage() . "\n");
    fwrite(STDERR, "Aucun effacement automatique n’est effectué ; inspectez mon_elevage_dev avant toute reprise.\n");
    exit(1);
} finally {
    if (is_file($clientConfigPath)) {
        unlink($clientConfigPath);
    }
}

require $root . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';
$result = HealthCheck::run($config);
foreach ($result['checks'] as $check) {
    printf(
        "[%s] %s — %s\n",
        $check['ok'] ? 'OK' : 'ÉCHEC',
        $check['label'],
        $check['message']
    );
}

if (!$result['ok']) {
    fwrite(STDERR, "La base a été créée, mais les contrôles ne sont pas tous satisfaits.\n");
    exit(1);
}

fwrite(STDOUT, "Initialisation locale réussie. Aucun compte utilisateur de l’application n’a été créé.\n");
