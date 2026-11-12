<?php

namespace App\Libraries\Google;

use CodeIgniter\HTTP\CURLRequest;
use Closure;
use Throwable;

/**
 * `subscriptions.pull` and `subscriptions.acknowledge` of the Pub/Sub REST API v1.
 *
 * Pulls ask for an immediate answer (`returnImmediately`), because a cron run must not hang
 * waiting for messages. Such a pull can come back empty although messages are waiting, so an
 * empty answer is asked again a few times before it is believed.
 */
final class GooglePubSub implements PubSub
{
    private const URL            = 'https://pubsub.googleapis.com/v1/%s:%s';
    private const EMPTY_ATTEMPTS = 3;

    /**
     * @param string                $subscription `projects/<project>/subscriptions/<name>`
     * @param Closure(): CURLRequest $http        a fresh client per request
     */
    public function __construct(
        private readonly ServiceAccountTokens $tokens,
        private readonly Closure $http,
        private readonly string $subscription,
        private readonly int $pauseMicroseconds = 1_000_000,
    ) {
    }

    public function pull(int $max): array
    {
        for ($attempt = 1; ; $attempt++) {
            $body     = $this->call('pull', ['maxMessages' => $max, 'returnImmediately' => true]);
            $messages = [];

            foreach ($body['receivedMessages'] ?? [] as $received) {
                $ackId     = $received['ackId'] ?? null;
                $messageId = $received['message']['messageId'] ?? null;
                if (is_string($ackId) && is_string($messageId)) {
                    $messages[] = new PubSubMessage($ackId, $messageId, (string) ($received['message']['data'] ?? ''));
                }
            }
            if ($messages !== [] || $attempt >= self::EMPTY_ATTEMPTS) {
                return $messages;
            }
            usleep($this->pauseMicroseconds);
        }
    }

    public function acknowledge(array $ackIds): void
    {
        if ($ackIds !== []) {
            $this->call('acknowledge', ['ackIds' => $ackIds]);
        }
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $json): array
    {
        if (preg_match('~^projects/[^/]+/subscriptions/[^/]+$~', $this->subscription) !== 1) {
            throw new GoogleUnavailable('Pub/Sub subscription is not configured.');
        }
        $accessToken = $this->tokens->accessToken(ServiceAccountTokens::SCOPE_PUBSUB);

        try {
            $response = ($this->http)()->post(sprintf(self::URL, $this->subscription, $method), [
                'http_errors' => false,
                'timeout'     => 20,
                'headers'     => ['Authorization' => 'Bearer ' . $accessToken],
                'json'        => $json,
            ]);
        } catch (Throwable $e) {
            throw new GoogleUnavailable("Pub/Sub {$method} request failed.", 0, $e);
        }
        $body = json_decode($response->getBody() ?? '', true);
        if ($response->getStatusCode() !== 200 || ! is_array($body)) {
            throw new GoogleUnavailable("Pub/Sub {$method} answered with status " . $response->getStatusCode() . '.');
        }

        return $body;
    }
}
