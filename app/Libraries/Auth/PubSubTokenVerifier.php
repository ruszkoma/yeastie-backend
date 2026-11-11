<?php

namespace App\Libraries\Auth;

/**
 * Verifies the OIDC token that Pub/Sub attaches to push requests (RTDN): RS256 signed by Google,
 * issuer `accounts.google.com`, the audience set on the push subscription and the configured
 * service account as a verified `email`.
 */
final class PubSubTokenVerifier
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(
        private readonly JwtVerifier $jwt,
        private readonly KeySource $keys,
        private readonly string $audience,
        private readonly string $serviceAccountEmail,
    ) {
    }

    /**
     * @throws InvalidToken
     * @throws KeysUnavailable
     */
    public function verify(string $token): void
    {
        if ($this->audience === '' || $this->serviceAccountEmail === '') {
            throw new InvalidToken('RTDN push is not configured.');
        }
        $claims = $this->jwt->decode($token, $this->keys);

        if (! in_array($claims->iss ?? null, self::ISSUERS, true)) {
            throw new InvalidToken('Wrong issuer.');
        }
        if (($claims->aud ?? null) !== $this->audience) {
            throw new InvalidToken('Wrong audience.');
        }
        if (($claims->email ?? null) !== $this->serviceAccountEmail || ($claims->email_verified ?? false) !== true) {
            throw new InvalidToken('Wrong service account.');
        }
    }
}
