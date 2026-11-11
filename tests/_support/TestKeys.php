<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * RSA keys and tokens for tests, generated once per run. `trusted` stands in for Google's key;
 * `stranger` signs tokens that must be rejected.
 */
final class TestKeys
{
    public const KID = 'test-kid';

    /** @var array<string, array{private: string, public: string}> */
    private static array $pairs = [];

    public static function trustedKey(): Key
    {
        return new Key(self::pair('trusted')['public'], 'RS256');
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function sign(array $claims, string $signer = 'trusted', string $kid = self::KID): string
    {
        return JWT::encode($claims, self::pair($signer)['private'], 'RS256', $kid);
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function pair(string $name): array
    {
        if (! isset(self::$pairs[$name])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$pairs[$name] = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$pairs[$name];
    }
}
