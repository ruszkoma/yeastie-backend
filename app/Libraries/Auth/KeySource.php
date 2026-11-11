<?php

namespace App\Libraries\Auth;

use Firebase\JWT\Key;

/** Public keys that may have signed a token, by key id (`kid`). */
interface KeySource
{
    /**
     * @param bool $refresh skip the cache, used once when a token names an unknown `kid`
     *
     * @return array<string, Key>
     *
     * @throws KeysUnavailable when the keys cannot be fetched
     */
    public function keys(bool $refresh = false): array;
}
