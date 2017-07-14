<?php

namespace Domain\Event;

use Prooph\EventSourcing\AggregateChanged;
use Rhumsaa\Uuid\Uuid;

class AccountCreated extends AggregateChanged
{
    const EVENT_NAME = 'ACCOUNT_CREATED';

    public static function from(Uuid $id, string $currency)
    {
        return self::occur(
            (string)$id,
            [
                'currency' => $currency
            ]
        );
    }

    public function currency()
    {
        return $this->payload['currency'];
    }
}