<?php

namespace App\Libraries\Google;

use App\Libraries\Time\Iso;
use CodeIgniter\HTTP\CURLRequest;
use Throwable;

/** `purchases.subscriptionsv2.get` of the Google Play Developer API v3. */
final class GooglePlayDeveloperApi implements PlayDeveloperApi
{
    private const URL = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptionsv2/tokens/%s';

    public function __construct(
        private readonly ServiceAccountTokens $tokens,
        private readonly CURLRequest $http,
    ) {
    }

    public function subscription(string $packageName, string $purchaseToken): ?SubscriptionState
    {
        $accessToken = $this->tokens->accessToken(ServiceAccountTokens::SCOPE_ANDROID_PUBLISHER);
        try {
            $response = $this->http->get(
                sprintf(self::URL, rawurlencode($packageName), rawurlencode($purchaseToken)),
                [
                    'http_errors' => false,
                    'timeout'     => 15,
                    'headers'     => ['Authorization' => 'Bearer ' . $accessToken],
                ],
            );
        } catch (Throwable $e) {
            throw new GoogleUnavailable('Play Developer API request failed.', 0, $e);
        }

        $status = $response->getStatusCode();
        // 400 (invalid token), 404 and 410 (expired long ago) mean Google has no such purchase.
        if (in_array($status, [400, 404, 410], true)) {
            return null;
        }
        $body = json_decode($response->getBody() ?? '', true);
        if ($status !== 200 || ! is_array($body)) {
            throw new GoogleUnavailable('Play Developer API answered with status ' . $status . '.');
        }

        return self::parse($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function parse(array $body): SubscriptionState
    {
        // A subscription can have several line items; the one that ends last decides.
        $latest = null;
        foreach ($body['lineItems'] ?? [] as $item) {
            $expiry = Iso::parse($item['expiryTime'] ?? null);
            if ($latest === null || ($expiry !== null && ($latest['expiry'] === null || $expiry > $latest['expiry']))) {
                $latest = ['item' => $item, 'expiry' => $expiry];
            }
        }
        $item = $latest['item'] ?? [];

        return new SubscriptionState(
            state: (string) ($body['subscriptionState'] ?? 'SUBSCRIPTION_STATE_UNSPECIFIED'),
            productId: $item['productId'] ?? null,
            basePlanId: $item['offerDetails']['basePlanId'] ?? null,
            expiresAt: $latest['expiry'] ?? null,
            autoRenewing: (bool) ($item['autoRenewingPlan']['autoRenewEnabled'] ?? false),
            accountHash: $body['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null,
            linkedPurchaseToken: $body['linkedPurchaseToken'] ?? null,
        );
    }
}
