<?php

namespace App\Libraries\Auth;

use App\Libraries\Time\Clock;

/**
 * Verifies Firebase ID tokens as Firebase documents for third-party backends: RS256 signed by
 * `securetoken@system.gserviceaccount.com`, `aud` = project id,
 * `iss` = `https://securetoken.google.com/<project id>`, `exp` in the future, `iat` and
 * `auth_time` in the past, non-empty `sub`. The user id is taken only from `sub`.
 */
final class FirebaseTokenVerifier
{
    private const MAX_UID_LENGTH = 128;

    public function __construct(
        private readonly JwtVerifier $jwt,
        private readonly KeySource $keys,
        private readonly Clock $clock,
        private readonly string $projectId,
        private readonly int $leewaySeconds,
    ) {
    }

    /**
     * @return string the Firebase UID
     *
     * @throws InvalidToken
     * @throws KeysUnavailable
     */
    public function verify(string $idToken): string
    {
        if ($this->projectId === '') {
            throw new InvalidToken('Firebase project is not configured.');
        }
        $claims = $this->jwt->decode($idToken, $this->keys);

        if (($claims->aud ?? null) !== $this->projectId) {
            throw new InvalidToken('Wrong audience.');
        }
        if (($claims->iss ?? null) !== 'https://securetoken.google.com/' . $this->projectId) {
            throw new InvalidToken('Wrong issuer.');
        }
        $authTime = $claims->auth_time ?? null;
        if (! is_int($authTime) || $authTime > $this->clock->now()->getTimestamp() + $this->leewaySeconds) {
            throw new InvalidToken('Invalid auth_time.');
        }
        $uid = $claims->sub ?? null;
        if (! is_string($uid) || $uid === '' || strlen($uid) > self::MAX_UID_LENGTH) {
            throw new InvalidToken('Invalid subject.');
        }

        return $uid;
    }
}
