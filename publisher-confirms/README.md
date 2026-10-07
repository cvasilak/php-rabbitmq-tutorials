# RabbitMQ Publisher Confirms — PHP

Sample project for the official [RabbitMQ tutorial 7: Publisher Confirms (PHP)](https://www.rabbitmq.com/tutorials/tutorial-seven-php),
using [php-amqplib](https://github.com/php-amqplib/php-amqplib).

`basic_publish()` returns as soon as the message has left PHP. It doesn't tell you whether the broker
received it. With **publisher confirms** turned on, the broker answers every published message: an
**ack** once it has taken responsibility for the message (for example, stored it in a queue), or a
**nack** if it couldn't. This is how a publisher makes sure no message is silently lost.

The [work-queues](../work-queues/README.md) tutorial made messages survive a broker restart, and
[RPC](../rpc/README.md) got answers from a consumer. Confirms cover the remaining step: the publisher
knowing the broker received the message.

Start the broker and install dependencies first; see the [root README](../README.md).

## Files

| File | Role |
|------|------|
| `publisher_confirms.php` | Publishes the same number of messages (default 50,000) with three confirm strategies and prints how long each took. Each strategy uses its own connection and temporary queue. |

## Try it

Run these commands from this `publisher-confirms/` folder.

```bash
php publisher_confirms.php          # 50,000 messages per strategy
php publisher_confirms.php 5000     # fewer, for a quicker run
```

Example output from the local Docker broker:

```
 [x] Published individually:          50,000 messages in 14,360 ms
 [x] Published in batches of 100:     50,000 messages in 2,619 ms
 [x] Published, confirmed async:      50,000 messages in 3,651 ms
```

Your timings depend on your machine and on network latency to the broker. The order is what matters.

## The three strategies

All three start with `$channel->confirm_select()`, which puts the channel in confirm mode. From then
on the broker numbers every message published on the channel (1, 2, 3, …) and acks or nacks each one.

### 1. Publish individually

```php
$channel->basic_publish($msg, '', $queueName);
$channel->wait_for_pending_acks(5);   // block until this message is confirmed
```

The simplest approach: publish, then wait for that message's confirm. Every message costs a full round
trip to the broker, so this is by far the slowest. You always know exactly which message failed.

### 2. Publish in batches

```php
$channel->basic_publish($msg, '', $queueName);
if (++$outstanding === 100) {
    $channel->wait_for_pending_acks(5);   // wait for the whole batch
    $outstanding = 0;
}
```

Publish 100 messages, then wait for all their confirms at once. One round trip covers 100 messages, so
this is several times faster. The cost: if something fails, you don't know which message caused it
and have to republish the whole batch, which may produce duplicates.

### 3. Handle confirms asynchronously

```php
$channel->set_ack_handler(function (AMQPMessage $msg) { /* confirmed */ });
$channel->set_nack_handler(function (AMQPMessage $msg) { /* republish or log $msg */ });
$channel->confirm_select();

// publish freely; when 100 messages are unconfirmed, stop and let some confirms arrive
$channel->wait(null, false, 5);
```

Publish without stopping and let callbacks deal with each confirm. The nack handler receives the exact
message that failed, so you can republish just that one. This is the most flexible approach and the
one the tutorial recommends for high throughput.

## Notes for PHP

These two points come from running the strategies with php-amqplib. The tutorial doesn't cover them.

- **Callbacks only run while you wait.** PHP has no background thread reading the socket. The ack and
  nack handlers run only inside `wait()`, `wait_for_pending_acks()` or similar calls. In PHP,
  "asynchronous" means "don't wait after every message", not "handled in the background".
- **Cap the number of unconfirmed messages.** Each time the broker acks several messages at once, the
  library scans every unconfirmed message it is tracking. Publishing all 50,000 before reading any
  confirms made strategy 3 take **115 seconds** instead of under 4. So the code stops publishing when
  `MAX_OUTSTANDING_CONFIRMS` (100) messages are unconfirmed and waits for some confirms first.

Because PHP runs a handler call for every message, strategy 3 ends up slightly slower than batching
here. It is still much faster than strategy 1, and it is the only one of the three that tells you
exactly which message failed without blocking after each publish.

## Concepts the code demonstrates

| Concept | Where | What it does |
|---------|-------|--------------|
| **Confirm mode** | `$channel->confirm_select()` | Turns on publisher confirms for the channel. It can't be turned off again on that channel. |
| **Ack / nack** | `set_ack_handler()`, `set_nack_handler()` | The broker acks a message once it is safely handled, or nacks it if it can't take it. A nack means the publisher should republish or log the message. |
| **Delivery tags** | kept by php-amqplib | The broker numbers messages per channel. The library keeps each unconfirmed message under its number and passes the matching `AMQPMessage` to your handler. |
| **Multiple acks** | inside php-amqplib | The broker may confirm many messages with one ack ("everything up to number 100"). The library calls your handler once for each of them. |
| **Confirm timeout** | `wait_for_pending_acks(5)` | Throws `AMQPTimeoutException` if confirms don't arrive within 5 seconds, instead of waiting forever. |
| **Exclusive temporary queue** | `queue_declare('', false, false, true, false)` | RabbitMQ 4 rejects non-durable queues that aren't exclusive (see [rpc](../rpc/README.md)). The same connection publishes to the queue and owns it, so an exclusive queue works, and it is deleted when the strategy finishes. |

### Experiments

- **Change the batch size:** edit `BATCH_SIZE` (try 10 and 1000) and compare strategy 2's time.
- **Change the cap:** set `MAX_OUTSTANDING_CONFIRMS` to 100000 and run with 50,000 messages to see
  the slowdown described above.
- **Watch the queue fill:** run `php publisher_confirms.php 500000` and open *Queues and Streams* in
  the management UI (http://localhost:15672) while it runs. Each strategy's `amq.gen-...` queue
  fills up and disappears when that strategy finishes.
