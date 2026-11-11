<?php

namespace App\Libraries\Auth;

use RuntimeException;

/** Google's signing keys could not be fetched; the request can be retried later. */
final class KeysUnavailable extends RuntimeException
{
}
