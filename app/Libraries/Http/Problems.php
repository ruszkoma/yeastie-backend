<?php

namespace App\Libraries\Http;

use CodeIgniter\HTTP\ResponseInterface;

/** Writes problem details responses. */
final class Problems
{
    private const TYPE_BASE = 'https://yeastie.cprojekt.pl/problems/';

    public static function respond(ResponseInterface $response, ApiProblem $problem): ResponseInterface
    {
        return self::write($response, $problem->status, $problem->problemCode, $problem->title, $problem->detail);
    }

    public static function write(
        ResponseInterface $response,
        int $status,
        string $code,
        string $title,
        ?string $detail = null,
    ): ResponseInterface {
        $body = [
            'type'   => self::TYPE_BASE . str_replace('_', '-', $code),
            'title'  => $title,
            'status' => $status,
            'code'   => $code,
        ];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return $response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setContentType('application/problem+json')
            ->setBody(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
