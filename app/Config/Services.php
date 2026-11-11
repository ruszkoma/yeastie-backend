<?php

namespace Config;

use App\Libraries\Auth\CurrentUser;
use App\Libraries\Auth\FirebaseTokenVerifier;
use App\Libraries\Auth\GoogleKeySource;
use App\Libraries\Auth\JwtVerifier;
use App\Libraries\Auth\KeySource;
use App\Libraries\Auth\PubSubTokenVerifier;
use App\Libraries\Auth\StaticKeySource;
use App\Libraries\Google\FirebaseAdmin;
use App\Libraries\Google\GoogleFirebaseAdmin;
use App\Libraries\Google\GooglePlayDeveloperApi;
use App\Libraries\Google\PlayDeveloperApi;
use App\Libraries\Google\ServiceAccountTokens;
use App\Libraries\Time\Clock;
use App\Libraries\Time\SystemClock;
use App\Services\AccountService;
use App\Services\Premium\EntitlementService;
use App\Services\Premium\PurchaseService;
use App\Services\Premium\RtdnService;
use App\Services\Sync\SyncService;
use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\CURLRequest;
use Firebase\JWT\Key;

/**
 * Dependency wiring of the API. Tests replace services with `Services::injectMock()`:
 * `clock`, `firebaseKeys`, `oidcKeys`, `playDeveloperApi` and `firebaseAdmin` are the seams to
 * time and to Google.
 */
class Services extends BaseService
{
    public static function clock(bool $getShared = true): Clock
    {
        return $getShared ? static::getSharedInstance('clock') : new SystemClock();
    }

    public static function currentUser(bool $getShared = true): CurrentUser
    {
        return $getShared ? static::getSharedInstance('currentUser') : new CurrentUser();
    }

    public static function firebaseKeys(bool $getShared = true): KeySource
    {
        if ($getShared) {
            return static::getSharedInstance('firebaseKeys');
        }
        // Local smoke tests only: tokens signed with a key from `yeastie.devPublicKeyFile`.
        $devKey = config('Yeastie')->devPublicKeyFile;
        if (ENVIRONMENT === 'development' && $devKey !== '' && is_readable($devKey)) {
            return new StaticKeySource(['dev' => new Key((string) file_get_contents($devKey), 'RS256')]);
        }

        return GoogleKeySource::firebase(service('cache'), self::http());
    }

    public static function oidcKeys(bool $getShared = true): KeySource
    {
        return $getShared
            ? static::getSharedInstance('oidcKeys')
            : GoogleKeySource::oidc(service('cache'), self::http());
    }

    public static function firebaseTokenVerifier(bool $getShared = true): FirebaseTokenVerifier
    {
        if ($getShared) {
            return static::getSharedInstance('firebaseTokenVerifier');
        }
        $config = config('Yeastie');

        return new FirebaseTokenVerifier(
            self::jwtVerifier(),
            service('firebaseKeys'),
            service('clock'),
            $config->firebaseProjectId,
            $config->clockSkewSeconds,
        );
    }

    public static function pubSubTokenVerifier(bool $getShared = true): PubSubTokenVerifier
    {
        if ($getShared) {
            return static::getSharedInstance('pubSubTokenVerifier');
        }
        $config = config('Yeastie');

        return new PubSubTokenVerifier(
            self::jwtVerifier(),
            service('oidcKeys'),
            $config->pubsubAudience,
            $config->pubsubServiceAccountEmail,
        );
    }

    public static function serviceAccountTokens(bool $getShared = true): ServiceAccountTokens
    {
        return $getShared
            ? static::getSharedInstance('serviceAccountTokens')
            : new ServiceAccountTokens(config('Yeastie')->serviceAccountFile, self::http(), service('clock'));
    }

    public static function playDeveloperApi(bool $getShared = true): PlayDeveloperApi
    {
        return $getShared
            ? static::getSharedInstance('playDeveloperApi')
            : new GooglePlayDeveloperApi(service('serviceAccountTokens'), self::http());
    }

    public static function firebaseAdmin(bool $getShared = true): FirebaseAdmin
    {
        return $getShared
            ? static::getSharedInstance('firebaseAdmin')
            : new GoogleFirebaseAdmin(service('serviceAccountTokens'), self::http(), config('Yeastie')->firebaseProjectId);
    }

    public static function syncService(bool $getShared = true): SyncService
    {
        return $getShared ? static::getSharedInstance('syncService') : new SyncService(db_connect(), service('clock'));
    }

    public static function entitlementService(bool $getShared = true): EntitlementService
    {
        return $getShared
            ? static::getSharedInstance('entitlementService')
            : new EntitlementService(db_connect(), service('clock'), config('Yeastie'));
    }

    public static function purchaseService(bool $getShared = true): PurchaseService
    {
        return $getShared
            ? static::getSharedInstance('purchaseService')
            : new PurchaseService(
                db_connect(),
                service('playDeveloperApi'),
                service('entitlementService'),
                service('clock'),
                config('Yeastie'),
            );
    }

    public static function rtdnService(bool $getShared = true): RtdnService
    {
        return $getShared
            ? static::getSharedInstance('rtdnService')
            : new RtdnService(db_connect(), service('purchaseService'), service('clock'), config('Yeastie'));
    }

    public static function accountService(bool $getShared = true): AccountService
    {
        return $getShared ? static::getSharedInstance('accountService') : new AccountService(db_connect(), service('firebaseAdmin'));
    }

    private static function jwtVerifier(): JwtVerifier
    {
        return new JwtVerifier(service('clock'), config('Yeastie')->clockSkewSeconds);
    }

    /** A fresh client per use: the shared CURLRequest keeps headers between requests. */
    private static function http(): CURLRequest
    {
        return service('curlrequest', [], null, null, false);
    }
}
