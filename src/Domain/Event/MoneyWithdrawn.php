<?php

namespace Domain\Event;

use Prooph\EventSourcing\AggregateChanged;
use Rhumsaa\Uuid\Uuid;

class MoneyWithdrawn extends AggregateChanged
{
    const EVENT_NAME = 'MONEY_WITHDRAWN';

    public static function from(Uuid $id, $amount, string $currency, string $transactionTitle = '')
    {
        return self::occur(
            (string)$id,
            [
                'amount' => $amount,
                'currency' => $currency,
                'transactionTitle' => $transactionTitle
            ]
        );
    }

    public function amount()
    {
        return $this->payload['amount'];
    }

    public function currency()
    {
        return $this->payload['currency'];
    }

    public function transactionTitle()
    {
        return $this->payload['transactionTitle'];
    }
}