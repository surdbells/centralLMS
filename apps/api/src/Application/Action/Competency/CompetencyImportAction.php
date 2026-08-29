<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Competency;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Import\CompetencyWorkbookImporter;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Importing the Job Competency Profile workbooks from the browser.
 *
 * The console command reads a folder on the server; this reads the workbooks
 * an administrator uploads, so the import is reachable without shell access.
 * Both run the same {@see CompetencyWorkbookImporter}, so they agree on what a
 * workbook means.
 */
final class CompetencyImportAction
{
    /** Enough for a workbook of descriptors; a real JCP is well under this. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(private readonly CompetencyRepository $repository)
    {
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::CompetencyManage);

        $tenantId = $caller->tenantId;
        if ($tenantId === null) {
            throw new AccessDenied(Permission::CompetencyManage->value);
        }

        $files = $this->uploads($request);
        $importer = new CompetencyWorkbookImporter($this->repository);

        $results = [];
        $totals = ['files' => 0, 'functions' => 0, 'competencies' => 0, 'profiles' => 0, 'requirements' => 0, 'people' => 0];

        foreach ($files as $file) {
            $name = $file->getClientFilename() ?? 'workbook.xlsx';
            $path = $this->stash($file);

            try {
                $summary = $importer->importFile($tenantId, $path, null, false, $name);
            } catch (\Throwable) {
                $results[] = ['file' => $name, 'status' => 'unreadable', 'functions' => []];

                continue;
            } finally {
                @unlink($path);
            }

            $totals['files']++;

            if ($summary['legacyXls']) {
                // An old .xls renamed to .xlsx — nothing the reader can open.
                $results[] = ['file' => $name, 'status' => 'legacy_xls', 'functions' => []];

                continue;
            }

            if ($summary['empty']) {
                $results[] = ['file' => $name, 'status' => 'no_competency_sheets', 'functions' => []];

                continue;
            }

            foreach ($summary['functions'] as $function) {
                $totals['functions']++;
                $totals['competencies'] += $function['competencies'];
                $totals['profiles'] += count($function['profiles']);
                foreach ($function['profiles'] as $profile) {
                    $totals['requirements'] += $profile['requirements'];
                }
            }
            $totals['people'] += $summary['people'];

            $results[] = [
                'file'      => $name,
                'status'    => 'imported',
                'people'    => $summary['people'],
                'functions' => $summary['functions'],
            ];
        }

        return Json::ok($response, ['totals' => $totals, 'files' => $results]);
    }

    /**
     * @return list<UploadedFileInterface>
     */
    private function uploads(ServerRequestInterface $request): array
    {
        $out = [];

        // Accept a single `file`, a `files[]` array, or whatever field was
        // used — an administrator picking one workbook or the whole set.
        foreach ($request->getUploadedFiles() as $entry) {
            foreach (is_array($entry) ? $entry : [$entry] as $file) {
                if ($file instanceof UploadedFileInterface) {
                    $out[] = $file;
                }
            }
        }

        if ($out === []) {
            throw ValidationFailed::fromArray(['file' => 'Attach at least one .xlsx workbook.']);
        }

        foreach ($out as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw ValidationFailed::fromArray(['file' => 'A workbook did not arrive in one piece.']);
            }

            $size = $file->getSize();
            if ($size !== null && $size > self::MAX_BYTES) {
                throw ValidationFailed::fromArray(['file' => 'A workbook is larger than the 15 MB limit.']);
            }
        }

        return $out;
    }

    /**
     * openspout reads a file by path (a workbook is a zip), so the upload is
     * written to a temporary file the importer can open, and removed after.
     */
    private function stash(UploadedFileInterface $file): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jcp_') ?: sys_get_temp_dir() . '/jcp_' . bin2hex(random_bytes(8));
        $file->moveTo($path);

        return $path;
    }
}
