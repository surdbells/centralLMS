<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Catalog;

use Central\Api\Domain\Catalog\LessonContentType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LessonContentType::class)]
final class LessonContentTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('legacySpellings')]
    public function legacy_spellings_fold_onto_one_case(string $input, LessonContentType $expected): void
    {
        self::assertSame($expected, LessonContentType::fromLegacy($input));
    }

    /**
     * @return iterable<string, array{string, LessonContentType}>
     */
    public static function legacySpellings(): iterable
    {
        yield 'canonical' => ['video', LessonContentType::Video];
        yield 'padded and mixed case' => ['  Video ', LessonContentType::Video];
        yield 'doc means pdf' => ['doc', LessonContentType::Pdf];
        yield 'document means pdf' => ['document', LessonContentType::Pdf];
        yield 'file means pdf' => ['file', LessonContentType::Pdf];
        yield 'youtube is a video' => ['youtube', LessonContentType::Video];
        yield 'vimeo is a video' => ['vimeo', LessonContentType::Video];
        yield 'mp4 is a video' => ['mp4', LessonContentType::Video];
        yield 'mp3 is audio' => ['mp3', LessonContentType::Audio];
        yield 'article is text' => ['article', LessonContentType::Text];
        yield 'richtext is html' => ['richtext', LessonContentType::Html];
        yield 'assessment is a quiz' => ['assessment', LessonContentType::Quiz];
    }

    #[Test]
    public function an_unknown_spelling_throws_rather_than_defaulting(): void
    {
        // Defaulting to text would render a video lesson as an empty page,
        // which looks like working software.
        $this->expectException(\ValueError::class);

        LessonContentType::fromLegacy('hologram');
    }

    #[Test]
    public function every_case_requires_exactly_one_kind_of_content_or_none(): void
    {
        foreach (LessonContentType::cases() as $type) {
            self::assertFalse(
                $type->requiresUrl() && $type->requiresText(),
                sprintf('%s cannot require both a URL and inline text.', $type->value),
            );

            // The only case allowed to require neither is the one that gets
            // its content from somewhere else entirely.
            if (!$type->requiresUrl() && !$type->requiresText()) {
                self::assertTrue(
                    $type->isExternallyOwned(),
                    sprintf('%s requires no content but is not externally owned.', $type->value),
                );
            }
        }
    }

    #[Test]
    public function acronyms_are_not_sentence_cased_in_labels(): void
    {
        self::assertSame('PDF', LessonContentType::Pdf->label());
        self::assertSame('HTML', LessonContentType::Html->label());
        self::assertSame('SCORM', LessonContentType::Scorm->label());
        self::assertSame('Video', LessonContentType::Video->label());
    }
}
