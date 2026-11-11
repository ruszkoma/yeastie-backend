<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings of the Yeastie API. Secrets and per-host values come from `.env` (`yeastie.<name>`);
 * see env.example. Nothing secret has a default here.
 */
class Yeastie extends BaseConfig
{
    /** Firebase project id: the expected `aud` of ID tokens and the project for account deletion. */
    public string $firebaseProjectId = '';

    /** Absolute path to the Google service account JSON key, outside the public directory. */
    public string $serviceAccountFile = '';

    /** Android package and the subscription product sold in Google Play. */
    public string $playPackageName = 'pl.cprojekt.yeastie';

    public string $premiumProductId = 'premium';

    /** Audience configured on the Pub/Sub push subscription for RTDN (usually the endpoint URL). */
    public string $pubsubAudience = '';

    /** Service account that Pub/Sub uses to sign push requests. */
    public string $pubsubServiceAccountEmail = '';

    /** How long the app may use its entitlement copy offline. */
    public int $entitlementCacheDays = 7;

    public int $trialDays = 14;

    /** `POST /sync` limits, as in the API contract. */
    public int $maxSyncBytes = 2_000_000;

    public int $maxSyncChanges = 500;

    /** Requests per user per minute before 429. */
    public int $rateLimitPerMinute = 120;

    /** Retention of idempotency records and RTDN message ids (yeastie:cleanup). */
    public int $retentionDays = 30;

    /** Allowed clock difference when checking token times. */
    public int $clockSkewSeconds = 60;

    /**
     * Development only (ignored unless CI_ENVIRONMENT is `development`): a PEM public key that
     * replaces Google's Firebase keys, so local smoke tests can sign their own ID tokens (kid `dev`).
     */
    public string $devPublicKeyFile = '';
}
