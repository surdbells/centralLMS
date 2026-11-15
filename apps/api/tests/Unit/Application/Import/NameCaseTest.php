<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\NameCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameCase::class)]
final class NameCaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function names(): iterable
    {
        yield 'all caps'              => ['ADA LOVELACE', 'Ada', 'Lovelace'];
        yield 'hyphenated caps'       => ['BOLA ADE-OBI', 'Bola', 'Ade-Obi'];
        yield 'apostrophe caps'       => ["SEAN O'NEIL", 'Sean', "O'Neil"];
        yield 'curly apostrophe'      => ["SEAN O\u{2019}NEIL", 'Sean', "O\u{2019}Neil"];
        yield 'mixed case kept'       => ['Ada McTest', 'Ada', 'McTest'];
        yield 'lower-case particle'   => ['Kofi deGraft Test', 'Kofi', 'deGraft Test'];
        yield 'trailing initial'      => ['Ada Testing C.', 'Ada', 'Testing C.'];
        yield 'caps trailing initial' => ['ADA TESTING C.', 'Ada', 'Testing C.'];
        yield 'middle names'          => ['ADA BYRON KING TEST', 'Ada', 'Byron King Test'];
        yield 'spaces collapsed'      => ["  Ada \n  Test\u{00A0} ", 'Ada', 'Test'];
        yield 'one caps word only'    => ['Ada TEST', 'Ada', 'Test'];
        yield 'title dropped'         => ['Engr. ADA TEST', 'Ada', 'Test'];
        yield 'title kept if alone'   => ['Dr Test', 'Dr', 'Test'];
        yield 'surname first, comma'  => ['TEST, Ada', 'Ada', 'Test'];
        yield 'comma with middle'     => ['TEST, ADA BYRON', 'Ada', 'Byron Test'];
        yield 'comma with initial'    => ['Test, Ada C.', 'Ada', 'C. Test'];
        yield 'single name'           => ['MONONYM', 'Mononym', ''];
        yield 'empty'                 => ['   ', '', ''];
    }

    #[Test]
    #[DataProvider('names')]
    public function it_splits_and_recases(string $source, string $first, string $last): void
    {
        self::assertSame(['firstName' => $first, 'lastName' => $last], NameCase::split($source));
    }

    #[Test]
    public function a_comma_is_read_against_the_email_address(): void
    {
        // Register style: the address starts with the given name after the comma.
        self::assertSame(['firstName' => 'Ada', 'lastName' => 'Test'], NameCase::split('TEST, ADA', 'ada.test@example.test'));
        // The address starts with the part before the comma: kept in order.
        self::assertSame(['firstName' => 'Ada', 'lastName' => 'Test'], NameCase::split('ADA, TEST', 'ada.test@example.test'));
        // Nothing to go on: register style.
        self::assertSame(['firstName' => 'Ada', 'lastName' => 'Test'], NameCase::split('TEST, ADA', 'xyz@example.test'));
    }

    #[Test]
    public function capitals_are_put_in_the_addresss_given_surname_order(): void
    {
        // No comma, surname first.
        self::assertSame(['firstName' => 'Ada', 'lastName' => 'Test'], NameCase::split('TEST ADA', 'ada.test@example.test'));
        // Comma, but the given name is the second word after it.
        self::assertSame(['firstName' => 'Byron', 'lastName' => 'Ada Test'], NameCase::split('TEST, ADA BYRON', 'byron.test@example.test'));
        // A two-word given name the address joins with a hyphen.
        self::assertSame(['firstName' => 'Ada-Lee', 'lastName' => 'Test'], NameCase::split('TEST ADA LEE', 'ada-lee.test@example.test'));
        // A title is still dropped.
        self::assertSame(['firstName' => 'Ada', 'lastName' => 'Test'], NameCase::split('ENGR. TEST ADA', 'ada.test@example.test'));
        // Already in order, or nothing to go on: unchanged.
        self::assertSame(['firstName' => 'Ada', 'lastName' => 'Test'], NameCase::split('ADA TEST', 'ada.test@example.test'));
        self::assertSame(['firstName' => 'Test', 'lastName' => 'Ada'], NameCase::split('TEST ADA', 'atest@example.test'));
        // Mixed case is trusted as typed.
        self::assertSame(['firstName' => 'Test', 'lastName' => 'Ada'], NameCase::split('Test Ada', 'ada.test@example.test'));
    }

    #[Test]
    public function a_name_matches_an_address_that_names_one_of_its_words(): void
    {
        self::assertTrue(NameCase::matchesEmail('ADA TEST', 'ada.test@example.test'));
        self::assertTrue(NameCase::matchesEmail('Ada Test-New', 'ada.new@example.test'));
        self::assertFalse(NameCase::matchesEmail('Bola Obi', 'ada.test@example.test'));
        self::assertFalse(NameCase::matchesEmail(null, 'ada.test@example.test'));
        self::assertSame(2, NameCase::emailScore('Ada Test', 'ada.test@example.test'));
        self::assertSame(1, NameCase::emailScore('Bola Ada OBI', 'ada.test@example.test'));
    }

    #[Test]
    public function a_word_already_cased_is_never_touched(): void
    {
        self::assertSame('McTest', NameCase::word('McTest'));
        self::assertSame('Ade-Obi', NameCase::word('ADE-OBI'));
        self::assertSame('C.', NameCase::word('C.'));
        self::assertSame('Ada Byron', NameCase::proper('ADA   BYRON'));
    }
}
