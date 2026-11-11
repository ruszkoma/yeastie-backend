<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

/**
 * @internal
 */
final class SyncTest extends ApiTestCase
{
    private int $changeCounter = 0;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function change(string $type, string $id, array $payload, string $updatedAt, ?string $deletedAt = null, ?string $changeId = null): array
    {
        $this->changeCounter++;

        return [
            'client_change_id' => $changeId ?? sprintf('00000000-0000-4000-8000-%012d', $this->changeCounter),
            'entity_type'      => $type,
            'entity_id'        => $id,
            'payload'          => (object) $payload,
            'updated_at'       => $updatedAt,
            'deleted_at'       => $deletedAt,
        ];
    }

    /**
     * @param list<array<string, mixed>> $changes
     *
     * @return list<array<string, mixed>>
     */
    private function push(string $uid, array $changes): array
    {
        $response = $this->api('POST', 'v1/sync', $uid, ['changes' => $changes]);
        $response->assertStatus(200);

        return $this->json($response)['results'];
    }

    /**
     * @return array<string, mixed>
     */
    private function pull(string $uid, int $after = 0, ?int $limit = null): array
    {
        $response = $this->api('GET', 'v1/sync?after=' . $after . ($limit === null ? '' : '&limit=' . $limit), $uid);
        $response->assertStatus(200);

        return $this->json($response);
    }

    public function testPushedDocumentComesBackOnPull(): void
    {
        $payload = ['name' => 'Brewzilla 35 L', 'max_volume_l' => 35.0, 'boil_off_l_h' => 3.5, 'notes' => 'Zażółć', 'items' => [['a' => 1]], 'empty' => (object) []];
        $results = $this->push('user-a', [$this->change('brewhouse', 'bh-1', $payload, '2026-11-09T18:30:00.000Z')]);

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame(1, $results[0]['seq']);
        $this->assertArrayNotHasKey('server', $results[0]);

        $pulled = $this->pull('user-a');
        $this->assertFalse($pulled['has_more']);
        $this->assertSame(1, $pulled['next_after']);
        $this->assertSame('brewhouse', $pulled['changes'][0]['entity_type']);
        $this->assertSame('bh-1', $pulled['changes'][0]['entity_id']);
        $this->assertSame('2026-11-09T18:30:00.000Z', $pulled['changes'][0]['updated_at']);
        $this->assertNull($pulled['changes'][0]['deleted_at']);

        // Types and empty objects survive the round trip.
        $raw = $this->api('GET', 'v1/sync?after=0', 'user-a')->response()->getBody();
        $this->assertStringContainsString('"max_volume_l":35.0', $raw);
        $this->assertStringContainsString('"empty":{}', $raw);
        $this->assertStringContainsString('Zażółć', $raw);
    }

    public function testNewerChangeWinsAndOlderIsStale(): void
    {
        $this->push('user-a', [$this->change('recipe', 'r-1', ['name' => 'v1'], '2026-11-09T10:00:00.000Z')]);

        $newer = $this->push('user-a', [$this->change('recipe', 'r-1', ['name' => 'v2'], '2026-11-09T11:00:00.000Z')]);
        $this->assertSame('accepted', $newer[0]['status']);
        $this->assertSame(2, $newer[0]['seq']);

        $older = $this->push('user-a', [$this->change('recipe', 'r-1', ['name' => 'old'], '2026-11-09T10:30:00.000Z')]);
        $this->assertSame('stale', $older[0]['status']);
        $this->assertSame(2, $older[0]['seq']);
        $this->assertSame('v2', $older[0]['server']['payload']['name']);
        $this->assertSame('2026-11-09T11:00:00.000Z', $older[0]['server']['updated_at']);

        $pulled = $this->pull('user-a');
        $this->assertCount(1, $pulled['changes']);
        $this->assertSame('v2', $pulled['changes'][0]['payload']['name']);
    }

    public function testTieGoesToIncomingChange(): void
    {
        $this->push('user-a', [$this->change('setting', 'units.volume', ['value' => 'l'], '2026-11-09T10:00:00.000Z')]);
        $tie = $this->push('user-a', [$this->change('setting', 'units.volume', ['value' => 'gal'], '2026-11-09T10:00:00.000Z')]);

        $this->assertSame('accepted', $tie[0]['status']);
        $this->assertSame('gal', $this->pull('user-a')['changes'][0]['payload']['value']);
    }

    public function testTimesWithOffsetsAreNormalisedToUtc(): void
    {
        $this->push('user-a', [$this->change('fermenter', 'f-1', [], '2026-11-09T12:00:00.5+02:00')]);

        $this->assertSame('2026-11-09T10:00:00.500Z', $this->pull('user-a')['changes'][0]['updated_at']);
    }

    public function testSoftDeleteTravelsAsChange(): void
    {
        $this->push('user-a', [$this->change('water_profile', 'w-1', ['name' => 'Tap'], '2026-11-09T10:00:00.000Z')]);
        $this->push('user-a', [$this->change('water_profile', 'w-1', ['name' => 'Tap'], '2026-11-09T11:00:00.000Z', '2026-11-09T11:00:00.000Z')]);

        $pulled = $this->pull('user-a', 1);
        $this->assertCount(1, $pulled['changes']);
        $this->assertSame('2026-11-09T11:00:00.000Z', $pulled['changes'][0]['deleted_at']);
    }

    public function testAppendOnlyRowsMergeAndOnlyTakeDeletion(): void
    {
        $first = $this->push('user-a', [
            $this->change('batch_measurement', 'm-1', ['gravity' => 1.050], '2026-11-09T10:00:00.000Z'),
            $this->change('batch_measurement', 'm-2', ['gravity' => 1.020], '2026-11-09T09:00:00.000Z'),
        ]);
        $this->assertSame(['accepted', 'accepted'], array_column($first, 'status'));

        // An older edit of an existing row is not stale and does not change it.
        $edit = $this->push('user-a', [$this->change('batch_measurement', 'm-1', ['gravity' => 1.099], '2026-11-08T10:00:00.000Z')]);
        $this->assertSame('accepted', $edit[0]['status']);
        $this->assertSame($first[0]['seq'], $edit[0]['seq']);

        // A deletion is taken even with an older time, and keeps the original content.
        $delete = $this->push('user-a', [$this->change('batch_measurement', 'm-1', ['gravity' => 1.0], '2026-11-08T10:00:00.000Z', '2026-11-10T08:00:00.000Z')]);
        $this->assertSame('accepted', $delete[0]['status']);
        $this->assertSame(3, $delete[0]['seq']);

        $changes = array_column($this->pull('user-a')['changes'], null, 'entity_id');
        $this->assertSame(1.05, $changes['m-1']['payload']['gravity']);
        $this->assertSame('2026-11-10T08:00:00.000Z', $changes['m-1']['deleted_at']);
        $this->assertSame('2026-11-09T10:00:00.000Z', $changes['m-1']['updated_at']);
        $this->assertNull($changes['m-2']['deleted_at']);
    }

    public function testCounterMergesByLargerValue(): void
    {
        $this->push('user-a', [$this->change('usage_counter', 'generator.saved_recipes', ['value' => 2], '2026-11-09T10:00:00.000Z')]);

        // A reinstalled app with a lower count gets the server's value back.
        $lower = $this->push('user-a', [$this->change('usage_counter', 'generator.saved_recipes', ['value' => 0], '2026-11-10T10:00:00.000Z')]);
        $this->assertSame('stale', $lower[0]['status']);
        $this->assertSame(2, $lower[0]['server']['payload']['value']);

        $higher = $this->push('user-a', [$this->change('usage_counter', 'generator.saved_recipes', ['value' => 3], '2026-11-08T10:00:00.000Z')]);
        $this->assertSame('accepted', $higher[0]['status']);

        $counter = $this->pull('user-a')['changes'][0];
        $this->assertSame(3, $counter['payload']['value']);
        $this->assertSame('2026-11-09T10:00:00.000Z', $counter['updated_at']);
    }

    public function testRetryReturnsTheSameResultWithoutWritingAgain(): void
    {
        $change = $this->change('batch', 'b-1', ['status' => 'brewing'], '2026-11-09T10:00:00.000Z', null, 'abcdef01-2345-6789-abcd-ef0123456789');
        $first  = $this->push('user-a', [$change]);
        $retry  = $this->push('user-a', [$change]);

        $this->assertSame($first, $retry);
        $this->assertCount(1, $this->pull('user-a')['changes']);
        $this->assertSame(1, $this->pull('user-a')['next_after']);
    }

    public function testRetryOfStaleChangeReturnsCurrentServerCopy(): void
    {
        $this->push('user-a', [$this->change('recipe', 'r-1', ['name' => 'server'], '2026-11-09T12:00:00.000Z')]);
        $stale = $this->change('recipe', 'r-1', ['name' => 'old'], '2026-11-09T10:00:00.000Z');

        $this->assertSame('stale', $this->push('user-a', [$stale])[0]['status']);
        $retry = $this->push('user-a', [$stale]);
        $this->assertSame('stale', $retry[0]['status']);
        $this->assertSame('server', $retry[0]['server']['payload']['name']);
    }

    public function testUsersCannotSeeOrOverwriteEachOthersData(): void
    {
        $this->push('user-a', [$this->change('recipe', 'shared-id', ['name' => 'A'], '2026-11-09T10:00:00.000Z')]);
        $this->push('user-b', [$this->change('recipe', 'shared-id', ['name' => 'B'], '2026-11-09T11:00:00.000Z')]);

        $a = $this->pull('user-a');
        $b = $this->pull('user-b');
        $this->assertCount(1, $a['changes']);
        $this->assertSame('A', $a['changes'][0]['payload']['name']);
        $this->assertSame('B', $b['changes'][0]['payload']['name']);
        // Sequence numbers are per user.
        $this->assertSame(1, $b['changes'][0]['seq']);

        $this->assertSame([], $this->pull('user-c')['changes']);
    }

    public function testPullPagesInSequenceOrder(): void
    {
        $changes = [];
        for ($i = 1; $i <= 5; $i++) {
            $changes[] = $this->change('inventory_item', "i-{$i}", ['n' => $i], '2026-11-09T10:00:00.000Z');
        }
        $this->push('user-a', $changes);

        $page1 = $this->pull('user-a', 0, 2);
        $this->assertSame([1, 2], array_column($page1['changes'], 'seq'));
        $this->assertTrue($page1['has_more']);
        $this->assertSame(2, $page1['next_after']);

        $page3 = $this->pull('user-a', 4, 2);
        $this->assertSame([5], array_column($page3['changes'], 'seq'));
        $this->assertFalse($page3['has_more']);

        $empty = $this->pull('user-a', 5);
        $this->assertSame([], $empty['changes']);
        $this->assertSame(5, $empty['next_after']);
    }

    public function testClientCannotWriteEntitlementOrUnknownTypes(): void
    {
        foreach (['entitlement', 'purchase', 'sync_user', 'document'] as $type) {
            $response = $this->api('POST', 'v1/sync', 'user-a', ['changes' => [
                $this->change($type, 'x', ['tier' => 'premium'], '2026-11-09T10:00:00.000Z'),
            ]]);
            $this->assertProblem($response, 400, 'validation_failed');
        }
        $this->assertSame([], $this->pull('user-a')['changes']);
        $this->assertSame('free', $this->json($this->api('GET', 'v1/entitlement', 'user-a'))['tier']);
    }

    public function testOneInvalidChangeRejectsTheWholeRequest(): void
    {
        $response = $this->api('POST', 'v1/sync', 'user-a', ['changes' => [
            $this->change('recipe', 'r-1', ['name' => 'ok'], '2026-11-09T10:00:00.000Z'),
            $this->change('recipe', 'r-2', ['name' => 'bad time'], 'yesterday'),
        ]]);

        $this->assertProblem($response, 400, 'validation_failed');
        $this->assertSame([], $this->pull('user-a')['changes']);
    }

    public function testValidation(): void
    {
        $bad = [
            'not json'            => null,
            'changes not array'   => ['changes' => 'x'],
            'payload is a list'   => ['changes' => [array_merge($this->change('recipe', 'r', [], '2026-11-09T10:00:00.000Z'), ['payload' => [1, 2]])]],
            'long entity id'      => ['changes' => [$this->change('recipe', str_repeat('x', 65), [], '2026-11-09T10:00:00.000Z')]],
            'bad change id'       => ['changes' => [$this->change('recipe', 'r', [], '2026-11-09T10:00:00.000Z', null, 'not-a-uuid')]],
            'time without zone'   => ['changes' => [$this->change('recipe', 'r', [], '2026-11-09T10:00:00')]],
            'counter not numeric' => ['changes' => [$this->change('usage_counter', 'k', ['value' => 'many'], '2026-11-09T10:00:00.000Z')]],
        ];
        foreach ($bad as $case => $body) {
            $response = $body === null
                ? $this->withHeaders(['Authorization' => 'Bearer ' . $this->idToken('user-a')])->withBody('{oops')->call('POST', 'v1/sync')
                : $this->api('POST', 'v1/sync', 'user-a', $body);
            $this->assertSame(400, $response->response()->getStatusCode(), $case);
        }

        $this->assertProblem($this->api('GET', 'v1/sync', 'user-a'), 400, 'validation_failed');
        $this->assertProblem($this->api('GET', 'v1/sync?after=-1', 'user-a'), 400, 'validation_failed');
        $this->assertProblem($this->api('GET', 'v1/sync?after=0&limit=501', 'user-a'), 400, 'validation_failed');
        $this->assertProblem($this->api('GET', 'v1/sync?after=0&limit=0', 'user-a'), 400, 'validation_failed');
    }

    public function testRequestLimits(): void
    {
        config('Yeastie')->maxSyncChanges = 2;
        $three = [];
        for ($i = 0; $i < 3; $i++) {
            $three[] = $this->change('recipe', "r-{$i}", [], '2026-11-09T10:00:00.000Z');
        }
        $this->assertProblem($this->api('POST', 'v1/sync', 'user-a', ['changes' => $three]), 400, 'validation_failed');

        config('Yeastie')->maxSyncBytes = 1000;
        $big = $this->change('recipe', 'r-big', ['notes' => str_repeat('x', 2000)], '2026-11-09T10:00:00.000Z');
        $this->assertProblem($this->api('POST', 'v1/sync', 'user-a', ['changes' => [$big]]), 413, 'payload_too_large');
    }
}
