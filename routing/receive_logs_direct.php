<?php

/*
 * Subscriber: prints only the logs whose severity was given on the command line.
 *
 * It binds its temporary queue to the "direct_logs" exchange once per severity.
 *   php receive_logs_direct.php warning error > logs_from_rabbit.log
 *   php receive_logs_direct.php info warning error
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;

const EXCHANGE_NAME = 'direct_logs';

$severities = array_slice($argv, 1);
if (empty($severities)) {
    file_put_contents('php://stderr', "Usage: $argv[0] [info] [warning] [error]\n");
    exit(1);
}

$connection = createConnection();
$channel = $connection->channel();

// Must match the declaration in emit_log_direct.php (declaring is idempotent).
$channel->exchange_declare(EXCHANGE_NAME, 'direct', false, false, false);

// Temporary queue: server-named and deleted when this connection closes.
// queue_declare($queue, $passive, $durable, $exclusive, $auto_delete)
[$queueName, ,] = $channel->queue_declare('', false, false, true, false);

// One binding per severity. The binding key is the third argument of queue_bind().
foreach ($severities as $severity) {
    $channel->queue_bind($queueName, EXCHANGE_NAME, $severity);
}

echo " [*] Waiting for logs. To exit press CTRL+C\n";

$callback = function (AMQPMessage $msg) {
    // getRoutingKey() returns the key the message was published with, i.e. its severity.
    echo ' [x] ', $msg->getRoutingKey(), ':', $msg->getBody(), "\n";
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
