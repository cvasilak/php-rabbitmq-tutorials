<?php

/*
 * Publisher: broadcasts a log message to the "logs" fanout exchange.
 *
 * It publishes to the exchange, not to a queue. Every queue bound to the
 * exchange gets a copy. If no receiver is running, the message is dropped.
 *   php emit_log.php "warning: disk almost full"
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;

const EXCHANGE_NAME = 'logs';

$connection = createConnection();
$channel = $connection->channel();

// Fanout exchange: copies every message to all bound queues and ignores the routing key.
// exchange_declare($exchange, $type, $passive, $durable, $auto_delete)
$channel->exchange_declare(EXCHANGE_NAME, 'fanout', false, false, false);

$data = implode(' ', array_slice($argv, 1));
if (empty($data)) {
    $data = 'info: Hello World!';
}

$msg = new AMQPMessage($data);

// Publish to the exchange with an empty routing key (fanout ignores it).
$channel->basic_publish($msg, EXCHANGE_NAME);

echo ' [x] Sent ', $data, "\n";

$channel->close();
$connection->close();
