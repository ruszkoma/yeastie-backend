<?php

namespace App\Libraries\Google;

/** One pulled Pub/Sub message: `data` is still base64, as Pub/Sub sends it. */
final class PubSubMessage
{
    public function __construct(
        public readonly string $ackId,
        public readonly string $messageId,
        public readonly string $data,
    ) {
    }
}
