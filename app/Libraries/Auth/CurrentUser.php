<?php

namespace App\Libraries\Auth;

use LogicException;

/**
 * The user of the current request, set by the `firebaseAuth` filter from the verified token.
 * Controllers read the user id only from here, never from the request.
 */
final class CurrentUser
{
    private ?string $uid = null;

    public function set(string $uid): void
    {
        $this->uid = $uid;
    }

    public function uid(): string
    {
        return $this->uid ?? throw new LogicException('No authenticated user; is the firebaseAuth filter applied?');
    }
}
