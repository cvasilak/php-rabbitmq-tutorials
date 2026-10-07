<?php

/*
 * Consumer: takes tasks from "task_queue" and "works" on them,
 * sleeping one second for every dot in the message body.
 *
 * Run several of these in separate terminals to see the work being shared.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

const QUEUE_NAME = 'task_queue';

$connection = createConnection();
$channel = $connection->channel();

// Must match the declaration in new_task.php (declaring is idempotent).
$channel->queue_declare(QUEUE_NAME, false, true, false, false, false, new AMQPTable([
    'x-queue-type' => 'quorum',
]));

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function (AMQPMessage $msg) {
    echo ' [x] Received ', $msg->getBody(), "\n";
    sleep(substr_count($msg->getBody(), '.'));
    echo " [x] Done\n";

    // Manual acknowledgement: only now may RabbitMQ delete the message.
    // If the worker dies before this line, the task is redelivered to another worker.
    $msg->ack();
};

// Fair dispatch: don't give this worker a new message until it has acked the previous one.
// basic_qos($prefetch_size, $prefetch_count, $a_global)
$channel->basic_qos(null, 1, false);

// basic_consume($queue, $consumer_tag, $no_local, $no_ack, $exclusive, $nowait, $callback)
// $no_ack = false -> manual acknowledgements are enabled.
$channel->basic_consume(QUEUE_NAME, '', false, false, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();
