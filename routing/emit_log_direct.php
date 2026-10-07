<?php

/*
 * Publisher: sends a log message to the "direct_logs" exchange, using the
 * severity as the routing key.
 *
 * Only queues bound with that exact severity get the message.
 *   php emit_log_direct.php error "Run. Run. Or it will explode."
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;

const EXCHANGE_NAME = 'direct_logs';

$connection = createConnection();
$channel = $connection->channel();

// Direct exchange: delivers a message to the queues whose binding key equals its routing key.
// exchange_declare($exchange, $type, $passive, $durable, $auto_delete)
$channel->exchange_declare(EXCHANGE_NAME, 'direct', false, false, false);

$severity = $argv[1] ?? 'info';

$data = implode(' ', array_slice($argv, 2));
if (empty($data)) {
    $data = 'Hello World!';
}

$msg = new AMQPMessage($data);

// The third argument is the routing key; here it is the severity.
$channel->basic_publish($msg, EXCHANGE_NAME, $severity);

echo ' [x] Sent ', $severity, ':', $data, "\n";

$channel->close();
$connection->close();
