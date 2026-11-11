<?php

namespace Tests\Support;

use App\Libraries\Google\GoogleUnavailable;
use App\Libraries\Google\PlayDeveloperApi;
use App\Libraries\Google\SubscriptionState;

final class FakePlayDeveloperApi implements PlayDeveloperApi
{
    /** @var array<string, SubscriptionState> */
    public array $subscriptions = [];

    public bool $unavailable = false;

    public int $calls = 0;

    public function subscription(string $packageName, string $purchaseToken): ?SubscriptionState
    {
        $this->calls++;
        if ($this->unavailable) {
            throw new GoogleUnavailable('Fake outage.');
        }

        return $this->subscriptions[$purchaseToken] ?? null;
    }
}
