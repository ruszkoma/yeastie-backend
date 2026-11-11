<?php

namespace App\Libraries\Google;

/** Read access to Google Play subscription purchases. */
interface PlayDeveloperApi
{
    /**
     * @return SubscriptionState|null null when Google does not know the token
     *
     * @throws GoogleUnavailable
     */
    public function subscription(string $packageName, string $purchaseToken): ?SubscriptionState;
}
