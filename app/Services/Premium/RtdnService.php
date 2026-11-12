<?php

namespace App\Services\Premium;

use App\Libraries\Google\GoogleUnavailable;
use App\Libraries\Http\ApiProblem;
use App\Libraries\Time\Clock;
use App\Libraries\Time\Iso;
use CodeIgniter\Database\BaseConnection;
use Config\Yeastie;
use stdClass;

/**
 * Google Play real-time developer notifications from Pub/Sub, pulled by `yeastie:rtdn-pull` or
 * pushed to `POST /rtdn`. The notification only says that something changed; the state is read
 * again from Google, so the order of notifications does not matter. Pub/Sub redelivers until the
 * message is acknowledged (a 2xx for a push), so a failure to reach Google leaves it unacknowledged.
 */
final class RtdnService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly PurchaseService $purchases,
        private readonly Clock $clock,
        private readonly Yeastie $config,
    ) {
    }

    /**
     * @throws ApiProblem when the push body is malformed
     * @throws GoogleUnavailable
     */
    public function handle(stdClass $push): void
    {
        $messageId = $push->message->messageId ?? null;
        $data      = $push->message->data ?? null;
        if (! is_string($messageId) || ! is_string($data)) {
            throw ApiProblem::validation('Not a Pub/Sub push message.');
        }
        $this->handleMessage($messageId, $data);
    }

    /**
     * One Pub/Sub message: `$data` is the base64 notification. Returning normally means the
     * message is done and can be acknowledged.
     *
     * @throws ApiProblem when the message id is unusable
     * @throws GoogleUnavailable
     */
    public function handleMessage(string $messageId, string $data): void
    {
        if ($messageId === '' || strlen($messageId) > 128) {
            throw ApiProblem::validation('Not a Pub/Sub message.');
        }
        if ($this->db->query('SELECT 1 FROM rtdn_event WHERE message_id = ?', [$messageId])->getRow() !== null) {
            return;
        }

        $notification = json_decode((string) base64_decode($data, true), true);
        if (is_array($notification) && ($notification['packageName'] ?? null) === $this->config->playPackageName) {
            $this->process($notification);
        }
        // Unreadable or foreign notifications are acknowledged too, so Pub/Sub stops redelivering them.
        $this->db->query(
            'INSERT IGNORE INTO rtdn_event (message_id, received_at) VALUES (?, ?)',
            [$messageId, Iso::toDb($this->clock->now())],
        );
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function process(array $notification): void
    {
        $subscription = $notification['subscriptionNotification'] ?? null;
        if (is_array($subscription) && is_string($subscription['purchaseToken'] ?? null)) {
            $this->purchases->refresh($subscription['purchaseToken']);

            return;
        }
        $voided = $notification['voidedPurchaseNotification'] ?? null;
        if (is_array($voided) && is_string($voided['purchaseToken'] ?? null)) {
            $this->purchases->revoke($voided['purchaseToken']);
        }
        // testNotification and one-time product notifications need nothing.
    }
}
