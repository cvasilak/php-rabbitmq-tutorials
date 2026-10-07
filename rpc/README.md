# RabbitMQ RPC — PHP

Sample project for the official [RabbitMQ tutorial 6: Remote procedure call (PHP)](https://www.rabbitmq.com/tutorials/tutorial-six-php),
using [php-amqplib](https://github.com/php-amqplib/php-amqplib).

The earlier tutorials only sent messages one way. **RPC** (remote procedure call) sends a request and
waits for an answer: the client asks a server to compute something and blocks until the result
comes back. Here the server computes Fibonacci numbers, so `fib(30)` returns `832040`.

Start the broker and install dependencies first; see the [root README](../README.md).

## Files

| File | Role |
|------|------|
| `rpc_server.php` | Server. Takes requests from `rpc_queue`, computes `fib(n)` and sends the result to the queue named in the request's `reply_to`. |
| `rpc_client.php` | Client. `FibonacciRpcClient::call($n)` sends a request and waits up to 30 seconds for the matching reply (default: `fib(30)`). |

## How a call works

```
 rpc_client.php                                                 rpc_server.php
 ──────────────                                                 ──────────────
 1. request  "30"                       ┌───────────┐
    reply_to       = amq.gen-Xa2…  ───► │ rpc_queue │ ───────►  2. compute fib(30)
    correlation_id = 6523f1…            └───────────┘
                                        ┌──────────────┐
 4. correlation_id matches?  ◄───────── │ amq.gen-Xa2… │ ◄────  3. reply "832040"
    yes → return 832040                 └──────────────┘           correlation_id = 6523f1…
                                         callback queue
```

1. The client creates its own exclusive **callback queue** when it starts.
2. It publishes the request to `rpc_queue` with two properties: `reply_to` (the callback queue's
   name) and a new, unique `correlation_id`.
3. A server takes the request, computes the answer and publishes it through the default exchange to
   the `reply_to` queue, copying the `correlation_id`.
4. The client waits on its callback queue. When a reply's `correlation_id` matches its request, it
   returns the result.

## Try it

Run these commands from this `rpc/` folder.

Start the server:

```bash
php rpc_server.php
#  [x] Awaiting RPC requests
```

From another terminal, make a call:

```bash
php rpc_client.php
#  [x] Requesting fib(30)
#  [.] Got 832040

php rpc_client.php 10
#  [x] Requesting fib(10)
#  [.] Got 55
```

The server logs each request it handles:

```
 [x] Awaiting RPC requests
 [.] fib(30)
 [.] fib(10)
```

`fib()` is a deliberately slow recursive version. Values above about 35 take seconds, and large ones can
take longer than the client's 30-second timeout.

## Concepts the code demonstrates

| Concept | Where | What it does |
|---------|-------|--------------|
| **Callback queue** | `queue_declare('', false, false, true, false)` in the client | A server-named, exclusive queue where only this client receives its replies. It is deleted when the client disconnects. |
| **`reply_to`** | request message properties | Tells the server which queue to send the answer to. The server publishes to the default exchange `''` with this name as the routing key. |
| **`correlation_id`** | request and reply properties | A unique id per request. The client ignores any reply whose id doesn't match, such as a late answer to an earlier request that timed out. |
| **Blocking wait** | `while ($this->response === null) { $this->channel->wait(...); }` | Processes incoming messages until the matching reply arrives. Its timeout argument raises `AMQPTimeoutException`, so the client doesn't hang forever if no server is running. |
| **Ack after replying** | `$req->ack()` after `basic_publish` in the server | If a server dies mid-request, the request goes back to `rpc_queue` and another server handles it. |
| **Fair dispatch** | `basic_qos(null, 1, false)` | Each server takes one request at a time, so several servers share the load evenly. |
| **Quorum queue** | `rpc_queue` declaration | The tutorial declares `rpc_queue` as non-durable. RabbitMQ 4 rejects non-durable queues that aren't exclusive, so this version uses a durable quorum queue, like `task_queue` in [work-queues](../work-queues/README.md). |

### Experiments

- **No server running:** stop `rpc_server.php` and run `php rpc_client.php`. After 30 seconds it prints
  `No reply within 30 seconds. Is rpc_server.php running?` and exits with code 1. The request stays in
  `rpc_queue`. Start a server and it handles that request, but the reply has nowhere to go, because the
  client's callback queue was deleted when it exited.
- **Scale out:** start two servers and run several clients at once:
  `for n in 5 20 25 32; do php rpc_client.php $n & done; wait`. Each server's log shows it handled
  about half of the requests.
- **Slow call:** run `php rpc_client.php 38` and watch the server's CPU while the client waits.

## Things to consider in real use

The tutorial lists these as open questions; this sample doesn't handle them:

- What should the client do if no server is running? (Here it gives up after a timeout.)
- Should the client limit how long it waits, and what happens to the request afterwards?
- How should the server report an error or invalid input (for example, a negative number) to the
  client instead of crashing?
- How do you guard against malformed requests before processing them?
