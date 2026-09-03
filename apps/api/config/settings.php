<?php

/**
 * Every environment-driven value the application reads, resolved once.
 *
 * Nothing outside this file should call Env:: directly — services receive
 * their configuration through the container instead, which keeps them
 * testable and makes the full surface of configuration greppable in one place.
 */

declare(strict_types=1);

use Central\Api\Support\Env;

$root = dirname(__DIR__);

return [
    'app' => [
        'name'     => Env::get('APP_NAME', 'Central LMS'),
        'env'      => Env::get('APP_ENV', 'prod'),
        'debug'    => Env::bool('APP_DEBUG', false),
        'url'      => rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/'),
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'version'  => trim((string) @file_get_contents($root . '/VERSION')) ?: '0.1.0-dev',
    ],

    'paths' => [
        'root'       => $root,
        'var'        => $root . '/var',
        'cache'      => $root . '/var/cache',
        'log'        => $root . '/var/log',
        'migrations' => $root . '/migrations',
        'entities'   => [$root . '/src/Domain'],
    ],

    'cors' => [
        'allowed_origins' => Env::list('CORS_ALLOWED_ORIGINS', ['http://localhost:4200']),
        'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With', 'X-Request-Id', 'X-Tenant'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'expose_headers'  => ['X-Request-Id', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
        'max_age'         => 86400,
    ],

    'db' => [
        'driver'   => Env::get('DB_DRIVER', 'pdo_pgsql'),
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => Env::int('DB_PORT', 5432),
        'dbname'   => Env::get('DB_NAME', 'central_lms'),
        'user'     => Env::get('DB_USER', 'central_lms'),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset'  => 'utf8',
        // Doctrine only manages tables in this schema; anything the legacy
        // system still owns lives elsewhere and is never touched by migrations.
        'schema'   => Env::get('DB_SCHEMA', 'public'),
    ],

    'redis' => [
        'dsn'       => Env::get('REDIS_DSN', 'redis://127.0.0.1:6379'),
        'namespace' => Env::get('CACHE_NAMESPACE', 'central'),
        'ttl'       => 3600,
    ],

    'messenger' => [
        'transport_dsn' => Env::get('MESSENGER_TRANSPORT_DSN', 'redis://127.0.0.1:6379/messages'),
    ],

    'jwt' => [
        'secret'      => Env::get('JWT_SECRET', ''),
        'algorithm'   => 'HS256',
        'issuer'      => Env::get('JWT_ISSUER', 'central-lms'),
        'audience'    => Env::get('JWT_AUDIENCE', 'central-clients'),
        'access_ttl'  => Env::int('JWT_ACCESS_TTL', 900),
        'refresh_ttl' => Env::int('JWT_REFRESH_TTL', 2592000),
        'leeway'      => 10,
    ],

    'rate_limit' => [
        'enabled' => Env::bool('RATE_LIMIT_ENABLED', true),
        'max'     => Env::int('RATE_LIMIT_MAX', 120),
        'window'  => Env::int('RATE_LIMIT_WINDOW', 60),
    ],

    'mail' => [
        'provider' => Env::get('MAIL_PROVIDER', 'zeptomail'),
        'from'     => [
            'address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
            'name'    => Env::get('MAIL_FROM_NAME', 'Central LMS'),
        ],
        'zeptomail' => [
            'token'    => Env::get('ZEPTOMAIL_TOKEN', ''),
            'base_url' => Env::get('ZEPTOMAIL_BASE_URL', 'https://api.zeptomail.com/v1.1'),
        ],
    ],

    'sms' => [
        'provider' => Env::get('SMS_PROVIDER', 'termii'),
        'termii'   => [
            'api_key'   => Env::get('TERMII_API_KEY', ''),
            'sender_id' => Env::get('TERMII_SENDER_ID', 'Bayti'),
            'base_url'  => Env::get('TERMII_BASE_URL', 'https://api.ng.termii.com'),
        ],
    ],

    'push' => [
        'fcm' => [
            'project_id'       => Env::get('FCM_PROJECT_ID', ''),
            'credentials_path' => Env::get('FCM_CREDENTIALS_PATH', 'config/firebase-service-account.json'),
        ],
    ],

    /**
     * Collecting money for an invoice.
     *
     * A provider adapter, like mail and sms: one gateway chosen by
     * `PAYMENTS_PROVIDER`, its keys read from the environment and never
     * committed. `callback_url` is where the gateway returns the customer after
     * they pay — a page in the tenant application that confirms the payment.
     */
    'payments' => [
        'provider' => Env::get('PAYMENTS_PROVIDER', 'paystack'),
        // Where the gateway returns the customer after paying — the tenant
        // application's billing page, NOT the API (APP_URL is the API). Defaults
        // off APP_WEB_URL, the same origin SSO and password-reset return to.
        'callback_url' => rtrim(
            (string) Env::get('PAYMENTS_CALLBACK_URL', rtrim((string) Env::get('APP_WEB_URL', ''), '/') . '/admin/billing'),
            '/',
        ),
        'paystack' => [
            'secret_key' => Env::get('PAYSTACK_SECRET_KEY', ''),
            'public_key' => Env::get('PAYSTACK_PUBLIC_KEY', ''),
            'base_url'   => rtrim((string) Env::get('PAYSTACK_BASE_URL', 'https://api.paystack.co'), '/'),
        ],
    ],

    'storage' => [
        /**
         * Where uploaded files go.
         *
         * `local` writes under `storage/uploads` and serves them back through
         * the API; `s3` talks to object storage. Development has no S3 and
         * should not need one to test an upload — the same reasoning as
         * `MAIL_PROVIDER=log`.
         */
        'driver' => Env::get('STORAGE_DRIVER', 'local'),

        'local' => [
            'root'            => Env::get('STORAGE_LOCAL_ROOT', __DIR__ . '/../storage/uploads'),
            'public_base_url' => Env::get('STORAGE_LOCAL_BASE_URL', '/v1/media/files'),
        ],

        's3' => [
            'endpoint'        => Env::get('S3_ENDPOINT'),
            'region'          => Env::get('S3_REGION', 'us-east-1'),
            'bucket'          => Env::get('S3_BUCKET', 'central-lms'),
            'key'             => Env::get('S3_KEY', ''),
            'secret'          => Env::get('S3_SECRET', ''),
            'path_style'      => Env::bool('S3_USE_PATH_STYLE', true),
            'public_base_url' => Env::get('S3_PUBLIC_BASE_URL'),
        ],
    ],

    // Our OAuth *app* credentials, one registration per platform, shared by all
    // tenants. The per-tenant access and refresh tokens live in the
    // video_integrations table, not here — each customer connects their own
    // Zoom/Microsoft/Google account through a consent screen.
    //
    // Keys must match Domain\Ilt\MeetingProviderId values.
    'ilt' => [
        // Where the platforms send the user back after consent. The path
        // /v1/ilt/integrations/{platform}/callback is appended.
        'redirect_base_url' => rtrim((string) Env::get('ILT_REDIRECT_BASE_URL', (string) Env::get('APP_URL', '')), '/'),

        'zoom' => [
            'enabled'              => Env::bool('ZOOM_ENABLED', false),
            'client_id'            => Env::get('ZOOM_CLIENT_ID', ''),
            'client_secret'        => Env::get('ZOOM_CLIENT_SECRET', ''),
            'webhook_secret_token' => Env::get('ZOOM_WEBHOOK_SECRET_TOKEN', ''),
        ],
        'google_meet' => [
            'enabled'       => Env::bool('GOOGLE_MEET_ENABLED', false),
            'client_id'     => Env::get('GOOGLE_CLIENT_ID', ''),
            'client_secret' => Env::get('GOOGLE_CLIENT_SECRET', ''),
        ],
        'microsoft_teams' => [
            'enabled'       => Env::bool('TEAMS_ENABLED', false),
            'client_id'     => Env::get('MS_CLIENT_ID', ''),
            'client_secret' => Env::get('MS_CLIENT_SECRET', ''),
            // 'common' lets any Microsoft tenant consent. Pin to a directory id
            // only for a single-tenant deployment.
            'tenant_id'     => Env::get('MS_TENANT_ID', 'common'),
        ],
    ],

    'security' => [
        // Encrypts tenant OAuth credentials at rest. Rotating it disconnects
        // every integration (they must reconnect) but loses no other data.
        'credentials_key' => Env::get('CREDENTIALS_ENCRYPTION_KEY', ''),
    ],

    'sentry' => [
        'dsn'                => Env::get('SENTRY_DSN'),
        'traces_sample_rate' => Env::float('SENTRY_TRACES_SAMPLE_RATE', 0.1),
    ],

    'logger' => [
        'name'  => 'api',
        'level' => Env::get('LOG_LEVEL', 'info'),
        'path'  => $root . '/' . ltrim((string) Env::get('LOG_PATH', 'var/log/app.log'), '/'),
    ],
];
