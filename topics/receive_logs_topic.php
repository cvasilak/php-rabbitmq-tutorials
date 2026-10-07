<?php

/*
 * Subscriber: prints the logs whose routing key matches one of the
 * binding patterns given on the command line.
 *
 *   php receive_logs_topic.php "#"                   # everything
 *   php receive_logs_topic.php "kern.*"              # every severity from the kernel
 *   php receive_logs_topic.php "*.critical"          # critical logs from any facility
 *   php receive_logs_topic.php "kern.*" "*.critical"
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;

const EXCHANGE_NAME = 'topic_logs';

$bindingKeys = array_slice($argv, 1);
if (empty($bindingKeys)) {
    file_put_contents('php://stderr', "Usage: $argv[0] [binding_key]\n");
    exit(1);
}

$connection = createConnection();
$channel = $connection->channel();

// Must match the declaration in emit_log_topic.php (declaring is idempotent).
$channel->exchange_declare(EXCHANGE_NAME, 'topic', false, false, false);

// Temporary queue: server-named and deleted when this connection closes.
// queue_declare($queue, $passive, $durable, $exclusive, $auto_delete)
[$queueName, ,] = $channel->queue_declare('', false, false, true, false);

// One binding per pattern. A message that matches several of them is still delivered once.
foreach ($bindingKeys as $bindingKey) {
    $channel->queue_bind($queueName, EXCHANGE_NAME, $bindingKey);
}

echo " [*] Waiting for logs. To exit press CTRL+C\n";

$callback = function (AMQPMessage $msg) {
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
