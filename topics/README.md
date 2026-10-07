# RabbitMQ Topics — PHP

Sample project for the official [RabbitMQ tutorial 5: Topics (PHP)](https://www.rabbitmq.com/tutorials/tutorial-five-php),
using [php-amqplib](https://github.com/php-amqplib/php-amqplib).

In [routing](../routing/README.md), the `direct` exchange could only match one exact word, the severity.
A **topic** exchange routes on several criteria at once. Here each log's routing key has two words,
`<facility>.<severity>`, like `kern.critical` or `cron.info`. A receiver can then ask for "all critical
logs", "everything from the kernel", or both, much like the Unix `syslog` tool.

Start the broker and install dependencies first; see the [root README](../README.md).

## Files

| File | Role |
|------|------|
| `emit_log_topic.php` | Publisher. Sends one log message to the `topic_logs` exchange with a dotted routing key (default: `anonymous.info` / `Hello World!`). |
| `receive_logs_topic.php` | Subscriber. Creates its own temporary queue, binds it once for each pattern given on the command line and prints the matching messages. |

## How patterns match

The routing key is a list of words separated by dots (up to 255 bytes). A binding key uses the same
format and can contain two wildcards:

- `*` matches **exactly one** word.
- `#` matches **zero or more** words.

| Routing key sent | `#` | `kern.*` | `*.critical` | `kern.*` + `*.critical` |
|---|:-:|:-:|:-:|:-:|
| `kern.critical` | ✓ | ✓ | ✓ | ✓ (once) |
| `kern.info` | ✓ | ✓ | – | ✓ |
| `cron.critical` | ✓ | – | ✓ | ✓ |
| `cron.info` | ✓ | – | – | – |
| `kern` (one word) | ✓ | – | – | – |
| `kern.disk.critical` (three words) | ✓ | – | – | – |

`*` needs exactly one word in its place, so `kern.*` misses both `kern` and `kern.disk.critical`.
`kern.#` would match both.

## Try it

Run these commands from this `topics/` folder. Quote the patterns so the shell doesn't expand `*` or
treat `#` as a comment.

Start four receivers, each in its own terminal:

```bash
php receive_logs_topic.php "#"                     # everything
php receive_logs_topic.php "kern.*"                # every severity from the kernel
php receive_logs_topic.php "*.critical"            # critical logs from any facility
php receive_logs_topic.php "kern.*" "*.critical"   # either of the above
```

From another terminal, send some logs:

```bash
php emit_log_topic.php kern.critical "A critical kernel error"
php emit_log_topic.php kern.info "kernel booted"
php emit_log_topic.php cron.critical "cron died"
php emit_log_topic.php cron.info "job ran"
```

The `"kern.*" "*.critical"` receiver shows:

```
 [*] Waiting for logs. To exit press CTRL+C
 [x] kern.critical:A critical kernel error
 [x] kern.info:kernel booted
 [x] cron.critical:cron died
```

`kern.critical` matches both of its patterns but arrives only once, because both bindings point to the
same queue.

To see the bindings while the receivers are running:

```bash
docker compose exec rabbitmq rabbitmqctl list_bindings source_name destination_name routing_key
# topic_logs    amq.gen-...A    #
# topic_logs    amq.gen-...B    kern.*
# topic_logs    amq.gen-...C    *.critical
# topic_logs    amq.gen-...D    *.critical
# topic_logs    amq.gen-...D    kern.*
```

## Concepts the code demonstrates

| Concept | Where | What it does |
|---------|-------|--------------|
| **Topic exchange** | `exchange_declare('topic_logs', 'topic', false, false, false)` | Sends a message to every queue with a binding pattern that matches its routing key. A message that matches no pattern is dropped. |
| **Dotted routing key** | `basic_publish($msg, 'topic_logs', 'kern.critical')` | The key is a list of words separated by dots. The words can mean anything; here they are facility and severity. |
| **Wildcards** | `queue_bind($queueName, 'topic_logs', 'kern.*')` | `*` replaces exactly one word and `#` replaces zero or more. |
| **One delivery per queue** | several patterns on one queue | A message that matches more than one binding of the same queue is put in that queue once. |
| **Topic covers the other types** | binding key `#` or a key with no wildcards | `#` matches everything, so the queue behaves as if bound to a `fanout` exchange. A key with no `*` or `#` matches only itself, as with a `direct` exchange. |

### Experiments

- **Wrong word count:** send `php emit_log_topic.php kern "one word"` and
  `php emit_log_topic.php kern.disk.critical "three words"`. Only the `"#"` receiver gets them.
  Start a receiver with `"kern.#"` and send them again: it gets both.
- **No dots at all:** start `php receive_logs_topic.php orange` and send
  `php emit_log_topic.php orange "hi"`. A key without wildcards matches exactly, as in the routing tutorial.
- **Default key:** run `php emit_log_topic.php` with no arguments. It sends `anonymous.info`, which only
  `"#"` (and patterns like `*.info` or `anonymous.*`) would match.
