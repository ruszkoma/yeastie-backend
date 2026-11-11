<?php

namespace App\Libraries\Http;

use RuntimeException;

/**
 * An expected API error, answered as `application/problem+json` (RFC 9457) with a `code` from the
 * API contract. Thrown by services and turned into a response by controllers and filters.
 */
final class ApiProblem extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($title, $status);
    }

    public static function unauthorized(string $detail = 'Missing or invalid token.'): self
    {
        return new self(401, 'unauthorized', 'Unauthorized', $detail);
    }

    public static function validation(string $detail): self
    {
        return new self(400, 'validation_failed', 'Invalid request', $detail);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'Not found');
    }

    public static function payloadTooLarge(string $detail): self
    {
        return new self(413, 'payload_too_large', 'Payload too large', $detail);
    }

    public static function rateLimited(): self
    {
        return new self(429, 'rate_limited', 'Too many requests');
    }

    public static function tokenAlreadyUsed(): self
    {
        return new self(409, 'token_already_used', 'Purchase already used', 'This purchase belongs to another account.');
    }

    public static function trialAlreadyUsed(): self
    {
        return new self(409, 'trial_already_used', 'Trial already used');
    }

    public static function purchaseInvalid(string $detail): self
    {
        return new self(422, 'purchase_invalid', 'Purchase not valid', $detail);
    }

    /** A Google service did not answer; the caller may retry later. */
    public static function unavailable(string $detail = 'An upstream service is unavailable.'): self
    {
        return new self(503, 'server_error', 'Service unavailable', $detail);
    }
}
