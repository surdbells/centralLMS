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
use Central\Api\Application\Console\CheckFrontendRoutesCommand;
use Central\Api\Application\Console\GenerateOpenApiCommand;
use Central\Api\Application\Handler\JsonErrorHandler;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Middleware\CorsMiddleware;
use Central\Api\Application\Middleware\RateLimitMiddleware;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Identity\PasswordTokenRepository;
use Central\Api\Domain\Sso\SsoRepository;
use Central\Api\Domain\Identity\RefreshTokenRepository;
use Central\Api\Domain\Identity\UserRepository;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\Grader;
use Central\Api\Domain\Certificate\CertificateRenderer;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Ilt\AttendanceCalculator;
use Central\Api\Domain\Ilt\MeetingProviderResolver;
use Central\Api\Domain\Ilt\SessionScheduler;
use Central\Api\Domain\Ilt\TrainingRepository;
use Central\Api\Domain\Proctoring\ProctoringRepository;
use Central\Api\Domain\Assessment\PaperBuilder;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Catalog\LessonRepository;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Enrollment\LessonProgressRepository;
use Central\Api\Domain\Catalog\ModuleRepository;
use Central\Api\Domain\Lookup\LookupRepository;
use Central\Api\Infrastructure\Persistence\DbalAssessmentRepository;
use Central\Api\Infrastructure\Persistence\DbalAttemptRepository;
use Central\Api\Application\Notification\NotificationDispatcher;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Application\Notification\ReminderExpander;
use Central\Api\Application\Notification\SessionNotifier;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Infrastructure\Notification\AccessTokenProvider;
use Central\Api\Infrastructure\Notification\FcmPushTransport;
use Central\Api\Infrastructure\Notification\HttpSender;
use Central\Api\Infrastructure\Notification\InAppTransport;
use Central\Api\Infrastructure\Notification\LogTransport;
use Central\Api\Infrastructure\Notification\TermiiSmsTransport;
use Central\Api\Infrastructure\Notification\ZeptoMailTransport;
use Central\Api\Domain\Report\CsvWriter;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Domain\Report\SavedReportRepository;
use Central\Api\Infrastructure\Persistence\DbalReportRepository;
use Central\Api\Infrastructure\Persistence\DbalSavedReportRepository;
use Central\Api\Application\Gamification\Awarder;
use Central\Api\Domain\Gamification\GamificationRepository;
use Central\Api\Infrastructure\Persistence\DbalGamificationRepository;
use Central\Api\Application\Dashboard\AdminDashboardAssembler;
use Central\Api\Application\Dashboard\LearnerDashboardAssembler;
use Central\Api\Domain\Dashboard\DashboardRepository;
use Central\Api\Domain\LearningPath\LearningPathRepository;
use Central\Api\Domain\Media\MediaRepository;
use Central\Api\Domain\Messaging\MessagingRepository;
use Central\Api\Domain\Audit\AuditRepository;
use Central\Api\Domain\Note\NoteRepository;
use Central\Api\Domain\Settings\SettingCatalogue;
use Central\Api\Domain\Skill\SkillRepository;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Infrastructure\Persistence\DbalSettingsRepository;
use Central\Api\Infrastructure\Persistence\DbalSkillRepository;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Infrastructure\Persistence\DbalDashboardRepository;
use Central\Api\Infrastructure\Persistence\DbalLearningPathRepository;
use Central\Api\Infrastructure\Persistence\DbalMediaRepository;
use Central\Api\Infrastructure\Persistence\DbalMessagingRepository;
use Central\Api\Infrastructure\Persistence\DbalAuditRepository;
use Central\Api\Infrastructure\Persistence\DbalNoteRepository;
use Central\Api\Infrastructure\Persistence\DbalPersonRepository;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Central\Api\Infrastructure\Persistence\DbalNotificationRepository;
use Central\Api\Infrastructure\Persistence\DbalCertificateRepository;
use Central\Api\Infrastructure\Persistence\DbalCourseRepository;
use Central\Api\Infrastructure\Persistence\DbalProctoringRepository;
use Central\Api\Infrastructure\Persistence\DbalQuestionRepository;
use Central\Api\Infrastructure\Persistence\DbalTrainingRepository;
use Central\Api\Infrastructure\Persistence\DbalEnrollmentRepository;
use Central\Api\Infrastructure\Persistence\DbalLessonProgressRepository;
use Central\Api\Infrastructure\Persistence\DbalLessonRepository;
use Central\Api\Infrastructure\Persistence\DbalModuleRepository;
use Central\Api\Infrastructure\Persistence\DbalLookupRepository;
use Central\Api\Infrastructure\Authz\DbalRoleRepository;
use Central\Api\Infrastructure\Persistence\DbalPasswordTokenRepository;
use Central\Api\Infrastructure\Persistence\DbalSsoRepository;
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
use League\Flysystem\Local\LocalFilesystemAdapter;
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
        $storage = $c->get('settings')['storage'];

        // Anything but `s3` writes to disk. Defaulting that way round means a
        // deployment with no object storage configured stores files somewhere
        // real rather than failing every upload against an empty endpoint.
        if ($storage['driver'] !== 's3') {
            return new Filesystem(new LocalFilesystemAdapter((string) $storage['local']['root']));
        }

        $s3 = $storage['s3'];

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

    CheckFrontendRoutesCommand::class => factory(
        static fn (ContainerInterface $c) => new CheckFrontendRoutesCommand($c, $c->get('settings')),
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

    PasswordTokenRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalPasswordTokenRepository($c->get(Connection::class)),
    ),

    // The client secrets are encrypted with the same cipher the
    // conferencing credentials use.
    SsoRepository::class => factory(static fn (ContainerInterface $c) => new DbalSsoRepository(
        $c->get(Connection::class),
        $c->get(Cipher::class),
    )),

    CourseRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalCourseRepository($c->get(Connection::class)),
    ),

    Grader::class => factory(static fn (): Grader => new Grader()),

    CertificateRenderer::class => factory(static fn (): CertificateRenderer => new CertificateRenderer()),

    CertificateRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalCertificateRepository($c->get(Connection::class)),
    ),

    AttendanceCalculator::class => factory(static fn (): AttendanceCalculator => new AttendanceCalculator()),

    // The infrastructure factory answers the domain-side interface, so
    // SessionScheduler never reaches into Infrastructure\Ilt itself.
    MeetingProviderResolver::class => factory(
        static fn (ContainerInterface $c) => $c->get(MeetingProviderFactory::class),
    ),

    SessionScheduler::class => factory(
        static fn (ContainerInterface $c) => new SessionScheduler($c->get(MeetingProviderResolver::class)),
    ),

    TrainingRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalTrainingRepository(
            $c->get(Connection::class),
            $c->get(AttendanceCalculator::class),
        ),
    ),

    ProctoringRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalProctoringRepository($c->get(Connection::class)),
    ),

    PaperBuilder::class => factory(static fn (): PaperBuilder => new PaperBuilder()),

    QuestionRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalQuestionRepository($c->get(Connection::class)),
    ),

    AssessmentRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalAssessmentRepository(
            $c->get(Connection::class),
            $c->get(QuestionRepository::class),
        ),
    ),

    AttemptRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalAttemptRepository(
            $c->get(Connection::class),
            $c->get(QuestionRepository::class),
        ),
    ),

    EnrollmentRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalEnrollmentRepository($c->get(Connection::class)),
    ),

    LessonProgressRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalLessonProgressRepository(
            $c->get(Connection::class),
            $c->get(EnrollmentRepository::class),
        ),
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

    // --- notifications ----------------------------------------------------

    NotificationComposer::class => factory(static fn (): NotificationComposer => new NotificationComposer()),

    NotificationRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalNotificationRepository($c->get(Connection::class)),
    ),

    Notifier::class => factory(static fn (ContainerInterface $c) => new Notifier(
        $c->get(NotificationRepository::class),
        $c->get(NotificationComposer::class),
        $c->get(Clock::class),
        $c->get(LoggerInterface::class),
    )),

    /**
     * One transport per channel, keyed by channel value.
     *
     * The provider name `log` is what development and the test suite use: the
     * whole path runs — route, queue, claim, send, mark sent — and the message
     * lands in the log rather than in somebody's inbox. Anything unrecognised
     * falls back to it too, because a typo in MAIL_PROVIDER should not send
     * mail through a provider nobody meant.
     */
    'notification.transports' => factory(static function (ContainerInterface $c): array {
        $settings = $c->get('settings');
        $logger   = $c->get(LoggerInterface::class);
        $http     = new HttpSender($c->get(HttpClientInterface::class));

        $transports = [
            NotificationChannel::InApp->value => new InAppTransport(),
        ];

        $mail = $settings['mail'];

        $transports[NotificationChannel::Email->value] = $mail['provider'] === 'zeptomail'
            ? new ZeptoMailTransport(
                $http,
                (string) $mail['zeptomail']['token'],
                (string) $mail['zeptomail']['base_url'],
                (string) $mail['from']['address'],
                (string) $mail['from']['name'],
                $logger,
            )
            : new LogTransport(NotificationChannel::Email, $logger);

        $sms = $settings['sms'];

        $transports[NotificationChannel::Sms->value] = $sms['provider'] === 'termii'
            ? new TermiiSmsTransport(
                $http,
                (string) $sms['termii']['api_key'],
                (string) $sms['termii']['sender_id'],
                (string) $sms['termii']['base_url'],
                $logger,
            )
            : new LogTransport(NotificationChannel::Sms, $logger);

        $fcm = $settings['push']['fcm'];

        $transports[NotificationChannel::Push->value] = (string) $fcm['project_id'] !== ''
            ? new FcmPushTransport(
                $http,
                (string) $fcm['project_id'],
                new AccessTokenProvider(
                    $http,
                    (string) $fcm['credentials_path'],
                    $c->get(Clock::class),
                ),
                $logger,
            )
            : new LogTransport(NotificationChannel::Push, $logger);

        return $transports;
    }),

    NotificationDispatcher::class => factory(static fn (ContainerInterface $c) => new NotificationDispatcher(
        $c->get(NotificationRepository::class),
        $c->get('notification.transports'),
        $c->get(Clock::class),
        $c->get(LoggerInterface::class),
    )),

    SessionNotifier::class => factory(static fn (ContainerInterface $c) => new SessionNotifier(
        $c->get(NotificationRepository::class),
        $c->get(TrainingRepository::class),
        $c->get(Notifier::class),
    )),

    ReminderExpander::class => factory(static fn (ContainerInterface $c) => new ReminderExpander(
        $c->get(NotificationRepository::class),
        $c->get(Notifier::class),
        $c->get(Clock::class),
        $c->get(LoggerInterface::class),
    )),
    // --- reporting --------------------------------------------------------

    ReportCatalogue::class => factory(static fn (): ReportCatalogue => new ReportCatalogue()),

    CsvWriter::class => factory(static fn (): CsvWriter => new CsvWriter()),

    ReportRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalReportRepository($c->get(Connection::class)),
    ),

    SavedReportRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalSavedReportRepository($c->get(Connection::class)),
    ),
    GamificationRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalGamificationRepository($c->get(Connection::class)),
    ),

    Awarder::class => factory(static fn (ContainerInterface $c) => new Awarder(
        $c->get(GamificationRepository::class),
        $c->get(SettingsRepository::class),
        $c->get(LoggerInterface::class),
    )),
    PersonRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalPersonRepository($c->get(Connection::class)),
    ),

    MediaRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalMediaRepository($c->get(Connection::class)),
    ),

    NoteRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalNoteRepository($c->get(Connection::class)),
    ),

    // Takes a logger because a failed audit write must be loud and must not
    // reach the request it was describing — see DbalAuditRepository::record().
    AuditRepository::class => factory(static fn (ContainerInterface $c) => new DbalAuditRepository(
        $c->get(Connection::class),
        $c->get(LoggerInterface::class),
    )),

    MessagingRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalMessagingRepository($c->get(Connection::class)),
    ),

    LearningPathRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalLearningPathRepository($c->get(Connection::class)),
    ),

    SkillRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalSkillRepository($c->get(Connection::class)),
    ),

    SettingCatalogue::class => autowire(),

    SettingsRepository::class => factory(static fn (ContainerInterface $c) => new DbalSettingsRepository(
        $c->get(Connection::class),
        $c->get(SettingCatalogue::class),
    )),

    DashboardRepository::class => factory(
        static fn (ContainerInterface $c) => new DbalDashboardRepository($c->get(Connection::class)),
    ),

    // Assembled from the report definitions rather than from fresh SQL — see
    // the class docblock for why the legacy's two copies disagreed.
    AdminDashboardAssembler::class => factory(static fn (ContainerInterface $c) => new AdminDashboardAssembler(
        $c->get(ReportRepository::class),
        $c->get(DashboardRepository::class),
        $c->get(ReportCatalogue::class),
    )),

    LearnerDashboardAssembler::class => factory(static fn (ContainerInterface $c) => new LearnerDashboardAssembler(
        $c->get(DashboardRepository::class),
        $c->get(EnrollmentRepository::class),
        $c->get(CertificateRepository::class),
        $c->get(GamificationRepository::class),
        $c->get(Clock::class),
    )),

    MediaStorage::class => factory(static fn (ContainerInterface $c) => new MediaStorage(
        $c->get(FilesystemOperator::class),
        $c->get(LoggerInterface::class),
    )),

    PasswordHasher::class => autowire(),

    TokenAuthenticator::class => factory(static fn (ContainerInterface $c) => new TokenAuthenticator(
        $c->get(JwtTokenService::class),
        $c->get(RoleRepository::class),
    )),

    AuthenticationMiddleware::class => autowire()
        ->constructorParameter('authenticator', get(TokenAuthenticator::class)),
];
