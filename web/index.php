<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 13.07.2017
 * Time: 08:11
 */
use Application\CreateAccount;
use Application\WithdrawMoney;
use Domain\Account;
use Rhumsaa\Uuid\Uuid;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new Application();

$serviceLoader = new \Application\ServiceLoader($app);
$serviceLoader->loadServices();

$app->get('/', function() {
    $html = '<h1>Account API v0.0.1 beta</h1>' .
        '<h2>endpoints:</h2>' .
        '<p>
            <strong>GET</strong> http://192.168.96.22:85/index.php/accounts</br>
            <strong>POST</strong> http://192.168.96.22:85/index.php/accounts (currency)</br>
            <strong>GET</strong> http://192.168.96.22:85/index.php/accounts/{accountId}</br>
            <strong>PUT</strong> http://192.168.96.22:85/index.php/accounts/{accountId}/withdraw (amount, currency)</br>
        </p>';
    return new Response($html);
});

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

$app->put('/accounts/{accountId}/withdraw', function($accountId, Application $app, Request $request) {
    $amount = $request->get('amount');
    $currency = $request->get('currency', 'PLN');
    $app['command_bus']->dispatch(new WithdrawMoney($accountId, $amount, $currency));

    return new Response('', 204);
})->convert('accountId', function ($accountId) {
    return Uuid::fromString($accountId);
});;

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

$app->error(function (\Exception $e, $code) {
    return new Response($e->getMessage());
});

$app->run();