<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\GoogleAccountService;
use MonElevage\Auth\GoogleOidcClient;
use MonElevage\Database\Connection;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Échec du test Google : ' . $message);
    }
    $passed++;
};
$expectFailure = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (RuntimeException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$base64Url = static fn (string $value): string => rtrim(
    strtr(base64_encode($value), '+/', '-_'),
    '='
);
$makeToken = static function (
    array $claims,
    OpenSSLAsymmetricKey $privateKey,
    string $algorithm = 'RS256'
) use ($base64Url): string {
    $header = $base64Url(json_encode(
        ['alg' => $algorithm, 'kid' => 'test-key', 'typ' => 'JWT'],
        JSON_THROW_ON_ERROR
    ));
    $payload = $base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
    openssl_sign($header . '.' . $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return $header . '.' . $payload . '.' . $base64Url($signature);
};
$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(6));
$testUserIds = [];
$testFarmIds = [];
$opensslConfig = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras'
    . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
$keyOptions = [
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
];
if (is_file($opensslConfig)) {
    $keyOptions['config'] = $opensslConfig;
}
$key = openssl_pkey_new($keyOptions);
if (!$key instanceof OpenSSLAsymmetricKey) {
    throw new RuntimeException('Impossible de générer la clé de test OAuth.');
}
$keyDetails = openssl_pkey_get_details($key);
if (!is_array($keyDetails) || !is_array($keyDetails['rsa'] ?? null)) {
    throw new RuntimeException('Impossible de lire la clé RSA de test.');
}
$keys = [[
    'kty' => 'RSA',
    'use' => 'sig',
    'alg' => 'RS256',
    'kid' => 'test-key',
    'n' => $base64Url($keyDetails['rsa']['n']),
    'e' => $base64Url($keyDetails['rsa']['e']),
]];
$now = time();
$claims = [
    'iss' => 'https://accounts.google.com',
    'aud' => 'test-client-id',
    'sub' => 'google-sub-' . $suffix,
    'email' => 'Google-Test-' . $suffix . '@example.test',
    'email_verified' => true,
    'name' => 'Utilisateur Google',
    'iat' => $now,
    'exp' => $now + 3600,
    'nonce' => 'test-nonce',
];

try {
    $verified = GoogleOidcClient::verifyIdToken(
        $makeToken($claims, $key),
        $keys,
        'test-nonce',
        'test-client-id',
        $now
    );
    $assert($verified['email'] === strtolower($claims['email']), 'normalise l’adresse Google vérifiée');
    $assert($verified['subject'] === $claims['sub'], 'conserve le sujet Google signé');
    $assert($verified['email_verified'] === true, 'exige une adresse confirmée par Google');
    $missingName = GoogleOidcClient::verifyIdToken(
        $makeToken(array_diff_key($claims, ['name' => true]), $key),
        $keys,
        'test-nonce',
        'test-client-id',
        $now
    );
    $assert($missingName['name'] === '', 'signale un nom absent au lieu de fabriquer un nom depuis l’e-mail');

    $expectFailure(
        static fn () => GoogleOidcClient::verifyIdToken(
            $makeToken(array_merge($claims, ['aud' => 'another-client']), $key),
            $keys,
            'test-nonce',
            'test-client-id',
            $now
        ),
        'refuse un jeton destiné à un autre client'
    );
    $expectFailure(
        static fn () => GoogleOidcClient::verifyIdToken(
            $makeToken(array_merge($claims, ['nonce' => 'different']), $key),
            $keys,
            'test-nonce',
            'test-client-id',
            $now
        ),
        'refuse un nonce différent'
    );
    $expectFailure(
        static fn () => GoogleOidcClient::verifyIdToken(
            $makeToken(array_merge($claims, ['exp' => $now - 1]), $key),
            $keys,
            'test-nonce',
            'test-client-id',
            $now
        ),
        'refuse un jeton expiré'
    );
    $expectFailure(
        static fn () => GoogleOidcClient::verifyIdToken(
            $makeToken(array_merge($claims, ['email_verified' => false]), $key),
            $keys,
            'test-nonce',
            'test-client-id',
            $now
        ),
        'refuse une adresse non vérifiée'
    );
    $expectFailure(
        static fn () => GoogleOidcClient::verifyIdToken(
            $makeToken($claims, $key, 'none'),
            $keys,
            'test-nonce',
            'test-client-id',
            $now
        ),
        'refuse un algorithme non autorisé'
    );
    $expectFailure(
        static fn () => GoogleOidcClient::verifyIdToken(
            $makeToken($claims, $key),
            [],
            'test-nonce',
            'test-client-id',
            $now
        ),
        'refuse une clé de signature inconnue'
    );
    $tamperedToken = $makeToken($claims, $key);
    $tamperedParts = explode('.', $tamperedToken);
    $tamperedParts[2] = $base64Url(random_bytes(256));
    $expectFailure(
        static fn () => GoogleOidcClient::verifyIdToken(
            implode('.', $tamperedParts),
            $keys,
            'test-nonce',
            'test-client-id',
            $now
        ),
        'refuse une signature falsifiée'
    );

    $client = new GoogleOidcClient([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'https://example.test/google-callback.php',
    ]);
    $assert($client->isConfigured(), 'accepte une configuration HTTPS complète');
    $authorizationUrl = $client->authorizationUrl('state-test', 'nonce-test', 'challenge-test');
    $assert(str_contains($authorizationUrl, 'code_challenge_method=S256'), 'active PKCE S256');
    $assert(str_contains($authorizationUrl, 'scope=openid%20email%20profile'), 'demande les scopes OpenID minimaux');

    $insecureClient = new GoogleOidcClient([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://example.test/google-callback.php',
    ]);
    $assert(!$insecureClient->isConfigured(), 'refuse une redirection HTTP hors localhost');
    $missingClient = new GoogleOidcClient([]);
    $assert(!$missingClient->isConfigured(), 'signale les identifiants absents');
    $expectFailure(
        static fn () => $missingClient->authorizationUrl('state', 'nonce', 'challenge'),
        'ne démarre pas OAuth sans configuration'
    );

    $accounts = new GoogleAccountService($pdo, $config);
    $googleProfile = [
        'subject' => 'google-account-' . $suffix,
        'email' => 'google-account-' . $suffix . '@example.test',
        'email_verified' => true,
        'name' => 'Compte Google test',
    ];
    $expectFailure(
        static fn () => $accounts->createAccount($googleProfile, 'Exploitation test', false, true, true),
        'ne crée pas de compte sans les consentements obligatoires'
    );
    $assert(
        $accounts->findAccountByEmail($googleProfile['email']) === null,
        'ne laisse aucun compte après un consentement incomplet'
    );

    $googleUserId = $accounts->createAccount(
        $googleProfile,
        'Exploitation Google ' . $suffix,
        true,
        true,
        true,
        'Nom différent soumis par le formulaire'
    );
    $testUserIds[] = $googleUserId;
    $storedName = $pdo->prepare('SELECT full_name FROM users WHERE id = :user_id');
    $storedName->execute(['user_id' => $googleUserId]);
    $assert(
        $storedName->fetchColumn() === $googleProfile['name'],
        'préserve le nom fourni par Google sans accepter son remplacement'
    );
    $assert(
        $accounts->findLinkedAccount($googleProfile['subject'])['user_id'] === $googleUserId,
        'crée l’identité Google dans la même inscription'
    );
    $assert($accounts->hasGoogleIdentity($googleUserId), 'retrouve l’identité liée au compte');
    $consent = $pdo->prepare('SELECT COUNT(*) FROM user_consents WHERE user_id = :user_id');
    $consent->execute(['user_id' => $googleUserId]);
    $assert((int) $consent->fetchColumn() === 1, 'enregistre le consentement obligatoire');
    $farm = $pdo->prepare('SELECT id FROM farms WHERE owner_user_id = :user_id');
    $farm->execute(['user_id' => $googleUserId]);
    $farmId = (int) $farm->fetchColumn();
    $testFarmIds[] = $farmId;
    $membership = $pdo->prepare(
        "SELECT COUNT(*) FROM farm_members
         WHERE farm_id = :farm_id AND user_id = :user_id AND role = 'owner' AND status = 'active'"
    );
    $membership->execute(['farm_id' => $farmId, 'user_id' => $googleUserId]);
    $assert((int) $membership->fetchColumn() === 1, 'crée l’exploitation avec une adhésion propriétaire active');

    $missingNameProfile = array_merge($googleProfile, [
        'subject' => 'google-missing-name-' . $suffix,
        'email' => 'google-missing-name-' . $suffix . '@example.test',
        'name' => '',
    ]);
    $missingNameUserId = $accounts->createAccount(
        $missingNameProfile,
        'Exploitation sans nom Google ' . $suffix,
        true,
        true,
        true,
        'Nom fourni lors de la finalisation'
    );
    $testUserIds[] = $missingNameUserId;
    $storedName->execute(['user_id' => $missingNameUserId]);
    $assert(
        $storedName->fetchColumn() === 'Nom fourni lors de la finalisation',
        'accepte le nom saisi uniquement lorsque Google ne l’a pas fourni'
    );

    $longNameProfile = array_merge($googleProfile, [
        'subject' => 'google-long-name-' . $suffix,
        'email' => 'google-long-name-' . $suffix . '@example.test',
        'name' => str_repeat('N', 121),
    ]);
    $longNameUserId = $accounts->createAccount(
        $longNameProfile,
        'Exploitation Google ' . $suffix,
        true,
        true,
        true,
        'Nom utilisable'
    );
    $testUserIds[] = $longNameUserId;
    $storedName->execute(['user_id' => $longNameUserId]);
    $assert(
        $storedName->fetchColumn() === 'Nom utilisable',
        'demande un nom utilisable lorsque le nom Google dépasse la limite du compte'
    );

    $expectFailure(
        static fn () => $accounts->createAccount(
            array_merge($googleProfile, ['subject' => 'another-subject-' . $suffix]),
            'Doublon',
            true,
            true,
            true
        ),
        'refuse un compte doublon basé sur la même adresse'
    );

    $classicEmail = 'classic-' . $suffix . '@example.test';
    $classicUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES ('Compte classique test', :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    $classicUser->execute(['email' => $classicEmail]);
    $classicUserId = (int) $pdo->lastInsertId();
    $testUserIds[] = $classicUserId;
    $classicProfile = [
        'subject' => 'google-classic-' . $suffix,
        'email' => $classicEmail,
        'email_verified' => true,
        'name' => 'Compte classique test',
    ];
    $accounts->linkIdentity($classicUserId, $classicProfile);
    $accounts->linkIdentity($classicUserId, $classicProfile);
    $assert($accounts->hasGoogleIdentity($classicUserId), 'lie explicitement une identité au compte connecté');
    $linkedClassic = $accounts->findLinkedAccount($classicProfile['subject']);
    $assert(
        is_array($linkedClassic) && $linkedClassic['user_id'] === $classicUserId,
        'ne lie l’identité qu’au compte qui a lancé la liaison'
    );
    $expectFailure(
        static fn () => $accounts->linkIdentity(
            $classicUserId,
            array_merge($classicProfile, ['email' => 'different-' . $suffix . '@example.test'])
        ),
        'refuse une liaison avec une adresse Google différente'
    );
    $expectFailure(
        static fn () => $accounts->linkIdentity(
            $classicUserId,
            array_merge($classicProfile, ['email_verified' => false])
        ),
        'refuse la liaison d’une adresse Google non vérifiée'
    );

    echo 'OK — ' . $passed . ' assertions OIDC et comptes Google.' . PHP_EOL;
} finally {
    if ($testUserIds !== []) {
        $userPlaceholders = implode(',', array_fill(0, count($testUserIds), '?'));
        $ownedFarms = $pdo->prepare(
            'SELECT id FROM farms WHERE owner_user_id IN (' . $userPlaceholders . ')'
        );
        $ownedFarms->execute($testUserIds);
        $cleanupFarmIds = array_values(array_unique(array_merge(
            $testFarmIds,
            array_map('intval', $ownedFarms->fetchAll(PDO::FETCH_COLUMN))
        )));
        if ($cleanupFarmIds !== []) {
            $farmPlaceholders = implode(',', array_fill(0, count($cleanupFarmIds), '?'));
            $deleteFarmAudit = $pdo->prepare(
                'DELETE FROM audit_logs WHERE farm_id IN (' . $farmPlaceholders . ')'
            );
            $deleteFarmAudit->execute($cleanupFarmIds);
            $deleteMembers = $pdo->prepare(
                'DELETE FROM farm_members WHERE farm_id IN (' . $farmPlaceholders . ')'
            );
            $deleteMembers->execute($cleanupFarmIds);
            $deleteFarms = $pdo->prepare(
                'DELETE FROM farms WHERE id IN (' . $farmPlaceholders . ')'
            );
            $deleteFarms->execute($cleanupFarmIds);
        }
        $deleteActorAudit = $pdo->prepare(
            'DELETE FROM audit_logs WHERE actor_user_id IN (' . $userPlaceholders . ')'
        );
        $deleteActorAudit->execute($testUserIds);
        $deleteUsers = $pdo->prepare(
            'DELETE FROM users WHERE id IN (' . $userPlaceholders . ')'
        );
        $deleteUsers->execute($testUserIds);
    }
}
