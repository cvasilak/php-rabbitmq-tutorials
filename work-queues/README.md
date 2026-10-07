# RabbitMQ Work Queues — PHP

Sample project for the official [RabbitMQ tutorial 2: Work Queues (PHP)](https://www.rabbitmq.com/tutorials/tutorial-two-php),
using [php-amqplib](https://github.com/php-amqplib/php-amqplib).

A **work queue** spreads slow tasks across several workers. Tasks go into a queue instead of being run
right away, and each worker takes one, does it, and asks for the next.

Start the broker and install dependencies first; see the [root README](../README.md).

## Files

| File | Role |
|------|------|
| `new_task.php` | Producer. Sends one task to `task_queue`. Each `.` in the message stands for 1 second of work. |
| `worker.php` | Consumer. Takes tasks, sleeps 1 second per `.`, then acks the message. |

## Try it

Run these commands from this `work-queues/` folder.

Open two terminals and start a worker in each:

```bash
php worker.php
```

From a third terminal, send some tasks:

```bash
php new_task.php First message.
php new_task.php Second message..
php new_task.php Third message...
php new_task.php Fourth message....
php new_task.php Fifth message.....
```

The tasks alternate between the workers (round-robin):

```
# worker 1                         # worker 2
 [x] Received First message.        [x] Received Second message..
 [x] Received Third message...      [x] Received Fourth message....
 [x] Received Fifth message.....
```

## Concepts the code demonstrates

| Concept | Where | What it does |
|---------|-------|--------------|
| **Round-robin dispatch** | default behaviour | Each message goes to the next worker in turn. |
| **Message acknowledgement** | `basic_consume(..., $no_ack = false, ...)` and `$msg->ack()` | A message is deleted only after the worker acks it. If a worker dies mid-task (try Ctrl+C during a long `.....` task), RabbitMQ sends the message to another worker. |
| **Durable queue** | `queue_declare('task_queue', false, true, ...)` with `x-queue-type: quorum` | The queue survives a broker restart. You can't redeclare an existing queue with different settings, which is why the tutorial uses a new name, `task_queue`. |
| **Persistent messages** | `'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT` | Messages are written to disk. |
| **Fair dispatch** | `basic_qos(null, 1, false)` | Prefetch is 1, so a busy worker gets no new task until it acks its current one. Free workers pick up the work. |

### Experiments

- **Forgotten ack:** comment out `$msg->ack()`, send a few tasks, and watch the *Unacked* count grow
  in the management UI (or run `docker compose exec rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged`).
  Stop the worker and the messages go back to *Ready*.
- **Crash recovery:** send `php new_task.php "Long task.........."` and press Ctrl+C on the worker
  that picks it up. Another worker receives it.
- **Durability:** queue some tasks with no workers running, run `docker compose restart rabbitmq`,
  then start a worker. The tasks are still there.
