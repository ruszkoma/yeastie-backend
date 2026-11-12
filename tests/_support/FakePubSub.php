<?php

namespace Tests\Support;

use App\Libraries\Google\GoogleUnavailable;
use App\Libraries\Google\PubSub;
use App\Libraries\Google\PubSubMessage;

/** An in-memory subscription: unacknowledged messages are delivered again, as Pub/Sub does. */
final class FakePubSub implements PubSub
{
    /** @var array<string, PubSubMessage> waiting messages by ack id */
    public array $waiting = [];

    public bool $unavailable = false;

    /**
     * @param array<string, mixed> $notification
     */
    public function publish(string $messageId, array $notification): void
    {
        $ackId                 = 'ack-' . $messageId;
        $this->waiting[$ackId] = new PubSubMessage($ackId, $messageId, base64_encode(json_encode($notification)));
    }

    public function pull(int $max): array
    {
        if ($this->unavailable) {
            throw new GoogleUnavailable('Fake outage.');
        }

        return array_slice(array_values($this->waiting), 0, $max);
    }

    public function acknowledge(array $ackIds): void
    {
        if ($this->unavailable) {
            throw new GoogleUnavailable('Fake outage.');
        }

        foreach ($ackIds as $ackId) {
            unset($this->waiting[$ackId]);
        }
    }
}
