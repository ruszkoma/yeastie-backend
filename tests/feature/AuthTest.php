<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Tests\Support\ApiTestCase;
use Tests\Support\TestKeys;

/**
 * @internal
 */
final class AuthTest extends ApiTestCase
{
    public function testValidTokenIsAccepted(): void
    {
        $this->api('GET', 'v1/sync?after=0', 'user-a')->assertStatus(200);
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null), 401, 'unauthorized');
    }

    public function testMalformedHeaderIsRejected(): void
    {
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, 'not a jwt'), 401, 'unauthorized');
    }

    public function testTokenSignedByAnotherKeyIsRejected(): void
    {
        $token = $this->idToken('user-a', [], 'stranger');

        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $token), 401, 'unauthorized');
    }

    public function testUnknownKeyIdIsRejected(): void
    {
        $now   = $this->clock->now()->getTimestamp();
        $token = TestKeys::sign([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT, 'aud' => self::PROJECT, 'sub' => 'user-a',
            'iat' => $now, 'exp' => $now + 3600, 'auth_time' => $now,
        ], 'trusted', 'other-kid');

        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $token), 401, 'unauthorized');
    }

    public function testUnsignedAndSymmetricTokensAreRejected(): void
    {
        $now    = $this->clock->now()->getTimestamp();
        $claims = ['iss' => 'https://securetoken.google.com/' . self::PROJECT, 'aud' => self::PROJECT,
            'sub' => 'user-a', 'iat' => $now, 'exp' => $now + 3600, 'auth_time' => $now];

        $none = JWT::urlsafeB64Encode(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => TestKeys::KID]))
            . '.' . JWT::urlsafeB64Encode(json_encode($claims)) . '.';
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $none), 401, 'unauthorized');

        // HS256 signed with the public key as the secret: the classic algorithm confusion attack.
        $publicPem = TestKeys::trustedKey()->getKeyMaterial();
        $hs256     = JWT::encode($claims, is_string($publicPem) ? $publicPem : 'secret', 'HS256', TestKeys::KID);
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $hs256), 401, 'unauthorized');
    }

    public function testWrongAudienceOrIssuerIsRejected(): void
    {
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $this->idToken('user-a', ['aud' => 'other-project'])), 401, 'unauthorized');
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $this->idToken('user-a', ['iss' => 'https://securetoken.google.com/other-project'])), 401, 'unauthorized');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $now   = $this->clock->now()->getTimestamp();
        $token = $this->idToken('user-a', ['iat' => $now - 7200, 'exp' => $now - 3600]);

        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $token), 401, 'unauthorized');
    }

    public function testTokenFromTheFutureIsRejected(): void
    {
        $now = $this->clock->now()->getTimestamp();

        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $this->idToken('user-a', ['iat' => $now + 600])), 401, 'unauthorized');
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $this->idToken('user-a', ['auth_time' => $now + 600])), 401, 'unauthorized');
    }

    public function testEmptySubjectIsRejected(): void
    {
        $this->assertProblem($this->api('GET', 'v1/sync?after=0', null, null, $this->idToken('', [])), 401, 'unauthorized');
    }

    public function testRateLimitPerUser(): void
    {
        config('Yeastie')->rateLimitPerMinute = 2;

        $this->api('GET', 'v1/entitlement', 'user-a')->assertStatus(200);
        $this->api('GET', 'v1/entitlement', 'user-a')->assertStatus(200);
        $limited = $this->api('GET', 'v1/entitlement', 'user-a');
        $this->assertProblem($limited, 429, 'rate_limited');
        $this->assertNotSame('', $limited->response()->getHeaderLine('Retry-After'));

        // Another user has their own budget.
        $this->api('GET', 'v1/entitlement', 'user-b')->assertStatus(200);
    }

    public function testRtdnRejectsFirebaseTokens(): void
    {
        $response = $this->api('POST', 'v1/rtdn', 'user-a', ['message' => ['data' => '', 'messageId' => '1'], 'subscription' => 's']);

        $this->assertProblem($response, 401, 'unauthorized');
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->api('GET', 'v1/nothing-here', 'user-a');
    }
}
