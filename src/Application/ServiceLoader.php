<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 13.07.2017
 * Time: 11:06
 */
namespace Application;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Domain\Account;
use Domain\Event\AccountCreated;
use Domain\Event\MoneyAdded;
use Domain\Event\MoneyWithdrawn;
use Igorw\Silex\ConfigServiceProvider;
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
use Silex\Application;
use Silex\Provider\SerializerServiceProvider;
use Symfony\Component\Serializer\Normalizer\CustomNormalizer;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;

class ServiceLoader
{
    /** @var  Application */
    protected $app;

    /**
     * ServiceLoader constructor.
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function loadServices()
    {
        $this->app->register(new SerializerServiceProvider());
        $this->app['serializer.normalizers'] = function () {
            return array(new JsonSerializableNormalizer(), new CustomNormalizer(), new GetSetMethodNormalizer());
        };

        $this->app->register(new ConfigServiceProvider(__DIR__ . "/../../config/app_prod.json"));

        //@TODO brzydko
        define('DAY_LIMIT', $this->app['day_limit']);

        $this->app['db_connection'] = function (Application $app) {
            return DriverManager::getConnection($app['db_configuration'], new Configuration());
        };

        $this->app['queue_client'] = function (Application $app) {
            return new RabbitMQClient(
                $app['queue_configuration']['host'],
                $app['queue_configuration']['port'],
                $app['queue_configuration']['user'],
                $app['queue_configuration']['pass'],
                $app['queue_configuration']['vhost'],
                $app['queue_configuration']['exchange'],
                $app['queue_configuration']['queue']
            );
        };

        $this->app['event_bus'] = function () {
            return new EventBus();
        };
        $this->app['event_store'] = function (Application $app) {
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
        $this->app['event_router'] = function (Application $app) {
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

        $this->app['repository.accounts'] = function (Application $app) {
            return new EventSourcedAccountRepository(
                new AggregateRepository(
                    $app['event_store'],
                    AggregateType::fromAggregateRootClass(Account::class),
                    new AggregateTranslator()
                )
            );
        };

        $this->app['command_bus'] = function (Application $app) {
            $commandBus = new CommandBus();
            $transactionManager = new TransactionManager();
            $transactionManager->setUp($app['event_store']);
            $commandBus->utilize($transactionManager);

            return $commandBus;
        };

        $this->app['command_router'] = function (Application $app) {
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

        $this->app['event_router']->attach($this->app['event_bus']->getActionEventEmitter());
        $this->app['command_router']->attach($this->app['command_bus']->getActionEventEmitter());
    }
}