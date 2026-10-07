# RabbitMQ Publish/Subscribe — PHP

Sample project for the official [RabbitMQ tutorial 3: Publish/Subscribe (PHP)](https://www.rabbitmq.com/tutorials/tutorial-three-php),
using [php-amqplib](https://github.com/php-amqplib/php-amqplib).

In a [work queue](../work-queues/README.md), each message goes to **one** worker. **Publish/subscribe**
sends each message to **every** subscriber. This example is a simple logging system: one program sends
log messages and every running receiver gets a copy. One receiver could save them to disk while
another prints them to the screen.

Start the broker and install dependencies first; see the [root README](../README.md).

## Files

| File | Role |
|------|------|
| `emit_log.php` | Publisher. Sends one log message to the `logs` exchange (default: `info: Hello World!`). |
| `receive_logs.php` | Subscriber. Creates its own temporary queue, binds it to `logs` and prints every message. |

## Try it

Run these commands from this `publish-subscribe/` folder.

Start one receiver that saves the logs to a file:

```bash
php receive_logs.php > logs_from_rabbit.log
```

Start another receiver that prints the logs on screen:

```bash
php receive_logs.php
```

From a third terminal, send some logs:

```bash
php emit_log.php
php emit_log.php "warning: disk almost full"
```

Both receivers get both messages. The screen one shows:

```
 [*] Waiting for logs. To exit press CTRL+C
 [x] info: Hello World!
 [x] warning: disk almost full
```

and `logs_from_rabbit.log` holds the same lines.

To see the bindings while the receivers are running:

```bash
docker compose exec rabbitmq rabbitmqctl list_bindings source_name destination_name
# logs    amq.gen-C08XOoDa7CCpazL-sA-Wzw
# logs    amq.gen-SrIQNSNPy-IYv1a1DpGNUA
```

## Concepts the code demonstrates

| Concept | Where | What it does |
|---------|-------|--------------|
| **Exchange** | `basic_publish($msg, 'logs')` | Producers never send directly to a queue. They send to an exchange, and the exchange decides which queues get the message. (Tutorial 2 used the nameless default exchange `''`, which routes by queue name.) |
| **Fanout exchange** | `exchange_declare('logs', 'fanout', false, false, false)` | Copies every message to all queues bound to it and ignores the routing key. Other types are `direct`, `topic` and `headers`. |
| **Temporary queue** | `queue_declare('', false, false, true, false)` | The empty name makes the server pick a random one (`amq.gen-...`). `exclusive = true` deletes the queue when the receiver disconnects, so each receiver gets a fresh, empty queue. |
| **Binding** | `queue_bind($queueName, 'logs')` | Tells the exchange to send its messages to this queue. |
| **Auto-ack** | `basic_consume(..., $no_ack = true, ...)` | Unlike the work queue, a lost log line is acceptable here, so messages are acknowledged as soon as they are delivered. |

### Experiments

- **No subscribers:** stop all receivers and run `php emit_log.php`. The exchange has no bound queues,
  so the message is dropped. Start a receiver afterwards and it sees nothing.
- **Late joiner:** start a third receiver after sending a few logs. It only gets messages sent from
  then on, because its queue didn't exist earlier.
- **Watch the queues come and go:** open *Queues and Streams* in the management UI
  (http://localhost:15672), start and stop receivers, and watch the `amq.gen-...` queues appear and disappear.
