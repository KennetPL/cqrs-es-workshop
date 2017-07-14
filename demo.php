<?php

use Application\AddMoney;
use Application\AddMoneyHandler;
use Application\CreateAccount;
use Application\CreateAccountHandler;
use Application\WithdrawMoney;
use Application\WithdrawMoneyHandler;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Domain\Account;
use Domain\Event\AccountCreated;
use Domain\Event\MoneyAdded;
use Domain\Event\MoneyWithdrawn;
use Infrastructure\EventHandler\AccountCreatedEventHandler;
use Infrastructure\EventHandler\AddMoneyEventHandler;
use Infrastructure\EventHandler\MoneyAddedEventHandler;
use Infrastructure\EventHandler\MoneyWithdrawnEventHandler;
use Infrastructure\EventSourcedAccountRepository;
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
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\EventStoreBusBridge\TransactionManager;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Plugin\Router\CommandRouter;
use Prooph\ServiceBus\Plugin\Router\EventRouter;
use Rhumsaa\Uuid\Uuid;

require_once __DIR__ . '/vendor/autoload.php';


$config = json_decode(file_get_contents(__DIR__ . '/config/app_prod.json'), true);
//@TODO brzydko
define('DAY_LIMIT', $config['day_limit']);

// Connection and schema setup
$connection = DriverManager::getConnection($config['db_configuration'], new Configuration());

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

//RabbitMQ setup
$rabbitMQClient = new \Infrastructure\RabbitMQClient(
    $config['queue_configuration']['host'],
    $config['queue_configuration']['port'],
    $config['queue_configuration']['user'],
    $config['queue_configuration']['pass'],
    $config['queue_configuration']['vhost'],
    $config['queue_configuration']['exchange'],
    $config['queue_configuration']['queue']
);

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
    ->to(new CreateAccountHandler($accountRepository));
$commandRouter
    ->route(AddMoney::class)
    ->to(new AddMoneyHandler($accountRepository));
$commandRouter
    ->route(WithdrawMoney::class)
    ->to(new WithdrawMoneyHandler($accountRepository));

$eventRouter
    ->route(AccountCreated::class)
    ->to(new AccountCreatedEventHandler($rabbitMQClient, $connection));

$eventRouter
    ->route(MoneyAdded::class)
    ->to(new MoneyAddedEventHandler($rabbitMQClient, $connection));

$eventRouter
    ->route(MoneyWithdrawn::class)
    ->to(new MoneyWithdrawnEventHandler($rabbitMQClient, $connection));

// Demo
//$id = Uuid::fromString('b660359c-0b3a-466c-a5fa-513d04dd4b75');
//$id = Uuid::uuid4();
//$commandBus->dispatch(new CreateAccount($id, 'PLN'));
//$commandBus->dispatch(new AddMoney($id, 1000, 'PLN'));
//$commandBus->dispatch(new WithdrawMoney($id, 50, 'PLN', 'Tytuł transakcji'));
//$commandBus->dispatch(new WithdrawMoney($id, 20, 'PLN', 'Lorem ipsum'));

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