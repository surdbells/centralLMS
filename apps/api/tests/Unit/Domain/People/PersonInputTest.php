<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\People;

use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a people record will and will not accept.
 *
 * `User::create()` read twenty properties the caller had set one at a time and
 * validated none of them: an empty email, a hire date of `"soon"` and a role
 * id from another organisation all reached the insert.
 */
#[CoversClass(PersonInput::class)]
#[CoversClass(Person::class)]
final class PersonInputTest extends TestCase
{
    private const TENANT = 'tenant-1';
    private const ROLE = 'role-1';

    // Real uuids: the id sanitiser drops anything that is not shaped like one,
    // so a placeholder would be nulled before the self-reporting check ran.
    private const PERSON = '11111111-1111-4111-8111-111111111111';
    private const DEPARTMENT = '22222222-2222-4222-8222-222222222222';

    #[Test]
    public function a_complete_record_is_accepted(): void
    {
        $violations = [];

        $person = PersonInput::toPerson(self::TENANT, null, $this->valid(), $violations);

        self::assertNotNull($person);
        self::assertSame([], $violations);
        self::assertSame('ada@example.test', $person->email);
        self::assertSame('Ada Lovelace', $person->fullName());
    }

    #[Test]
    public function an_address_is_lower_cased_and_trimmed(): void
    {
        // Otherwise "Ada@Example.test" and "ada@example.test" are two accounts
        // and the unique index does not stop it.
        $violations = [];

        $person = PersonInput::toPerson(
            self::TENANT,
            null,
            [...$this->valid(), 'email' => '  Ada@Example.TEST '],
            $violations,
        );

        self::assertSame('ada@example.test', $person?->email);
    }

    #[Test]
    public function a_record_without_an_address_is_refused(): void
    {
        $violations = [];

        self::assertNull(PersonInput::toPerson(
            self::TENANT,
            null,
            [...$this->valid(), 'email' => '   '],
            $violations,
        ));

        self::assertArrayHasKey('email', $violations);
    }

    #[Test]
    public function something_that_is_not_an_address_is_refused(): void
    {
        $violations = [];

        PersonInput::toPerson(self::TENANT, null, [...$this->valid(), 'email' => 'ada'], $violations);

        self::assertArrayHasKey('email', $violations);
    }

    #[Test]
    public function a_nameless_record_is_refused(): void
    {
        // A list of blank rows is not a people screen.
        $violations = [];

        PersonInput::toPerson(
            self::TENANT,
            null,
            [...$this->valid(), 'firstName' => '', 'lastName' => ''],
            $violations,
        );

        self::assertArrayHasKey('firstName', $violations);
        self::assertArrayHasKey('lastName', $violations);
    }

    #[Test]
    public function an_account_with_no_role_is_refused(): void
    {
        // It can sign in and see nothing, which reads as the application
        // being broken rather than as a half-finished record.
        $violations = [];

        PersonInput::toPerson(self::TENANT, null, [...$this->valid(), 'roleIds' => []], $violations);

        self::assertArrayHasKey('roleIds', $violations);
    }

    #[Test]
    public function a_new_record_starts_invited(): void
    {
        $violations = [];

        $person = PersonInput::toPerson(self::TENANT, null, $this->valid(), $violations);

        self::assertSame(UserStatus::Invited, $person?->status);
    }

    #[Test]
    public function an_unknown_status_is_refused(): void
    {
        $violations = [];

        PersonInput::toPerson(self::TENANT, null, [...$this->valid(), 'status' => 'onboarding'], $violations);

        self::assertArrayHasKey('status', $violations);
    }

    #[Test]
    public function an_unparseable_date_is_refused(): void
    {
        $violations = [];

        PersonInput::toPerson(self::TENANT, null, [...$this->valid(), 'hireDate' => 'soon'], $violations);

        self::assertArrayHasKey('hireDate', $violations);
    }

    #[Test]
    public function probation_cannot_end_before_the_start_date(): void
    {
        // A typo here drives a report somebody acts on.
        $violations = [];

        PersonInput::toPerson(self::TENANT, null, [
            ...$this->valid(),
            'hireDate'         => '2026-06-01',
            'probationEndDate' => '2026-01-01',
        ], $violations);

        self::assertArrayHasKey('probationEndDate', $violations);
    }

    #[Test]
    public function nobody_reports_to_themselves(): void
    {
        $violations = [];

        PersonInput::toPerson(
            self::TENANT,
            self::PERSON,
            [...$this->valid(), 'managerId' => self::PERSON],
            $violations,
        );

        self::assertArrayHasKey('managerId', $violations);
    }

    #[Test]
    public function nobody_is_their_own_line_executive(): void
    {
        $violations = [];

        PersonInput::toPerson(
            self::TENANT,
            self::PERSON,
            [...$this->valid(), 'lineExecutiveId' => self::PERSON],
            $violations,
        );

        self::assertArrayHasKey('lineExecutiveId', $violations);
    }

    #[Test]
    public function a_gender_outside_the_set_is_refused(): void
    {
        // The column is free text, but the common case is validated so a typo
        // ('mael') does not reach the record.
        $violations = [];

        PersonInput::toPerson(self::TENANT, null, [...$this->valid(), 'gender' => 'mael'], $violations);

        self::assertArrayHasKey('gender', $violations);
    }

    #[Test]
    public function a_known_gender_is_lower_cased_and_kept(): void
    {
        $violations = [];

        $person = PersonInput::toPerson(
            self::TENANT,
            null,
            [...$this->valid(), 'gender' => 'Female', 'employeeId' => 'EMP-0042'],
            $violations,
        );

        self::assertSame([], $violations);
        self::assertNotNull($person);
        self::assertSame('female', $person->gender);
        self::assertSame('EMP-0042', $person->employeeId);
    }

    #[Test]
    public function a_locale_is_always_set(): void
    {
        // The column is NOT NULL with a default; passing an explicit null
        // overrides the default and the insert fails.
        $violations = [];

        $person = PersonInput::toPerson(self::TENANT, null, $this->valid(), $violations);

        self::assertSame('en', $person?->locale);
    }

    // --- partial updates ---------------------------------------------------

    #[Test]
    public function an_update_keeps_what_it_does_not_mention(): void
    {
        // A form that sends three fields must not blank the other seventeen.
        $violations = [];
        $existing   = $this->existing();

        $person = PersonInput::toPerson(
            self::TENANT,
            self::PERSON,
            ['phone' => '+2348012345678'],
            $violations,
            $existing,
        );

        self::assertNotNull($person);
        self::assertSame('+2348012345678', $person->phone);
        self::assertSame('Ada', $person->firstName);
        self::assertSame('ada@example.test', $person->email);
        self::assertSame([self::ROLE], $person->roleIds);
        self::assertSame(UserStatus::Active, $person->status);
        self::assertSame(self::DEPARTMENT, $person->departmentId);
    }

    #[Test]
    public function an_update_can_clear_an_optional_field(): void
    {
        // Sending an empty string is how a form says "remove this", and it has
        // to be distinguishable from not sending the field at all.
        $violations = [];

        $person = PersonInput::toPerson(
            self::TENANT,
            self::PERSON,
            ['phone' => ''],
            $violations,
            $this->existing(),
        );

        self::assertNull($person?->phone);
    }

    // --- passwords ---------------------------------------------------------

    #[Test]
    public function a_short_password_is_refused(): void
    {
        // `create.php` hashed whatever it was given, including the empty
        // string.
        $violations = [];

        PersonInput::checkPassword('short', $violations);

        self::assertArrayHasKey('password', $violations);
    }

    #[Test]
    public function a_password_of_only_letters_is_refused(): void
    {
        $violations = [];

        PersonInput::checkPassword('correcthorsebattery', $violations);

        self::assertArrayHasKey('password', $violations);
    }

    #[Test]
    public function a_long_passphrase_is_accepted(): void
    {
        // Length does more for strength than punctuation rules, and a
        // passphrase is easier to remember.
        $violations = [];

        PersonInput::checkPassword('correct horse battery 9', $violations);

        self::assertSame([], $violations);
    }

    #[Test]
    public function an_unbounded_password_is_refused(): void
    {
        // Hashing an arbitrarily long input is a way to burn CPU on request.
        $violations = [];

        PersonInput::checkPassword(str_repeat('a1', 4096), $violations);

        self::assertArrayHasKey('password', $violations);
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'email'     => 'ada@example.test',
            'firstName' => 'Ada',
            'lastName'  => 'Lovelace',
            'roleIds'   => [self::ROLE],
        ];
    }

    private function existing(): Person
    {
        return new Person(
            id: self::PERSON,
            tenantId: self::TENANT,
            email: 'ada@example.test',
            firstName: 'Ada',
            lastName: 'Lovelace',
            status: UserStatus::Active,
            phone: '+2340000000000',
            departmentId: self::DEPARTMENT,
            roleIds: [self::ROLE],
        );
    }
}
