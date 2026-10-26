<?php

namespace App\Auth;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use OpenSSLAsymmetricKey;
use Throwable;

final class AmpTokenVerifier
{
    public const string ISSUER = 'https://ampcode.com/api/workload-identity';

    private const string ALGORITHM = 'RS256';

    private const int JWKS_REFRESH_COOLDOWN_SECONDS = 10;

    public function verify(#[\SensitiveParameter] string $token): AmpWorkloadIdentity
    {
        try {
            [$encodedHeader, $encodedPayload, $encodedSignature] = $this->segments($token);
            $header = $this->jsonObject($this->decode($encodedHeader, 'header_encoding'), 'header_json');
            $claims = $this->jsonObject($this->decode($encodedPayload, 'payload_encoding'), 'payload_json');

            if (($header['alg'] ?? null) !== self::ALGORITHM) {
                $this->deny('header_algorithm');
            }
            if (! is_string($header['kid'] ?? null) || trim($header['kid']) === '') {
                $this->deny('header_key_id');
            }

            $metadata = $this->metadata();
            if (! in_array(self::ALGORITHM, $metadata['id_token_signing_alg_values_supported'], true)) {
                $this->deny('metadata_algorithm');
            }

            $jwk = $this->key($metadata['jwks_uri'], $header['kid']);
            $key = openssl_pkey_get_public($this->rsaPem($jwk));
            if (! $key instanceof OpenSSLAsymmetricKey) {
                $this->deny('jwk_public_key');
            }
            if (openssl_verify($encodedHeader.'.'.$encodedPayload, $this->decode($encodedSignature, 'signature_encoding'), $key, OPENSSL_ALGO_SHA256) !== 1) {
                $this->deny('signature_invalid');
            }

            return $this->identity($claims);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->deny('unexpected_failure');
        }
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function segments(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3 || in_array('', $segments, true)) {
            $this->deny('token_segments');
        }

        return [$segments[0], $segments[1], $segments[2]];
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json, string $reason): array
    {
        try {
            $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->deny($reason);
        }

        if (! is_array($value) || array_is_list($value)) {
            $this->deny($reason);
        }

        return $value;
    }

    /** @return array{issuer: string, jwks_uri: string, id_token_signing_alg_values_supported: list<string>} */
    private function metadata(): array
    {
        $key = 'capstan:amp-oidc:metadata';
        $cached = Cache::get($key);
        $validatedCache = $this->validatedMetadata($cached);
        if ($validatedCache !== null) {
            return $validatedCache;
        }

        $response = Http::acceptJson()->timeout(5)->withOptions(['allow_redirects' => false])
            ->get(self::ISSUER.'/.well-known/openid-configuration');
        if (! $response->successful()) {
            $this->deny('metadata_http');
        }
        $metadata = $response->json();
        $algorithms = is_array($metadata) ? ($metadata['id_token_signing_alg_values_supported'] ?? null) : null;
        $jwksUri = is_array($metadata) ? ($metadata['jwks_uri'] ?? null) : null;
        if (! is_array($metadata)) {
            $this->deny('metadata_document');
        }
        if (($metadata['issuer'] ?? null) !== self::ISSUER) {
            $this->deny('metadata_issuer');
        }
        if (! is_string($jwksUri) || ! $this->trustedAmpUrl($jwksUri)) {
            $this->deny('metadata_jwks_uri');
        }
        if (! is_array($algorithms) || array_filter($algorithms, is_string(...)) !== $algorithms) {
            $this->deny('metadata_algorithms');
        }

        $validated = $this->validatedMetadata([
            'issuer' => self::ISSUER,
            'jwks_uri' => $jwksUri,
            'id_token_signing_alg_values_supported' => array_values($algorithms),
        ]);
        if ($validated === null) {
            $this->deny('metadata_invalid');
        }
        Cache::put($key, $validated, now()->addSeconds($this->cacheSeconds()));

        return $validated;
    }

    /** @return array<string, mixed> */
    private function key(string $uri, string $kid): array
    {
        $keys = $this->keys($uri);
        $match = $this->findKey($keys, $kid);
        if ($match !== null) {
            return $match;
        }

        $cooldownKey = 'capstan:amp-oidc:jwks-refresh:'.hash('sha256', $uri);
        if (! Cache::add($cooldownKey, true, now()->addSeconds(self::JWKS_REFRESH_COOLDOWN_SECONDS))) {
            $this->deny('jwk_refresh_cooldown');
        }

        $keys = $this->keys($uri, true);
        $match = $this->findKey($keys, $kid);
        if ($match === null) {
            $this->deny('jwk_not_found');
        }

        return $match;
    }

    /** @return list<array<string, mixed>> */
    private function keys(string $uri, bool $refresh = false): array
    {
        $cacheKey = 'capstan:amp-oidc:jwks:'.hash('sha256', $uri);
        if (! $refresh) {
            $cached = Cache::get($cacheKey);
            $validatedCache = $this->validatedKeys($cached);
            if ($validatedCache !== null) {
                return $validatedCache;
            }
        }

        $response = Http::acceptJson()->timeout(5)->withOptions(['allow_redirects' => false])->get($uri);
        if (! $response->successful()) {
            $this->deny('jwks_http');
        }
        $document = $response->json();
        $keys = is_array($document) ? ($document['keys'] ?? null) : null;
        if (! is_array($keys) || ! array_is_list($keys) || count($keys) > 100) {
            $this->deny('jwks_document');
        }
        $validated = $this->validatedKeys($keys);
        if ($validated === null) {
            $this->deny('jwks_keys');
        }

        Cache::put($cacheKey, $validated, now()->addSeconds($this->cacheSeconds()));

        return $validated;
    }

    /** @param list<array<string, mixed>> $keys
     * @return array<string, mixed>|null
     */
    private function findKey(array $keys, string $kid): ?array
    {
        $matches = array_values(array_filter($keys, fn (array $key): bool => ($key['kid'] ?? null) === $kid));
        if ($matches === []) {
            return null;
        }
        if (count($matches) !== 1) {
            $this->deny('jwk_duplicate');
        }

        $key = $matches[0];
        if (($key['kty'] ?? null) !== 'RSA') {
            $this->deny('jwk_type');
        }
        if (isset($key['use']) && $key['use'] !== 'sig') {
            $this->deny('jwk_use');
        }
        if (isset($key['alg']) && $key['alg'] !== self::ALGORITHM) {
            $this->deny('jwk_algorithm');
        }
        if (! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
            $this->deny('jwk_parameters');
        }

        return $key;
    }

    /** @param array<string, mixed> $claims */
    private function identity(array $claims): AmpWorkloadIdentity
    {
        $now = now()->getTimestamp();
        $issuer = $claims['iss'] ?? null;
        $audience = $claims['aud'] ?? null;
        $expires = $claims['exp'] ?? null;
        $issued = $claims['iat'] ?? null;
        $notBefore = $claims['nbf'] ?? null;
        $userId = $this->claimString($claims, 'user_id');
        $projectId = $this->claimString($claims, 'project_id');
        $threadId = $this->claimString($claims, 'thread_id');
        $email = $this->claimString($claims, 'email');

        if ($issuer !== self::ISSUER) {
            $this->deny('issuer_mismatch');
        }
        $origin = $this->audienceOrigin();
        if (! in_array($audience, [$origin, $origin.'/mcp/orb'], true)) {
            $this->deny('aud_mismatch', $audience);
        }
        if (! is_int($expires)) {
            $this->deny('expiry_type');
        }
        if ($expires <= $now) {
            $this->deny('token_expired');
        }
        if (! is_int($issued)) {
            $this->deny('issued_at_type');
        }
        if ($issued > $now + 60) {
            $this->deny('issued_at_future');
        }
        if ($issued >= $expires) {
            $this->deny('lifetime_invalid');
        }
        if ($notBefore !== null && ! is_int($notBefore)) {
            $this->deny('not_before_type');
        }
        if ($notBefore !== null && $notBefore > $now + 60) {
            $this->deny('not_before_future');
        }
        if (($claims['token_use'] ?? null) !== 'exchanged') {
            $this->deny('token_use');
        }
        if (($claims['email_verified'] ?? null) !== true) {
            $this->deny('email_unverified');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->deny('email_shape');
        }
        if (($claims['sub'] ?? null) !== "project:{$projectId}:user:{$userId}:thread:{$threadId}") {
            $this->deny('subject_binding');
        }

        return new AmpWorkloadIdentity($userId, $projectId, $threadId, $email);
    }

    /** @param array<string, mixed> $claims */
    private function claimString(array $claims, string $key): string
    {
        $value = $claims[$key] ?? null;
        if (! is_string($value) || $value === '' || $value !== trim($value) || strlen($value) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $this->deny('claim_'.$key);
        }

        return $value;
    }

    private function audienceOrigin(): string
    {
        $url = config('app.url');
        if (! is_string($url) || ! $this->trustedApplicationUrl($url)) {
            $this->deny('application_url');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            $this->deny('application_url');
        }
        $origin = $parts['scheme'].'://'.strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;

        return $port !== null && $port !== $defaultPort ? $origin.':'.$port : $origin;
    }

    private function trustedApplicationUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts) && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']);
    }

    private function trustedAmpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'ampcode.com'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['fragment']);
    }

    /** @param array<string, mixed> $jwk */
    private function rsaPem(array $jwk): string
    {
        $modulus = $this->decode($jwk['n'], 'jwk_modulus_encoding');
        $exponent = $this->decode($jwk['e'], 'jwk_exponent_encoding');
        $rsa = $this->derSequence($this->derInteger($modulus).$this->derInteger($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if (! is_string($algorithm)) {
            $this->deny('jwk_der_algorithm');
        }
        $subject = $this->derSequence($algorithm."\x03".$this->derLength(strlen($rsa) + 1)."\x00".$rsa);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($subject), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        } elseif ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->derLength(strlen($bytes)).$bytes;
    }

    private function derSequence(string $bytes): string
    {
        return "\x30".$this->derLength(strlen($bytes)).$bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 0) {
            $this->deny('jwk_der_length');
        }
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = pack('C', $length & 0xFF).$encoded;
            $length >>= 8;
        }

        return pack('C', 0x80 | strlen($encoded)).$encoded;
    }

    /** @return array{issuer: string, jwks_uri: string, id_token_signing_alg_values_supported: list<string>}|null */
    private function validatedMetadata(mixed $metadata): ?array
    {
        if (! is_array($metadata)
            || ($metadata['issuer'] ?? null) !== self::ISSUER
            || ! is_string($metadata['jwks_uri'] ?? null)
            || ! $this->trustedAmpUrl($metadata['jwks_uri'])
            || ! is_array($metadata['id_token_signing_alg_values_supported'] ?? null)
            || ! array_is_list($metadata['id_token_signing_alg_values_supported'])) {
            return null;
        }
        $algorithms = [];
        foreach ($metadata['id_token_signing_alg_values_supported'] as $algorithm) {
            if (! is_string($algorithm)) {
                return null;
            }
            $algorithms[] = $algorithm;
        }

        return [
            'issuer' => self::ISSUER,
            'jwks_uri' => $metadata['jwks_uri'],
            'id_token_signing_alg_values_supported' => $algorithms,
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function validatedKeys(mixed $keys): ?array
    {
        if (! is_array($keys) || ! array_is_list($keys)) {
            return null;
        }
        $validated = [];
        foreach ($keys as $key) {
            if (! is_array($key)) {
                return null;
            }
            $validated[] = $key;
        }

        return $validated;
    }

    private function decode(string $encoded, string $reason): string
    {
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1) {
            $this->deny($reason);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if (! is_string($decoded) || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $encoded) {
            $this->deny($reason);
        }

        return $decoded;
    }

    private function cacheSeconds(): int
    {
        return max(60, min(3600, (int) config('capstan.orb.oidc_cache_seconds', 300)));
    }

    private function deny(string $reason, mixed $audience = null): never
    {
        try {
            $context = ['component' => 'verifier', 'reason_code' => $reason];
            if ($reason === 'aud_mismatch' && is_string($audience)) {
                $url = config('app.url');
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && $this->trustedApplicationUrl($url)) {
                    $base = $this->audienceOrigin();
                    if (in_array($audience, [$base.'/', $base.'/mcp', $base.'/mcp/', $base.'/mcp/orb/'], true)) {
                        $context['received_aud'] = $audience;
                    }
                }
            }

            Log::warning('Orb authentication denied.', $context);
        } catch (Throwable) {
            // Diagnostics must never replace the authentication denial.
        }

        throw new AuthorizationException('Unauthenticated.');
    }
}
