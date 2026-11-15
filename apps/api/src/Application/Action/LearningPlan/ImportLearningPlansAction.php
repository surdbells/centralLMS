<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\LearningPlan;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Import\LearningPlanImporter;
use Central\Api\Application\Import\WorkbookDecryptor;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\LearningPlan\LearningPlanRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Importing per-person Learning Plans from an Excel workbook.
 *
 * POST /v1/learning-plans/import/preview   multipart file              → what would change
 * POST /v1/learning-plans/import/commit    multipart file, sheets,
 *                                          publish?, removeMissing?     → what changed
 *
 * The commit re-sends and re-reads the workbook — nothing is kept between the
 * two calls — so a commit always acts on exactly the file it is given.
 * Needs `course.manage`, like the rest of plan administration.
 */
final class ImportLearningPlansAction
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly LearningPlanRepository $plans,
        private readonly WorkbookDecryptor $decryptor = new WorkbookDecryptor(),
    ) {
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenant = $this->tenant($request);
        [$file, $name] = $this->upload($request);
        $path = $this->stash($file);

        try {
            $this->assertReadable($path);
            $importer = new LearningPlanImporter($this->plans);

            try {
                $parsed = $importer->read($path);
            } catch (\Throwable) {
                throw ValidationFailed::fromArray(['file' => 'The workbook could not be read.']);
            }
        } finally {
            @unlink($path);
        }

        // Database work stays outside the catch: a fault there (e.g. the
        // migration not run yet) is a 500 that gets logged, not a "bad file".
        return Json::ok($response, $importer->previewParsed($tenant, $parsed, $name));
    }

    public function commit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $body   = (array) ($request->getParsedBody() ?? []);
        $sheets = $this->sheetNames($body);
        $publish       = filter_var($body['publish'] ?? false, FILTER_VALIDATE_BOOL);
        $removeMissing = filter_var($body['removeMissing'] ?? false, FILTER_VALIDATE_BOOL);

        [$file, $name] = $this->upload($request);
        $path = $this->stash($file);

        try {
            $this->assertReadable($path);
            $importer = new LearningPlanImporter($this->plans);

            try {
                $parsed = $importer->read($path);
            } catch (\Throwable) {
                throw ValidationFailed::fromArray(['file' => 'The workbook could not be read.']);
            }
        } finally {
            @unlink($path);
        }

        $kinds = [];
        foreach ($parsed as $sheet) {
            $kinds[$sheet['sheet']] = $sheet['kind'];
        }

        foreach ($sheets as $sheetName) {
            if (($kinds[$sheetName] ?? null) !== 'plan') {
                throw ValidationFailed::fromArray(['sheets' => sprintf('"%s" is not a learning-plan sheet in this workbook.', $sheetName)]);
            }
        }

        return Json::ok($response, $importer->commit($tenant, $parsed, $name, $sheets, $publish, $removeMissing));
    }

    // --- plumbing -------------------------------------------------------------

    /**
     * `sheets` as a JSON array string, or repeated `sheets[]` fields.
     *
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private function sheetNames(array $body): array
    {
        $raw = $body['sheets'] ?? null;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [$raw];
        }

        $names = [];
        foreach (is_array($raw) ? $raw : [] as $n) {
            if (is_string($n) && $n !== '') {
                $names[$n] = true;
            }
        }

        if ($names === []) {
            throw ValidationFailed::fromArray(['sheets' => 'Choose at least one sheet to import.']);
        }

        return array_map('strval', array_keys($names));
    }

    /**
     * @return array{0: UploadedFileInterface, 1: string}
     */
    private function upload(ServerRequestInterface $request): array
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw ValidationFailed::fromArray(['file' => 'Attach an .xlsx workbook.']);
        }

        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
            || ($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw ValidationFailed::fromArray(['file' => 'The workbook is larger than the 10 MB limit.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'The workbook did not arrive in one piece.']);
        }

        $name = $file->getClientFilename() ?? 'workbook.xlsx';
        if (!str_ends_with(strtolower($name), '.xlsx')) {
            throw ValidationFailed::fromArray(['file' => 'Upload an Excel .xlsx workbook.']);
        }

        return [$file, $name];
    }

    /** An xlsx is a zip; an OLE file is an encrypted workbook or a legacy .xls. */
    private function assertReadable(string $path): void
    {
        if ($this->decryptor->isOleContainer($path)) {
            throw ValidationFailed::fromArray(['file' => 'The workbook is password-protected or an old .xls file. Save it as a plain .xlsx and try again.']);
        }

        $handle = @fopen($path, 'rb');
        $magic  = $handle === false ? '' : (string) fread($handle, 4);
        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic !== "PK\x03\x04") {
            throw ValidationFailed::fromArray(['file' => 'The file is not an .xlsx workbook.']);
        }
    }

    /** openspout reads by path, so the upload goes to a temp file removed after. */
    private function stash(UploadedFileInterface $file): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lpi_') ?: sys_get_temp_dir() . '/lpi_' . bin2hex(random_bytes(8));
        $file->moveTo($path);

        return $path;
    }

    private function tenant(ServerRequestInterface $request): string
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::CourseManage);

        return (string) $caller->tenantId;
    }
}
