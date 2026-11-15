<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\ImportedPersonCreator;
use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldType;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Tests\Doubles\FakeRoleRepository;
use Central\Api\Tests\Doubles\InMemoryCustomFieldRepository;
use Central\Api\Tests\Doubles\InMemoryLookupRepository;
use Central\Api\Tests\Doubles\InMemoryPersonRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportedPersonCreator::class)]
final class ImportedPersonCreatorTest extends TestCase
{
    private const T = '11111111-1111-4111-8111-111111111111';

    private InMemoryPersonRepository $people;
    private InMemoryLookupRepository $lookups;
    private InMemoryCustomFieldRepository $fields;
    private ImportedPersonCreator $creator;

    protected function setUp(): void
    {
        $this->people  = new InMemoryPersonRepository();
        $this->lookups = new InMemoryLookupRepository();
        $this->fields  = new InMemoryCustomFieldRepository();
        $this->creator = new ImportedPersonCreator($this->people, $this->lookups, $this->fields, new FakeRoleRepository());
    }

    #[Test]
    public function it_says_why_a_row_cannot_become_an_account(): void
    {
        self::assertSame('missing_email', ImportedPersonCreator::assess('', 'Ada Test')['reason']);
        self::assertSame('invalid_email', ImportedPersonCreator::assess('ada.example.test', 'Ada Test')['reason']);
        self::assertSame('missing_name', ImportedPersonCreator::assess('ada@example.test', null)['reason']);
        self::assertSame('missing_name', ImportedPersonCreator::assess('ada@example.test', '  ')['reason']);
        self::assertSame('incomplete_name', ImportedPersonCreator::assess('ada@example.test', 'Ada')['reason']);

        $ok = ImportedPersonCreator::assess(' Ada@Example.TEST ', 'ADA BYRON-TEST');
        self::assertTrue($ok['ok']);
        self::assertSame('ada@example.test', $ok['email']);
        self::assertSame('Ada', $ok['firstName']);
        self::assertSame('Byron-Test', $ok['lastName']);
        self::assertNull($ok['reason']);
    }

    #[Test]
    public function new_people_get_the_learner_role_unless_another_is_named(): void
    {
        self::assertSame(['learner'], $this->creator->roleIds(self::T, null));
        self::assertSame(['learner'], $this->creator->roleIds(self::T, ' '));
        self::assertSame(['manager'], $this->creator->roleIds(self::T, 'manager'));

        $this->expectException(ValidationFailed::class);
        $this->creator->roleIds(self::T, 'no-such-role');
    }

    #[Test]
    public function it_creates_an_invited_account_with_its_profile_and_never_twice(): void
    {
        $this->lookups->create(new Lookup('00000000-0000-4000-8000-0000000000d1', self::T, LookupKind::Department, 'Finance & Accounts'));
        $this->fields->create(new CustomFieldDefinition('f-1', self::T, 'entity', 'Entity', CustomFieldType::Dropdown, ['JV']));

        $spec = [
            'email'     => 'Ada@Example.test',
            'firstName' => 'Ada',
            'lastName'  => 'Test',
            'lookups'   => ['department' => 'finance and accounts', 'jobTitle' => 'Analyst', 'level' => 'Senior Officer'],
            'custom'    => [
                'division' => ['label' => 'Division', 'value' => 'Upstream'],
                'entity'   => ['label' => 'Entity', 'value' => 'sole'],
            ],
        ];

        $first = $this->creator->create(self::T, $spec, ['learner'], 'actor');
        $again = $this->creator->create(self::T, $spec, ['learner'], 'actor');

        self::assertTrue($first['created']);
        self::assertFalse($again['created']);
        self::assertSame($first['person']->id, $again['person']->id);
        self::assertSame(1, $this->people->creates);

        $person = $first['person'];
        self::assertSame('ada@example.test', $person->email);
        self::assertSame(UserStatus::Invited, $person->status);
        self::assertSame(['learner'], $person->roleIds);
        // Found by normalised name, not duplicated.
        self::assertSame('00000000-0000-4000-8000-0000000000d1', $person->departmentId);
        self::assertNotNull($person->jobTitleId);
        self::assertNotNull($person->levelId);
        self::assertSame(['Senior Officer'], $this->lookups->names(LookupKind::Level));
        self::assertSame(['Analyst'], $this->lookups->names(LookupKind::JobTitle));

        // A missing text field is created; a dropdown gains the option.
        self::assertSame(CustomFieldType::Text, $this->fields->byKey('division')?->type);
        self::assertSame(['JV', 'sole'], $this->fields->byKey('entity')?->options);
        self::assertSame(['division' => 'Upstream', 'entity' => 'sole'], $this->people->custom[(string) $person->id]);
    }

    #[Test]
    public function a_second_person_reuses_reference_data_created_for_the_first(): void
    {
        $this->creator->create(self::T, ['email' => 'a@example.test', 'firstName' => 'A', 'lastName' => 'Test', 'lookups' => ['level' => 'Manager']], ['learner'], null);
        $this->creator->create(self::T, ['email' => 'b@example.test', 'firstName' => 'B', 'lastName' => 'Test', 'lookups' => ['level' => 'MANAGER']], ['learner'], null);

        self::assertSame(1, $this->lookups->creates);
    }
}
