<?php

use CodeIgniter\Router\RouteCollection;

/**
 * API v1 (docs/api/openapi.yaml). The front controller is served at https://yeastie.cprojekt.pl/api/,
 * so these paths become https://yeastie.cprojekt.pl/api/v1/<path>.
 *
 * @var RouteCollection $routes
 */
$routes->group('v1', static function (RouteCollection $routes): void {
    $routes->group('', ['filter' => 'firebaseAuth'], static function (RouteCollection $routes): void {
        $routes->get('sync', 'SyncController::pull');
        $routes->post('sync', 'SyncController::push');
        $routes->get('entitlement', 'PremiumController::entitlement');
        $routes->post('trial', 'PremiumController::startTrial');
        $routes->post('purchases/verify', 'PremiumController::verifyPurchase');
        $routes->delete('me', 'AccountController::delete');
    });

    // Called by Google Pub/Sub, not by the app.
    $routes->post('rtdn', 'PremiumController::rtdn', ['filter' => 'pubSubAuth']);
});
