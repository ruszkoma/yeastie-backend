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
 * Google Play real-time developer notifications, pushed by Pub/Sub. The notification only says
 * that something changed; the state is read again from Google. Pub/Sub retries until it gets a
 * 2xx, so a failure to reach Google is answered with 503.
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
        if (! is_string($messageId) || $messageId === '' || strlen($messageId) > 128 || ! is_string($data)) {
            throw ApiProblem::validation('Not a Pub/Sub push message.');
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
