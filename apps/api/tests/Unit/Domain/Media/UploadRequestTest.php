<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Media;

use Central\Api\Domain\Media\MediaKind;
use Central\Api\Domain\Media\UploadRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What may be uploaded, decided before a byte is written.
 *
 * The legacy checked the `Content-Type` the browser sent, which the browser is
 * told by whatever is doing the sending. Everything here works from the
 * extension instead.
 */
#[CoversClass(UploadRequest::class)]
#[CoversClass(MediaKind::class)]
final class UploadRequestTest extends TestCase
{
    #[Test]
    public function an_ordinary_upload_is_accepted_and_classified(): void
    {
        $violations = [];

        $request = UploadRequest::check('induction.mp4', 5_000_000, 'video/mp4', $violations);

        self::assertSame([], $violations);
        self::assertNotNull($request);
        self::assertSame(MediaKind::Video, $request->kind);
        self::assertSame('mp4', $request->extension);
        // No title given, so the filename stands in.
        self::assertSame('induction', $request->title);
    }

    #[Test]
    public function the_extension_decides_the_kind_not_the_declared_mime_type(): void
    {
        // A client that says a .exe is a video is not mistaken.
        $violations = [];

        self::assertNull(UploadRequest::check('payload.exe', 1000, 'video/mp4', $violations));
        self::assertArrayHasKey('file', $violations);
    }

    #[Test]
    public function a_double_extension_is_read_as_its_last_one(): void
    {
        // `report.pdf.exe` is an exe. Reading the first extension is how a
        // file that looks harmless in a listing runs when it is opened.
        $violations = [];

        self::assertNull(UploadRequest::check('report.pdf.exe', 1000, 'application/pdf', $violations));
        self::assertSame(['file' => '.exe files are not accepted.'], $violations);
    }

    #[Test]
    public function a_filename_carrying_a_path_keeps_only_its_last_segment(): void
    {
        $violations = [];

        $request = UploadRequest::check('../../etc/passwd.pdf', 1000, 'application/pdf', $violations);

        self::assertSame('passwd.pdf', $request?->originalFilename);
    }

    #[Test]
    public function windows_separators_are_stripped_too(): void
    {
        // A browser on Windows sends backslashes, and basename() alone does
        // not treat them as separators on a Linux server.
        $violations = [];

        $request = UploadRequest::check('C:\\Users\\bob\\policy.pdf', 1000, 'application/pdf', $violations);

        self::assertSame('policy.pdf', $request?->originalFilename);
    }

    #[Test]
    public function the_storage_key_is_built_from_nothing_the_caller_sent(): void
    {
        // Not from the filename: a key derived from it is a key another
        // tenant can guess, and a crafted one escapes the directory.
        $violations = [];

        $request = UploadRequest::check('../../secret.pdf', 1000, 'application/pdf', $violations);
        $key     = (string) $request?->storageKey('tenant-1', 'abc123');

        self::assertSame('tenant-1/pdf/abc123.pdf', $key);
        self::assertStringNotContainsString('..', $key);
        self::assertStringNotContainsString('secret', $key);
    }

    #[Test]
    public function a_file_with_no_extension_is_refused(): void
    {
        $violations = [];

        self::assertNull(UploadRequest::check('README', 1000, 'text/plain', $violations));
        self::assertArrayHasKey('file', $violations);
    }

    #[Test]
    public function an_empty_upload_is_refused(): void
    {
        $violations = [];

        self::assertNull(UploadRequest::check('empty.pdf', 0, 'application/pdf', $violations));
        self::assertSame(['file' => 'The file is empty.'], $violations);
    }

    #[Test]
    public function each_kind_has_its_own_size_limit(): void
    {
        // A 60 MB video is unremarkable; a 60 MB avatar is somebody uploading
        // a video as their avatar.
        $violations = [];
        self::assertNotNull(UploadRequest::check('talk.mp4', 60_000_000, 'video/mp4', $violations));

        $violations = [];
        self::assertNull(UploadRequest::check('avatar.png', 60_000_000, 'image/png', $violations));
        self::assertSame('Image files are limited to 10 MB.', $violations['file']);
    }

    #[Test]
    public function a_declared_kind_that_contradicts_the_extension_is_refused(): void
    {
        $violations = [];

        self::assertNull(
            UploadRequest::check('clip.mp4', 1000, 'video/mp4', $violations, null, 'pdf'),
        );
        self::assertSame('A .mp4 file is Video, not PDF.', $violations['kind']);
    }

    #[Test]
    public function a_zip_declared_as_scorm_is_taken_at_its_word(): void
    {
        // The one case where the caller knows more than the extension: a SCORM
        // package and a plain archive are both .zip, and only the uploader can
        // say which this is.
        $violations = [];

        $request = UploadRequest::check('course.zip', 1000, 'application/zip', $violations, null, 'scorm');

        self::assertSame([], $violations);
        self::assertSame(MediaKind::Scorm, $request?->kind);
    }

    #[Test]
    public function an_unknown_declared_kind_is_refused_rather_than_ignored(): void
    {
        $violations = [];

        self::assertNull(
            UploadRequest::check('notes.pdf', 1000, 'application/pdf', $violations, null, 'hologram'),
        );
        self::assertArrayHasKey('kind', $violations);
    }

    #[Test]
    public function an_upload_with_no_mime_type_still_gets_one(): void
    {
        // Some clients send none. A NOT NULL column and a Content-Type header
        // both need something.
        $violations = [];

        $request = UploadRequest::check('notes.pdf', 1000, '', $violations);

        self::assertSame('application/octet-stream', $request?->mimeType);
    }

    #[Test]
    public function an_extension_is_matched_whatever_its_case(): void
    {
        self::assertSame(MediaKind::Image, MediaKind::forExtension('PNG'));
        self::assertSame(MediaKind::Image, MediaKind::forExtension('.Png'));
        self::assertNull(MediaKind::forExtension('psd'));
    }

    #[Test]
    public function no_extension_belongs_to_two_kinds(): void
    {
        // forExtension() returns the first match, so an extension listed twice
        // would resolve differently depending on the order of the enum.
        $seen = [];

        foreach (MediaKind::cases() as $kind) {
            foreach ($kind->extensions() as $extension) {
                self::assertArrayNotHasKey($extension, $seen, $extension . ' is claimed by two kinds');
                $seen[$extension] = $kind;
            }
        }

        self::assertNotSame([], $seen);
    }

    #[Test]
    public function a_given_title_is_kept_and_a_blank_one_falls_back(): void
    {
        $violations = [];
        self::assertSame(
            'Fire safety',
            UploadRequest::check('f-s-2026-final-v3.pdf', 1000, 'application/pdf', $violations, 'Fire safety')?->title,
        );

        $violations = [];
        self::assertSame(
            'f-s-2026-final-v3',
            UploadRequest::check('f-s-2026-final-v3.pdf', 1000, 'application/pdf', $violations, '   ')?->title,
        );
    }
}
