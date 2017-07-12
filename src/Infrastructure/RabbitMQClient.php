<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 13:31
 */

namespace Infrastructure;


use Domain\QueueClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQClient implements QueueClient
{

    const EXCHANGE = 'mk-router';

    const QUEUE = 'mk-messages';

    /** @var \PhpAmqpLib\Channel\AMQPChannel  */
    protected $channel;

    /** @var AMQPStreamConnection  */
    protected $connection;

    public function __construct($host, $port, $user, $pass, $vhost)
    {
        $this->connection = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
        $this->channel = $this->connection->channel();

        $this->channel->queue_declare(static::QUEUE, false, true, false, false);
        $this->channel->exchange_declare(static::EXCHANGE, 'direct', false, true, false);
        $this->channel->queue_bind(static::QUEUE, static::EXCHANGE);
    }

    public function sendMessage($messageBody)
    {
        $message = new AMQPMessage($messageBody, array(
            'content_type' => 'text/plain',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ));
        $this->channel->basic_publish($message, static::EXCHANGE);
    }

    public function __destruct()
    {
        $this->channel->close();
        $this->connection->close();
    }

}