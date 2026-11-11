<?php

namespace App\Services\Sync;

use App\Libraries\Time\Clock;
use App\Libraries\Time\Iso;
use CodeIgniter\Database\BaseConnection;
use stdClass;
use Throwable;

/**
 * Sync of user documents (API contract, `/sync`). The server keeps payloads as opaque JSON and
 * decides only by `updated_at`, `deleted_at` and, for counters, `payload.value`.
 */
final class SyncService
{
    public const ACCEPTED = 'accepted';
    public const STALE    = 'stale';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The owner's documents after sequence number `$after`, in sequence order.
     *
     * @return array{changes: list<array<string, mixed>>, next_after: int, has_more: bool}
     */
    public function pull(string $uid, int $after, int $limit): array
    {
        $rows = $this->db->query(
            'SELECT entity_type, entity_id, payload, updated_at, deleted_at, seq FROM document
             WHERE user_id = ? AND seq > ? ORDER BY seq LIMIT ?',
            [$uid, $after, $limit + 1],
        )->getResult();

        $hasMore = count($rows) > $limit;
        $rows    = array_slice($rows, 0, $limit);
        $changes = array_map(self::change(...), $rows);

        return [
            'changes'    => $changes,
            'next_after' => $rows === [] ? $after : (int) end($rows)->seq,
            'has_more'   => $hasMore,
        ];
    }

    /**
     * Applies the changes in one transaction and returns one result per change, in order.
     *
     * @param list<ChangeIn> $changes
     *
     * @return list<array<string, mixed>>
     */
    public function push(string $uid, array $changes): array
    {
        $this->db->transBegin();

        try {
            // Locking the user's row serialises their writes; sequence numbers become visible in order.
            $this->db->query('INSERT IGNORE INTO sync_user (user_id, last_seq) VALUES (?, 0)', [$uid]);
            $seq = (int) $this->db->query('SELECT last_seq FROM sync_user WHERE user_id = ? FOR UPDATE', [$uid])
                ->getRow()->last_seq;
            $startSeq = $seq;

            $results = [];
            foreach ($changes as $change) {
                $results[] = $this->apply($uid, $change, $seq);
            }

            if ($seq !== $startSeq) {
                $this->db->query('UPDATE sync_user SET last_seq = ? WHERE user_id = ?', [$seq, $uid]);
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(string $uid, ChangeIn $change, int &$seq): array
    {
        $applied = $this->db->query(
            'SELECT status, seq FROM applied_change WHERE user_id = ? AND client_change_id = ?',
            [$uid, $change->clientChangeId],
        )->getRow();
        if ($applied !== null) {
            // A retry after a lost response: answer as before, with the current server copy if stale.
            $server = $applied->status === self::STALE ? $this->current($uid, $change) : null;

            return $this->result($change, $applied->status, (int) $applied->seq, $server);
        }

        $current = $this->current($uid, $change);
        if (in_array($change->entityType, EntityTypes::APPEND_ONLY, true)) {
            [$status, $resultSeq] = $this->applyAppendOnly($uid, $change, $current, $seq);
        } elseif ($change->entityType === EntityTypes::COUNTER) {
            [$status, $resultSeq] = $this->applyCounter($uid, $change, $current, $seq);
        } else {
            [$status, $resultSeq] = $this->applyDocument($uid, $change, $current, $seq);
        }

        $this->db->query(
            'INSERT INTO applied_change (user_id, client_change_id, entity_type, entity_id, status, seq, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uid, $change->clientChangeId, $change->entityType, $change->entityId, $status, $resultSeq,
                Iso::toDb($this->clock->now())],
        );

        return $this->result($change, $status, $resultSeq, $status === self::STALE ? $current : null);
    }

    /**
     * Last write wins by `updated_at` of the whole document. On a tie the incoming change wins.
     *
     * @return array{0: string, 1: int}
     */
    private function applyDocument(string $uid, ChangeIn $change, ?stdClass $current, int &$seq): array
    {
        if ($current !== null && Iso::fromDb($current->updated_at) > $change->updatedAt) {
            return [self::STALE, (int) $current->seq];
        }
        $this->write($uid, $change, $this->encode($change->payload), $change->updatedAt, $change->deletedAt, ++$seq);

        return [self::ACCEPTED, $seq];
    }

    /**
     * Inserted once; afterwards only a deletion is taken. Never stale.
     *
     * @return array{0: string, 1: int}
     */
    private function applyAppendOnly(string $uid, ChangeIn $change, ?stdClass $current, int &$seq): array
    {
        if ($current === null) {
            $this->write($uid, $change, $this->encode($change->payload), $change->updatedAt, $change->deletedAt, ++$seq);

            return [self::ACCEPTED, $seq];
        }
        if ($change->deletedAt !== null && $current->deleted_at === null) {
            $updatedAt = max(Iso::fromDb($current->updated_at), $change->updatedAt);
            $this->write($uid, $change, $current->payload, $updatedAt, $change->deletedAt, ++$seq);

            return [self::ACCEPTED, $seq];
        }

        return [self::ACCEPTED, (int) $current->seq];
    }

    /**
     * The larger `payload.value` wins, so a reinstall cannot reset a free-plan limit.
     *
     * @return array{0: string, 1: int}
     */
    private function applyCounter(string $uid, ChangeIn $change, ?stdClass $current, int &$seq): array
    {
        if ($current === null) {
            $this->write($uid, $change, $this->encode($change->payload), $change->updatedAt, null, ++$seq);

            return [self::ACCEPTED, $seq];
        }
        $serverValue = json_decode($current->payload, false)->value ?? 0;
        $serverValue = is_int($serverValue) || is_float($serverValue) ? $serverValue : 0;
        $value       = $change->payload->value;

        if ($value > $serverValue) {
            $updatedAt = max(Iso::fromDb($current->updated_at), $change->updatedAt);
            $this->write($uid, $change, $this->encode($change->payload), $updatedAt, null, ++$seq);

            return [self::ACCEPTED, $seq];
        }
        if ($value < $serverValue) {
            return [self::STALE, (int) $current->seq];
        }

        return [self::ACCEPTED, (int) $current->seq];
    }

    private function current(string $uid, ChangeIn $change): ?stdClass
    {
        return $this->db->query(
            'SELECT entity_type, entity_id, payload, updated_at, deleted_at, seq FROM document
             WHERE user_id = ? AND entity_type = ? AND entity_id = ?',
            [$uid, $change->entityType, $change->entityId],
        )->getRow();
    }

    private function write(
        string $uid,
        ChangeIn $change,
        string $payload,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $deletedAt,
        int $seq,
    ): void {
        $this->db->query(
            'INSERT INTO document (user_id, entity_type, entity_id, payload, updated_at, deleted_at, seq)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at),
                 deleted_at = VALUES(deleted_at), seq = VALUES(seq)',
            [$uid, $change->entityType, $change->entityId, $payload, Iso::toDb($updatedAt), Iso::toDbOrNull($deletedAt), $seq],
        );
    }

    private function encode(stdClass $payload): string
    {
        return json_encode($payload, self::JSON_FLAGS);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(ChangeIn $change, string $status, int $seq, ?stdClass $server): array
    {
        $result = [
            'client_change_id' => $change->clientChangeId,
            'entity_type'      => $change->entityType,
            'entity_id'        => $change->entityId,
            'status'           => $status,
            'seq'              => $seq,
        ];
        if ($server !== null) {
            $result['server'] = self::change($server);
        }

        return $result;
    }

    /**
     * A stored document as the contract's `Change`.
     *
     * @return array<string, mixed>
     */
    private static function change(stdClass $row): array
    {
        return [
            'entity_type' => $row->entity_type,
            'entity_id'   => $row->entity_id,
            'payload'     => json_decode($row->payload, false, 512, JSON_THROW_ON_ERROR),
            'updated_at'  => Iso::format(Iso::fromDb($row->updated_at)),
            'deleted_at'  => Iso::formatOrNull(Iso::fromDbOrNull($row->deleted_at)),
            'seq'         => (int) $row->seq,
        ];
    }
}
