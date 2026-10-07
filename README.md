# RabbitMQ Tutorials — PHP

Sample projects for the official [RabbitMQ tutorials](https://www.rabbitmq.com/tutorials), written in PHP
with [php-amqplib](https://github.com/php-amqplib/php-amqplib).

## Tutorials

| Folder | Tutorial |
|--------|----------|
| [`work-queues/`](work-queues/README.md) | [2. Work Queues](https://www.rabbitmq.com/tutorials/tutorial-two-php): spreading tasks across several workers |
| [`publish-subscribe/`](publish-subscribe/README.md) | [3. Publish/Subscribe](https://www.rabbitmq.com/tutorials/tutorial-three-php): broadcasting each message to every subscriber |
| [`routing/`](routing/README.md) | [4. Routing](https://www.rabbitmq.com/tutorials/tutorial-four-php): subscribing to only some messages, by routing key |
| [`topics/`](topics/README.md) | [5. Topics](https://www.rabbitmq.com/tutorials/tutorial-five-php): subscribing by pattern (`*` and `#`) on dotted routing keys |
| [`rpc/`](rpc/README.md) | [6. RPC](https://www.rabbitmq.com/tutorials/tutorial-six-php): request/reply with a callback queue and correlation id |
| [`publisher-confirms/`](publisher-confirms/README.md) | [7. Publisher Confirms](https://www.rabbitmq.com/tutorials/tutorial-seven-php): making sure the broker received each published message |

## Shared files

| File | Role |
|------|------|
| `composer.json` | A single php-amqplib install that every tutorial uses (`vendor/`). |
| `config.php` | `createConnection()`, with settings environment variables can override. |
| `docker-compose.yml` | A local RabbitMQ 4 broker with the management UI. |

## Setup

```bash
composer install
docker compose up -d --wait
```

The management UI is at http://localhost:15672 (login `guest` / `guest`).

**Port already in use?** If another broker already uses 5672/15672, pick other ports for this one:

```bash
export RABBITMQ_PORT=5673 RABBITMQ_MGMT_PORT=15673
docker compose up -d --wait
```

Docker Compose and the PHP scripts both read `RABBITMQ_PORT`. You can also set `RABBITMQ_HOST`,
`RABBITMQ_USER` and `RABBITMQ_PASS`.

## Cleanup

```bash
docker compose down        # stop the broker
docker compose down -v     # stop it and delete its stored data
```
