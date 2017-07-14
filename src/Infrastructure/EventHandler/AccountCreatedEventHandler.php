<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 14:09
 */

namespace Infrastructure\EventHandler;


use Doctrine\DBAL\Connection;
use Domain\Event\AccountCreated;
use Domain\QueueClient;

class AccountCreatedEventHandler
{
    /** @var  Connection */
    protected $connection;

    /** @var  QueueClient @var  */
    protected $queueClient;

    /**
     * AccountCreatedEventHandler constructor.
     * @param QueueClient $queueClient
     * @param Connection $connection
     */
    public function __construct(QueueClient $queueClient, Connection $connection)
    {
        $this->connection = $connection;
        $this->queueClient = $queueClient;
    }

    public function __invoke(AccountCreated $event)
    {
        $this->connection->executeQuery('DELETE FROM accounts WHERE id = ?', array((string)$event->aggregateId()));
        $this->connection->executeQuery('INSERT INTO accounts (id, balance, currency, transactions, last_transaction_date) VALUES (?,?,?,?,?)', array(
            (string)$event->aggregateId(),
            0,
            (string)$event->currency(),
            0,
            null
        ));

        $this->queueClient->sendMessage(array(
            'accountId' => $event->aggregateId(),
            'event' => AccountCreated::class
        ));
    }

}