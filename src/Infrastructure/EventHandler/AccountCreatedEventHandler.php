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

class AccountCreatedEventHandler
{
    /** @var  Connection */
    protected $connection;

    /**
     * AccountCreatedEventHandler constructor.
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function __invoke(AccountCreated $event)
    {
        var_dump('CREATED: ' . $event->currency());
        $this->connection->executeQuery('DELETE FROM accounts WHERE id = ?', array((string)$event->aggregateId()));
        $this->connection->executeQuery('INSERT INTO accounts (id, balance, currency, transactions, last_transaction_date) VALUES (?,?,?,?,?)', array(
            (string)$event->aggregateId(),
            0,
            (string)$event->currency(),
            0,
            null
        ));
    }

}