<?php

namespace App\Libraries\Auth;

use App\Libraries\Time\Clock;
use Firebase\JWT\JWT;
use stdClass;
use Throwable;

/**
 * Checks the RS256 signature and the time claims (`exp`, `iat`, `nbf`) of a JWT. Only RS256 is
 * accepted: every key is bound to RS256, so `none`, HS256 and other algorithms fail. Callers check
 * issuer, audience and subject.
 */
final class JwtVerifier
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $leewaySeconds,
    ) {
    }

    /**
     * @throws InvalidToken
     * @throws KeysUnavailable
     */
    public function decode(string $jwt, KeySource $keySource): stdClass
    {
        $kid  = $this->keyId($jwt);
        $keys = $keySource->keys();
        if (! isset($keys[$kid])) {
            // Google rotates keys; fetch once more before rejecting.
            $keys = $keySource->keys(true);
        }
        if (! isset($keys[$kid])) {
            throw new InvalidToken('Unknown key id.');
        }

        $previousLeeway    = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway       = $this->leewaySeconds;
        JWT::$timestamp    = $this->clock->now()->getTimestamp();

        try {
            $claims = JWT::decode($jwt, [$kid => $keys[$kid]]);
        } catch (Throwable $e) {
            throw new InvalidToken('Token rejected.', 0, $e);
        } finally {
            JWT::$leeway    = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }
        if (! isset($claims->exp, $claims->iat) || ! is_int($claims->exp) || ! is_int($claims->iat)) {
            throw new InvalidToken('Missing time claims.');
        }

        return $claims;
    }

    private function keyId(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidToken('Malformed token.');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), false, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidToken('Malformed token header.', 0, $e);
        }
        if (! $header instanceof stdClass || ($header->alg ?? null) !== 'RS256' || ! is_string($header->kid ?? null)) {
            throw new InvalidToken('Unsupported token header.');
        }

        return $header->kid;
    }
}
