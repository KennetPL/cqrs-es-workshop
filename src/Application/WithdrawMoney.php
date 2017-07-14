<?php

namespace Application;

use Prooph\Common\Messaging\Command;

class WithdrawMoney extends Command
{
    private $id;

    private $amount;

    private $currency;

    private $transactionTitle;

    public function __construct($id, $amount, $currency, $transactionTitle = '')
    {
        $this->init();

        $this->id = $id;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->transactionTitle = $transactionTitle;
    }

    /**
     * @return mixed
     */
    public function id()
    {
        return $this->id;
    }

    public function amount()
    {
        return $this->amount;
    }

    public function currency()
    {
        return $this->currency;
    }

    public function transactionTitle() {
        return $this->transactionTitle;
    }

    public function payload()
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'transactionTitle' => $this->transactionTitle
        ];
    }

    protected function setPayload(array $payload)
    {
        $this->currency = $payload['currency'];
        $this->amount = $payload['amount'];
        $this->transactionTitle = $payload['transactionTitle'];
    }
}