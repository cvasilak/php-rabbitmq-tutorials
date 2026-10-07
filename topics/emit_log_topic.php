<?php

/*
 * Publisher: sends a log message to the "topic_logs" exchange with a
 * dotted routing key of the form "<facility>.<severity>".
 *
 * Queues bound with a matching pattern get the message.
 *   php emit_log_topic.php kern.critical "A critical kernel error"
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;

const EXCHANGE_NAME = 'topic_logs';

$connection = createConnection();
$channel = $connection->channel();

// Topic exchange: routes by matching the routing key against binding patterns
// (* = exactly one word, # = zero or more words).
// exchange_declare($exchange, $type, $passive, $durable, $auto_delete)
$channel->exchange_declare(EXCHANGE_NAME, 'topic', false, false, false);

$routingKey = $argv[1] ?? 'anonymous.info';

$data = implode(' ', array_slice($argv, 2));
if (empty($data)) {
    $data = 'Hello World!';
}

$msg = new AMQPMessage($data);

// The routing key is a list of words separated by dots, up to 255 bytes.
$channel->basic_publish($msg, EXCHANGE_NAME, $routingKey);

echo ' [x] Sent ', $routingKey, ':', $data, "\n";

$channel->close();
$connection->close();
