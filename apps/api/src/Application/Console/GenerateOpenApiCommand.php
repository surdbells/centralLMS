<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use OpenApi\Generator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds the OpenAPI document from the attributes on the action classes.
 *
 * The document is generated, never hand-maintained, and the TypeScript client
 * in packages/api-client is generated from the document. That chain is what
 * keeps the three frontends from each hand-rolling their own idea of the API —
 * the duplication that made the legacy Angular services drift out of sync with
 * the backend.
 *
 * CI runs this with --check and fails if the committed document is stale.
 */
#[AsCommand(
    name: 'openapi:generate',
    description: 'Generate the OpenAPI document from source attributes',
)]
final class GenerateOpenApiCommand extends Command
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the document')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail if the document on disk is out of date');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $root = (string) $this->settings['paths']['root'];
        $path = (string) ($input->getOption('output') ?? $root . '/public/openapi.json');

        $openapi = Generator::scan([$root . '/src']);

        if ($openapi === null) {
            $io->error('swagger-php produced no document. Are there any OpenAPI attributes in src/?');

            return Command::FAILURE;
        }

        $json = $openapi->toJson() . "\n";

        if ((bool) $input->getOption('check')) {
            $existing = is_readable($path) ? (string) file_get_contents($path) : '';

            if ($existing === $json) {
                $io->success('OpenAPI document is up to date.');

                return Command::SUCCESS;
            }

            $io->error(sprintf(
                'OpenAPI document at %s is out of date. Run: php bin/console openapi:generate',
                $path,
            ));

            return Command::FAILURE;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $io->error(sprintf('Could not create directory %s', $directory));

            return Command::FAILURE;
        }

        file_put_contents($path, $json);

        $io->success(sprintf('Wrote %s (%s).', $path, $this->humanSize(strlen($json))));

        return Command::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' B' : round($bytes / 1024, 1) . ' KB';
    }
}
