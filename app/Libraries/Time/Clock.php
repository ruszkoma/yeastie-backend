<?php

namespace App\Libraries\Time;

use DateTimeImmutable;

/** Current time, injected so that tests can fix it. Always UTC. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
