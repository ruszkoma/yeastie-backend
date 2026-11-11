<?php

namespace App\Filters;

use App\Libraries\Auth\InvalidToken;
use App\Libraries\Auth\KeysUnavailable;
use App\Libraries\Http\ApiProblem;
use App\Libraries\Http\Problems;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a valid Firebase ID token (`Authorization: Bearer`) and sets the current user from it,
 * then applies the per-user rate limit. The token itself is never logged.
 */
final class FirebaseAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $token = BearerToken::from($request);
        if ($token === null) {
            return Problems::respond(service('response'), ApiProblem::unauthorized());
        }
        try {
            $uid = service('firebaseTokenVerifier')->verify($token);
        } catch (InvalidToken) {
            return Problems::respond(service('response'), ApiProblem::unauthorized());
        } catch (KeysUnavailable $e) {
            log_message('error', 'Firebase keys unavailable: {message}', ['message' => $e->getMessage()]);

            return Problems::respond(service('response'), ApiProblem::unavailable());
        }

        $limit = config('Yeastie')->rateLimitPerMinute;
        if (! service('throttler')->check('user_' . hash('sha256', $uid), $limit, MINUTE)) {
            return Problems::respond(service('response'), ApiProblem::rateLimited())
                ->setHeader('Retry-After', (string) max(1, service('throttler')->getTokenTime()));
        }
        service('currentUser')->set($uid);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
