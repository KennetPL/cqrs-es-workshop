<?php

namespace Domain;

use Domain\Event\AccountCreated;
use Domain\Event\MoneyAdded;
use Domain\Event\MoneyWithdrawn;
use Money\Currency;
use Money\Money;
use Prooph\EventSourcing\AggregateRoot;
use Rhumsaa\Uuid\Uuid;

class Account extends AggregateRoot implements \JsonSerializable
{
    /**
     * @var Uuid
     */
    private $id;

    /**
     * @var Money
     */
    private $balance;

    /**
     * @var AccountState
     */
    private $state;

    /**
     * @var Money
     */
    private $todayWithdrawn;

    public static function new(Uuid $id, string $currency)
    {
        $self = new self();

        $self->recordThat(AccountCreated::from($id, $currency));

        return $self;
    }

    public function add(Money $money)
    {
        // ?
        $this->recordThat(MoneyAdded::from($this->id, $money->getAmount(), $money->getCurrency()));
    }

    public function withdraw(Money $money)
    {
        $afterWithdraw = $money->add($this->todayWithdrawn);

        if ($afterWithdraw->getAmount() > DAY_LIMIT) {
            throw new \Exception('Hola hola. Wypłaciłeś już dzisiaj ' . $this->todayWithdrawn->getAmount() .
            '! Nie możesz wybrać kolejnych ' . $money->getAmount() . $money->getCurrency()->getCode() .
                '! Limit wynosi: ' . DAY_LIMIT . 'PLN');
        }

        $this->recordThat(MoneyWithdrawn::from($this->id, $money->getAmount(), $money->getCurrency()));
    }

    protected function aggregateId()
    {
        return (string)$this->id;
    }

    /**
     * @return string
     */
    public function id()
    {
        return $this->aggregateId();
    }

    protected function whenAccountCreated(AccountCreated $event)
    {
        $this->id = Uuid::fromString($event->aggregateId());
        $this->state = AccountState::ACTIVE();
        $this->balance = new Money(0, new Currency($event->currency()));
        $this->todayWithdrawn = new Money(0, new Currency($event->currency()));
    }

    protected function whenMoneyAdded(MoneyAdded $event)
    {
        $this->balance = $this->balance->add(new Money($event->amount(), new Currency($event->currency())));
    }

    protected function whenMoneyWithdrawn(MoneyWithdrawn $event)
    {
        $money = new Money($event->amount(), new Currency($event->currency()));

        $today = new \DateTime(date('Y-m-d'));
        $match_date = new \DateTime($event->createdAt()->format('Y-m-d'));

        $diff = $today->diff($match_date);
        if ($diff->days === 0) {
            $this->todayWithdrawn = $this->todayWithdrawn->add($money);
        }

        $this->balance = $this->balance->subtract($money);

    }

    function jsonSerialize()
    {
        return [
            'id' => $this->id(),
            'balance' => $this->balance->getAmount(),
            'currency' => $this->balance->getCurrency(),
            'todayWithdrawn' => $this->todayWithdrawn->getAmount()
        ];
    }
}