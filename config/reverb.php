<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                /*
                 * This application's own hosts, never `*` — an unauthenticated socket is the
                 * cheapest thing here to open, and `*` invites every page on the internet to
                 * open one. A comma-separated list for the environments that serve more than
                 * one hostname; the default is whatever `APP_URL` points at (TASK-170-012).
                 */
                'allowed_origins' => array_values(array_filter(array_map(
                    trim(...),
                    explode(',', (string) env(
                        'REVERB_ALLOWED_ORIGINS',
                        (string) parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
                    )),
                ))),
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                /*
                 * The largest message a client sends here is a subscription — a channel name and
                 * an auth signature, a few hundred bytes. Ten kilobytes is already far more than
                 * anything this application asks for, and every broadcast travels the other way.
                 */
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),

                /*
                 * None. This application has no client-to-client events: every change goes
                 * through an endpoint that authorizes it and comes back as a broadcast the
                 * server sent. `members` would let a subscriber send messages to everyone else
                 * on a channel without passing a policy, which is a feature nothing here asked
                 * for. Reverb rejects client events for any value other than `all` or `members`.
                 */
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none'),

                /*
                 * On, and terminating. Our own clients send a handful of messages when a screen
                 * mounts and then listen, so a connection making sixty in a minute is not one of
                 * ours — dropping it is cheaper than reading it.
                 */
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', true),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', true),
                ],
            ],
        ],

    ],

];
