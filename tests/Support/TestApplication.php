<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use Psr\Log\NullLogger;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Application\DeliverPayload;
use RomainMillan\WebPushNotification\Application\DeliveryOutcomeListeners;
use RomainMillan\WebPushNotification\Application\EventPublisher;
use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Application\RegistrationRules;
use RomainMillan\WebPushNotification\Application\RetirementPolicy;
use RomainMillan\WebPushNotification\Application\RetireOnExpiry;
use RomainMillan\WebPushNotification\Application\SubscriptionQuota;
use RomainMillan\WebPushNotification\Application\SubscriptionTransaction;
use RomainMillan\WebPushNotification\Application\WebPushSender;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\NullSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\InMemory\InMemorySubscriptionRepository;
use RomainMillan\WebPushNotification\Infrastructure\InMemory\InMemoryTransactionBoundary;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;

/**
 * The core wired with its in-memory reference adapters, as the bridges wire it with
 * Doctrine or Eloquent.
 */
final class TestApplication
{
    public readonly FrozenClock $clock;
    public readonly RecordingEventDispatcher $events;
    public readonly RecordingPushTransport $transport;
    public readonly InMemorySubscriptionRepository $subscriptions;
    public readonly InMemoryTransactionBoundary $transactionBoundary;
    public SubscriptionTransaction $subscriptionTransaction;
    public readonly AllowedPushServices $allowedPushServices;
    private readonly SequentialIdGenerator $idGenerator;
    public readonly StrictSubscriptionLock $lock;

    /**
     * @param list<DeliveryOutcomeListener> $listeners
     * @param list<string>                  $extraHosts
     */
    public function __construct(
        public readonly RetirementPolicy $retirementPolicy = RetirementPolicy::Deactivate,
        private readonly int $maxPerSubscriber = 16,
        private readonly int $maxAnonymous = 10000,
        private readonly array $listeners = [],
        array $extraHosts = [],
    ) {
        $this->clock = new FrozenClock();
        $this->idGenerator = new SequentialIdGenerator();
        $this->lock = new StrictSubscriptionLock();
        $this->events = new RecordingEventDispatcher();
        $this->transport = new RecordingPushTransport();
        $this->allowedPushServices = AllowedPushServices::createWithKnownServices($extraHosts);
        $this->subscriptions = new InMemorySubscriptionRepository(new SubscriptionRowMapper(new NullSubscriptionCipher()), $this->allowedPushServices);
        $this->transactionBoundary = new InMemoryTransactionBoundary();
        $this->subscriptionTransaction = new SubscriptionTransaction(
            $this->subscriptions,
            $this->transactionBoundary,
            $this->lock,
            new EventPublisher($this->events, $this->transactionBoundary),
        );
    }

    /** The same application writing through another repository (decorated for a test). */
    public function withRepository(SubscriptionRepository $subscriptionRepository): self
    {
        $clone = clone $this;
        $clone->subscriptionTransaction = new SubscriptionTransaction(
            $subscriptionRepository,
            $this->transactionBoundary,
            $this->lock,
            new EventPublisher($this->events, $this->transactionBoundary),
        );

        return $clone;
    }

    public function registerSubscription(): RegisterSubscription
    {
        return new RegisterSubscription(
            $this->subscriptionTransaction,
            new RegistrationRules($this->allowedPushServices, SubscriptionQuota::fromLimits($this->maxPerSubscriber, $this->maxAnonymous), $this->retirementPolicy),
            $this->idGenerator,
            $this->clock,
        );
    }

    public function deliverPayload(): DeliverPayload
    {
        return new DeliverPayload(
            $this->subscriptions,
            $this->transport,
            $this->allowedPushServices,
            new DeliveryOutcomeListeners(new RetireOnExpiry($this->subscriptionTransaction, $this->retirementPolicy, $this->clock), $this->listeners, new NullLogger()),
        );
    }

    public function sender(): WebPushSender
    {
        return new WebPushSender(new PayloadEncoder(), $this->deliverPayload());
    }
}
