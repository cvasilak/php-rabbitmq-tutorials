# RabbitMQ Routing — PHP

Sample project for the official [RabbitMQ tutorial 4: Routing (PHP)](https://www.rabbitmq.com/tutorials/tutorial-four-php),
using [php-amqplib](https://github.com/php-amqplib/php-amqplib).

In [publish/subscribe](../publish-subscribe/README.md), the `fanout` exchange sent every log to every
receiver. **Routing** lets each receiver pick the messages it wants. Here the logger tags each message
with a severity (`info`, `warning` or `error`), and each receiver subscribes only to the severities it
cares about. One receiver could save only errors to disk while another prints everything on screen.

Start the broker and install dependencies first; see the [root README](../README.md).

## Files

| File | Role |
|------|------|
| `emit_log_direct.php` | Publisher. Sends one log message to the `direct_logs` exchange, using the severity as the routing key (default: `info` / `Hello World!`). |
| `receive_logs_direct.php` | Subscriber. Creates its own temporary queue, binds it once for each severity given on the command line and prints the matching messages. |

## How messages are routed

```
                                    ┌─ "warning" ─┐
                                    ├─ "error" ───┴─► queue amq.gen-A ──► receiver 1 (to file)
emit_log_direct.php ──► direct_logs ┤
 (routing key = severity)           ├─ "info" ────┐
                                    ├─ "warning" ─┼─► queue amq.gen-B ──► receiver 2 (screen)
                                    └─ "error" ───┘
```

Each `"..."` is a binding key. A message goes to every queue that has a binding key equal to its
routing key.

`warning` and `error` messages match both queues, so both receivers get them. An `info` message only
matches receiver 2's queue.

## Try it

Run these commands from this `routing/` folder.

Start one receiver that saves only warnings and errors to a file:

```bash
php receive_logs_direct.php warning error > logs_from_rabbit.log
```

Start another receiver that prints every severity on screen:

```bash
php receive_logs_direct.php info warning error
```

From a third terminal, send some logs:

```bash
php emit_log_direct.php info "user logged in"
php emit_log_direct.php warning "disk almost full"
php emit_log_direct.php error "Run. Run. Or it will explode."
```

The screen receiver shows all three:

```
 [*] Waiting for logs. To exit press CTRL+C
 [x] info:user logged in
 [x] warning:disk almost full
 [x] error:Run. Run. Or it will explode.
```

`logs_from_rabbit.log` holds only the `warning` and `error` lines.

To see the bindings while the receivers are running:

```bash
docker compose exec rabbitmq rabbitmqctl list_bindings source_name destination_name routing_key
# direct_logs    amq.gen-...A    error
# direct_logs    amq.gen-...A    warning
# direct_logs    amq.gen-...B    error
# direct_logs    amq.gen-...B    info
# direct_logs    amq.gen-...B    warning
```

## Concepts the code demonstrates

| Concept | Where | What it does |
|---------|-------|--------------|
| **Direct exchange** | `exchange_declare('direct_logs', 'direct', false, false, false)` | Sends a message to the queues whose binding key exactly matches the message's routing key. A message that matches no binding is dropped. |
| **Routing key** | `basic_publish($msg, 'direct_logs', $severity)` | The label the publisher puts on each message. Here it is the severity. The `fanout` exchange in tutorial 3 ignored it. |
| **Binding key** | `queue_bind($queueName, 'direct_logs', $severity)` | The third argument of `queue_bind()`. It says which routing keys this queue wants. |
| **Multiple bindings** | the `foreach` over `$severities` | One queue can be bound several times with different keys, so it receives messages for any of them. |
| **Same key on several queues** | two receivers both bound with `error` | Each matching queue gets its own copy. Binding every queue with the same key makes a direct exchange behave like `fanout`. |
| **Reading the key back** | `$msg->getRoutingKey()` | The consumer can see which routing key a message was published with. |

### Experiments

- **Unmatched key:** with the receivers above running, run `php emit_log_direct.php debug "verbose stuff"`.
  No queue is bound with `debug`, so the message is dropped and neither receiver prints it.
- **Errors only:** start `php receive_logs_direct.php error` and send an `info` and an `error` message.
  It only prints the `error` one.
- **Compare with fanout:** start two receivers bound only with `info`, then send `info` messages. Both
  receivers get every message, as they did in tutorial 3.
