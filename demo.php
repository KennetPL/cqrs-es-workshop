<?php

use Application\AddMoney;
use Application\AddMoneyHandler;
use Application\CreateAccount;
use Application\WithdrawMoney;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Domain\Account;
use Domain\Event\MoneyAdded;
use Domain\Event\MoneyWithdrawn;
use Infrastructure\EventHandler\AddMoneyEventHandler;
use Infrastructure\EventSourcedAccountRepository;
use Money\Currency;
use Money\Money;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Adapter\Doctrine\DoctrineEventStoreAdapter;
use Prooph\EventStore\Adapter\Doctrine\Schema\EventStoreSchema;
use Prooph\EventStore\Adapter\PayloadSerializer\JsonPayloadSerializer;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Prooph\EventStore\Aggregate\AggregateType;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Stream\StreamName;
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\EventStoreBusBridge\TransactionManager;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Plugin\Router\CommandRouter;
use Prooph\ServiceBus\Plugin\Router\EventRouter;
use Rhumsaa\Uuid\Uuid;

require_once __DIR__ . '/vendor/autoload.php';

// Connection and schema setup
$config = new Configuration();
$connectionParams = array(
    'dbname' => getenv('DB_NAME'),
    'user' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'host' => getenv('DB_HOST'),
    'port' => getenv('DB_PORT'),
    'driver' => 'pdo_mysql',
);

define('DAY_LIMIT', 100);


$connection = DriverManager::getConnection($connectionParams, $config);

$schema = $connection->getSchemaManager()->createSchema();

try {
    EventStoreSchema::createSingleStream($schema, 'event_stream', true);

    foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
        $connection->exec($sql);
    }

} catch (SchemaException $e) {}

try {
    $sql = "CREATE TABLE accounts (
                id char(36) PRIMARY KEY, 
                balance INTEGER NOT NULL,            
                currency VARCHAR(50) NOT NULL,
                transactions INTEGER NOT NULL,
                last_transaction_date TIMESTAMP
    )";
    $connection->exec($sql);
} catch (\Exception $e) {}

$rabbitMQClient = new \Infrastructure\RabbitMQClient('rabbit1', 5672, 'rabbitmq', 'rabbitmq', '/');
$rabbitMQClient->sendMessage('Hello world!');


// Event bus and event store setup
$eventBus = new EventBus();
$eventStore = new EventStore(
    new DoctrineEventStoreAdapter(
        $connection,
        new FQCNMessageFactory(),
        new NoOpMessageConverter(),
        new JsonPayloadSerializer()
    ),
    new ProophActionEventEmitter()
);

$eventRouter = new EventRouter();

$eventRouter->attach($eventBus->getActionEventEmitter());

(new EventPublisher($eventBus))->setUp($eventStore);

// Repo setup
$accountRepository = new EventSourcedAccountRepository(
    new AggregateRepository(
        $eventStore,
        AggregateType::fromAggregateRootClass(Account::class),
        new AggregateTranslator()
    )
);

// Command bus setup
$commandBus = new CommandBus();

$transactionManager = new TransactionManager();

$transactionManager->setUp($eventStore);
$commandBus->utilize($transactionManager);

$commandRouter = new CommandRouter();

$commandRouter->attach($commandBus->getActionEventEmitter());

// Routing
$commandRouter
    ->route(CreateAccount::class)
    ->to(new \Application\CreateAccountHandler($accountRepository));
$commandRouter
    ->route(AddMoney::class)
    ->to(new AddMoneyHandler($accountRepository));
$commandRouter
    ->route(WithdrawMoney::class)
    ->to(new \Application\WithdrawMoneyHandler($accountRepository));

$eventRouter
    ->route(\Domain\Event\AccountCreated::class)
    ->to(function (\Domain\Event\AccountCreated $event) use ($connection) {
        var_dump('CREATED: ' . $event->currency());
        $connection->executeQuery('DELETE FROM accounts WHERE id = ?', array((string)$event->aggregateId()));
        $connection->executeQuery('INSERT INTO accounts (id, balance, currency, transactions, last_transaction_date) VALUES (?,?,?,?,?)', array(
            (string)$event->aggregateId(),
            0,
            (string)$event->currency(),
            0,
            null
        ));
    });

$eventRouter
    ->route(MoneyAdded::class)
    ->to(new AddMoneyEventHandler($rabbitMQClient, $connection));

$eventRouter
    ->route(MoneyWithdrawn::class)
    ->to(function (MoneyWithdrawn $event) use ($connection) {
        var_dump('WITHDRAWN: ' . $event->amount());
        $connection->executeQuery('UPDATE accounts SET balance = balance - ?, transactions = transactions + 1, last_transaction_date = ? WHERE id = ?', array(
            $event->amount(),
            $event->createdAt()->format('Y-m-d H:i:s'),
            (string)$event->aggregateId()
        ));
    });

// Demo
//$id = Uuid::fromString('27ca6f93-ddde-41a7-a62b-b2cbd2af51e5');
$id = Uuid::uuid4();
$commandBus->dispatch(new CreateAccount($id, 'PLN'));
$commandBus->dispatch(new AddMoney($id, 1500, 'PLN'));
$commandBus->dispatch(new WithdrawMoney($id, 50, 'PLN'));
$commandBus->dispatch(new WithdrawMoney($id, 30, 'PLN'));

//var_dump($accountRepository->get($id));

//$events = $eventStore->loadEventsByMetadataFrom(new StreamName('event_stream'), []);
//
//foreach ($events as $event) {
//    //var_dump(get_class($event));
//    $eventBus->dispatch($event);
//}

//
//192.168.96.125:32778
//
//192.168.96.125:32781
//guest/guest