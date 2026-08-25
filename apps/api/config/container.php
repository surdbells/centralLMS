<?php

/**
 * Container definitions.
 *
 * Only infrastructure that needs real construction logic is declared here.
 * Everything under src/ is autowired by PHP-DI, so adding an action or a
 * service does not mean editing this file — which is deliberate: the legacy
 * backend's single god-registry is what made it impossible to change safely.
 */

declare(strict_types=1);

use Central\Api\Application\Action\VersionAction;
use Central\Api\Application\Console\GenerateOpenApiCommand;
use Central\Api\Application\Handler\JsonErrorHandler;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Middleware\CorsMiddleware;
use Central\Api\Application\Middleware\RateLimitMiddleware;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Identity\RefreshTokenRepository;
use Central\Api\Domain\Identity\UserRepository;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Catalog\LessonRepository;
use Central\Api\Domain\Catalog\ModuleRepository;
use Central\Api\Domain\Lookup\LookupRepository;
use Central\Api\Infrastructure\Persistence\DbalCourseRepository;
use Central\Api\Infrastructure\Persistence\DbalLessonRepository;
use Central\Api\Infrastructure\Persistence\DbalModuleRepository;
use Central\Api\Infrastructure\Persistence\DbalLookupRepository;
use Central\Api\Infrastructure\Authz\DbalRoleRepository;
use Central\Api\Infrastructure\Persistence\DbalRefreshTokenRepository;
use Central\Api\Infrastructure\Persistence\DbalUserRepository;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Central\Api\Domain\Ilt\VideoIntegrationRepository;
use Central\Api\Infrastructure\Authz\SystemRoleRepository;
use Central\Api\Infrastructure\Security\TokenAuthenticator;
use Central\Api\Infrastructure\Ilt\MeetingProviderFactory;
use Central\Api\Infrastructure\Persistence\DbalVideoIntegrationRepository;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Support\Cipher;
use Central\Api\Support\Clock;
use Central\Api\Support\SystemClock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use GuzzleHttp\Client as GuzzleClient;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

return [
    'settings' => factory(static fn (): array => require __DIR__ . '/settings.php'),

    // --- logging ----------------------------------------------------------
    LoggerInterface::class => factory(static function (ContainerInterface $c): LoggerInterface {
        $settings = $c->get('settings')['logger'];

        $logger = new Logger($settings['name']);
        $logger->pushProcessor(new UidProcessor());
        $logger->pushProcessor(new PsrLogMessageProcessor());

        $level = match (strtolower((string) $settings['level'])) {
            'debug'     => Level::Debug,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
            default     => Level::Info,
        };

        // Containers and aaPanel both capture stdout; the rotating file is the
        // fallback for hosts where only the filesystem is readable.
        $stdout = new StreamHandler('php://stdout', $level);
        $stdout->setFormatter(new LineFormatter(null, null, true, true));
        $logger->pushHandler($stdout);

        $dir = dirname((string) $settings['path']);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_writable($dir)) {
            $logger->pushHandler(new RotatingFileHandler((string) $settings['path'], 14, $level));
        }

        return $logger;
    }),

    // --- cache / redis ----------------------------------------------------
    CacheItemPoolInterface::class => factory(static function (ContainerInterface $c): CacheItemPoolInterface {
        $settings = $c->get('settings');

        // The test suite must never reach for a real Redis.
        if ($settings['app']['env'] === 'test') {
            return new ArrayAdapter();
        }

        $connection = RedisAdapter::createConnection((string) $settings['redis']['dsn']);

        return new RedisAdapter(
            $connection,
            (string) $settings['redis']['namespace'],
            (int) $settings['redis']['ttl'],
        );
    }),

    // --- database ---------------------------------------------------------
    Connection::class => factory(static function (ContainerInterface $c): Connection {
        $db = $c->get('settings')['db'];

        return DriverManager::getConnection([
            'driver'   => $db['driver'],
            'host'     => $db['host'],
            'port'     => $db['port'],
            'dbname'   => $db['dbname'],
            'user'     => $db['user'],
            'password' => $db['password'],
            'charset'  => $db['charset'],
        ], new \Doctrine\DBAL\Configuration());
    }),

    EntityManagerInterface::class => factory(static function (ContainerInterface $c): EntityManagerInterface {
        $settings = $c->get('settings');

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: $settings['paths']['entities'],
            isDevMode: (bool) $settings['app']['debug'],
            proxyDir: $settings['paths']['cache'] . '/doctrine/proxies',
            cache: $c->get(CacheItemPoolInterface::class),
        );

        return new EntityManager($c->get(Connection::class), $config);
    }),

    // --- validation -------------------------------------------------------
    ValidatorInterface::class => factory(
        static fn (): ValidatorInterface => Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator(),
    ),

    // --- async queue ------------------------------------------------------
    TransportInterface::class => factory(static function (ContainerInterface $c): TransportInterface {
        $dsn = (string) $c->get('settings')['messenger']['transport_dsn'];

        return (new \Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory())
            ->createTransport($dsn, [], new PhpSerializer());
    }),

    MessageBusInterface::class => factory(static function (ContainerInterface $c): MessageBusInterface {
        $transport = $c->get(TransportInterface::class);

        $senders = new class ($transport) implements ContainerInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function get(string $id): TransportInterface
            {
                return $this->transport;
            }

            public function has(string $id): bool
            {
                return $id === 'async';
            }
        };

        return new MessageBus([
            // Everything is routed async by default. A handler that must run
            // inline should be invoked directly rather than dispatched.
            new SendMessageMiddleware(new SendersLocator(['*' => ['async']], $senders)),
            new HandleMessageMiddleware(new HandlersLocator([]), allowNoHandlers: true),
        ]);
    }),

    // --- outbound http ----------------------------------------------------
    HttpClientInterface::class => factory(static fn (): HttpClientInterface => new GuzzleClient([
        'timeout'         => 15,
        'connect_timeout' => 5,
        'http_errors'     => false,
        'headers'         => ['User-Agent' => 'CentralLMS/1.0'],
    ])),

    // --- object storage ---------------------------------------------------
    FilesystemOperator::class => factory(static function (ContainerInterface $c): FilesystemOperator {
        $s3 = $c->get('settings')['storage']['s3'];

        $client = new \Aws\S3\S3Client(array_filter([
            'version'                 => 'latest',
            'region'                  => $s3['region'],
            'endpoint'                => $s3['endpoint'],
            'use_path_style_endpoint' => (bool) $s3['path_style'],
            'credentials'             => [
                'key'    => $s3['key'],
                'secret' => $s3['secret'],
            ],
        ], static fn ($v) => $v !== null && $v !== ''));

        return new Filesystem(new AwsS3V3Adapter($client, (string) $s3['bucket']));
    }),

    // --- application services --------------------------------------------
    Clock::class => autowire(SystemClock::class),

    JwtTokenService::class => factory(static function (ContainerInterface $c): JwtTokenService {
        return new JwtTokenService($c->get('settings')['jwt'], $c->get(Clock::class));
    }),

    // Encrypts tenant OAuth credentials at rest. Built lazily so a deployment
    // that has not configured ILT can still boot and serve everything else.
    Cipher::class => factory(
        static fn (ContainerInterface $c) => new Cipher((string) $c->get('settings')['security']['credentials_key']),
    ),

    VideoIntegrationRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalVideoIntegrationRepository(
            $c->get(Connection::class),
            $c->get(Cipher::class),
            $c->get(Clock::class),
        ),
    ),

    MeetingProviderFactory::class => factory(
        static fn (ContainerInterface $c) => new MeetingProviderFactory(
            $c->get('settings')['ilt'],
            $c->get(VideoIntegrationRepository::class),
            $c->get(HttpClientInterface::class),
            $c->get(LoggerInterface::class),
            $c->get(Clock::class),
        ),
    ),

    // --- things that need the raw settings array --------------------------
    VersionAction::class => factory(
        static fn (ContainerInterface $c) => new VersionAction($c->get('settings')),
    ),

    GenerateOpenApiCommand::class => factory(
        static fn (ContainerInterface $c) => new GenerateOpenApiCommand($c->get('settings')),
    ),

    JsonErrorHandler::class => factory(static fn (ContainerInterface $c) => new JsonErrorHandler(
        $c->get(LoggerInterface::class),
        new \Slim\Psr7\Factory\ResponseFactory(),
        (bool) $c->get('settings')['app']['debug'],
    )),

    // --- middleware -------------------------------------------------------
    CorsMiddleware::class => factory(
        static fn (ContainerInterface $c) => new CorsMiddleware($c->get('settings')['cors']),
    ),

    RateLimitMiddleware::class => factory(static fn (ContainerInterface $c) => new RateLimitMiddleware(
        $c->get(CacheItemPoolInterface::class),
        $c->get('settings')['rate_limit'],
    )),

    // Built-in roles exist as rows so user_roles can reference them, but
    // their permissions still come from the SystemRole enum — a tenant
    // cannot redefine what "Instructor" means.
    RoleRepository::class => factory(static fn (ContainerInterface $c) => new DbalRoleRepository(
        $c->get(Connection::class),
        $c->get(CacheItemPoolInterface::class),
    )),

    UserRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalUserRepository($c->get(Connection::class)),
    ),

    RefreshTokenRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalRefreshTokenRepository($c->get(Connection::class)),
    ),

    CourseRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalCourseRepository($c->get(Connection::class)),
    ),

    ModuleRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalModuleRepository($c->get(Connection::class)),
    ),

    LessonRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalLessonRepository($c->get(Connection::class)),
    ),

    LookupRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalLookupRepository($c->get(Connection::class)),
    ),

    PasswordHasher::class => autowire(),

    TokenAuthenticator::class => factory(static fn (ContainerInterface $c) => new TokenAuthenticator(
        $c->get(JwtTokenService::class),
        $c->get(RoleRepository::class),
    )),

    AuthenticationMiddleware::class => autowire()
        ->constructorParameter('authenticator', get(TokenAuthenticator::class)),
];
