<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\WebPushConfiguration;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\ServiceWorkerController;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\SubscribeController;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\UnsubscribeController;

/** @var Router $router */
$router = app(Router::class);
$configuration = app(WebPushConfiguration::class);

// Session + CSRF (the "web" group by default): the current user comes from the
// session, and a cross-site page must not subscribe a victim's browser.
$router->group(['middleware' => $configuration->routeMiddleware(), 'prefix' => $configuration->routePrefix()], static function (Router $router): void {
    $router->post('subscriptions', SubscribeController::class)->name('web-push.subscribe');
    $router->post('subscriptions/unsubscribe', UnsubscribeController::class)->name('web-push.unsubscribe');
});

// Stateless on purpose: no group, so no session, no cookie — the most privileged
// script of the origin must never depend on who fetches it.
$router->get($configuration->serviceWorkerPath(), ServiceWorkerController::class)->name('web-push.service-worker');
