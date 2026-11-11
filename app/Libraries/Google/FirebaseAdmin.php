<?php

namespace App\Libraries\Google;

/** Firebase Authentication admin operations. */
interface FirebaseAdmin
{
    /**
     * Deletes the Firebase account. An account that no longer exists counts as deleted.
     *
     * @throws GoogleUnavailable
     */
    public function deleteUser(string $uid): void;
}
