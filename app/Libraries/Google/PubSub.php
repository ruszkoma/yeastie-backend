<?php

namespace App\Libraries\Google;

/** Pull access to the Pub/Sub subscription that carries Google Play notifications (RTDN). */
interface PubSub
{
    /**
     * Messages waiting in the subscription, at most `$max`. Empty when there are none.
     *
     * @return list<PubSubMessage>
     *
     * @throws GoogleUnavailable
     */
    public function pull(int $max): array;

    /**
     * Tells Pub/Sub the messages are done, so it stops redelivering them.
     *
     * @param list<string> $ackIds
     *
     * @throws GoogleUnavailable
     */
    public function acknowledge(array $ackIds): void;
}
