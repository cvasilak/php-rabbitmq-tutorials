<?php

/*
 * Producer: publishes a task to the durable "task_queue".
 *
 * Each dot in the message simulates one second of work in the worker:
 *   php new_task.php "Hello..."   -> takes the worker 3 seconds
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

const QUEUE_NAME = 'task_queue';

$connection = createConnection();
$channel = $connection->channel();

// Durable quorum queue: survives a broker restart.
// queue_declare($queue, $passive, $durable, $exclusive, $auto_delete, $nowait, $arguments)
$channel->queue_declare(QUEUE_NAME, false, true, false, false, false, new AMQPTable([
    'x-queue-type' => 'quorum',
]));

$data = implode(' ', array_slice($argv, 1));
if (empty($data)) {
    $data = 'Hello World!';
}

// Persistent message: written to disk so it is not lost if RabbitMQ restarts.
$msg = new AMQPMessage($data, [
    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
]);

$channel->basic_publish($msg, '', QUEUE_NAME);

echo ' [x] Sent ', $data, "\n";

$channel->close();
$connection->close();
