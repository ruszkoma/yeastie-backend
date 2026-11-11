<?php

namespace App\Filters;

use CodeIgniter\HTTP\Header;
use CodeIgniter\HTTP\RequestInterface;

final class BearerToken
{
    private const MAX_LENGTH = 8192;

    /** The token from a single `Authorization: Bearer <token>` header, or null. */
    public static function from(RequestInterface $request): ?string
    {
        // A repeated header is rejected rather than guessed at (and getHeaderLine() would throw).
        $header = $request->header('Authorization');
        if (! $header instanceof Header) {
            return null;
        }
        $value = $header->getValueLine();
        if (strlen($value) > self::MAX_LENGTH || preg_match('/^Bearer\s+([A-Za-z0-9_\-.]+)$/', $value, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
