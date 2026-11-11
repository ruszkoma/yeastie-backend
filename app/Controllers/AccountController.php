<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

final class AccountController extends BaseController
{
    /** `DELETE /me` */
    public function delete(): ResponseInterface
    {
        return $this->guarded(function (): ResponseInterface {
            service('accountService')->delete(service('currentUser')->uid());

            return $this->noContent();
        });
    }
}
