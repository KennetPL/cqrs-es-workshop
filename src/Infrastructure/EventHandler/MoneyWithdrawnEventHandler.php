<?php

namespace Infrastructure\EventHandler;

use Domain\Event\MoneyAdded;
use Domain\Event\MoneyWithdrawn;

/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 13:51
 */
class MoneyWithdrawnEventHandler
{
    /** @var  \Domain\QueueClient */
    protected $queueClient;

    /** @var  \Doctrine\DBAL\Connection */
    protected $connection;

    /**
     * AddMoneyEventHandler constructor.
     * @param \Domain\QueueClient $queueClient
     * @param \Doctrine\DBAL\Connection $pdoConnection
     */
    public function __construct(\Domain\QueueClient $queueClient, \Doctrine\DBAL\Connection $pdoConnection)
    {
        $this->queueClient = $queueClient;
        $this->connection = $pdoConnection;
    }

    public function __invoke(MoneyWithdrawn $event)
    {
        $this->connection->executeQuery('UPDATE accounts SET balance = balance - ?, transactions = transactions + 1, last_transaction_date = ? WHERE id = ?', array(
            $event->amount(),
            $event->createdAt()->format('Y-m-d H:i:s'),
            (string)$event->aggregateId()
        ));

        $this->queueClient->sendMessage(array(
            'accountId' => $event->aggregateId(),
            'event' => MoneyWithdrawn::class,
            'amount' => $event->amount()
        ));
    }
}