<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\People;

use Central\Api\Application\Action\Auth\PasswordResetAction;
use Central\Api\Application\Http\Json;
use Central\Api\Application\Import\ImportedPersonCreator;
use Central\Api\Application\Import\ImportOptions;
use Central\Api\Application\Import\PlacementImporter;
use Central\Api\Application\Import\PlacementWorkbook;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\CustomField\CustomFieldDefinitionRepository;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Lookup\LookupRepository;
use Central\Api\Domain\People\PersonRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Updating where people sit from a staff placement workbook.
 *
 * POST /v1/people/import/placement/preview   the workbook in, a verdict per row out
 * POST /v1/people/import/placement/commit    the same workbook again, and it is written
 *
 * Like {@see ImportPeopleAction}, nothing is kept between the two calls: the
 * commit re-reads the file and recomputes every decision, so the preview is
 * advice and the commit cannot be handed a verdict that was never true.
 */
final class ImportPlacementAction extends PeopleAction
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private readonly PlacementImporter $importer;

    public function __construct(
        PersonRepository $people,
        LookupRepository $lookups,
        CustomFieldDefinitionRepository $fields,
        RoleRepository $roles,
        private readonly PasswordResetAction $passwords,
    ) {
        parent::__construct($people);
        $this->importer = new PlacementImporter(
            $people,
            $lookups,
            $fields,
            new ImportedPersonCreator($people, $lookups, $fields, $roles),
        );
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writable($request);
        $options  = ImportOptions::read($this->body($request));
        [$name, $parsed] = $this->parse($request);

        $preview = $this->importer->preview($tenantId, $parsed, $options['createMissing']);

        return Json::ok($response, [
            'file'     => $name,
            'rowCount' => count($parsed['rows']),
            ...$preview,
        ]);
    }

    public function commit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writable($request);
        $options  = ImportOptions::read($this->body($request));
        [$name, $parsed] = $this->parse($request);

        $result = $this->importer->commit(
            $tenantId,
            $parsed,
            $options['createMissing'],
            $options['roleId'],
            $this->userId($request),
        );

        $invites = ['sent' => 0, 'failed' => []];

        if ($options['sendInvites'] && $result['createdPeople'] !== []) {
            $invites = ImportOptions::invite(
                $this->passwords,
                $request,
                $tenantId,
                array_map(static fn (array $p): string => (string) $p['userId'], $result['createdPeople']),
            );
        }

        return Json::ok($response, [
            'file'  => $name,
            'sheet' => $parsed['sheet'],
            ...$result,
            'sendInvites'       => $options['sendInvites'],
            'invitationsSent'   => $invites['sent'],
            'invitationsFailed' => $invites['failed'],
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parse(ServerRequestInterface $request): array
    {
        $file  = $this->file($request);
        $name  = $file->getClientFilename() ?? 'placement.xlsx';
        $body  = $this->body($request);
        $sheet = isset($body['sheet']) && is_string($body['sheet']) && trim($body['sheet']) !== ''
            ? $body['sheet']
            : null;

        $path = tempnam(sys_get_temp_dir(), 'plc_') ?: sys_get_temp_dir() . '/plc_' . bin2hex(random_bytes(8));

        try {
            $file->moveTo($path);

            if (!$this->isZip($path)) {
                throw ValidationFailed::fromArray([
                    'file' => 'This is not an .xlsx workbook. Password-protected and old .xls files cannot be read; save it as .xlsx without a password.',
                ]);
            }

            try {
                $sheets = PlacementWorkbook::load($path);
            } catch (\InvalidArgumentException $e) {
                throw ValidationFailed::fromArray(['file' => $e->getMessage()]);
            }
        } finally {
            @unlink($path);
        }

        try {
            $parsed = PlacementWorkbook::parse($sheets, $sheet);
        } catch (\InvalidArgumentException $e) {
            throw ValidationFailed::fromArray([$sheet !== null ? 'sheet' : 'file' => $e->getMessage()]);
        }

        return [$name, $parsed];
    }

    private function isZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        return $magic === "PK\x03\x04";
    }

    private function file(ServerRequestInterface $request): UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach an .xlsx workbook.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'That file did not arrive in one piece.']);
        }

        $size = $file->getSize();

        if ($size !== null && $size > self::MAX_BYTES) {
            throw ValidationFailed::fromArray(['file' => 'The workbook is larger than the 10 MB limit.']);
        }

        $name = strtolower((string) $file->getClientFilename());

        if ($name !== '' && !str_ends_with($name, '.xlsx')) {
            throw ValidationFailed::fromArray(['file' => 'Choose an .xlsx workbook.']);
        }

        return $file;
    }

    private function writable(ServerRequestInterface $request): string
    {
        $this->caller($request)->mustHave(Permission::UserManage);

        return $this->tenantId($request);
    }
}
