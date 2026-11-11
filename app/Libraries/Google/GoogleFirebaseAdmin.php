<?php

namespace App\Libraries\Google;

use CodeIgniter\HTTP\CURLRequest;
use Throwable;

/** Identity Toolkit REST API (`accounts:delete`), which the Firebase Admin SDKs call too. */
final class GoogleFirebaseAdmin implements FirebaseAdmin
{
    private const URL = 'https://identitytoolkit.googleapis.com/v1/projects/%s/accounts:delete';

    public function __construct(
        private readonly ServiceAccountTokens $tokens,
        private readonly CURLRequest $http,
        private readonly string $projectId,
    ) {
    }

    public function deleteUser(string $uid): void
    {
        if ($this->projectId === '') {
            throw new GoogleUnavailable('Firebase project is not configured.');
        }
        $accessToken = $this->tokens->accessToken(ServiceAccountTokens::SCOPE_IDENTITY_TOOLKIT);
        try {
            $response = $this->http->post(sprintf(self::URL, rawurlencode($this->projectId)), [
                'http_errors' => false,
                'timeout'     => 15,
                'headers'     => ['Authorization' => 'Bearer ' . $accessToken],
                'json'        => ['localId' => $uid],
            ]);
        } catch (Throwable $e) {
            throw new GoogleUnavailable('Account deletion request failed.', 0, $e);
        }
        if ($response->getStatusCode() === 200) {
            return;
        }
        $message = json_decode($response->getBody() ?? '', true)['error']['message'] ?? '';
        if (is_string($message) && str_starts_with($message, 'USER_NOT_FOUND')) {
            return;
        }

        throw new GoogleUnavailable('Account deletion failed with status ' . $response->getStatusCode() . '.');
    }
}
