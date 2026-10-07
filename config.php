<?php

/*
 * Connection settings shared by every tutorial.
 * Defaults match the tutorial (localhost:5672, guest/guest) and can be
 * overridden with environment variables, e.g. RABBITMQ_PORT=5673.
 */

use PhpAmqpLib\Connection\AMQPStreamConnection;

function createConnection(): AMQPStreamConnection
{
    return new AMQPStreamConnection(
        getenv('RABBITMQ_HOST') ?: 'localhost',
        (int) (getenv('RABBITMQ_PORT') ?: 5672),
        getenv('RABBITMQ_USER') ?: 'guest',
        getenv('RABBITMQ_PASS') ?: 'guest',
    );
}
