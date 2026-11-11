<?php

namespace App\Controllers;

use App\Libraries\Google\GoogleUnavailable;
use App\Libraries\Http\ApiProblem;
use App\Libraries\Http\Problems;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use stdClass;
use Throwable;

/**
 * Base of the API controllers: JSON in and out, expected errors as problem+json. Controllers stay
 * thin; the rules live in `App\Services`.
 *
 * @property IncomingRequest $request
 */
abstract class BaseController extends Controller
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Runs the action and maps expected failures to problem responses. Anything else reaches the
     * global handler, which answers 500 without details.
     *
     * @param callable(): ResponseInterface $action
     */
    protected function guarded(callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (ApiProblem $problem) {
            return Problems::respond($this->response, $problem);
        } catch (GoogleUnavailable $e) {
            log_message('error', 'Google API unavailable: {message}', ['message' => $e->getMessage()]);

            return Problems::respond($this->response, ApiProblem::unavailable());
        }
    }

    protected function json(mixed $data, int $status = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setContentType('application/json')
            ->setBody(json_encode($data, self::JSON_FLAGS));
    }

    protected function noContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204)->setBody('');
    }

    /**
     * The request body as a JSON object.
     *
     * @throws ApiProblem
     */
    protected function jsonBody(): stdClass
    {
        try {
            $body = json_decode((string) $this->request->getBody(), false, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ApiProblem::validation('The body must be JSON.');
        }
        if (! $body instanceof stdClass) {
            throw ApiProblem::validation('The body must be a JSON object.');
        }

        return $body;
    }
}
