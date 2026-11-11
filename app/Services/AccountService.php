<?php

namespace App\Services;

use App\Libraries\Google\FirebaseAdmin;
use App\Libraries\Google\GoogleUnavailable;
use App\Services\Premium\EntitlementService;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * `DELETE /me`: removes the account's data and the Firebase account. Store subscriptions are not
 * cancelled (the app tells the user); their tokens stay, unbound, so they cannot move to another
 * account.
 */
final class AccountService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FirebaseAdmin $firebase,
    ) {
    }

    /**
     * Everything or nothing: if Firebase refuses, the data stays and the app can retry.
     *
     * @throws GoogleUnavailable
     */
    public function delete(string $uid): void
    {
        $this->db->transBegin();

        try {
            foreach (['document', 'applied_change', 'sync_user', 'entitlement'] as $table) {
                $this->db->query("DELETE FROM {$table} WHERE user_id = ?", [$uid]);
            }
            $this->db->query(
                'UPDATE purchase SET user_id = NULL, account_hash = NULL WHERE user_id = ? OR account_hash = ?',
                [$uid, EntitlementService::accountHash($uid)],
            );
            $this->firebase->deleteUser($uid);
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }
}
