<?php

namespace App\Services\Premium;

use App\Libraries\Google\SubscriptionState;
use App\Libraries\Http\ApiProblem;
use App\Libraries\Time\Clock;
use App\Libraries\Time\Iso;
use CodeIgniter\Database\BaseConnection;
use Config\Yeastie;
use Throwable;

/**
 * Premium status of an account. Only the server writes it: from verified store purchases, RTDN and
 * the one-time trial. Clients read it through `GET /entitlement`.
 */
final class EntitlementService
{
    private const PLANS = ['yearly', 'monthly'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly Yeastie $config,
    ) {
    }

    /** The value Google keeps as `obfuscatedExternalAccountId`: lowercase hex SHA-256 of the UID. */
    public static function accountHash(string $uid): string
    {
        return hash('sha256', $uid);
    }

    /**
     * @return array<string, mixed> the contract's `Entitlement`
     */
    public function current(string $uid): array
    {
        $now       = $this->clock->now();
        $row       = $this->db->query('SELECT trial_started_at, trial_ends_at FROM entitlement WHERE user_id = ?', [$uid])->getRow();
        $purchases = $this->db->query(
            'SELECT base_plan_id, state, expires_at, auto_renewing FROM purchase WHERE user_id = ?',
            [$uid],
        )->getResult();

        $active = null;
        foreach ($purchases as $purchase) {
            $expires = Iso::fromDbOrNull($purchase->expires_at);
            if (! in_array($purchase->state, SubscriptionState::ENTITLED_STATES, true) || $expires === null || $expires <= $now) {
                continue;
            }
            if ($active === null || $expires > $active['expires']) {
                $active = ['row' => $purchase, 'expires' => $expires];
            }
        }
        $trialEnds = Iso::fromDbOrNull($row->trial_ends_at ?? null);

        if ($active !== null) {
            $plan        = in_array($active['row']->base_plan_id, self::PLANS, true) ? $active['row']->base_plan_id : null;
            $entitlement = ['tier' => 'premium', 'plan' => $plan, 'expires_at' => Iso::format($active['expires']),
                'auto_renewing' => (bool) $active['row']->auto_renewing];
        } elseif ($trialEnds !== null && $trialEnds > $now) {
            $entitlement = ['tier' => 'trial', 'plan' => null, 'expires_at' => Iso::format($trialEnds), 'auto_renewing' => false];
        } else {
            $entitlement = ['tier' => 'free', 'plan' => null, 'expires_at' => null, 'auto_renewing' => false];
        }

        return $entitlement + [
            'trial_available'   => ($row->trial_started_at ?? null) === null && $purchases === [],
            'cache_valid_until' => Iso::format($now->modify('+' . $this->config->entitlementCacheDays . ' days')),
        ];
    }

    /**
     * Starts the 14-day trial, once per account and never after a purchase.
     *
     * @return array<string, mixed>
     *
     * @throws ApiProblem 409 trial_already_used
     */
    public function startTrial(string $uid): array
    {
        $now = $this->clock->now();
        $this->db->transBegin();

        try {
            $this->ensureAccount($uid);
            $row = $this->db->query('SELECT trial_started_at FROM entitlement WHERE user_id = ? FOR UPDATE', [$uid])->getRow();
            $hasPurchase = $this->db->query('SELECT 1 FROM purchase WHERE user_id = ? LIMIT 1', [$uid])->getRow() !== null;
            if ($row->trial_started_at !== null || $hasPurchase) {
                throw ApiProblem::trialAlreadyUsed();
            }
            $this->db->query(
                'UPDATE entitlement SET trial_started_at = ?, trial_ends_at = ?, updated_at = ? WHERE user_id = ?',
                [Iso::toDb($now), Iso::toDb($now->modify('+' . $this->config->trialDays . ' days')), Iso::toDb($now), $uid],
            );
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $this->current($uid);
    }

    /** Creates the account's row, which links Google's account hash back to the UID for RTDN. */
    public function ensureAccount(string $uid): void
    {
        $this->db->query(
            'INSERT IGNORE INTO entitlement (user_id, account_hash, updated_at) VALUES (?, ?, ?)',
            [$uid, self::accountHash($uid), Iso::toDb($this->clock->now())],
        );
    }
}
