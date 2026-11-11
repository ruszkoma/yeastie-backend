<?php

namespace App\Filters;

use App\Libraries\Auth\InvalidToken;
use App\Libraries\Auth\KeysUnavailable;
use App\Libraries\Http\ApiProblem;
use App\Libraries\Http\Problems;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Accepts only Pub/Sub push requests signed for the configured audience and service account. */
final class PubSubAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $token = BearerToken::from($request);
        if ($token === null) {
            return Problems::respond(service('response'), ApiProblem::unauthorized());
        }
        try {
            service('pubSubTokenVerifier')->verify($token);
        } catch (InvalidToken) {
            return Problems::respond(service('response'), ApiProblem::unauthorized());
        } catch (KeysUnavailable $e) {
            log_message('error', 'Google OIDC keys unavailable: {message}', ['message' => $e->getMessage()]);

            return Problems::respond(service('response'), ApiProblem::unavailable());
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
