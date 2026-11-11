<?php

namespace App\Services\Sync;

use App\Libraries\Http\ApiProblem;
use App\Libraries\Time\Iso;
use DateTimeImmutable;
use stdClass;

/** One validated change of `POST /sync` (API contract, `ChangeIn`). */
final class ChangeIn
{
    private const MAX_ENTITY_ID_LENGTH = 64;

    /** The app derives the id from the change content and formats it like a UUID; any hex digits are fine. */
    private const CHANGE_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(
        public readonly string $clientChangeId,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly stdClass $payload,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?DateTimeImmutable $deletedAt,
    ) {
    }

    /**
     * @throws ApiProblem when the change is malformed
     */
    public static function fromJson(mixed $raw, int $index): self
    {
        $fail = static fn (string $why): ApiProblem => ApiProblem::validation("changes[{$index}]: {$why}");

        if (! $raw instanceof stdClass) {
            throw $fail('must be an object');
        }
        $changeId = is_string($raw->client_change_id ?? null) ? strtolower($raw->client_change_id) : null;
        if ($changeId === null || preg_match(self::CHANGE_ID_PATTERN, $changeId) !== 1) {
            throw $fail('client_change_id must be a UUID-shaped string');
        }
        $type = $raw->entity_type ?? null;
        if (! is_string($type) || ! EntityTypes::isKnown($type)) {
            throw $fail('unknown entity_type');
        }
        $id = $raw->entity_id ?? null;
        if (! is_string($id) || $id === '' || mb_strlen($id) > self::MAX_ENTITY_ID_LENGTH) {
            throw $fail('entity_id must be 1 to 64 characters');
        }
        $payload = $raw->payload ?? null;
        if (! $payload instanceof stdClass) {
            throw $fail('payload must be an object');
        }
        $updatedAt = is_string($raw->updated_at ?? null) ? Iso::parse($raw->updated_at) : null;
        if ($updatedAt === null) {
            throw $fail('updated_at must be an ISO 8601 time');
        }
        $deletedAt = null;
        if (isset($raw->deleted_at)) {
            $deletedAt = is_string($raw->deleted_at) ? Iso::parse($raw->deleted_at) : null;
            if ($deletedAt === null) {
                throw $fail('deleted_at must be an ISO 8601 time or null');
            }
        }
        if ($type === EntityTypes::COUNTER && ! self::isCount($payload->value ?? null)) {
            throw $fail('usage_counter payload.value must be a non-negative number');
        }

        return new self($changeId, $type, $id, $payload, $updatedAt, $deletedAt);
    }

    private static function isCount(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && $value >= 0;
    }
}
