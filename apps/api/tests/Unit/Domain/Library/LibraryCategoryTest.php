<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Library;

use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Library\DigitalLibraryItem;
use Central\Api\Domain\Library\DigitalLibraryRepository;
use Central\Api\Domain\Library\LibraryCategory;
use Central\Api\Domain\Library\LibraryCategoryFilter;
use Central\Api\Domain\Media\MediaKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LibraryCategory::class)]
#[CoversClass(LibraryCategoryFilter::class)]
#[CoversClass(DigitalLibraryItem::class)]
final class LibraryCategoryTest extends TestCase
{
    private const TENANT = 'tenant-1';
    private const ID = '3f2a1b4c-5d6e-4f70-8a9b-0c1d2e3f4a5b';

    // --- category input -------------------------------------------------------

    #[Test]
    public function it_builds_a_trimmed_category(): void
    {
        $category = LibraryCategory::fromInput(self::TENANT, [
            'name'        => '  Safety videos  ',
            'description' => '  For site staff ',
            'sortOrder'   => '3',
        ]);

        self::assertNull($category->id);
        self::assertSame('Safety videos', $category->name);
        self::assertSame('For site staff', $category->description);
        self::assertSame(3, $category->sortOrder);
        self::assertSame(0, $category->itemCount);
    }

    #[Test]
    public function a_name_is_required_on_create(): void
    {
        foreach ([[], ['name' => ''], ['name' => '   '], ['name' => null], ['name' => 42]] as $body) {
            self::assertViolation(static fn () => LibraryCategory::fromInput(self::TENANT, $body), 'name');
        }
    }

    #[Test]
    public function an_overlong_name_is_refused(): void
    {
        self::assertViolation(
            static fn () => LibraryCategory::fromInput(self::TENANT, ['name' => str_repeat('a', LibraryCategory::NAME_MAX + 1)]),
            'name',
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badPositions(): iterable
    {
        yield 'words'    => ['first'];
        yield 'fraction' => [1.5];
        yield 'decimal'  => ['2.5'];
        yield 'huge'     => ['99999999999'];
        yield 'array'    => [[1]];
    }

    #[Test]
    #[DataProvider('badPositions')]
    public function a_position_must_be_a_whole_number(mixed $sortOrder): void
    {
        self::assertViolation(
            static fn () => LibraryCategory::fromInput(self::TENANT, ['name' => 'Policies', 'sortOrder' => $sortOrder]),
            'sortOrder',
        );
    }

    #[Test]
    public function an_update_keeps_what_the_body_leaves_out(): void
    {
        $existing = new LibraryCategory(self::ID, self::TENANT, 'Policies', 'HR policies', 2, 7);

        $renamed = LibraryCategory::fromInput(self::TENANT, ['name' => 'HR Policies'], $existing);
        self::assertSame(self::ID, $renamed->id);
        self::assertSame('HR Policies', $renamed->name);
        self::assertSame('HR policies', $renamed->description);
        self::assertSame(2, $renamed->sortOrder);

        $moved = LibraryCategory::fromInput(self::TENANT, ['sortOrder' => -1, 'description' => ''], $existing);
        self::assertSame('Policies', $moved->name);
        self::assertNull($moved->description, 'An empty description clears it.');
        self::assertSame(-1, $moved->sortOrder);
    }

    #[Test]
    public function an_update_cannot_blank_the_name(): void
    {
        $existing = new LibraryCategory(self::ID, self::TENANT, 'Policies');

        self::assertViolation(
            static fn () => LibraryCategory::fromInput(self::TENANT, ['name' => '  '], $existing),
            'name',
        );
    }

    #[Test]
    public function it_serialises_in_camel_case(): void
    {
        self::assertSame(
            ['id' => self::ID, 'name' => 'Policies', 'description' => null, 'sortOrder' => 1, 'itemCount' => 4],
            (new LibraryCategory(self::ID, self::TENANT, 'Policies', null, 1, 4))->toArray(),
        );
    }

    // --- category ids off a request -------------------------------------------

    #[Test]
    public function the_list_filter_reads_none_a_uuid_or_nothing(): void
    {
        self::assertNull(LibraryCategoryFilter::fromQuery(null));
        self::assertNull(LibraryCategoryFilter::fromQuery('  '));
        self::assertSame(DigitalLibraryRepository::UNCATEGORISED, LibraryCategoryFilter::fromQuery('none'));
        self::assertSame(DigitalLibraryRepository::UNCATEGORISED, LibraryCategoryFilter::fromQuery('NONE'));
        self::assertSame(self::ID, LibraryCategoryFilter::fromQuery(strtoupper(self::ID)));
    }

    #[Test]
    public function the_list_filter_refuses_anything_else(): void
    {
        foreach (['abc', "1' OR 1=1", '3f2a1b4c', ['x']] as $value) {
            self::assertViolation(static fn () => LibraryCategoryFilter::fromQuery($value), 'categoryId');
        }
    }

    #[Test]
    public function an_empty_body_category_means_uncategorised(): void
    {
        foreach ([null, '', '  ', 'null', 'none'] as $value) {
            self::assertNull(LibraryCategoryFilter::fromBody($value));
        }
        self::assertSame(self::ID, LibraryCategoryFilter::fromBody(' ' . self::ID . ' '));
    }

    #[Test]
    public function a_malformed_body_category_is_refused(): void
    {
        foreach (['not-a-uuid', 12, ['id' => self::ID]] as $value) {
            self::assertViolation(static fn () => LibraryCategoryFilter::fromBody($value), 'categoryId');
        }
    }

    // --- the item carries its category ----------------------------------------

    #[Test]
    public function an_item_reports_its_category(): void
    {
        $item = new DigitalLibraryItem(
            id: 'item-1',
            tenantId: self::TENANT,
            kind: MediaKind::Pdf,
            title: 'Handbook',
            originalFilename: 'handbook.pdf',
            storageKey: 'k',
            fileSize: 10,
            mimeType: 'application/pdf',
            extension: 'pdf',
            targetType: AudienceTarget::All,
            categoryId: self::ID,
            categoryName: 'Policies',
        );

        $array = $item->toArray();
        self::assertSame(self::ID, $array['categoryId']);
        self::assertSame('Policies', $array['categoryName']);

        $loose = new DigitalLibraryItem(
            id: 'item-2',
            tenantId: self::TENANT,
            kind: MediaKind::Pdf,
            title: 'Loose',
            originalFilename: 'loose.pdf',
            storageKey: 'k2',
            fileSize: 10,
            mimeType: 'application/pdf',
            extension: 'pdf',
            targetType: AudienceTarget::All,
        );
        self::assertArrayHasKey('categoryId', $loose->toArray());
        self::assertNull($loose->toArray()['categoryId']);
        self::assertNull($loose->toArray()['categoryName']);
    }

    private static function assertViolation(callable $build, string $field): void
    {
        try {
            $build();
            self::fail(sprintf('Expected a violation on "%s".', $field));
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey($field, $e->details()['violations']);
        }
    }
}
