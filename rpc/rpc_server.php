<?php

/*
 * RPC server: takes requests from "rpc_queue", computes the Fibonacci number
 * of the integer in each request and sends the result back to the caller.
 *
 * Run several of these in separate terminals to share the load.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

const QUEUE_NAME = 'rpc_queue';

// Deliberately slow recursive version, so a request takes long enough to watch.
function fib(int $n): int
{
    if ($n === 0) {
        return 0;
    }
    if ($n === 1) {
        return 1;
    }

    return fib($n - 1) + fib($n - 2);
}

$connection = createConnection();
$channel = $connection->channel();

// RabbitMQ 4 no longer allows non-durable, non-exclusive queues (the tutorial's original
// declaration), so this is a durable quorum queue, like task_queue in work-queues/.
// queue_declare($queue, $passive, $durable, $exclusive, $auto_delete, $nowait, $arguments)
$channel->queue_declare(QUEUE_NAME, false, true, false, false, false, new AMQPTable([
    'x-queue-type' => 'quorum',
]));

echo " [x] Awaiting RPC requests\n";

$callback = function (AMQPMessage $req) {
    $n = intval($req->getBody());
    echo ' [.] fib(', $n, ")\n";

    // Reply with the request's correlation_id, so the client can match the answer to its question.
    $reply = new AMQPMessage(
        (string) fib($n),
        ['correlation_id' => $req->get('correlation_id')]
    );

    // Send the answer through the default exchange straight to the queue named in reply_to.
    $req->getChannel()->basic_publish($reply, '', $req->get('reply_to'));

    // Ack only after replying: if the server dies mid-request, another server gets it.
    $req->ack();
};

// Fair dispatch: one request at a time per server.
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
