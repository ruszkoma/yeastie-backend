<?php

namespace App\Libraries\Google;

use RuntimeException;

/** A Google API did not answer or failed on its side; the operation can be retried later. */
final class GoogleUnavailable extends RuntimeException
{
}
