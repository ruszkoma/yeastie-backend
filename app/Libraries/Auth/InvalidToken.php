<?php

namespace App\Libraries\Auth;

use RuntimeException;

/** The token is malformed, wrongly signed, expired or meant for someone else. */
final class InvalidToken extends RuntimeException
{
}
