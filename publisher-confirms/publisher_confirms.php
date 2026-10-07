<?php

/*
 * Publisher confirms: the broker tells the publisher when it has safely taken
 * responsibility for each message (ack) or failed to (nack).
 *
 * Publishes the same number of messages with three strategies and times each:
 *   1. wait for the confirm after every message
 *   2. wait for confirms after every batch of messages
 *   3. publish everything and handle confirms in callbacks
 *
 *   php publisher_confirms.php           # 50,000 messages per strategy
 *   php publisher_confirms.php 5000
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

const BATCH_SIZE = 100;
const MAX_OUTSTANDING_CONFIRMS = 100;
const CONFIRM_TIMEOUT_SECONDS = 5;

$messageCount = intval($argv[1] ?? 50000);

/*
 * Runs one strategy on a fresh connection, with its own temporary queue, and prints how long it took.
 * $publish receives the channel (already in confirm mode) and the name of the queue to publish to.
 */
function runStrategy(string $label, int $messageCount, callable $publish): void
{
    $connection = createConnection();
    $channel = $connection->channel();

    // Temporary queue: server-named, exclusive, deleted when this connection closes.
    // queue_declare($queue, $passive, $durable, $exclusive, $auto_delete)
    [$queueName, ,] = $channel->queue_declare('', false, false, true, false);

    $start = hrtime(true);
    $publish($channel, $queueName);
    $elapsedMs = (hrtime(true) - $start) / 1e6;

    printf(" [x] %-32s %s messages in %s ms\n", $label, number_format($messageCount), number_format($elapsedMs));

    $channel->close();
    $connection->close();
}

// Strategy 1: publish a message and block until the broker confirms it. Simple but slow,
// because every message costs a full round trip to the broker.
function publishMessagesIndividually(AMQPChannel $channel, string $queueName, int $messageCount): void
{
    // Put the channel in confirm mode: from now on the broker acks or nacks every publish.
    $channel->confirm_select();

    for ($i = 0; $i < $messageCount; $i++) {
        $channel->basic_publish(new AMQPMessage((string) $i), '', $queueName);

        // Throws AMQPTimeoutException if the confirm doesn't arrive in time.
        $channel->wait_for_pending_acks(CONFIRM_TIMEOUT_SECONDS);
    }
}

// Strategy 2: publish a batch, then wait for all its confirms at once. Much faster, but if the
// batch fails you don't know which message caused it and must republish the whole batch.
function publishMessagesInBatch(AMQPChannel $channel, string $queueName, int $messageCount): void
{
    $channel->confirm_select();

    $outstanding = 0;
    for ($i = 0; $i < $messageCount; $i++) {
        $channel->basic_publish(new AMQPMessage((string) $i), '', $queueName);
        $outstanding++;

        if ($outstanding === BATCH_SIZE) {
            $channel->wait_for_pending_acks(CONFIRM_TIMEOUT_SECONDS);
            $outstanding = 0;
        }
    }

    // Confirm the last, partial batch.
    if ($outstanding > 0) {
        $channel->wait_for_pending_acks(CONFIRM_TIMEOUT_SECONDS);
    }
}

// Strategy 3: register callbacks for acks and nacks, then publish without stopping.
// The callbacks get the exact message that was confirmed, so a nacked one can be republished.
function handlePublishConfirmsAsynchronously(AMQPChannel $channel, string $queueName, int $messageCount): void
{
    $confirmed = 0;
    $nacked = 0;

    // The broker may confirm several messages with one "multiple" ack; php-amqplib
    // calls the handler once for each of them.
    $channel->set_ack_handler(function (AMQPMessage $msg) use (&$confirmed) {
        $confirmed++;
    });
    $channel->set_nack_handler(function (AMQPMessage $msg) use (&$nacked) {
        // A real publisher would republish or log the message here.
        $nacked++;
        echo ' [!] Message ', $msg->getBody(), " was nacked\n";
    });

    // Handlers must be set before confirm mode is enabled.
    $channel->confirm_select();

    for ($i = 0; $i < $messageCount; $i++) {
        $channel->basic_publish(new AMQPMessage((string) $i), '', $queueName);

        // Cap the number of unconfirmed messages. php-amqplib scans all of them on every
        // "multiple" ack, so letting tens of thousands pile up makes confirms very slow.
        // wait() reads one incoming frame (here: an ack or nack) and runs its handler.
        while ($i + 1 - $confirmed - $nacked >= MAX_OUTSTANDING_CONFIRMS) {
            $channel->wait(null, false, CONFIRM_TIMEOUT_SECONDS);
        }
    }

    // php-amqplib only reads incoming frames while waiting, so the remaining handlers run here.
    $channel->wait_for_pending_acks(CONFIRM_TIMEOUT_SECONDS);

    if ($confirmed + $nacked !== $messageCount) {
        echo ' [!] Only ', $confirmed + $nacked, ' of ', $messageCount, " messages were confirmed\n";
    }
}

runStrategy('Published individually:', $messageCount, function (AMQPChannel $channel, string $queueName) use ($messageCount) {
    publishMessagesIndividually($channel, $queueName, $messageCount);
});

runStrategy('Published in batches of ' . BATCH_SIZE . ':', $messageCount, function (AMQPChannel $channel, string $queueName) use ($messageCount) {
    publishMessagesInBatch($channel, $queueName, $messageCount);
});

runStrategy('Published, confirmed async:', $messageCount, function (AMQPChannel $channel, string $queueName) use ($messageCount) {
    handlePublishConfirmsAsynchronously($channel, $queueName, $messageCount);
});
