<?php

namespace Tests\Support;

use App\Libraries\Google\FirebaseAdmin;
use App\Libraries\Google\GoogleUnavailable;

final class FakeFirebaseAdmin implements FirebaseAdmin
{
    /** @var list<string> */
    public array $deleted = [];

    public bool $unavailable = false;

    public function deleteUser(string $uid): void
    {
        if ($this->unavailable) {
            throw new GoogleUnavailable('Fake outage.');
        }
        $this->deleted[] = $uid;
    }
}
