<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/** Entitlement, trial and purchase verification. The client can only read premium state. */
final class PremiumController extends BaseController
{
    /** `GET /entitlement` */
    public function entitlement(): ResponseInterface
    {
        return $this->guarded(function (): ResponseInterface {
            $uid = service('currentUser')->uid();
            service('purchaseService')->refreshLapsed($uid);

            return $this->json(service('entitlementService')->current($uid));
        });
    }

    /** `POST /trial` */
    public function startTrial(): ResponseInterface
    {
        return $this->guarded(fn (): ResponseInterface => $this->json(
            service('entitlementService')->startTrial(service('currentUser')->uid()),
        ));
    }

    /** `POST /purchases/verify` */
    public function verifyPurchase(): ResponseInterface
    {
        return $this->guarded(fn (): ResponseInterface => $this->json(
            service('purchaseService')->verify(service('currentUser')->uid(), $this->jsonBody()),
        ));
    }

    /** `POST /rtdn`, called by Pub/Sub (the `pubSubAuth` filter checks the caller). */
    public function rtdn(): ResponseInterface
    {
        return $this->guarded(function (): ResponseInterface {
            service('rtdnService')->handle($this->jsonBody());

            return $this->noContent();
        });
    }
}
