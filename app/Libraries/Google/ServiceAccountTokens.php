<?php

namespace App\Libraries\Google;

use App\Libraries\Time\Clock;
use CodeIgniter\HTTP\CURLRequest;
use Firebase\JWT\JWT;
use Throwable;

/**
 * OAuth access tokens for the Google service account (JWT bearer grant, RFC 7523). The key file
 * lives outside the public directory. Tokens are kept in memory for the request only, never
 * written to disk: calls that need them (purchase checks, RTDN, account deletion) are rare.
 */
final class ServiceAccountTokens
{
    public const SCOPE_ANDROID_PUBLISHER = 'https://www.googleapis.com/auth/androidpublisher';
    public const SCOPE_IDENTITY_TOOLKIT  = 'https://www.googleapis.com/auth/identitytoolkit';

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokens = [];

    public function __construct(
        private readonly string $keyFile,
        private readonly CURLRequest $http,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws GoogleUnavailable
     */
    public function accessToken(string $scope): string
    {
        $now = $this->clock->now()->getTimestamp();
        if (isset($this->tokens[$scope]) && $this->tokens[$scope]['expires'] > $now + 60) {
            return $this->tokens[$scope]['token'];
        }
        $account   = $this->account();
        $assertion = JWT::encode([
            'iss'   => $account['client_email'],
            'scope' => $scope,
            'aud'   => $account['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], $account['private_key'], 'RS256');

        try {
            $response = $this->http->post($account['token_uri'], [
                'http_errors' => false,
                'timeout'     => 10,
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ],
            ]);
        } catch (Throwable $e) {
            throw new GoogleUnavailable('Token request failed.', 0, $e);
        }
        $body = json_decode($response->getBody() ?? '', true);
        if ($response->getStatusCode() !== 200 || ! is_string($body['access_token'] ?? null)) {
            throw new GoogleUnavailable('Token request failed with status ' . $response->getStatusCode() . '.');
        }
        $this->tokens[$scope] = [
            'token'   => $body['access_token'],
            'expires' => $now + (int) ($body['expires_in'] ?? 3600),
        ];

        return $body['access_token'];
    }

    /** The service account's project, used as the Firebase project for Admin calls if needed. */
    public function projectId(): string
    {
        return $this->account()['project_id'] ?? '';
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string, project_id?: string}
     */
    private function account(): array
    {
        if ($this->keyFile === '' || ! is_readable($this->keyFile)) {
            throw new GoogleUnavailable('Service account key is not configured.');
        }
        $data = json_decode((string) file_get_contents($this->keyFile), true);
        if (! is_array($data) || ! is_string($data['client_email'] ?? null) || ! is_string($data['private_key'] ?? null)) {
            throw new GoogleUnavailable('Service account key is unreadable.');
        }
        $data['token_uri'] = is_string($data['token_uri'] ?? null) ? $data['token_uri'] : 'https://oauth2.googleapis.com/token';

        return $data;
    }
}
