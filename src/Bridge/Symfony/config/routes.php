<?php

declare(strict_types=1);

use RomainMillan\WebPushNotification\Bridge\Symfony\Controller\ServiceWorkerController;
use RomainMillan\WebPushNotification\Bridge\Symfony\Controller\SubscribeController;
use RomainMillan\WebPushNotification\Bridge\Symfony\Controller\UnsubscribeController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Import in config/routes/web_push_notification.php:
 *
 *     return static fn (RoutingConfigurator $routes) => $routes->import('@WebPushNotificationBundle/config/routes.php');
 *
 * The service worker must be served from the root to control every page: keep
 * /web-push-sw.js out of any prefix (or declare your own route to the controller).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('web_push_subscribe', '/web-push/subscriptions')
        ->controller(SubscribeController::class)
        ->methods(['POST']);

    $routes->add('web_push_unsubscribe', '/web-push/subscriptions/unsubscribe')
        ->controller(UnsubscribeController::class)
        ->methods(['POST']);

    $routes->add('web_push_service_worker', '/web-push-sw.js')
        ->controller(ServiceWorkerController::class)
        ->methods(['GET'])
        ->stateless();
};
