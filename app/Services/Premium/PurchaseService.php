<?php

namespace App\Services\Premium;

use App\Libraries\Google\GoogleUnavailable;
use App\Libraries\Google\PlayDeveloperApi;
use App\Libraries\Google\SubscriptionState;
use App\Libraries\Http\ApiProblem;
use App\Libraries\Time\Clock;
use App\Libraries\Time\Iso;
use CodeIgniter\Database\BaseConnection;
use Config\Yeastie;
use stdClass;
use Throwable;

/**
 * Google Play subscriptions. A purchase token belongs to at most one Yeastie account: the account
 * whose UID hash Google holds as `obfuscatedExternalAccountId`. The state always comes from Google,
 * never from the client.
 */
final class PurchaseService
{
    public const REVOKED    = 'REVOKED';
    public const SUPERSEDED = 'SUPERSEDED';

    private const MAX_TOKEN_LENGTH = 4096;
    private const LAPSED_WINDOW_DAYS   = 3;
    private const LAPSED_RETRY_SECONDS = 60;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly PlayDeveloperApi $play,
        private readonly EntitlementService $entitlements,
        private readonly Clock $clock,
        private readonly Yeastie $config,
    ) {
    }

    /**
     * `POST /purchases/verify`: checks the purchase with Google and binds it to the account.
     *
     * @return array<string, mixed> the account's entitlement
     *
     * @throws ApiProblem
     * @throws GoogleUnavailable
     */
    public function verify(string $uid, stdClass $body): array
    {
        $packageName = $body->package_name ?? null;
        $productId   = $body->product_id ?? null;
        $token       = $body->purchase_token ?? null;
        if ($packageName !== $this->config->playPackageName) {
            throw ApiProblem::validation('Unknown package_name.');
        }
        if ($productId !== $this->config->premiumProductId) {
            throw ApiProblem::validation('Unknown product_id.');
        }
        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw ApiProblem::validation('purchase_token is required.');
        }

        $accountHash = EntitlementService::accountHash($uid);
        $this->assertClaimable($this->find($token), $uid, $accountHash);

        $state = $this->play->subscription($packageName, $token);
        if ($state === null) {
            throw ApiProblem::purchaseInvalid('Google does not know this purchase.');
        }
        if ($state->productId !== $this->config->premiumProductId) {
            throw ApiProblem::purchaseInvalid('The purchase is for another product.');
        }
        if ($state->accountHash !== $accountHash) {
            throw ApiProblem::purchaseInvalid('The purchase was made for another account.');
        }
        if (! $state->entitles($this->clock->now())) {
            throw ApiProblem::purchaseInvalid('The subscription is not active.');
        }

        $this->db->transBegin();

        try {
            $this->entitlements->ensureAccount($uid);
            // Checked again under the row lock, in case another account claimed it meanwhile.
            $this->assertClaimable($this->find($token, true), $uid, $accountHash);
            $this->save($token, $uid, $accountHash, $state);
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $this->entitlements->current($uid);
    }

    /**
     * Reads the subscription from Google after an RTDN and stores its state. A token the app has
     * not sent yet is bound by the account hash, if that account exists.
     *
     * @throws GoogleUnavailable
     */
    public function refresh(string $token): void
    {
        $state = $this->play->subscription($this->config->playPackageName, $token);
        if ($state === null || $state->productId !== $this->config->premiumProductId) {
            return;
        }

        $this->db->transBegin();

        try {
            $existing = $this->find($token, true);
            if ($existing !== null && $existing->user_id === null && $existing->account_hash === null) {
                // Released by account deletion: keep the state current but never bind it again.
                $this->save($token, null, null, $state);
            } else {
                $owner = $existing->user_id ?? $this->ownerOf($state->accountHash);
                $this->save($token, $owner, $state->accountHash, $state);
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /**
     * `GET /entitlement` calls this first. A renewal reaches the API only with the next RTDN pull,
     * so right after the paid period ends a subscription that renews looks expired here. Such
     * purchases are read from Google again, at most once per [LAPSED_RETRY_SECONDS] each.
     *
     * Never fails the request: when Google is down the stored state answers.
     */
    public function refreshLapsed(string $uid): void
    {
        $now  = $this->clock->now();
        $rows = $this->db->query(
            'SELECT purchase_token FROM purchase
             WHERE user_id = ? AND auto_renewing = 1 AND state IN ? AND expires_at <= ? AND expires_at > ? AND updated_at <= ?',
            [$uid, SubscriptionState::ENTITLED_STATES, Iso::toDb($now), Iso::toDb($now->modify('-' . self::LAPSED_WINDOW_DAYS . ' days')),
                Iso::toDb($now->modify('-' . self::LAPSED_RETRY_SECONDS . ' seconds'))],
        )->getResult();

        foreach ($rows as $row) {
            try {
                $this->refresh($row->purchase_token);
            } catch (GoogleUnavailable $e) {
                log_message('error', 'Lapsed purchase not refreshed: {message}', ['message' => $e->getMessage()]);

                return;
            }
        }
    }

    /** A refunded or charged-back purchase (`voidedPurchaseNotification`) ends premium at once. */
    public function revoke(string $token): void
    {
        $now = Iso::toDb($this->clock->now());
        $this->db->query(
            'UPDATE purchase SET state = ?, expires_at = LEAST(COALESCE(expires_at, ?), ?), auto_renewing = 0, updated_at = ?
             WHERE token_hash = ?',
            [self::REVOKED, $now, $now, $now, self::tokenHash($token)],
        );
    }

    private function assertClaimable(?stdClass $existing, string $uid, string $accountHash): void
    {
        if ($existing === null || $existing->user_id === $uid) {
            return;
        }
        // Stored from an RTDN before the app sent it: the account hash says whose it is.
        if ($existing->user_id === null && $existing->account_hash === $accountHash) {
            return;
        }

        throw ApiProblem::tokenAlreadyUsed();
    }

    private function find(string $token, bool $lock = false): ?stdClass
    {
        return $this->db->query(
            'SELECT user_id, account_hash FROM purchase WHERE token_hash = ?' . ($lock ? ' FOR UPDATE' : ''),
            [self::tokenHash($token)],
        )->getRow();
    }

    private function ownerOf(?string $accountHash): ?string
    {
        if ($accountHash === null) {
            return null;
        }

        return $this->db->query('SELECT user_id FROM entitlement WHERE account_hash = ?', [$accountHash])->getRow()->user_id ?? null;
    }

    private function save(string $token, ?string $uid, ?string $accountHash, SubscriptionState $state): void
    {
        $now        = Iso::toDb($this->clock->now());
        $linkedHash = $state->linkedPurchaseToken === null ? null : self::tokenHash($state->linkedPurchaseToken);
        $this->db->query(
            'INSERT INTO purchase (token_hash, purchase_token, user_id, account_hash, product_id, base_plan_id, state,
                 expires_at, auto_renewing, linked_token_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), account_hash = VALUES(account_hash),
                 base_plan_id = VALUES(base_plan_id),
                 state = IF(state = ?, state, VALUES(state)),
                 expires_at = IF(state = ?, expires_at, VALUES(expires_at)),
                 auto_renewing = VALUES(auto_renewing), linked_token_hash = VALUES(linked_token_hash),
                 updated_at = VALUES(updated_at)',
            [self::tokenHash($token), $token, $uid, $accountHash, (string) $state->productId, $state->basePlanId,
                $state->state, Iso::toDbOrNull($state->expiresAt), $state->autoRenewing ? 1 : 0, $linkedHash, $now, $now,
                self::REVOKED, self::REVOKED],
        );
        if ($linkedHash !== null) {
            // A plan change replaces the old purchase; it must not keep premium alive on its own.
            $this->db->query(
                'UPDATE purchase SET state = ?, auto_renewing = 0, updated_at = ? WHERE token_hash = ? AND state <> ?',
                [self::SUPERSEDED, $now, $linkedHash, self::REVOKED],
            );
        }
    }

    private static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
