<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 13.07.2017
 * Time: 08:11
 */
use Application\CreateAccount;
use Domain\Account;
use Rhumsaa\Uuid\Uuid;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new Application();

$serviceLoader = new \Application\ServiceLoader($app);
$serviceLoader->loadServices();

$app->get('/accounts', function (Application $app, Request $request) {
    /** @var \Doctrine\DBAL\Connection $connection */
    $connection = $app['db_connection'];

    $format = 'json';
    $accounts = $connection->fetchAll('SELECT * FROM accounts ORDER BY last_transaction_date DESC');
    return new Response($app['serializer']->serialize($accounts, $format), 200, [
        "Content-Type" => $request->getMimeType($format)
    ]);

});

$app->post('/accounts', function (Request $request, Application $app) {
    $currency = $request->get('currency', 'PLN');

    $accountId = Uuid::uuid4();
    $app['command_bus']->dispatch(new CreateAccount($accountId, $currency));

    return new Response('', 201, [
        "Location" => "/accounts/" . (string)$accountId
    ]);
});

$app->get('/accounts/{accountId}', function ($accountId, Application $app, Request $request) {
    /** @var Account $account */
    $account = $app['repository.accounts']->get($accountId);

    $format = 'json';
    return new Response($app['serializer']->serialize($account, $format), 200, array(
        "Content-Type" => $request->getMimeType($format)
    ));
})->convert('accountId', function ($accountId) {
    return Uuid::fromString($accountId);
});

$app->run();