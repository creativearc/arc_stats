<?php

namespace CreativeArc\ArcStats\Service\Google;

/**
 * Google API client authenticated as a SERVICE ACCOUNT (JWT bearer grant).
 *
 * Deliberately not OAuth: a per-install OAuth client means every site owner
 * creates a Cloud project, consent screen and redirect URI, and the refresh
 * token dies when the person who clicked "Connect" leaves or revokes access.
 * A service account is a key file plus one "add user" in GA4 / Search Console.
 *
 * One token covers both scopes, so GA4 and GSC share a single credential.
 */
class GoogleClient
{
    const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/webmasters.readonly',
    ];

    const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private array $account;

    public function __construct(array $serviceAccount)
    {
        if (empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
            throw new \InvalidArgumentException('Service account key is missing client_email or private_key.');
        }

        $this->account = $serviceAccount;
    }

    public function email(): string
    {
        return $this->account['client_email'];
    }

    public function postJson(string $url, array $body): array
    {
        return $this->request($url, json_encode($body), [
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/json',
        ]);
    }

    public function getJson(string $url): array
    {
        return $this->request($url, null, ['Authorization: Bearer ' . $this->accessToken()]);
    }

    private function accessToken(): string
    {
        $cacheKey = '/arc_stats/google_token/' . md5($this->account['client_email'] . implode(' ', self::SCOPES));
        $cached   = ee()->cache->get($cacheKey, \Cache::GLOBAL_SCOPE);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $this->account['client_email'],
            'scope' => implode(' ', self::SCOPES),
            'aud'   => self::TOKEN_URI,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $input = self::b64url(json_encode($header)) . '.' . self::b64url(json_encode($claims));

        $key = openssl_pkey_get_private($this->account['private_key']);
        if ($key === false || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign with the service account private key. Is the JSON key complete?');
        }

        $data = $this->request(self::TOKEN_URI, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $input . '.' . self::b64url($signature),
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Google token exchange returned no access_token.');
        }

        // Refresh a couple of minutes early so a request never carries a token
        // that expires in flight.
        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 120);
        ee()->cache->save($cacheKey, $data['access_token'], $ttl, \Cache::GLOBAL_SCOPE);

        return $data['access_token'];
    }

    /**
     * POST when $body is a string, GET when null.
     */
    private function request(string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException('cURL error: ' . $curlErr);
        }

        $data = json_decode((string) $response, true);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Google ' . $httpCode . ': ' . $this->errorMessage($data, (string) $response));
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Pull the human-readable part out of Google's error envelope, and add the
     * fix for the one error every new setup hits.
     */
    private function errorMessage($data, string $raw): string
    {
        $message = $data['error']['message']
            ?? $data['error_description']
            ?? (is_string($data['error'] ?? null) ? $data['error'] : null)
            ?? substr($raw, 0, 300);

        $status = $data['error']['status'] ?? '';

        // "API has not been used in project N before or it is disabled" is also
        // a PERMISSION_DENIED, but the fix is in Cloud Console, not on the property.
        if (stripos($message, 'has not been used') !== false || stripos($message, 'is disabled') !== false) {
            return $message . ' — enable that API in the service account\'s Cloud project.';
        }

        if ($status === 'PERMISSION_DENIED' || stripos($message, 'permission') !== false) {
            $message .= ' — add ' . $this->account['client_email'] . ' as a user (Viewer / Restricted) on the property.';
        }

        return $message;
    }

    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
