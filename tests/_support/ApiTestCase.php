<?php

namespace Tests\Support;

use App\Libraries\Auth\StaticKeySource;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Feature tests against the MariaDB test database, with Google replaced by fakes: tokens are
 * signed by [TestKeys], time is a [FixedClock], Play and Firebase Admin are in-memory.
 */
abstract class ApiTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    public const PROJECT = 'yeastie-test';

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    protected FixedClock $clock;
    protected FakePlayDeveloperApi $play;
    protected FakeFirebaseAdmin $firebase;

    protected function setUp(): void
    {
        parent::setUp();
        // Shared services (and the fakes they hold) must not leak from one test to the next.
        Services::reset(true);
        $this->mockCache();

        $this->clock   = new FixedClock();
        $this->play     = new FakePlayDeveloperApi();
        $this->firebase = new FakeFirebaseAdmin();
        Services::injectMock('clock', $this->clock);
        Services::injectMock('firebaseKeys', new StaticKeySource([TestKeys::KID => TestKeys::trustedKey()]));
        Services::injectMock('oidcKeys', new StaticKeySource([TestKeys::KID => TestKeys::trustedKey()]));
        Services::injectMock('playDeveloperApi', $this->play);
        Services::injectMock('firebaseAdmin', $this->firebase);
    }

    /**
     * A Firebase ID token for `$uid`, valid at the fixed clock unless `$override` says otherwise.
     *
     * @param array<string, mixed> $override
     */
    protected function idToken(string $uid, array $override = [], string $signer = 'trusted'): string
    {
        $now = $this->clock->now()->getTimestamp();

        return TestKeys::sign($override + [
            'iss'       => 'https://securetoken.google.com/' . self::PROJECT,
            'aud'       => self::PROJECT,
            'sub'       => $uid,
            'iat'       => $now - 60,
            'exp'       => $now + 3600,
            'auth_time' => $now - 120,
        ], $signer);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(string $method, string $path, ?string $uid, ?array $body = null, ?string $token = null): TestResponse
    {
        // The framework shares one response object; a fresh one per call keeps calls independent.
        Services::resetSingle('response');
        $headers = [];
        $token ??= $uid === null ? null : $this->idToken($uid);
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->withHeaders($headers)
            ->withBody($body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))
            ->call(strtoupper($method), $path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(TestResponse $response): array
    {
        return json_decode($response->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function assertProblem(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status);
        $this->assertStringStartsWith('application/problem+json', $response->response()->getHeaderLine('Content-Type'));
        $this->assertSame($code, $this->json($response)['code']);
    }
}
