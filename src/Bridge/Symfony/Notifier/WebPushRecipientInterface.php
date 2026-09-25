<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Notifier;

use Symfony\Component\Notifier\Recipient\RecipientInterface;

/** A Notifier recipient that can be reached by web push: its stable subscriber id. */
interface WebPushRecipientInterface extends RecipientInterface
{
    public function getWebPushSubscriberId(): string;
}
