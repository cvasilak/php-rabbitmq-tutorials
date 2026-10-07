<?php

/*
 * RPC client: asks the server for fib(n) and blocks until the answer arrives.
 *
 * Each client has its own callback queue; the server sends the reply there.
 *   php rpc_client.php        # fib(30)
 *   php rpc_client.php 10
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

const QUEUE_NAME = 'rpc_queue';
const TIMEOUT_SECONDS = 30;

class FibonacciRpcClient
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;
    private string $callbackQueue;
    private ?string $response = null;
    private ?string $corrId = null;

    public function __construct()
    {
        $this->connection = createConnection();
        $this->channel = $this->connection->channel();

        // Callback queue: server-named and exclusive, so only this client reads its replies.
        // queue_declare($queue, $passive, $durable, $exclusive, $auto_delete)
        [$this->callbackQueue, ,] = $this->channel->queue_declare('', false, false, true, false);

        // $no_ack = true -> a reply is used at most once, nothing to redeliver.
        // basic_consume($queue, $consumer_tag, $no_local, $no_ack, $exclusive, $nowait, $callback)
        $this->channel->basic_consume($this->callbackQueue, '', false, true, false, false, [$this, 'onResponse']);
    }

    public function onResponse(AMQPMessage $rep): void
    {
        // Ignore replies to other requests (e.g. a late answer to one that timed out).
        if ($rep->get('correlation_id') === $this->corrId) {
            $this->response = $rep->getBody();
        }
    }

    public function call(int $n): int
    {
        $this->response = null;
        $this->corrId = uniqid('', true);

        $msg = new AMQPMessage((string) $n, [
            'correlation_id' => $this->corrId,   // lets us match the reply to this request
            'reply_to' => $this->callbackQueue,  // tells the server where to send the reply
        ]);
        $this->channel->basic_publish($msg, '', QUEUE_NAME);

        // Process incoming messages until our reply has arrived.
        // wait($allowed_methods, $non_blocking, $timeout) throws AMQPTimeoutException on timeout.
        while ($this->response === null) {
            $this->channel->wait(null, false, TIMEOUT_SECONDS);
        }

        return intval($this->response);
    }

    public function close(): void
    {
        $this->channel->close();
        $this->connection->close();
    }
}

$n = intval($argv[1] ?? 30);

$fibonacciRpc = new FibonacciRpcClient();

echo ' [x] Requesting fib(', $n, ")\n";

$exitCode = 0;
try {
    $response = $fibonacciRpc->call($n);
    echo ' [.] Got ', $response, "\n";
} catch (AMQPTimeoutException $exception) {
    echo ' [!] No reply within ', TIMEOUT_SECONDS, " seconds. Is rpc_server.php running?\n";
    $exitCode = 1;
}

$fibonacciRpc->close();
exit($exitCode);
