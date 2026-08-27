<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Every path the browser asks for, against every path this API serves.
 *
 * Both sides are literal strings in two files that nothing checked against
 * each other, and they had drifted in fourteen places. The inbox was the worst
 * of them: `api-endpoints.ts` addressed it at `/me/notifications`, the API
 * served it at `/notifications`, and the bell had been returning 404 since the
 * day it was wired up — silently, because a failed request only ever produced
 * an empty list.
 *
 * A 404 from a mistyped constant looks exactly like a feature nobody uses. The
 * only reliable way to tell the two apart is to ask the router.
 *
 * There is no allowlist. A path the frontend names and the API does not serve
 * is either a typo to correct or an endpoint to write, and both are work —
 * not something to record and walk past.
 */
#[AsCommand(
    name: 'routes:check',
    description: "Check the frontend's endpoint table against the routes this API serves",
)]
final class CheckFrontendRoutesCommand extends Command
{
    /** Where the browser keeps its side of the contract. */
    private const ENDPOINTS = '/../web/src/app/core/api/api-endpoints.ts';

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('endpoints', null, InputOption::VALUE_REQUIRED, 'Path to api-endpoints.ts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $root = (string) $this->settings['paths']['root'];
        $file = (string) ($input->getOption('endpoints') ?? $root . self::ENDPOINTS);

        if (!is_file($file)) {
            $io->error(sprintf('No endpoint table at %s', $file));

            return Command::FAILURE;
        }

        $served  = $this->served();
        $missing = [];

        foreach ($this->asked((string) file_get_contents($file)) as $shape => $written) {
            if ($shape === '' || $shape === '/') {
                continue;
            }

            if (!isset($served[$shape]) && !isset($served['/v1' . $shape])) {
                $missing[] = $written;
            }
        }

        if ($missing !== []) {
            sort($missing);
            $io->error(sprintf('%d paths are asked for and not served:', count($missing)));
            $io->listing($missing);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Every path the frontend names is served. %d distinct paths.',
            count($served),
        ));

        return Command::SUCCESS;
    }

    /**
     * Every route this API declares, asked of the router rather than read
     * out of `routes.php` — a file whose group prefixes only mean what
     * they mean once Slim has assembled them.
     *
     * @return array<string, true>
     */
    private function served(): array
    {
        AppFactory::setContainer($this->container);
        $app = AppFactory::create();

        (require (string) $this->settings['paths']['root'] . '/config/routes.php')($app);

        $served = [];

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $served[$this->shape($route->getPattern())] = true;
        }

        return $served;
    }

    /**
     * The path literals in the endpoint table, by shape.
     *
     * @return array<string, string> shape => the path as written
     */
    private function asked(string $typescript): array
    {
        $asked = [];

        if (preg_match_all('#[\'`](/[a-zA-Z0-9_\-/{}$.]*)[\'`]#', $typescript, $matches) === false) {
            return [];
        }

        foreach ($matches[1] as $path) {
            $asked[$this->shape($path)] = $path;
        }

        return $asked;
    }

    /**
     * A path with its placeholders flattened, so the two sides are comparable.
     *
     * Segment by segment, and not with a pattern: Slim writes its constraints
     * inline — `{id:[0-9a-f]{8}-...}` — so the braces nest, and anything that
     * stops at the first closing brace mangles them.
     */
    private function shape(string $path): string
    {
        $segments = [];
        $depth    = 0;
        $segment  = '';
        $isParam  = false;

        foreach (str_split($path) as $character) {
            if ($character === '{') {
                $depth++;
                $isParam = true;

                continue;
            }

            if ($character === '}') {
                $depth--;

                continue;
            }

            if ($character === '/' && $depth === 0) {
                $segments[] = $isParam ? '{}' : $segment;
                $segment    = '';
                $isParam    = false;

                continue;
            }

            // A `$` only ever precedes a template placeholder on the
            // TypeScript side, and there is no `$` in a Slim pattern.
            if ($depth === 0 && $character !== '$') {
                $segment .= $character;
            }
        }

        $segments[] = $isParam ? '{}' : $segment;

        return implode('/', $segments);
    }
}
