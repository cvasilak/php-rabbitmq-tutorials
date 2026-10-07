<?php

/*
 * Subscriber: prints every message broadcast to the "logs" exchange.
 *
 * Each receiver gets its own temporary queue, so every running receiver
 * sees every message. To save the logs to a file instead:
 *   php receive_logs.php > logs_from_rabbit.log
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;

const EXCHANGE_NAME = 'logs';

$connection = createConnection();
$channel = $connection->channel();

// Must match the declaration in emit_log.php (declaring is idempotent).
$channel->exchange_declare(EXCHANGE_NAME, 'fanout', false, false, false);

// Temporary queue: an empty name makes the server pick a random one (e.g. amq.gen-JzTY20BRgKO-HjmUJj0wLg),
// and exclusive = true deletes the queue when this connection closes.
// queue_declare($queue, $passive, $durable, $exclusive, $auto_delete)
[$queueName, ,] = $channel->queue_declare('', false, false, true, false);

// Binding: tell the exchange to send its messages to this queue.
$channel->queue_bind($queueName, EXCHANGE_NAME);

echo " [*] Waiting for logs. To exit press CTRL+C\n";

$callback = function (AMQPMessage $msg) {
    echo ' [x] ', $msg->getBody(), "\n";
};

// basic_consume($queue, $consumer_tag, $no_local, $no_ack, $exclusive, $nowait, $callback)
// $no_ack = true -> automatic acknowledgement; losing a log line is acceptable here.
$channel->basic_consume($queueName, '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();
