<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use RuntimeException;

final class GoogleOidcClient
{
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const CERTIFICATES_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';

    public function __construct(private readonly array $config)
    {
    }

    public function isConfigured(): bool
    {
        return is_string($this->config['client_id'] ?? null)
            && trim($this->config['client_id']) !== ''
            && is_string($this->config['client_secret'] ?? null)
            && trim($this->config['client_secret']) !== ''
            && is_string($this->config['redirect_uri'] ?? null)
            && self::isAllowedRedirectUri($this->config['redirect_uri'])
            && function_exists('curl_init')
            && function_exists('openssl_verify');
    }

    public function authorizationUrl(
        string $state,
        string $nonce,
        string $codeChallenge
    ): string {
        $this->requireConfiguration();
        return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $nonce): array
    {
        $this->requireConfiguration();
        if ($code === '' || strlen($code) > 4096) {
            throw new RuntimeException('La réponse de Google est incomplète. Réessayez.');
        }

        $tokens = self::httpsJsonRequest(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ]);
        if (!is_string($tokens['id_token'] ?? null)) {
            throw new RuntimeException('Google n’a pas retourné de jeton d’identité valide.');
        }

        $certificates = self::httpsJsonRequest(self::CERTIFICATES_ENDPOINT);
        if (!is_array($certificates['keys'] ?? null)) {
            throw new RuntimeException('Impossible de vérifier l’identité retournée par Google.');
        }

        return self::verifyIdToken(
            $tokens['id_token'],
            $certificates['keys'],
            $nonce,
            (string) $this->config['client_id']
        );
    }

    public static function verifyIdToken(
        string $idToken,
        array $keys,
        string $expectedNonce,
        string $clientId,
        ?int $now = null
    ): array {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Le jeton d’identité Google est invalide.');
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = self::decodeJsonSegment($encodedHeader);
        $claims = self::decodeJsonSegment($encodedClaims);
        if (($header['alg'] ?? null) !== 'RS256'
            || !is_string($header['kid'] ?? null)
            || $header['kid'] === '') {
            throw new RuntimeException('L’algorithme du jeton Google n’est pas accepté.');
        }

        $key = null;
        foreach ($keys as $candidate) {
            if (is_array($candidate)
                && ($candidate['kid'] ?? null) === $header['kid']
                && ($candidate['kty'] ?? null) === 'RSA'
                && ($candidate['alg'] ?? 'RS256') === 'RS256'
                && ($candidate['use'] ?? 'sig') === 'sig') {
                $key = $candidate;
                break;
            }
        }
        if (!is_array($key)) {
            throw new RuntimeException('La clé de signature de Google est inconnue.');
        }

        $signature = self::base64UrlDecode($encodedSignature);
        $publicKey = openssl_pkey_get_public(self::rsaJwkToPem($key));
        if ($publicKey === false
            || openssl_verify(
                $encodedHeader . '.' . $encodedClaims,
                $signature,
                $publicKey,
                OPENSSL_ALGO_SHA256
            ) !== 1) {
            throw new RuntimeException('La signature de l’identité Google est invalide.');
        }

        $now ??= time();
        $issuer = $claims['iss'] ?? null;
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : $audience;
        $issuedAt = filter_var($claims['iat'] ?? null, FILTER_VALIDATE_INT);
        $expiresAt = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
            || !is_array($audiences)
            || !in_array($clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || !is_int($issuedAt)
            || $issuedAt > $now + 60
            || !is_int($expiresAt)
            || $expiresAt <= $now
            || !is_string($claims['nonce'] ?? null)
            || $expectedNonce === ''
            || !hash_equals($expectedNonce, $claims['nonce'])
            || !is_string($claims['sub'] ?? null)
            || $claims['sub'] === ''
            || strlen($claims['sub']) > 255
            || !is_string($claims['email'] ?? null)
            || filter_var($claims['email'], FILTER_VALIDATE_EMAIL) === false
            || ($claims['email_verified'] ?? false) !== true) {
            throw new RuntimeException('L’identité Google n’a pas pu être confirmée. Réessayez.');
        }

        $name = $claims['name'] ?? '';
        return [
            'subject' => $claims['sub'],
            'email' => AuthService::normalizeEmail($claims['email']),
            'email_verified' => true,
            'name' => is_string($name) && trim($name) !== ''
                ? trim($name)
                : '',
        ];
    }

    private function requireConfiguration(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'La connexion Google n’est pas encore configurée pour ce site.'
            );
        }
        if (!function_exists('curl_init') || !function_exists('openssl_verify')) {
            throw new RuntimeException('Les extensions PHP cURL et OpenSSL sont nécessaires pour Google.');
        }
    }

    private static function isAllowedRedirectUri(string $redirectUri): bool
    {
        $parts = parse_url($redirectUri);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        return $scheme === 'https'
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
    }

    private static function httpsJsonRequest(string $url, ?array $form = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('L’extension PHP cURL est nécessaire pour la connexion Google.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Impossible de démarrer la vérification auprès de Google.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($form !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($response) || $status < 200 || $status >= 300) {
            error_log('Google OIDC request failed with HTTP status ' . $status . '.');
            throw new RuntimeException(
                $error !== ''
                    ? 'La vérification Google est temporairement indisponible. Réessayez.'
                    : 'Google n’a pas accepté la demande d’authentification.'
            );
        }
        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('La réponse de Google est illisible. Réessayez.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('La réponse de Google est illisible. Réessayez.');
        }
        return $decoded;
    }

    private static function decodeJsonSegment(string $segment): array
    {
        try {
            $decoded = json_decode(self::base64UrlDecode($segment), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Le jeton d’identité Google est illisible.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Le jeton d’identité Google est illisible.');
        }
        return $decoded;
    }

    private static function rsaJwkToPem(array $key): string
    {
        if (!is_string($key['n'] ?? null) || !is_string($key['e'] ?? null)) {
            throw new RuntimeException('La clé publique de Google est invalide.');
        }
        $rsaPublicKey = self::derSequence(
            self::derInteger(self::base64UrlDecode($key['n']))
            . self::derInteger(self::base64UrlDecode($key['e']))
        );
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if (!is_string($algorithm)) {
            throw new RuntimeException('Impossible de préparer la vérification de signature.');
        }
        $subjectPublicKeyInfo = self::derSequence(
            $algorithm . "\x03" . self::derLength(strlen($rsaPublicKey) + 1)
            . "\x00" . $rsaPublicKey
        );
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        } elseif ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . self::derLength(strlen($value)) . $value;
    }

    private static function derSequence(string $value): string
    {
        return "\x30" . self::derLength(strlen($value)) . $value;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4),
            true
        );
        if (!is_string($decoded)) {
            throw new RuntimeException('Donnée encodée invalide dans le jeton Google.');
        }
        return $decoded;
    }
}
