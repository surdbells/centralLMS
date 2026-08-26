<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Certificate;

use Central\Api\Domain\Certificate\CertificateIdentity;
use Central\Api\Domain\Certificate\CertificateRenderer;
use Central\Api\Domain\Certificate\CertificateSource;
use Central\Api\Domain\Certificate\CertificateStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CertificateRenderer::class)]
#[CoversClass(CertificateIdentity::class)]
#[CoversClass(CertificateStatus::class)]
#[CoversClass(CertificateSource::class)]
final class CertificateRendererTest extends TestCase
{
    private CertificateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new CertificateRenderer();
    }

    // --- rendering --------------------------------------------------------

    #[Test]
    public function it_fills_the_placeholders(): void
    {
        $html = $this->renderer->render(
            '<h1>{{learner_name}}</h1><p>{{title}}</p><small>{{certificate_number}}</small>',
            ['learner_name' => 'Ada Lovelace', 'title' => 'Fire Safety', 'certificate_number' => 'ACME-2026-00001'],
        );

        self::assertSame('<h1>Ada Lovelace</h1><p>Fire Safety</p><small>ACME-2026-00001</small>', $html);
    }

    #[Test]
    public function a_name_containing_markup_is_escaped(): void
    {
        // A certificate is rendered and then shown to third parties verifying
        // it, so an unescaped name is stored XSS aimed at exactly the people
        // most inclined to trust the page.
        $html = $this->renderer->render(
            '<h1>{{learner_name}}</h1>',
            ['learner_name' => '<script>alert(1)</script>'],
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function quotes_and_ampersands_survive_intact(): void
    {
        $html = $this->renderer->render(
            '<p title="{{title}}">{{learner_name}}</p>',
            ['title' => 'Health & Safety "Advanced"', 'learner_name' => "O'Brien"],
        );

        self::assertStringContainsString('Health &amp; Safety &quot;Advanced&quot;', $html);
        self::assertStringContainsString('O&#039;Brien', $html);
    }

    #[Test]
    public function the_templates_own_markup_is_left_alone(): void
    {
        // The template is the author's HTML; only the values poured in are
        // treated as data.
        $html = $this->renderer->render(
            '<div class="cert"><img src="logo.png"><h1>{{learner_name}}</h1></div>',
            ['learner_name' => 'Ada'],
        );

        self::assertStringContainsString('<img src="logo.png">', $html);
        self::assertStringContainsString('<h1>Ada</h1>', $html);
    }

    #[Test]
    public function an_absent_value_leaves_nothing_rather_than_a_placeholder(): void
    {
        // Printing `{{grade}}` or the word "null" on a certificate is worse
        // than printing nothing at all.
        $html = $this->renderer->render('<p>Grade: {{grade}}</p>', ['learner_name' => 'Ada']);

        self::assertSame('<p>Grade: </p>', $html);
    }

    #[Test]
    public function custom_fields_are_namespaced_and_escaped(): void
    {
        $html = $this->renderer->render(
            '<p>{{custom.department}}</p>',
            ['custom' => ['department' => 'R&D <Ops>']],
        );

        self::assertSame('<p>R&amp;D &lt;Ops&gt;</p>', $html);
    }

    #[Test]
    public function a_custom_field_cannot_shadow_a_built_in_one(): void
    {
        // Otherwise a tenant could quietly rewrite the certificate number.
        $html = $this->renderer->render(
            '<p>{{certificate_number}}</p>',
            [
                'certificate_number' => 'REAL-001',
                'custom' => ['certificate_number' => 'FAKE-999'],
            ],
        );

        self::assertSame('<p>REAL-001</p>', $html);
    }

    #[Test]
    public function non_scalar_custom_values_are_skipped_rather_than_printed(): void
    {
        $html = $this->renderer->render(
            '<p>{{custom.thing}}</p>',
            ['custom' => ['thing' => ['nested' => 'array']]],
        );

        self::assertSame('<p>{{custom.thing}}</p>', $html);
    }

    // --- template validation ----------------------------------------------

    #[Test]
    public function a_typo_in_a_placeholder_is_reported(): void
    {
        // The author should hear about it when they save, not from whoever
        // receives the certificate.
        self::assertSame(
            ['leaner_name'],
            $this->renderer->unknownPlaceholders('<h1>{{leaner_name}}</h1><p>{{title}}</p>'),
        );
    }

    #[Test]
    public function a_correct_template_reports_nothing(): void
    {
        self::assertSame(
            [],
            $this->renderer->unknownPlaceholders('{{learner_name}} {{title}} {{issue_date}} {{verification_url}}'),
        );
    }

    #[Test]
    public function custom_placeholders_are_the_tenants_own_business(): void
    {
        self::assertSame([], $this->renderer->unknownPlaceholders('{{custom.anything_at_all}}'));
    }

    #[Test]
    public function whitespace_and_case_inside_a_placeholder_are_tolerated(): void
    {
        self::assertSame([], $this->renderer->unknownPlaceholders('{{ Learner_Name }}'));
    }

    // --- identity ---------------------------------------------------------

    #[Test]
    public function a_number_is_readable_and_carries_the_tenant_and_year(): void
    {
        $identity = CertificateIdentity::mint('acme-corp', 2026, 7);

        self::assertSame('ACMECO-2026-00007', $identity->number);
    }

    #[Test]
    public function a_tenant_code_with_no_usable_characters_still_yields_a_number(): void
    {
        self::assertStringStartsWith('CERT-2026-', CertificateIdentity::mint('---', 2026, 1)->number);
    }

    #[Test]
    public function the_verification_hash_is_not_derived_from_the_number(): void
    {
        // Hashing something guessable produces something guessable, and the
        // hash is what a verification URL carries.
        $a = CertificateIdentity::mint('acme', 2026, 1);
        $b = CertificateIdentity::mint('acme', 2026, 1);

        self::assertSame($a->number, $b->number);
        self::assertNotSame($a->hash, $b->hash);
        self::assertNotSame($a->code, $b->code);
    }

    #[Test]
    public function the_hash_is_long_enough_not_to_be_guessed(): void
    {
        self::assertSame(64, strlen(CertificateIdentity::mint('acme', 2026, 1)->hash));
    }

    #[Test]
    public function a_code_avoids_characters_that_are_misread_aloud(): void
    {
        // Quoted over the phone often enough that O/0 and I/1 are a real
        // support cost.
        for ($i = 0; $i < 50; $i++) {
            $code = CertificateIdentity::mint('acme', 2026, 1)->code;

            self::assertSame(10, strlen($code));
            self::assertDoesNotMatchRegularExpression('/[O0I1S5Z2]/', $code);
        }
    }

    #[Test]
    public function a_plausible_code_is_recognised_and_rubbish_is_not(): void
    {
        $code = CertificateIdentity::mint('acme', 2026, 1)->code;

        self::assertTrue(CertificateIdentity::looksLikeCode($code));
        self::assertTrue(CertificateIdentity::looksLikeCode(strtolower($code)));
        self::assertFalse(CertificateIdentity::looksLikeCode(''));
        self::assertFalse(CertificateIdentity::looksLikeCode('ABC-123'));
        self::assertFalse(CertificateIdentity::looksLikeCode(str_repeat('A', 21)));
        // Contains the excluded characters, so it was never one of ours.
        self::assertFalse(CertificateIdentity::looksLikeCode('OOOOIIII11'));
    }

    #[Test]
    public function a_hash_is_recognised_only_in_the_right_shape(): void
    {
        self::assertTrue(CertificateIdentity::looksLikeHash(str_repeat('a1', 32)));
        self::assertFalse(CertificateIdentity::looksLikeHash(str_repeat('a1', 31)));
        self::assertFalse(CertificateIdentity::looksLikeHash(strtoupper(str_repeat('a1', 32))));
        self::assertFalse(CertificateIdentity::looksLikeHash('not a hash'));
    }

    // --- status and source ------------------------------------------------

    #[Test]
    public function only_an_active_certificate_verifies(): void
    {
        self::assertTrue(CertificateStatus::Active->isValid());
        self::assertFalse(CertificateStatus::Revoked->isValid());
        self::assertFalse(CertificateStatus::Expired->isValid());
        // Superseded is deliberately invalid: the holder has a newer one, and
        // allowing this would let them present whichever scored better.
        self::assertFalse(CertificateStatus::Superseded->isValid());
    }

    #[Test]
    public function each_status_tells_a_verifier_something_different(): void
    {
        $messages = array_map(
            static fn (CertificateStatus $s): string => $s->verificationMessage(),
            CertificateStatus::cases(),
        );

        // Collapsing these into "not valid" would leave an employer unable to
        // tell a forgery from a lapsed renewal.
        self::assertSame($messages, array_unique($messages));
    }

    #[Test]
    public function an_attendance_certificate_carries_no_score(): void
    {
        self::assertTrue(CertificateSource::Course->carriesScore());
        self::assertTrue(CertificateSource::Assessment->carriesScore());
        self::assertFalse(CertificateSource::Training->carriesScore());
    }
}
