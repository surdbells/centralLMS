<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\People;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\Lookup\LookupRepository;
use Central\Api\Domain\People\PeopleCsv;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonInput;
use Central\Api\Domain\People\PersonRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Adding a lot of people at once.
 *
 * POST /v1/users/import/preview   a file in, a verdict per row out
 * POST /v1/users/import           the rows back, and they are written
 *
 * **Nothing is kept between the two calls.** The legacy parsed the upload into
 * the PHP session and then wrote from it, which meant two administrators
 * importing at the same time overwrote each other's parse, and a preview left
 * open over lunch committed nothing because the session had expired.
 * The client sends back the rows it was shown.
 *
 * **The commit does not trust them.** It resolves and validates from scratch,
 * exactly as the preview did — the preview is advice, not a permission slip.
 * Skipping it and posting rows directly gets the same answers, which is also
 * what makes this scriptable.
 *
 * **No passwords, ever.** A column called `password` is not read. Imported
 * people are `invited` and set their own; a spreadsheet of credentials passed
 * around by email is not a thing this should help anybody make.
 *
 * Every row is validated by {@see PersonInput}, the same code the user form
 * goes through, so an import cannot admit a record the form would refuse.
 */
final class ImportPeopleAction extends PeopleAction
{
    /** Names in the file are matched to reference data of these kinds. */
    private const PLACEMENT = [
        'branch'     => LookupKind::Branch,
        'department' => LookupKind::Department,
        'team'       => LookupKind::Team,
        'level'      => LookupKind::Level,
        'jobTitle'   => LookupKind::JobTitle,
    ];

    public function __construct(
        PersonRepository $people,
        private readonly LookupRepository $lookups,
        private readonly RoleRepository $roles,
    ) {
        parent::__construct($people);
    }

    /**
     * Reads the file and says what would happen. Writes nothing.
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writable($request);

        try {
            $csv = PeopleCsv::read((string) $this->file($request)->getStream());
        } catch (\RuntimeException $e) {
            throw ValidationFailed::fromArray(['file' => $e->getMessage()]);
        }

        $rows = $this->examine($tenantId, array_map(
            static fn (array $row): array => [...$row['values'], 'line' => (string) $row['line']],
            $csv->rows,
        ));

        return Json::ok($response, [
            // Shown so somebody can see that "E-mail Address" was read as the
            // email and "Cost Centre" was read as nothing at all.
            'mapping'   => $csv->mapping,
            'ignored'   => $csv->unmapped,
            'truncated' => $csv->truncated,
            'maxRows'   => PeopleCsv::MAX_ROWS,
            'rows'      => $rows,
            'summary'   => $this->summarise($rows),
        ]);
    }

    /**
     * Writes the rows.
     *
     * `onExisting` decides what an address already in this organisation means:
     * `skip` leaves the person alone, `update` applies the row to them. There
     * is no third option that creates a second account for the same address —
     * the unique index would refuse it, and quietly not doing so is how a
     * directory ends up with two of everybody.
     */
    public function commit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writable($request);
        $body     = $this->body($request);

        $onExisting = strtolower(trim((string) ($body['onExisting'] ?? 'skip')));

        if (!in_array($onExisting, ['skip', 'update'], true)) {
            throw ValidationFailed::fromArray(['onExisting' => 'Choose either "skip" or "update".']);
        }

        $submitted = $body['rows'] ?? null;

        if (!is_array($submitted) || $submitted === []) {
            throw ValidationFailed::fromArray(['rows' => 'Send the rows to import.']);
        }

        if (count($submitted) > PeopleCsv::MAX_ROWS) {
            throw ValidationFailed::fromArray([
                'rows' => sprintf('An import is limited to %d rows at a time.', PeopleCsv::MAX_ROWS),
            ]);
        }

        // Resolved and validated again from nothing. The preview's verdict is
        // not carried over, so a client cannot hand back a row marked valid
        // that never was.
        $rows = $this->examine($tenantId, array_map(
            static fn (mixed $row): array => is_array($row) ? array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                $row,
            ) : [],
            array_values($submitted),
        ));

        $actorId  = $this->userId($request);
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $failed   = 0;
        $outcomes = [];

        foreach ($rows as $row) {
            $outcome = $this->write($tenantId, $row, $onExisting, $actorId);

            match ($outcome['outcome']) {
                'created' => $created++,
                'updated' => $updated++,
                'skipped' => $skipped++,
                default   => $failed++,
            };

            $outcomes[] = $outcome;
        }

        return Json::ok($response, [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed'  => $failed,
            'rows'    => $outcomes,
        ]);
    }

    // --- reading a row ------------------------------------------------------

    /**
     * Resolves each row and decides what it would do, without doing it.
     *
     * Reference data is looked up once per distinct name rather than once per
     * row: a file of four hundred people in six departments is six queries,
     * not four hundred.
     *
     * @param list<array<string, string>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function examine(string $tenantId, array $rows): array
    {
        $lookupCache = [];
        $roles       = $this->rolesByName($tenantId);
        $seenEmails  = [];
        $examined    = [];

        foreach ($rows as $index => $row) {
            $line       = (int) ($row['line'] ?? $index + 2);
            $violations = [];
            $warnings   = [];

            $email    = strtolower(trim($row['email'] ?? ''));
            $existing = $email === '' ? null : $this->people->findByEmail($tenantId, $email);

            // A file that lists the same person twice would otherwise create
            // them and then fail, or update them with whichever row landed
            // last — neither of which anybody asked for.
            if ($email !== '' && isset($seenEmails[$email])) {
                $violations['email'] = sprintf('This address is already on line %d of the file.', $seenEmails[$email]);
            } elseif ($email !== '') {
                $seenEmails[$email] = $line;
            }

            $input = [
                'email'     => $email,
                'firstName' => $row['firstName'] ?? '',
                'lastName'  => $row['lastName'] ?? '',
                'phone'     => $row['phone'] ?? '',
                'locale'    => trim($row['locale'] ?? '') ?: 'en',
                'hireDate'  => $row['hireDate'] ?? '',
            ];

            foreach (self::PLACEMENT as $field => $kind) {
                $name = trim($row[$field] ?? '');

                if ($name === '') {
                    continue;
                }

                $id = $this->resolveLookup($tenantId, $kind, $name, $lookupCache);

                if ($id === null) {
                    // A warning, not a violation: importing somebody without a
                    // department is better than not importing them, and the
                    // gap is visible on their record afterwards.
                    $warnings[] = sprintf('No %s called "%s" — left unset.', $kind->value, $name);

                    continue;
                }

                $input[$field . 'Id'] = $id;
            }

            $manager = strtolower(trim($row['manager'] ?? ''));

            if ($manager !== '') {
                $found = $this->people->findByEmail($tenantId, $manager);

                if ($found === null) {
                    // Managers commonly appear further down the same file, so
                    // this is not fatal either.
                    $warnings[] = sprintf('No account for the manager "%s" — left unset.', $manager);
                } else {
                    $input['managerId'] = $found->id;
                }
            }

            $roleNames = array_filter(array_map('trim', explode(',', $row['roles'] ?? '')));
            $roleIds   = [];

            foreach ($roleNames as $name) {
                $role = $roles[$this->key($name)] ?? null;

                if ($role === null) {
                    $violations['roles'] = sprintf('There is no role called "%s".', $name);

                    continue;
                }

                $roleIds[] = $role->id;
            }

            if ($roleIds !== []) {
                $input['roleIds'] = $roleIds;
            } elseif ($existing === null && $roleNames === []) {
                $warnings[] = 'No role given — they will be able to sign in and see nothing.';
            }

            $person = PersonInput::toPerson($tenantId, $existing?->id, $input, $violations, $existing);

            $examined[] = [
                'line'       => $line,
                // Handed back so the client can show the row it is judging and
                // send the same thing to the commit. Nothing is kept between
                // the two calls, so this is the only way across.
                'values'     => $row,
                'email'      => $email,
                'name'       => trim($input['firstName'] . ' ' . $input['lastName']),
                'existingId' => $existing?->id,
                'action'     => $violations !== [] ? 'error' : ($existing === null ? 'create' : 'existing'),
                'violations' => $violations,
                'warnings'   => $warnings,
                'person'     => $person,
            ];
        }

        return $examined;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function write(string $tenantId, array $row, string $onExisting, string $actorId): array
    {
        $reported = [
            'line'  => $row['line'],
            'email' => $row['email'],
            'name'  => $row['name'],
        ];

        if ($row['violations'] !== [] || !$row['person'] instanceof Person) {
            return [...$reported, 'outcome' => 'error', 'reason' => reset($row['violations']) ?: 'Invalid row.'];
        }

        if ($row['existingId'] !== null && $onExisting === 'skip') {
            return [...$reported, 'outcome' => 'skipped', 'reason' => 'Already here.'];
        }

        try {
            if ($row['existingId'] !== null) {
                $this->people->update($row['person']);

                return [...$reported, 'outcome' => 'updated'];
            }

            // No password: an imported account is `invited` and its holder
            // sets their own.
            $this->people->create($row['person'], null, $actorId);

            return [...$reported, 'outcome' => 'created'];
        } catch (\Throwable $e) {
            // One bad row must not abandon the four hundred behind it. What
            // failed is named in the result rather than raised.
            return [...$reported, 'outcome' => 'error', 'reason' => $e->getMessage()];
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function summarise(array $rows): array
    {
        $summary = ['total' => count($rows), 'create' => 0, 'existing' => 0, 'error' => 0, 'warnings' => 0];

        foreach ($rows as $row) {
            ++$summary[$row['action']];

            if ($row['warnings'] !== []) {
                ++$summary['warnings'];
            }
        }

        return $summary;
    }

    /**
     * @param array<string, array<string, string|null>> $cache
     */
    private function resolveLookup(string $tenantId, LookupKind $kind, string $name, array &$cache): ?string
    {
        $key = $this->key($name);

        if (!array_key_exists($kind->value, $cache)) {
            $cache[$kind->value] = [];
        }

        if (!array_key_exists($key, $cache[$kind->value])) {
            $cache[$kind->value][$key] = $this->lookups->findByName($tenantId, $kind, $name)?->id;
        }

        return $cache[$kind->value][$key];
    }

    /**
     * Roles by both name and slug, so a file may say "Instructor" or
     * "instructor" and mean the same thing.
     *
     * @return array<string, Role>
     */
    private function rolesByName(string $tenantId): array
    {
        $byName = [];

        foreach ($this->roles->findAllForTenant($tenantId) as $role) {
            $byName[$this->key($role->name)] = $role;
            $byName[$this->key($role->slug)] = $role;
        }

        return $byName;
    }

    private function key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    private function file(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file  = $files['file'] ?? reset($files);

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach a CSV file.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'That file did not arrive in one piece.']);
        }

        return $file;
    }

    private function writable(ServerRequestInterface $request): string
    {
        $this->caller($request)->mustHave(Permission::UserManage);

        return $this->tenantId($request);
    }
}
