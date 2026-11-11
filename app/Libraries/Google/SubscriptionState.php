<?php

namespace App\Libraries\Google;

use DateTimeImmutable;

/**
 * The part of a Google Play `SubscriptionPurchaseV2` the API uses.
 * `state` is Google's `subscriptionState`, e.g. `SUBSCRIPTION_STATE_ACTIVE`.
 */
final class SubscriptionState
{
    public const ACTIVE         = 'SUBSCRIPTION_STATE_ACTIVE';
    public const IN_GRACE       = 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD';
    public const CANCELED       = 'SUBSCRIPTION_STATE_CANCELED';
    public const ON_HOLD        = 'SUBSCRIPTION_STATE_ON_HOLD';
    public const EXPIRED        = 'SUBSCRIPTION_STATE_EXPIRED';

    /** States in which the user keeps premium until `expiresAt`. A canceled plan runs to its end. */
    public const ENTITLED_STATES = [self::ACTIVE, self::IN_GRACE, self::CANCELED];

    public function __construct(
        public readonly string $state,
        public readonly ?string $productId,
        public readonly ?string $basePlanId,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly bool $autoRenewing,
        public readonly ?string $accountHash,
        public readonly ?string $linkedPurchaseToken,
    ) {
    }

    public function entitles(DateTimeImmutable $now): bool
    {
        return in_array($this->state, self::ENTITLED_STATES, true)
            && $this->expiresAt !== null
            && $this->expiresAt > $now;
    }
}
