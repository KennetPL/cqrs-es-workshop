<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 13.07.2017
 * Time: 08:11
 */
use Application\AddMoney;
use Application\AddMoneyHandler;
use Application\CreateAccount;
use Application\CreateAccountHandler;
use Application\WithdrawMoney;
use Application\WithdrawMoneyHandler;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Domain\Account;
use Domain\Event\AccountCreated;
use Domain\Event\MoneyAdded;
use Domain\Event\MoneyWithdrawn;
use Infrastructure\EventHandler\AccountCreatedEventHandler;
use Infrastructure\EventHandler\MoneyAddedEventHandler;
use Infrastructure\EventHandler\MoneyWithdrawnEventHandler;
use Infrastructure\EventSourcedAccountRepository;
use Infrastructure\RabbitMQClient;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Adapter\Doctrine\DoctrineEventStoreAdapter;
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
use Silex\Application;
use Silex\Provider\SerializerServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\CustomNormalizer;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/defines.php';

$app = new Application();

$app['debug'] = true;

$app->register(new SerializerServiceProvider());
$app['serializer.normalizers'] = function () {
    return array(new JsonSerializableNormalizer(), new CustomNormalizer(), new GetSetMethodNormalizer());
};

$app['db_connection'] = function() {
    $config = new Configuration();
    $connectionParams = array(
        'dbname' => getenv('DB_NAME'),
        'user' => getenv('DB_USER'),
        'password' => getenv('DB_PASSWORD'),
        'host' => getenv('DB_HOST'),
        'port' => getenv('DB_PORT'),
        'driver' => 'pdo_mysql',
    );
    return DriverManager::getConnection($connectionParams, $config);
};

$app['queue_client'] = function() {
    return new RabbitMQClient(RABIT_HOST, RABIT_PORT, RABIT_USER, RABIT_PASS, RABIT_VHOST);
};

$app['event_bus'] = function () {
    return new EventBus();
};
$app['event_store'] = function(Application $app) {
    $eventStore = new EventStore (
        new DoctrineEventStoreAdapter(
            $app['db_connection'],
            new FQCNMessageFactory(),
            new NoOpMessageConverter(),
            new JsonPayloadSerializer()
        ),
        new ProophActionEventEmitter()
    );
    (new EventPublisher($app['event_bus']))->setUp($eventStore);

    return $eventStore;
};
$app['event_router'] = function(Application $app) {
    $eventRouter = new EventRouter();
    $eventRouter
        ->route(AccountCreated::class)
        ->to(new AccountCreatedEventHandler($app['queue_client'], $app['db_connection']));
    $eventRouter
        ->route(MoneyAdded::class)
        ->to(new MoneyAddedEventHandler($app['queue_client'], $app['db_connection']));
    $eventRouter
        ->route(MoneyWithdrawn::class)
        ->to(new MoneyWithdrawnEventHandler($app['queue_client'], $app['db_connection']));

    return $eventRouter;
};

$app['repository.accounts'] = function(Application $app) {
    return new EventSourcedAccountRepository(
        new AggregateRepository(
            $app['event_store'],
            AggregateType::fromAggregateRootClass(Account::class),
            new AggregateTranslator()
        )
    );
};

$app['command_bus'] = function(Application $app) {
    $commandBus = new CommandBus();
    $transactionManager = new TransactionManager();
    $transactionManager->setUp($app['event_store']);
    $commandBus->utilize($transactionManager);

    return $commandBus;
};

$app['command_router'] = function(Application $app) {
    $commandRouter = new CommandRouter();
    $commandRouter
        ->route(CreateAccount::class)
        ->to(new CreateAccountHandler($app['repository.accounts']));
    $commandRouter
        ->route(AddMoney::class)
        ->to(new AddMoneyHandler($app['repository.accounts']));
    $commandRouter
        ->route(WithdrawMoney::class)
        ->to(new WithdrawMoneyHandler($app['repository.accounts']));

    return $commandRouter;
};

$app['event_router']->attach($app['event_bus']->getActionEventEmitter());
$app['command_router']->attach($app['command_bus']->getActionEventEmitter());



$app->get('/accounts.{_format}', function(Application $app, Request $request){
    /** @var \Doctrine\DBAL\Connection $connection */
    $connection = $app['db_connection'];

    $format = 'json';

    $accounts = $connection->fetchAll('SELECT * FROM accounts ORDER BY last_transaction_date DESC');
    return new Response($app['serializer']->serialize($accounts, $format), 200, [
        "Content-Type" => $request->getMimeType($format)
    ]);

});

$app->post('/accounts', function(Request $request, Application $app){
    $currency = $request->get('currency', 'PLN');

    $accountId = Uuid::uuid4();
    $app['command_bus']->dispatch(new CreateAccount($accountId, $currency));

    return new Response('', 201, [
        "Location" => "/accounts/" . (string) $accountId
    ]);
});

$app->get('/accounts/{accountId}', function($accountId, Application $app, Request $request) {
    /** @var Account $account */
    $account = $app['repository.accounts']->get($accountId);

    $format = 'json';
    return new Response($app['serializer']->serialize($account, $format), 200, array(
        "Content-Type" => $request->getMimeType($format)
    ));
})->convert('accountId', function($accountId){
    return Uuid::fromString($accountId);
});

$app->run();