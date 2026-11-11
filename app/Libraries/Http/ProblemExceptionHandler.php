<?php

namespace App\Libraries\Http;

use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Last-resort handler for web requests: unknown routes and unexpected exceptions become
 * problem+json without details, so stack traces and data never reach the client. Expected errors
 * are answered earlier as [ApiProblem]. The framework logs the exception before calling this.
 */
final class ProblemExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        if ($exception instanceof ApiProblem) {
            $response = Problems::respond($response, $exception);
        } elseif ($statusCode === 404) {
            $response = Problems::respond($response, ApiProblem::notFound());
        } else {
            $response = Problems::write($response, 500, 'server_error', 'Server error');
        }
        $response->send();

        if (ENVIRONMENT !== 'testing' && $request instanceof IncomingRequest) {
            exit($exitCode);
        }
    }
}
