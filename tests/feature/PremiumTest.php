<?php

namespace Tests\Feature;

use App\Libraries\Google\PubSubMessage;
use App\Libraries\Google\SubscriptionState;
use DateTimeImmutable;
use Tests\Support\ApiTestCase;
use Tests\Support\TestKeys;

/**
 * @internal
 */
final class PremiumTest extends ApiTestCase
{
    private const PACKAGE = 'pl.cprojekt.yeastie';

    private function subscription(
        string $uid,
        string $state = SubscriptionState::ACTIVE,
        string $expires = '+1 year',
        string $plan = 'yearly',
        ?string $linked = null,
        string $product = 'premium',
    ): SubscriptionState {
        return new SubscriptionState(
            state: $state,
            productId: $product,
            basePlanId: $plan,
            expiresAt: $this->clock->now()->modify($expires),
            autoRenewing: $state === SubscriptionState::ACTIVE,
            accountHash: hash('sha256', $uid),
            linkedPurchaseToken: $linked,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyBody(string $token): array
    {
        return ['package_name' => self::PACKAGE, 'product_id' => 'premium', 'base_plan_id' => 'yearly', 'purchase_token' => $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function entitlement(string $uid): array
    {
        return $this->json($this->api('GET', 'v1/entitlement', $uid));
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function rtdn(array $notification, string $messageId = 'msg-1', ?string $token = null): \CodeIgniter\Test\TestResponse
    {
        $now     = $this->clock->now()->getTimestamp();
        $token ??= TestKeys::sign([
            'iss' => 'https://accounts.google.com', 'aud' => 'https://api.test/v1/rtdn',
            'email' => 'rtdn@yeastie-test.iam.gserviceaccount.com', 'email_verified' => true,
            'iat' => $now, 'exp' => $now + 3600, 'sub' => '123',
        ]);
        $body = [
            'message'      => ['data' => base64_encode(json_encode($notification + ['packageName' => self::PACKAGE])), 'messageId' => $messageId],
            'subscription' => 'projects/yeastie/subscriptions/rtdn',
        ];

        return $this->api('POST', 'v1/rtdn', null, $body, $token);
    }

    public function testNewAccountIsFreeWithTrialAvailable(): void
    {
        $entitlement = $this->entitlement('user-a');

        $this->assertSame('free', $entitlement['tier']);
        $this->assertNull($entitlement['plan']);
        $this->assertNull($entitlement['expires_at']);
        $this->assertFalse($entitlement['auto_renewing']);
        $this->assertTrue($entitlement['trial_available']);
        $this->assertSame('2026-11-18T12:00:00.000Z', $entitlement['cache_valid_until']);
    }

    public function testTrialRunsFourteenDaysOnce(): void
    {
        $trial = $this->json($this->api('POST', 'v1/trial', 'user-a'));
        $this->assertSame('trial', $trial['tier']);
        $this->assertSame('2026-11-25T12:00:00.000Z', $trial['expires_at']);
        $this->assertFalse($trial['trial_available']);

        $this->assertProblem($this->api('POST', 'v1/trial', 'user-a'), 409, 'trial_already_used');

        $this->clock->advance('+15 days');
        $after = $this->entitlement('user-a');
        $this->assertSame('free', $after['tier']);
        $this->assertFalse($after['trial_available']);

        // Another account has its own trial.
        $this->assertSame('trial', $this->json($this->api('POST', 'v1/trial', 'user-b'))['tier']);
    }

    public function testVerifiedPurchaseGrantsPremium(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a');

        $response = $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'));
        $response->assertStatus(200);
        $entitlement = $this->json($response);
        $this->assertSame('premium', $entitlement['tier']);
        $this->assertSame('yearly', $entitlement['plan']);
        $this->assertSame('2027-11-11T12:00:00.000Z', $entitlement['expires_at']);
        $this->assertTrue($entitlement['auto_renewing']);
        $this->assertFalse($entitlement['trial_available']);

        // Verifying again (restore on a new device) is fine for the same account.
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'))->assertStatus(200);

        // A purchase ends trial availability.
        $this->assertProblem($this->api('POST', 'v1/trial', 'user-a'), 409, 'trial_already_used');
    }

    public function testPurchaseOfAnotherAccountIsRejected(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'))->assertStatus(200);

        // Bound to user-a: user-b gets 409 before Google is even asked.
        $calls = $this->play->calls;
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-b', $this->verifyBody('tok-1')), 409, 'token_already_used');
        $this->assertSame($calls, $this->play->calls);
        $this->assertSame('free', $this->entitlement('user-b')['tier']);

        // Not bound yet, but Google says it was bought for user-a.
        $this->play->subscriptions['tok-2'] = $this->subscription('user-a');
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-b', $this->verifyBody('tok-2')), 422, 'purchase_invalid');
    }

    public function testInvalidPurchasesAreRejected(): void
    {
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('unknown')), 422, 'purchase_invalid');

        $this->play->subscriptions['expired'] = $this->subscription('user-a', SubscriptionState::EXPIRED, '-1 day');
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('expired')), 422, 'purchase_invalid');

        $this->play->subscriptions['on-hold'] = $this->subscription('user-a', SubscriptionState::ON_HOLD, '+1 day');
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('on-hold')), 422, 'purchase_invalid');

        $this->play->subscriptions['other-product'] = $this->subscription('user-a', product: 'coins');
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('other-product')), 422, 'purchase_invalid');

        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', ['package_name' => 'com.other'] + $this->verifyBody('x')), 400, 'validation_failed');
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', ['product_id' => 'coins'] + $this->verifyBody('x')), 400, 'validation_failed');
        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', ['purchase_token' => ''] + $this->verifyBody('x')), 400, 'validation_failed');

        $this->assertSame('free', $this->entitlement('user-a')['tier']);
        $this->assertTrue($this->entitlement('user-a')['trial_available']);
    }

    public function testCanceledSubscriptionKeepsPremiumUntilItEnds(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a', SubscriptionState::CANCELED, '+10 days', 'monthly');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'))->assertStatus(200);

        $entitlement = $this->entitlement('user-a');
        $this->assertSame('premium', $entitlement['tier']);
        $this->assertSame('monthly', $entitlement['plan']);
        $this->assertFalse($entitlement['auto_renewing']);

        $this->clock->advance('+11 days');
        $this->assertSame('free', $this->entitlement('user-a')['tier']);
    }

    public function testGoogleOutageIsServiceUnavailable(): void
    {
        $this->play->unavailable = true;

        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1')), 503, 'server_error');
    }

    public function testPlanChangeSupersedesOldPurchase(): void
    {
        $this->play->subscriptions['monthly'] = $this->subscription('user-a', expires: '+20 days', plan: 'monthly');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('monthly'))->assertStatus(200);

        $this->play->subscriptions['yearly'] = $this->subscription('user-a', expires: '+5 days', plan: 'yearly', linked: 'monthly');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('yearly'))->assertStatus(200);

        // The old monthly purchase no longer counts, even though its stored expiry is later.
        $entitlement = $this->entitlement('user-a');
        $this->assertSame('yearly', $entitlement['plan']);
        $this->assertSame('2026-11-16T12:00:00.000Z', $entitlement['expires_at']);
        $this->seeInDatabase('purchase', ['token_hash' => hash('sha256', 'monthly'), 'state' => 'SUPERSEDED']);
    }

    public function testRtdnRenewalAndExpiryUpdatePremium(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a', expires: '+1 month', plan: 'monthly');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'))->assertStatus(200);

        // Renewal.
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a', expires: '+2 months', plan: 'monthly');
        $this->rtdn(['subscriptionNotification' => ['notificationType' => 2, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']], 'm-1')
            ->assertStatus(204);
        $this->assertSame('2027-01-11T12:00:00.000Z', $this->entitlement('user-a')['expires_at']);

        // Expiry.
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a', SubscriptionState::EXPIRED, '-1 minute', 'monthly');
        $this->rtdn(['subscriptionNotification' => ['notificationType' => 13, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']], 'm-2')
            ->assertStatus(204);
        $this->assertSame('free', $this->entitlement('user-a')['tier']);
    }

    public function testRtdnRefundRevokesPremiumAndStaysRevoked(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'))->assertStatus(200);

        $this->rtdn(['voidedPurchaseNotification' => ['purchaseToken' => 'tok-1', 'orderId' => 'GPA.1', 'productType' => 1]], 'm-1')
            ->assertStatus(204);
        $this->assertSame('free', $this->entitlement('user-a')['tier']);

        // A later subscription notification must not bring a refunded purchase back.
        $this->rtdn(['subscriptionNotification' => ['notificationType' => 3, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']], 'm-2')
            ->assertStatus(204);
        $this->assertSame('free', $this->entitlement('user-a')['tier']);
    }

    public function testRtdnBindsPurchaseTheAppHasNotSentYet(): void
    {
        $this->entitlement('user-a');
        $this->api('POST', 'v1/trial', 'user-a');

        $this->play->subscriptions['tok-1'] = $this->subscription('user-a');
        $this->rtdn(['subscriptionNotification' => ['notificationType' => 4, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']])
            ->assertStatus(204);

        $this->assertSame('premium', $this->entitlement('user-a')['tier']);
    }

    public function testRtdnForUnknownAccountCanBeClaimedLaterByItsOwner(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-new');
        $this->rtdn(['subscriptionNotification' => ['notificationType' => 4, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']])
            ->assertStatus(204);

        $this->assertProblem($this->api('POST', 'v1/purchases/verify', 'user-other', $this->verifyBody('tok-1')), 409, 'token_already_used');
        $this->api('POST', 'v1/purchases/verify', 'user-new', $this->verifyBody('tok-1'))->assertStatus(200);
        $this->assertSame('premium', $this->entitlement('user-new')['tier']);
    }

    public function testRtdnDuplicatesTestsAndForeignPackagesAreAcknowledged(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a');
        $notification = ['subscriptionNotification' => ['notificationType' => 4, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']];

        $this->rtdn($notification, 'm-1')->assertStatus(204);
        $calls = $this->play->calls;
        $this->rtdn($notification, 'm-1')->assertStatus(204);
        $this->assertSame($calls, $this->play->calls);

        $this->rtdn(['testNotification' => ['version' => '1.0']], 'm-2')->assertStatus(204);
        $this->rtdn(['packageName' => 'com.other'] + $notification, 'm-3')->assertStatus(204);
        $this->assertSame($calls, $this->play->calls);
    }

    public function testRtdnRetriesWhenGoogleIsDown(): void
    {
        $this->play->unavailable = true;
        $notification = ['subscriptionNotification' => ['notificationType' => 4, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']];

        $this->assertProblem($this->rtdn($notification, 'm-1'), 503, 'server_error');

        // Not recorded, so the redelivery is processed.
        $this->play->unavailable = false;
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a');
        $this->entitlement('user-a');
        $this->api('POST', 'v1/trial', 'user-a');
        $this->rtdn($notification, 'm-1')->assertStatus(204);
        $this->assertSame('premium', $this->entitlement('user-a')['tier']);
    }

    public function testRtdnRequiresGooglePushToken(): void
    {
        $now    = $this->clock->now()->getTimestamp();
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'https://api.test/v1/rtdn',
            'email' => 'rtdn@yeastie-test.iam.gserviceaccount.com', 'email_verified' => true, 'iat' => $now, 'exp' => $now + 3600];
        $notification = ['testNotification' => ['version' => '1.0']];

        $this->assertProblem($this->rtdn($notification, 'a', TestKeys::sign(['aud' => 'https://evil.test'] + $claims)), 401, 'unauthorized');
        $this->assertProblem($this->rtdn($notification, 'b', TestKeys::sign(['email' => 'someone@evil.test'] + $claims)), 401, 'unauthorized');
        $this->assertProblem($this->rtdn($notification, 'c', TestKeys::sign(['email_verified' => false] + $claims)), 401, 'unauthorized');
        $this->assertProblem($this->rtdn($notification, 'd', TestKeys::sign(['iss' => 'https://evil.test'] + $claims)), 401, 'unauthorized');
        $this->assertProblem($this->rtdn($notification, 'e', TestKeys::sign($claims, 'stranger')), 401, 'unauthorized');
        $this->rtdn($notification, 'f', TestKeys::sign($claims))->assertStatus(204);
    }

    public function testPulledRtdnUpdatesPremiumAndIsAcknowledged(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a', expires: '+1 month', plan: 'monthly');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'))->assertStatus(200);

        $this->play->subscriptions['tok-1'] = $this->subscription('user-a', expires: '+2 months', plan: 'monthly');
        $renewal = ['subscriptionNotification' => ['notificationType' => 2, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']];
        $this->pubSub->publish('m-1', ['packageName' => self::PACKAGE] + $renewal);
        $this->pubSub->publish('m-2', ['packageName' => self::PACKAGE, 'testNotification' => ['version' => '1.0']]);
        $this->pubSub->publish('m-3', ['packageName' => 'com.other'] + $renewal);
        $this->pubSub->waiting['ack-bad'] = new PubSubMessage('ack-bad', 'm-4', 'not base64 json');
        $this->pubSub->waiting['ack-id']  = new PubSubMessage('ack-id', str_repeat('x', 200), '');

        command('yeastie:rtdn-pull');

        $this->assertSame('2027-01-11T12:00:00.000Z', $this->entitlement('user-a')['expires_at']);
        $this->assertSame([], $this->pubSub->waiting);

        // A message Pub/Sub delivers twice is applied once.
        $calls = $this->play->calls;
        $this->pubSub->publish('m-1', ['packageName' => self::PACKAGE] + $renewal);
        command('yeastie:rtdn-pull');
        $this->assertSame($calls, $this->play->calls);
        $this->assertSame([], $this->pubSub->waiting);
    }

    public function testPulledRtdnDrainsMoreThanOneBatch(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $this->pubSub->publish("m-{$i}", ['packageName' => self::PACKAGE, 'testNotification' => ['version' => '1.0']]);
        }

        command('yeastie:rtdn-pull');

        $this->assertSame([], $this->pubSub->waiting);
    }

    public function testPulledRtdnStaysQueuedWhileGoogleIsDown(): void
    {
        $this->entitlement('user-a');
        $this->api('POST', 'v1/trial', 'user-a');
        $purchase = ['subscriptionNotification' => ['notificationType' => 4, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'premium']];
        $this->pubSub->publish('m-1', ['packageName' => self::PACKAGE, 'testNotification' => ['version' => '1.0']]);
        $this->pubSub->publish('m-2', ['packageName' => self::PACKAGE] + $purchase);
        $this->pubSub->publish('m-3', ['packageName' => self::PACKAGE, 'testNotification' => ['version' => '1.0']]);

        // Play is down: what was applied before the failure is acknowledged, the rest waits.
        $this->play->unavailable = true;
        command('yeastie:rtdn-pull');
        $this->assertSame(['ack-m-2', 'ack-m-3'], array_keys($this->pubSub->waiting));
        $this->assertSame('trial', $this->entitlement('user-a')['tier']);

        // Pub/Sub is down: nothing is lost.
        $this->play->unavailable   = false;
        $this->pubSub->unavailable = true;
        command('yeastie:rtdn-pull');
        $this->assertCount(2, $this->pubSub->waiting);

        $this->pubSub->unavailable          = false;
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a');
        command('yeastie:rtdn-pull');
        $this->assertSame([], $this->pubSub->waiting);
        $this->assertSame('premium', $this->entitlement('user-a')['tier']);
    }

    public function testExpiredTrialAndExpiredPremiumKeepNoAccess(): void
    {
        $this->play->subscriptions['tok-1'] = $this->subscription('user-a', expires: '+3 days');
        $this->api('POST', 'v1/purchases/verify', 'user-a', $this->verifyBody('tok-1'))->assertStatus(200);
        $this->clock->advance('+4 days');

        $entitlement = $this->entitlement('user-a');
        $this->assertSame('free', $entitlement['tier']);
        $this->assertFalse($entitlement['trial_available']);
        $this->assertSame((new DateTimeImmutable('2026-11-22T12:00:00Z'))->format('Y-m-d'), substr($entitlement['cache_valid_until'], 0, 10));
    }
}
