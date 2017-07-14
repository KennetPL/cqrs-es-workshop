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

    protected $exchange;

    protected $queue;

    /** @var \PhpAmqpLib\Channel\AMQPChannel  */
    protected $channel;

    /** @var AMQPStreamConnection  */
    protected $connection;

    public function __construct($host, $port, $user, $pass, $vhost, $exchange = 'router', $queue = 'messages')
    {
        $this->connection = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
        $this->channel = $this->connection->channel();

        $this->queue = $queue;
        $this->exchange = $exchange;

        $this->channel->queue_declare($this->queue, false, true, false, false);
        $this->channel->exchange_declare($this->exchange, 'fanout', false, true, false);
        $this->channel->queue_bind($this->queue, $this->exchange);
    }

    public function sendMessage($messageBody)
    {
        if (is_array($messageBody) || is_object($messageBody)) {
            $messageBody = json_encode($messageBody);
        }

        $message = new AMQPMessage($messageBody, array(
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ));
        $this->channel->basic_publish($message, $this->exchange);
    }

    public function __destruct()
    {
        $this->channel->close();
        $this->connection->close();
    }

}