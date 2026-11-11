<?php

namespace App\Libraries\Auth;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Header;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Throwable;

/**
 * Google's public signing keys, fetched over HTTPS and cached for the time Google allows
 * (`Cache-Control: max-age`). Two formats:
 * - `x509`: `{kid: PEM certificate}`, used for Firebase ID tokens;
 * - `jwk`: a JWK set, used for the OIDC tokens Pub/Sub sends with RTDN.
 */
final class GoogleKeySource implements KeySource
{
    public const FIREBASE_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    public const OIDC_URL     = 'https://www.googleapis.com/oauth2/v3/certs';

    private const MIN_TTL     = 300;
    private const MAX_TTL     = 86400;
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private readonly string $url,
        private readonly string $format,
        private readonly CacheInterface $cache,
        private readonly CURLRequest $http,
    ) {
    }

    public static function firebase(CacheInterface $cache, CURLRequest $http): self
    {
        return new self(self::FIREBASE_URL, 'x509', $cache, $http);
    }

    public static function oidc(CacheInterface $cache, CURLRequest $http): self
    {
        return new self(self::OIDC_URL, 'jwk', $cache, $http);
    }

    public function keys(bool $refresh = false): array
    {
        $cacheKey = 'google_keys_' . md5($this->url);
        $raw      = $refresh ? null : $this->cache->get($cacheKey);
        if (! is_string($raw)) {
            [$raw, $ttl] = $this->fetch();
            $this->cache->save($cacheKey, $raw, $ttl);
        }

        return $this->parse($raw);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function fetch(): array
    {
        try {
            $response = $this->http->get($this->url, ['http_errors' => false, 'timeout' => 10]);
        } catch (Throwable $e) {
            throw new KeysUnavailable('Key fetch failed.', 0, $e);
        }
        if ($response->getStatusCode() !== 200) {
            throw new KeysUnavailable('Key fetch failed with status ' . $response->getStatusCode() . '.');
        }
        $ttl = self::DEFAULT_TTL;
        // CodeIgniter's response object starts with its own `no-store, max-age=0`; Google's header
        // is appended after it, so the last max-age is the server's.
        if (preg_match_all('/max-age=(\d+)/', self::headerText($response->header('Cache-Control')), $m) > 0) {
            $ttl = max(self::MIN_TTL, min(self::MAX_TTL, (int) end($m[1])));
        }

        return [(string) $response->getBody(), $ttl];
    }

    /**
     * All values of a header. Google sends `Cache-Control` more than once, and CodeIgniter's
     * `getHeaderLine()` throws in that case.
     *
     * @param Header|list<Header>|null $header
     */
    private static function headerText(Header|array|null $header): string
    {
        $headers = $header instanceof Header ? [$header] : ($header ?? []);

        return implode(', ', array_map(static fn (Header $h): string => $h->getValueLine(), $headers));
    }

    /**
     * @return array<string, Key>
     */
    private function parse(string $raw): array
    {
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new KeysUnavailable('Unreadable key set.');
        }
        if ($this->format === 'jwk') {
            try {
                return JWK::parseKeySet($data, 'RS256');
            } catch (Throwable $e) {
                throw new KeysUnavailable('Unreadable key set.', 0, $e);
            }
        }
        $keys = [];
        foreach ($data as $kid => $pem) {
            if (is_string($kid) && is_string($pem)) {
                $keys[$kid] = new Key($pem, 'RS256');
            }
        }

        return $keys;
    }
}
