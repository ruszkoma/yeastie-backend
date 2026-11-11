<?php

namespace App\Libraries\Auth;

use Firebase\JWT\Key;

/** Fixed keys, for tests. */
final class StaticKeySource implements KeySource
{
    /**
     * @param array<string, Key> $keys
     */
    public function __construct(private readonly array $keys)
    {
    }

    public function keys(bool $refresh = false): array
    {
        return $this->keys;
    }
}
