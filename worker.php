<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 14:15
 */
require_once __DIR__ . '/vendor/autoload.php';


$config = json_decode(file_get_contents(__DIR__ . '/config/app.json'), true);

$exchange = $config['queue_configuration']['exchange'];
$queue = $config['queue_configuration']['queue'];


$connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
    $config['queue_configuration']['host'],
    $config['queue_configuration']['port'],
    $config['queue_configuration']['user'],
    $config['queue_configuration']['pass'],
    $config['queue_configuration']['vhost']
);
$channel = $connection->channel();
$channel->queue_declare($queue, false, true, false, false);
$channel->exchange_declare($exchange, 'direct', false, true, false);
$channel->queue_bind($queue, $exchange);

/**
 * @param \PhpAmqpLib\Message\AMQPMessage $message
 */
function process_message($message)
{
    echo "\n--------\n";
    print_r(json_decode($message->body));
    echo "\n--------\n";

    $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);

    // Send a message with the string "quit" to cancel the consumer.
    if ($message->body === 'quit') {
        $message->delivery_info['channel']->basic_cancel($message->delivery_info['consumer_tag']);
    }
}
$channel->basic_consume($queue, $consumerTag, false, false, false, false, 'process_message');

/**
 * @param \PhpAmqpLib\Channel\AMQPChannel $channel
 * @param \PhpAmqpLib\Connection\AbstractConnection $connection
 */
function shutdown($channel, $connection)
{
    $channel->close();
    $connection->close();
}

register_shutdown_function('shutdown', $channel, $connection);

// Loop as long as the channel has callbacks registered
while (count($channel->callbacks)) {
    $channel->wait();
}