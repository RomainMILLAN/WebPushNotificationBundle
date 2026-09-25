<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Psr\Log\LoggerInterface;
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
use RomainMillan\WebPushNotification\Application\Queue\QueuedDeliveryPlanner;
use RomainMillan\WebPushNotification\Application\ReadModel\ListSubscriptions;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Application\RegistrationRules;
use RomainMillan\WebPushNotification\Application\RemoveAllSubscriptions;
use RomainMillan\WebPushNotification\Application\RetireOnExpiry;
use RomainMillan\WebPushNotification\Application\RevokeSubscription;
use RomainMillan\WebPushNotification\Application\SubscriptionTransaction;
use RomainMillan\WebPushNotification\Application\Unsubscribe;
use RomainMillan\WebPushNotification\Application\WebPushSender;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\CallableSubscriberIdResolver;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\LaravelAuthCurrentOwner;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\ModelSubscriberIdResolver;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\SubscriberIdResolver;
use RomainMillan\WebPushNotification\Bridge\Laravel\Clock\LaravelClock;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\InvalidConfiguration;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\WebPushConfiguration;
use RomainMillan\WebPushNotification\Bridge\Laravel\Console\GenerateVapidKeysCommand;
use RomainMillan\WebPushNotification\Bridge\Laravel\Console\PurgeCommand;
use RomainMillan\WebPushNotification\Bridge\Laravel\Console\SendTestCommand;
use RomainMillan\WebPushNotification\Bridge\Laravel\Dispatch\QueuePushDispatcher;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\DbTransactionBoundary;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\EloquentSubscriptionRepository;
use RomainMillan\WebPushNotification\Bridge\Laravel\Events\LaravelEventDispatcher;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\ClientRateLimit;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\MarkWebPushResponsePrivate;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\ServiceWorkerController;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\SubscribeController;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\UnsubscribeController;
use RomainMillan\WebPushNotification\Bridge\Laravel\Lock\CacheLockSubscriptionLock;
use RomainMillan\WebPushNotification\Bridge\Laravel\Notifications\WebPushChannel;
use RomainMillan\WebPushNotification\Bridge\Laravel\Signing\LaravelActionUrlSigner;
use RomainMillan\WebPushNotification\Bridge\Laravel\View\WebPushMetaTag;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Domain\Subscription\PurgeableSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Infrastructure\ClientState\HmacClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Infrastructure\Dispatch\ImmediatePushDispatcher;
use RomainMillan\WebPushNotification\Infrastructure\Id\RandomSubscriptionIdGenerator;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\HttpStatusClassifier;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\MinishlinkPushTransport;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\WebPushClientFactory;
use RomainMillan\WebPushNotification\Infrastructure\Network\NoPinning;
use RomainMillan\WebPushNotification\Infrastructure\Network\PublicIpPolicy;
use RomainMillan\WebPushNotification\Infrastructure\Network\ResolvedHostPinning;
use RomainMillan\WebPushNotification\Infrastructure\Network\SystemDnsResolver;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidCredentials;

/**
 * Auto-discovered. Every core service is a lazy singleton; applications override a
 * port by binding it in their own provider (registered after this one).
 *
 * Boot-time validation: the whole configuration is checked when the application
 * boots — HTTP, queue workers and console alike — EXCEPT in the console while no
 * VAPID key is set at all: `composer require` runs `package:discover` before anyone
 * could fill .env. Even then, resolving a web push service fails loudly.
 */
final class WebPushNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/web-push.php', 'web-push');

        $this->app->singleton(WebPushConfiguration::class, static fn (Container $app): WebPushConfiguration => WebPushConfiguration::fromRepository($app->make(Repository::class)));

        $this->registerConfiguredValues();
        $this->registerPersistence();
        $this->registerUseCases();
        $this->registerDelivery();
        $this->registerHttp();
    }

    public function boot(): void
    {
        $configuration = $this->app->make(WebPushConfiguration::class);

        if (!$this->app->runningInConsole() || $configuration->isVapidConfigured()) {
            $configuration->validate();
            // A configured resolver class that does not exist or implement the interface.
            $this->app->make(SubscriberIdResolver::class);
        }

        $this->publishes([__DIR__.'/config/web-push.php' => $this->app->configPath('web-push.php')], 'web-push-config');
        $this->publishesMigrations([__DIR__.'/database/migrations' => $this->app->databasePath('migrations')], 'web-push-migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'web-push');
        $this->publishes([__DIR__.'/resources/views' => $this->app->resourcePath('views/vendor/web-push')], 'web-push-views');

        if ($configuration->routesEnabled()) {
            $this->loadRoutesFrom(__DIR__.'/routes/web-push.php');
        }

        // Through the HTTP kernel: it re-syncs its groups onto the router at each
        // request, which would drop a middleware pushed onto the router alone.
        $this->callAfterResolving(HttpKernelContract::class, static function (HttpKernelContract $kernel): void {
            if ($kernel instanceof HttpKernel) {
                $kernel->appendMiddlewareToGroup('web', MarkWebPushResponsePrivate::class);
            }
        });

        $this->callAfterResolving(BladeCompiler::class, static function (BladeCompiler $blade): void {
            $blade->directive('webPushMeta', static fn (): string => '<?php echo app(\\'.WebPushMetaTag::class.'::class)->renderFor(request()); ?>');
        });

        $this->callAfterResolving(ChannelManager::class, static function (ChannelManager $channels, Container $app): void {
            $channels->extend(WebPushChannel::NAME, static fn (): WebPushChannel => $app->make(WebPushChannel::class));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateVapidKeysCommand::class, SendTestCommand::class, PurgeCommand::class]);
        }
    }

    private function registerConfiguredValues(): void
    {
        $this->app->singleton(VapidCredentials::class, static fn (Container $app): VapidCredentials => $app->make(WebPushConfiguration::class)->vapidCredentials());
        $this->app->singleton(Origin::class, static fn (Container $app): Origin => $app->make(WebPushConfiguration::class)->origin());
        $this->app->singleton(AllowedPushServices::class, static fn (Container $app): AllowedPushServices => $app->make(WebPushConfiguration::class)->allowedPushServices());
        $this->app->singleton(SubscriptionCipher::class, static fn (Container $app): SubscriptionCipher => $app->make(WebPushConfiguration::class)->subscriptionCipher());
        $this->app->singleton(DeliveryOptions::class, static fn (Container $app): DeliveryOptions => $app->make(WebPushConfiguration::class)->deliveryOptions());
        $this->app->singleton(LaravelClock::class);
    }

    private function registerPersistence(): void
    {
        $this->app->singleton(SubscriptionRowMapper::class, static fn (Container $app): SubscriptionRowMapper => new SubscriptionRowMapper($app->make(SubscriptionCipher::class)));

        $this->app->singleton(EloquentSubscriptionRepository::class, static fn (Container $app): EloquentSubscriptionRepository => new EloquentSubscriptionRepository(
            $app->make(SubscriptionRowMapper::class),
            $app->make(AllowedPushServices::class),
            $app->make(WebPushConfiguration::class)->databaseConnection(),
        ));

        $this->app->singleton(SubscriptionRepository::class, static function (Container $app): SubscriptionRepository&PurgeableSubscriptions&SubscriptionReadModel {
            $storage = $app->make(WebPushConfiguration::class)->storage();
            $adapter = $app->make('eloquent' === $storage ? EloquentSubscriptionRepository::class : $storage);

            if (!$adapter instanceof SubscriptionRepository || !$adapter instanceof PurgeableSubscriptions || !$adapter instanceof SubscriptionReadModel) {
                throw InvalidConfiguration::because('Cannot use a web-push.storage that does not implement SubscriptionRepository, PurgeableSubscriptions and SubscriptionReadModel.');
            }

            return $adapter;
        });
        $this->app->singleton(PurgeableSubscriptions::class, static fn (Container $app): PurgeableSubscriptions => self::storage($app));
        $this->app->singleton(SubscriptionReadModel::class, static fn (Container $app): SubscriptionReadModel => self::storage($app));

        $this->app->singleton(TransactionBoundary::class, static function (Container $app): TransactionBoundary {
            $connection = $app->make(WebPushConfiguration::class)->databaseConnection();

            return new DbTransactionBoundary($app->make(DatabaseManager::class)->connection('' === $connection ? null : $connection));
        });

        $this->app->singleton(SubscriptionLock::class, static function (Container $app): SubscriptionLock {
            $store = $app->make(WebPushConfiguration::class)->lockStore();
            $cache = $app->make(CacheFactory::class)->store('' === $store ? null : $store)->getStore();

            return $cache instanceof LockProvider
                ? new CacheLockSubscriptionLock($cache)
                : throw InvalidConfiguration::because('Cannot serialize web push registrations with a cache store that does not support locks (web-push.lock.store).');
        });

        $this->app->singleton(SubscriptionIdGenerator::class, RandomSubscriptionIdGenerator::class);
    }

    private function registerUseCases(): void
    {
        $this->app->singleton(EventPublisher::class, static fn (Container $app): EventPublisher => new EventPublisher(
            new LaravelEventDispatcher($app->make(EventDispatcher::class)),
            $app->make(TransactionBoundary::class),
        ));

        $this->app->singleton(SubscriptionTransaction::class, static fn (Container $app): SubscriptionTransaction => new SubscriptionTransaction(
            $app->make(SubscriptionRepository::class),
            $app->make(TransactionBoundary::class),
            $app->make(SubscriptionLock::class),
            $app->make(EventPublisher::class),
        ));

        $this->app->singleton(RegistrationRules::class, static fn (Container $app): RegistrationRules => new RegistrationRules(
            $app->make(AllowedPushServices::class),
            $app->make(WebPushConfiguration::class)->subscriptionQuota(),
            $app->make(WebPushConfiguration::class)->retirementPolicy(),
        ));

        $this->app->singleton(RegisterSubscription::class, static fn (Container $app): RegisterSubscription => new RegisterSubscription(
            $app->make(SubscriptionTransaction::class),
            $app->make(RegistrationRules::class),
            $app->make(SubscriptionIdGenerator::class),
            $app->make(LaravelClock::class),
        ));

        $this->app->singleton(Unsubscribe::class, static fn (Container $app): Unsubscribe => new Unsubscribe($app->make(SubscriptionTransaction::class), $app->make(LaravelClock::class)));
        $this->app->singleton(RevokeSubscription::class, static fn (Container $app): RevokeSubscription => new RevokeSubscription($app->make(SubscriptionTransaction::class), $app->make(LaravelClock::class)));
        $this->app->singleton(RemoveAllSubscriptions::class, static fn (Container $app): RemoveAllSubscriptions => new RemoveAllSubscriptions(
            $app->make(SubscriptionTransaction::class),
            $app->make(PurgeableSubscriptions::class),
            $app->make(LaravelClock::class),
        ));
        $this->app->singleton(ListSubscriptions::class, static fn (Container $app): ListSubscriptions => new ListSubscriptions($app->make(SubscriptionReadModel::class)));
        $this->app->singleton(PurgeSubscriptions::class, static fn (Container $app): PurgeSubscriptions => new PurgeSubscriptions(
            $app->make(PurgeableSubscriptions::class),
            $app->make(WebPushConfiguration::class)->purgeWindows(),
            new LaravelEventDispatcher($app->make(EventDispatcher::class)),
            $app->make(LaravelClock::class),
        ));
    }

    private function registerDelivery(): void
    {
        $this->app->singleton(PushTransport::class, static function (Container $app): PushTransport {
            $configuration = $app->make(WebPushConfiguration::class);

            return new MinishlinkPushTransport(
                WebPushClientFactory::createWithCurl($app->make(VapidCredentials::class), $configuration->deliveryTimeout()),
                new HttpStatusClassifier(),
                $configuration->dnsPinning() ? new ResolvedHostPinning(new SystemDnsResolver(), new PublicIpPolicy()) : new NoPinning(),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(DeliveryOutcomeListeners::class, static fn (Container $app): DeliveryOutcomeListeners => new DeliveryOutcomeListeners(
            new RetireOnExpiry($app->make(SubscriptionTransaction::class), $app->make(WebPushConfiguration::class)->retirementPolicy(), $app->make(LaravelClock::class)),
            self::applicationListeners($app),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(DeliverPayload::class, static fn (Container $app): DeliverPayload => new DeliverPayload(
            $app->make(SubscriptionRepository::class),
            $app->make(PushTransport::class),
            $app->make(AllowedPushServices::class),
            $app->make(DeliveryOutcomeListeners::class),
        ));

        $this->app->singleton(PayloadEncoder::class);
        $this->app->singleton(WebPushSender::class, static fn (Container $app): WebPushSender => new WebPushSender($app->make(PayloadEncoder::class), $app->make(DeliverPayload::class)));
        $this->app->singleton(QueuedDeliveryPlanner::class, static fn (Container $app): QueuedDeliveryPlanner => new QueuedDeliveryPlanner($app->make(SubscriptionRepository::class), $app->make(PayloadEncoder::class)));

        $this->app->singleton(PushDispatcher::class, static function (Container $app): PushDispatcher {
            $configuration = $app->make(WebPushConfiguration::class);

            return $configuration->usesQueue()
                ? new QueuePushDispatcher($app->make(QueuedDeliveryPlanner::class), $app->make(BusDispatcher::class), $configuration->queueConnection(), $configuration->queueName())
                : new ImmediatePushDispatcher($app->make(WebPushSender::class));
        });

        $this->app->singleton(WebPushChannel::class, static fn (Container $app): WebPushChannel => new WebPushChannel(
            $app->make(PushDispatcher::class),
            $app->make(SubscriberIdResolver::class),
            $app->make(DeliveryOptions::class),
        ));
    }

    private function registerHttp(): void
    {
        $this->app->singleton(SubscriberIdResolver::class, static function (Container $app): SubscriberIdResolver {
            $resolver = $app->make(WebPushConfiguration::class)->subscriberResolver();

            return match (true) {
                null === $resolver => new ModelSubscriberIdResolver(),
                \is_string($resolver) && class_exists($resolver) => self::configuredResolver($app->make($resolver)),
                \is_callable($resolver) => new CallableSubscriberIdResolver(\Closure::fromCallable($resolver)),
                default => throw InvalidConfiguration::because('Cannot use a web-push.subscriber.resolver that is neither a SubscriberIdResolver class nor a callable.'),
            };
        });

        $this->app->singleton(LaravelAuthCurrentOwner::class, static fn (Container $app): LaravelAuthCurrentOwner => new LaravelAuthCurrentOwner(
            $app->make(AuthFactory::class),
            $app->make(SubscriberIdResolver::class),
            $app->make(WebPushConfiguration::class)->subscriberGuard(),
        ));
        $this->app->singleton(CurrentOwner::class, LaravelAuthCurrentOwner::class);
        $this->app->singleton(AnonymousGate::class, static fn (Container $app): AnonymousGate => new AnonymousGate(
            $app->make(CurrentOwner::class),
            $app->make(WebPushConfiguration::class)->anonymousAllowed(),
        ));

        $this->app->singleton(ClientStateMarkerFactory::class, static fn (Container $app): ClientStateMarkerFactory => new HmacClientStateMarkerFactory($app->make(WebPushConfiguration::class)->clientStateSecret()));
        $this->app->singleton(ClientConfiguration::class, static fn (Container $app): ClientConfiguration => new ClientConfiguration(
            $app->make(WebPushConfiguration::class)->clientSettings(),
            $app->make(ClientStateMarkerFactory::class),
        ));
        // Resolving the signer resolves Origin (APP_URL): on first use, never at boot.
        $this->app->singleton(ActionUrlSigner::class, static fn (Container $app): ActionUrlSigner => new LaravelActionUrlSigner($app->make(UrlGenerator::class), $app->make(Origin::class)));
        $this->app->singleton(WebPushMetaTag::class, static fn (Container $app): WebPushMetaTag => new WebPushMetaTag($app->make(ClientConfiguration::class), $app->make(CurrentOwner::class)));

        $this->app->bind(SubscribeController::class, static fn (Container $app): SubscribeController => new SubscribeController(
            $app->make(AnonymousGate::class),
            new ClientRateLimit($app->make(RateLimiter::class), 'subscribe', $app->make(WebPushConfiguration::class)->anonymousRateLimiter()),
            $app->make(RegisterSubscription::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->bind(UnsubscribeController::class, static fn (Container $app): UnsubscribeController => new UnsubscribeController(
            new ClientRateLimit($app->make(RateLimiter::class), 'unsubscribe', $app->make(WebPushConfiguration::class)->unsubscribeRateLimiter()),
            $app->make(Unsubscribe::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->bind(ServiceWorkerController::class, static function (Container $app): ServiceWorkerController {
            $configuration = $app->make(WebPushConfiguration::class);

            return new ServiceWorkerController($configuration->serviceWorkerScript(), $configuration->serviceWorkerConfig(), $configuration->serviceWorkerPath());
        });
    }

    private static function storage(Container $app): SubscriptionRepository&PurgeableSubscriptions&SubscriptionReadModel
    {
        $storage = $app->make(SubscriptionRepository::class);

        return $storage instanceof PurgeableSubscriptions && $storage instanceof SubscriptionReadModel
            ? $storage
            : throw InvalidConfiguration::because('Cannot use a SubscriptionRepository binding that does not also implement PurgeableSubscriptions and SubscriptionReadModel.');
    }

    /**
     * Applications tag theirs: $this->app->tag([MyListener::class], DeliveryOutcomeListener::class).
     *
     * @return list<DeliveryOutcomeListener>
     */
    private static function applicationListeners(Container $app): array
    {
        return array_values(array_filter(
            iterator_to_array($app->tagged(DeliveryOutcomeListener::class), false),
            static fn (mixed $listener): bool => $listener instanceof DeliveryOutcomeListener,
        ));
    }

    private static function configuredResolver(mixed $resolver): SubscriberIdResolver
    {
        return $resolver instanceof SubscriberIdResolver
            ? $resolver
            : throw InvalidConfiguration::because('Cannot use a web-push.subscriber.resolver class that does not implement '.SubscriberIdResolver::class.'.');
    }
}
