<?php

declare(strict_types=1);

use RomainMillan\WebPushNotification\Application\AnonymousGate;
use RomainMillan\WebPushNotification\Application\ClientConfiguration;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Application\DeliverPayload;
use RomainMillan\WebPushNotification\Application\DeliveryOutcomeListeners;
use RomainMillan\WebPushNotification\Application\EventPublisher;
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Application\Port\ClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Application\Port\CurrentOwner;
use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;
use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Application\Port\PushTransport;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionCipher;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionIdGenerator;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionLock;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionReadModel;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Application\PurgeSubscriptions;
use RomainMillan\WebPushNotification\Application\PurgeWindows;
use RomainMillan\WebPushNotification\Application\Queue\QueuedDeliveryPlanner;
use RomainMillan\WebPushNotification\Application\ReadModel\ListSubscriptions;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Application\RegistrationRules;
use RomainMillan\WebPushNotification\Application\RemoveAllSubscriptions;
use RomainMillan\WebPushNotification\Application\RetirementPolicy;
use RomainMillan\WebPushNotification\Application\RetireOnExpiry;
use RomainMillan\WebPushNotification\Application\RevokeSubscription;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerConfig;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerScript;
use RomainMillan\WebPushNotification\Application\SubscriptionQuota;
use RomainMillan\WebPushNotification\Application\SubscriptionTransaction;
use RomainMillan\WebPushNotification\Application\Unsubscribe;
use RomainMillan\WebPushNotification\Application\WebPushSender;
use RomainMillan\WebPushNotification\Bridge\Symfony\ClientConfigurationFactory;
use RomainMillan\WebPushNotification\Bridge\Symfony\Command\GenerateVapidKeysCommand;
use RomainMillan\WebPushNotification\Bridge\Symfony\Command\PurgeCommand;
use RomainMillan\WebPushNotification\Bridge\Symfony\Command\SendTestCommand;
use RomainMillan\WebPushNotification\Bridge\Symfony\Controller\ServiceWorkerController;
use RomainMillan\WebPushNotification\Bridge\Symfony\Controller\SubscribeController;
use RomainMillan\WebPushNotification\Bridge\Symfony\Controller\UnsubscribeController;
use RomainMillan\WebPushNotification\Bridge\Symfony\DeliveryOptionsFactory;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\AfterCommitCallbacks;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\DoctrineSubscriptionRepository;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\DoctrineTransactionBoundary;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\Middleware\AfterCommitMiddleware;
use RomainMillan\WebPushNotification\Bridge\Symfony\EventListener\PrivateResponseListener;
use RomainMillan\WebPushNotification\Bridge\Symfony\Http\CsrfHeader;
use RomainMillan\WebPushNotification\Bridge\Symfony\Http\RequestGuard;
use RomainMillan\WebPushNotification\Bridge\Symfony\Http\RequestRateLimit;
use RomainMillan\WebPushNotification\Bridge\Symfony\Messenger\MessengerPushDispatcher;
use RomainMillan\WebPushNotification\Bridge\Symfony\Messenger\SendWebPushHandler;
use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushChannel;
use RomainMillan\WebPushNotification\Bridge\Symfony\OriginFactory;
use RomainMillan\WebPushNotification\Bridge\Symfony\Security\SecurityCurrentOwner;
use RomainMillan\WebPushNotification\Bridge\Symfony\Security\SubscriberIdResolver;
use RomainMillan\WebPushNotification\Bridge\Symfony\Security\WebPushSubscriberResolver;
use RomainMillan\WebPushNotification\Bridge\Symfony\Signing\SymfonyActionUrlSigner;
use RomainMillan\WebPushNotification\Bridge\Symfony\Twig\WebPushExtension;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Domain\Subscription\PurgeableSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Infrastructure\ClientState\HmacClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\AeadSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\EncryptionKey;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\NullSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\Dispatch\ImmediatePushDispatcher;
use RomainMillan\WebPushNotification\Infrastructure\Id\RandomSubscriptionIdGenerator;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\HttpStatusClassifier;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\MinishlinkPushTransport;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\WebPushClientFactory;
use RomainMillan\WebPushNotification\Infrastructure\Network\DnsResolver;
use RomainMillan\WebPushNotification\Infrastructure\Network\HostPinning;
use RomainMillan\WebPushNotification\Infrastructure\Network\NoPinning;
use RomainMillan\WebPushNotification\Infrastructure\Network\PublicIpPolicy;
use RomainMillan\WebPushNotification\Infrastructure\Network\ResolvedHostPinning;
use RomainMillan\WebPushNotification\Infrastructure\Network\SystemDnsResolver;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidCredentials;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidKeyGenerator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\RequestContext;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Every package service, private. The public API is the set of aliases on the core
 * classes and ports (autowirable): PushDispatcher, WebPushSender, ListSubscriptions,
 * RevokeSubscription, RemoveAllSubscriptions, SubscriptionReadModel, Origin, ActionUrlSigner.
 */
return static function (ContainerConfigurator $container, array $config): void {
    /** @var array{
     *     origin: ?string,
     *     vapid: array{public_key: string, private_key: string, subject: string},
     *     subscriber: array{resolver: ?string},
     *     storage: array{type: string, connection: string, repository: ?string, transaction_boundary: ?string},
     *     encryption: array{current: ?string, previous: list<string>},
     *     max_subscriptions_per_subscriber: int,
     *     retirement: string,
     *     anonymous: array{enabled: bool, rate_limiter: ?string, max_active: int, stale_after: string},
     *     push_services: array{extra_hosts: list<string>},
     *     delivery: array{dispatcher: string, timeout: int, dns_pinning: bool, ttl: int, urgency: string},
     *     routes: array{csrf_token_id: string, csrf_header: string, unsubscribe_rate_limiter: ?string},
     *     service_worker: array{fallback_title: string, icon: string, badge: string, click_prefixes: list<string>, asset_hosts: list<string>, state_cache: string, prebuilt_path: ?string, register_url: ?string},
     *     purge: array{retired_after: string},
     * } $config
     */
    $services = $container->services()->defaults()->private();
    $clock = service('web_push_notification.clock');
    $logger = service('web_push_notification.logger');

    // Configuration-derived value objects.
    $services->set(AllowedPushServices::class)->factory([AllowedPushServices::class, 'createWithKnownServices'])->args([$config['push_services']['extra_hosts']]);
    $services->set(SubscriptionQuota::class)->factory([SubscriptionQuota::class, 'fromLimits'])->args([$config['max_subscriptions_per_subscriber'], $config['anonymous']['max_active']]);
    $services->set(RetirementPolicy::class)->factory([RetirementPolicy::class, 'from'])->args([$config['retirement']]);
    $services->set(PurgeWindows::class)->factory([PurgeWindows::class, 'fromDurations'])->args([$config['purge']['retired_after'], $config['anonymous']['stale_after']]);
    $services->set(VapidCredentials::class)->factory([VapidCredentials::class, 'fromKeys'])->args([$config['vapid']['public_key'], $config['vapid']['private_key'], $config['vapid']['subject']]);
    $services->set(DeliveryOptions::class)->factory([DeliveryOptionsFactory::class, 'createFromConfiguration'])->args([$config['delivery']['ttl'], $config['delivery']['urgency']]);
    // A context of its own, built from framework.router.default_uri: the router's one
    // follows the Host header of the current request.
    $services->set('web_push_notification.request_context', RequestContext::class)->factory([RequestContext::class, 'fromUri'])->args([
        param('router.request_context.base_url'),
        param('router.request_context.host'),
        param('router.request_context.scheme'),
        param('request_listener.http_port'),
        param('request_listener.https_port'),
    ]);
    $services->set(Origin::class)->factory([OriginFactory::class, 'createFromRequestContext'])->args([service('web_push_notification.request_context'), $config['origin'] ?? ''])->public();
    $services->set(ServiceWorkerConfig::class)->factory([ServiceWorkerConfig::class, 'fromSettings'])->args([
        $config['service_worker']['fallback_title'],
        $config['service_worker']['icon'],
        $config['service_worker']['badge'],
        $config['service_worker']['click_prefixes'],
        $config['service_worker']['asset_hosts'],
        $config['service_worker']['state_cache'],
    ]);

    // Encryption at rest (optional).
    if (null !== $config['encryption']['current']) {
        $previous = [];
        foreach ($config['encryption']['previous'] as $index => $key) {
            $services->set('web_push_notification.encryption_key.previous_'.$index, EncryptionKey::class)->factory([EncryptionKey::class, 'fromConfiguration'])->args([$key]);
            $previous[] = service('web_push_notification.encryption_key.previous_'.$index);
        }
        $services->set('web_push_notification.encryption_key.current', EncryptionKey::class)->factory([EncryptionKey::class, 'fromConfiguration'])->args([$config['encryption']['current']]);
        $services->set(SubscriptionCipher::class, AeadSubscriptionCipher::class)->args([service('web_push_notification.encryption_key.current'), $previous]);
    } else {
        $services->set(SubscriptionCipher::class, NullSubscriptionCipher::class);
    }

    // Storage.
    $services->set(SubscriptionRowMapper::class)->args([service(SubscriptionCipher::class)]);

    if ('doctrine' === $config['storage']['type']) {
        $connection = service('doctrine.dbal.'.$config['storage']['connection'].'_connection');
        $services->set(AfterCommitCallbacks::class);
        $services->set(AfterCommitMiddleware::class)->args([service(AfterCommitCallbacks::class)])->tag('doctrine.middleware', ['connection' => $config['storage']['connection']]);
        $services->set(DoctrineTransactionBoundary::class)->args([$connection, service(AfterCommitCallbacks::class)]);
        $services->set(DoctrineSubscriptionRepository::class)->args([$connection, service(SubscriptionRowMapper::class), service(AllowedPushServices::class)]);
        $services->alias(SubscriptionRepository::class, DoctrineSubscriptionRepository::class);
        $services->alias(PurgeableSubscriptions::class, DoctrineSubscriptionRepository::class);
        $services->alias(SubscriptionReadModel::class, DoctrineSubscriptionRepository::class)->public();
        $services->alias(TransactionBoundary::class, DoctrineTransactionBoundary::class);
    } else {
        if (null === $config['storage']['repository'] || null === $config['storage']['transaction_boundary']) {
            throw new LogicException('Cannot use custom web push storage without storage.repository and storage.transaction_boundary services.');
        }

        $services->alias(SubscriptionRepository::class, $config['storage']['repository']);
        $services->alias(PurgeableSubscriptions::class, $config['storage']['repository']);
        $services->alias(SubscriptionReadModel::class, $config['storage']['repository'])->public();
        $services->alias(TransactionBoundary::class, $config['storage']['transaction_boundary']);
    }

    // SubscriptionLock is aliased by FrameworkIntegrationPass (Symfony Lock when available).
    $services->set(EventPublisher::class)->args([service('event_dispatcher'), service(TransactionBoundary::class)]);
    $services->set(SubscriptionTransaction::class)->args([service(SubscriptionRepository::class), service(TransactionBoundary::class), service(SubscriptionLock::class), service(EventPublisher::class)]);
    $services->set(SubscriptionIdGenerator::class, RandomSubscriptionIdGenerator::class);

    // Use cases.
    $services->set(RegistrationRules::class)->args([service(AllowedPushServices::class), service(SubscriptionQuota::class), service(RetirementPolicy::class)]);
    $services->set(RegisterSubscription::class)->args([service(SubscriptionTransaction::class), service(RegistrationRules::class), service(SubscriptionIdGenerator::class), $clock])->public();
    $services->set(Unsubscribe::class)->args([service(SubscriptionTransaction::class), $clock])->public();
    $services->set(RevokeSubscription::class)->args([service(SubscriptionTransaction::class), $clock])->public();
    $services->set(RemoveAllSubscriptions::class)->args([service(SubscriptionTransaction::class), service(PurgeableSubscriptions::class), $clock])->public();
    $services->set(ListSubscriptions::class)->args([service(SubscriptionReadModel::class)])->public();
    $services->set(PurgeSubscriptions::class)->args([service(PurgeableSubscriptions::class), service(PurgeWindows::class), service('event_dispatcher'), $clock]);

    // Delivery.
    $services->set(DnsResolver::class, SystemDnsResolver::class);
    $services->set(PublicIpPolicy::class);
    if ($config['delivery']['dns_pinning']) {
        $services->set(HostPinning::class, ResolvedHostPinning::class)->args([service(DnsResolver::class), service(PublicIpPolicy::class)]);
    } else {
        $services->set(HostPinning::class, NoPinning::class);
    }
    $services->set(WebPushClientFactory::class)->factory([WebPushClientFactory::class, 'createWithCurl'])->args([service(VapidCredentials::class), $config['delivery']['timeout']]);
    $services->set(HttpStatusClassifier::class);
    $services->set(PushTransport::class, MinishlinkPushTransport::class)->args([service(WebPushClientFactory::class), service(HttpStatusClassifier::class), service(HostPinning::class), $logger]);
    $services->set(RetireOnExpiry::class)->args([service(SubscriptionTransaction::class), service(RetirementPolicy::class), $clock]);
    $services->set(DeliveryOutcomeListeners::class)->args([service(RetireOnExpiry::class), tagged_iterator(DeliveryOutcomeListener::class), $logger]);
    $services->set(DeliverPayload::class)->args([service(SubscriptionRepository::class), service(PushTransport::class), service(AllowedPushServices::class), service(DeliveryOutcomeListeners::class)])->public();
    $services->set(PayloadEncoder::class);
    $services->set(WebPushSender::class)->args([service(PayloadEncoder::class), service(DeliverPayload::class)])->public();

    if ('messenger' === $config['delivery']['dispatcher']) {
        $services->set(QueuedDeliveryPlanner::class)->args([service(SubscriptionRepository::class), service(PayloadEncoder::class)]);
        $services->set(PushDispatcher::class, MessengerPushDispatcher::class)->args([service(QueuedDeliveryPlanner::class), service('messenger.default_bus')])->public();
    } else {
        $services->set(PushDispatcher::class, ImmediatePushDispatcher::class)->args([service(WebPushSender::class)])->public();
    }
    $services->set(SendWebPushHandler::class)->args([service(DeliverPayload::class)])->tag('messenger.message_handler');

    // HTTP.
    $services->set(SubscriberIdResolver::class, WebPushSubscriberResolver::class);
    if (null !== $config['subscriber']['resolver']) {
        $services->alias(SubscriberIdResolver::class, $config['subscriber']['resolver']);
    }
    $services->set(CurrentOwner::class, SecurityCurrentOwner::class)->args([service('security.token_storage'), service(SubscriberIdResolver::class)]);
    $services->set(AnonymousGate::class)->args([service(CurrentOwner::class), $config['anonymous']['enabled']]);
    $services->set(ClientStateMarkerFactory::class, HmacClientStateMarkerFactory::class)->args(['%kernel.secret%']);
    $services->set(CsrfHeader::class)->args([service('security.csrf.token_manager'), $config['routes']['csrf_token_id'], $config['routes']['csrf_header']]);
    $services->set('web_push_notification.anonymous_request_rate_limit', RequestRateLimit::class)->args([null === $config['anonymous']['rate_limiter'] ? [] : [service('limiter.'.$config['anonymous']['rate_limiter'])]]);
    $services->set('web_push_notification.unsubscribe_request_rate_limit', RequestRateLimit::class)->args([[service('limiter.'.($config['routes']['unsubscribe_rate_limiter'] ?? 'web_push_unsubscribe'))]]);
    $services->set(RequestGuard::class)->args([service(CsrfHeader::class), service('web_push_notification.anonymous_request_rate_limit'), service('web_push_notification.unsubscribe_request_rate_limit')]);
    $services->set(SubscribeController::class)->args([service(AnonymousGate::class), service(RequestGuard::class), service(RegisterSubscription::class), $logger])->public()->tag('controller.service_arguments');
    $services->set(UnsubscribeController::class)->args([service(RequestGuard::class), service(Unsubscribe::class)])->public()->tag('controller.service_arguments');
    $services->set(ServiceWorkerScript::class)->args([$config['service_worker']['prebuilt_path'] ?? dirname(__DIR__, 4).'/assets/dist/web-push-sw.js']);
    $services->set(ServiceWorkerController::class)->args([service(ServiceWorkerScript::class), service(ServiceWorkerConfig::class)])->public()->tag('controller.service_arguments');
    $services->set(ClientConfigurationFactory::class)->args([service('router'), service(VapidCredentials::class), service(ClientStateMarkerFactory::class), ['clickPrefixes' => $config['service_worker']['click_prefixes'], 'stateCache' => $config['service_worker']['state_cache'], 'registerUrl' => $config['service_worker']['register_url'] ?? '']]);
    $services->set(SymfonyActionUrlSigner::class)->args([service('router'), service('uri_signer'), service(Origin::class), $clock]);
    $services->alias(ActionUrlSigner::class, SymfonyActionUrlSigner::class)->public();
    $services->set(ClientConfiguration::class)->factory([service(ClientConfigurationFactory::class), 'createClientConfiguration']);
    $services->set(WebPushExtension::class)->args([service(ClientConfiguration::class), service(CurrentOwner::class), service(CsrfHeader::class), service('request_stack')])->tag('twig.extension');
    $services->set(PrivateResponseListener::class)->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);

    // Notifier and console.
    $services->set(WebPushChannel::class)->args([service(PushDispatcher::class), service(DeliveryOptions::class)])->tag('notifier.channel', ['channel' => 'web_push']);
    $services->set(VapidKeyGenerator::class);
    $services->set(GenerateVapidKeysCommand::class)->args([service(VapidKeyGenerator::class)])->tag('console.command');
    $services->set(SendTestCommand::class)->args([service(WebPushSender::class), service(DeliveryOptions::class)])->tag('console.command');
    $services->set(PurgeCommand::class)->args([service(PurgeSubscriptions::class)])->tag('console.command');
};
