<?php

namespace Tests\Unit;

use App\Libraries\Google\GooglePlayDeveloperApi;
use App\Libraries\Google\SubscriptionState;
use App\Libraries\Time\Iso;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ParsingTest extends CIUnitTestCase
{
    public function testIsoParsing(): void
    {
        $this->assertSame('2026-11-09T18:30:00.000Z', Iso::format(Iso::parse('2026-11-09T18:30:00Z')));
        $this->assertSame('2026-11-09T18:30:00.123Z', Iso::format(Iso::parse('2026-11-09T18:30:00.123456789Z')));
        $this->assertSame('2026-11-09T16:30:00.100Z', Iso::format(Iso::parse('2026-11-09T18:30:00.1+02:00')));
        $this->assertSame('2026-11-09 18:30:00.250', Iso::toDb(Iso::parse('2026-11-09T18:30:00.250Z')));
        $this->assertSame('2026-11-09T18:30:00.250Z', Iso::format(Iso::fromDb('2026-11-09 18:30:00.250')));

        foreach (['', 'yesterday', '2026-11-09', '2026-11-09T18:30:00', '2026-13-40T99:00:00Z', '2026-11-09 18:30:00Z'] as $bad) {
            $this->assertNull(Iso::parse($bad), $bad);
        }
    }

    public function testSubscriptionParsing(): void
    {
        $state = GooglePlayDeveloperApi::parse([
            'subscriptionState'          => 'SUBSCRIPTION_STATE_ACTIVE',
            'linkedPurchaseToken'        => 'old-token',
            'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => str_repeat('a', 64)],
            'lineItems'                  => [
                ['productId' => 'premium', 'expiryTime' => '2026-12-01T00:00:00.123456Z',
                    'offerDetails' => ['basePlanId' => 'monthly'], 'autoRenewingPlan' => ['autoRenewEnabled' => false]],
                ['productId' => 'premium', 'expiryTime' => '2027-11-11T10:00:00Z',
                    'offerDetails' => ['basePlanId' => 'yearly'], 'autoRenewingPlan' => ['autoRenewEnabled' => true]],
            ],
        ]);

        $this->assertSame(SubscriptionState::ACTIVE, $state->state);
        $this->assertSame('premium', $state->productId);
        $this->assertSame('yearly', $state->basePlanId);
        $this->assertSame('2027-11-11T10:00:00.000Z', Iso::format($state->expiresAt));
        $this->assertTrue($state->autoRenewing);
        $this->assertSame(str_repeat('a', 64), $state->accountHash);
        $this->assertSame('old-token', $state->linkedPurchaseToken);

        $empty = GooglePlayDeveloperApi::parse([]);
        $this->assertNull($empty->expiresAt);
        $this->assertFalse($empty->entitles(Iso::parse('2026-11-11T00:00:00Z')));
    }
}
