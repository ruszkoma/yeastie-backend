<?php

namespace Tests\Feature;

use App\Libraries\Google\SubscriptionState;
use Tests\Support\ApiTestCase;

/**
 * @internal
 */
final class AccountTest extends ApiTestCase
{
    private function seedAccount(string $uid): void
    {
        $this->api('POST', 'v1/sync', $uid, ['changes' => [[
            'client_change_id' => '11111111-2222-4333-8444-' . substr(hash('sha256', $uid), 0, 12),
            'entity_type'      => 'recipe',
            'entity_id'        => 'r-1',
            'payload'          => ['name' => 'Pils'],
            'updated_at'       => '2026-11-09T10:00:00.000Z',
        ]]])->assertStatus(200);
        $this->play->subscriptions["tok-{$uid}"] = new SubscriptionState(
            SubscriptionState::ACTIVE, 'premium', 'yearly', $this->clock->now()->modify('+1 year'), true, hash('sha256', $uid), null,
        );
        $this->api('POST', 'v1/purchases/verify', $uid, [
            'package_name' => 'pl.cprojekt.yeastie', 'product_id' => 'premium', 'purchase_token' => "tok-{$uid}",
        ])->assertStatus(200);
    }

    public function testDeleteRemovesDataAndFirebaseAccount(): void
    {
        $this->seedAccount('user-a');
        $this->seedAccount('user-b');

        $this->api('DELETE', 'v1/me', 'user-a')->assertStatus(204);

        $this->assertSame(['user-a'], $this->firebase->deleted);
        foreach (['document', 'applied_change', 'sync_user', 'entitlement'] as $table) {
            $this->dontSeeInDatabase($table, ['user_id' => 'user-a']);
            $this->seeInDatabase($table, ['user_id' => 'user-b']);
        }
        // The purchase stays, unbound and without the account hash.
        $this->seeInDatabase('purchase', ['token_hash' => hash('sha256', 'tok-user-a'), 'user_id' => null, 'account_hash' => null]);
        $this->seeInDatabase('purchase', ['token_hash' => hash('sha256', 'tok-user-b'), 'user_id' => 'user-b']);
    }

    public function testReleasedPurchaseCannotMoveToAnotherAccount(): void
    {
        $this->seedAccount('user-a');
        $this->api('DELETE', 'v1/me', 'user-a')->assertStatus(204);

        $this->play->subscriptions['tok-user-a'] = new SubscriptionState(
            SubscriptionState::ACTIVE, 'premium', 'yearly', $this->clock->now()->modify('+1 year'), true, hash('sha256', 'user-c'), null,
        );
        $response = $this->api('POST', 'v1/purchases/verify', 'user-c', [
            'package_name' => 'pl.cprojekt.yeastie', 'product_id' => 'premium', 'purchase_token' => 'tok-user-a',
        ]);
        $this->assertProblem($response, 409, 'token_already_used');

        // RTDN for the released purchase keeps it unbound.
        service('purchaseService')->refresh('tok-user-a');
        $this->seeInDatabase('purchase', ['token_hash' => hash('sha256', 'tok-user-a'), 'user_id' => null, 'account_hash' => null]);
    }

    public function testFirebaseFailureKeepsEverything(): void
    {
        $this->seedAccount('user-a');
        $this->firebase->unavailable = true;

        $this->assertProblem($this->api('DELETE', 'v1/me', 'user-a'), 503, 'server_error');

        $this->seeInDatabase('document', ['user_id' => 'user-a']);
        $this->seeInDatabase('purchase', ['user_id' => 'user-a']);

        $this->firebase->unavailable = false;
        $this->api('DELETE', 'v1/me', 'user-a')->assertStatus(204);
        $this->dontSeeInDatabase('document', ['user_id' => 'user-a']);
    }

    public function testDeletingAgainIsHarmless(): void
    {
        $this->api('DELETE', 'v1/me', 'user-a')->assertStatus(204);
        $this->api('DELETE', 'v1/me', 'user-a')->assertStatus(204);
    }

    public function testCleanupDropsOldRecords(): void
    {
        $this->seedAccount('user-a');
        $this->db->query("INSERT INTO rtdn_event (message_id, received_at) VALUES ('old', '2026-09-01 00:00:00.000'), ('new', '2026-11-10 00:00:00.000')");
        $this->db->query("UPDATE applied_change SET created_at = '2026-09-01 00:00:00.000'");

        command('yeastie:cleanup');

        $this->dontSeeInDatabase('applied_change', ['user_id' => 'user-a']);
        $this->dontSeeInDatabase('rtdn_event', ['message_id' => 'old']);
        $this->seeInDatabase('rtdn_event', ['message_id' => 'new']);
        // Documents are never cleaned up.
        $this->seeInDatabase('document', ['user_id' => 'user-a']);
    }
}
