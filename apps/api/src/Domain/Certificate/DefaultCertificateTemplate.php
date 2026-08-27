<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

use Ramsey\Uuid\Uuid;

/**
 * The template a tenant starts with.
 *
 * Without one, `findDefaultTemplate()` returns null and rendering a
 * certificate raises `template_missing` — so a fresh organisation could issue
 * certificates that nobody, the holder included, was ever able to open. The
 * download button on the learner's dashboard was the visible half of that;
 * the other half was that the register happily issued the things anyway.
 *
 * Plain, deliberately. It is meant to be replaced by whatever an organisation
 * wants on its own paper, and something legible with the right words on it is
 * a better starting point than something ornate that has to be undone first.
 */
final readonly class DefaultCertificateTemplate
{
    public const NAME = 'Standard certificate';

    public static function for(string $tenantId): CertificateTemplate
    {
        return new CertificateTemplate(
            id: Uuid::uuid4()->toString(),
            tenantId: $tenantId,
            name: self::NAME,
            bodyHtml: self::body(),
            description: 'Created with the organisation. Edit it, or add your own and make that the default.',
            stylesCss: self::styles(),
            orientation: 'landscape',
            pageSize: 'A4',
            isDefault: true,
            isActive: true,
        );
    }

    /**
     * Every placeholder here is one {@see CertificateRenderer::PLACEHOLDERS}
     * knows. An unknown one is refused at save time rather than printed
     * literally on somebody's certificate.
     */
    private static function body(): string
    {
        return <<<'HTML'
            <div class="certificate">
              <header class="certificate__head">
                <p class="certificate__org">{{organisation}}</p>
                <h1 class="certificate__kind">Certificate of Completion</h1>
              </header>

              <section class="certificate__body">
                <p class="certificate__lead">This is to certify that</p>
                <p class="certificate__name">{{learner_name}}</p>
                <p class="certificate__lead">has successfully completed</p>
                <p class="certificate__title">{{title}}</p>
                <p class="certificate__grade">{{grade}}</p>
              </section>

              <footer class="certificate__foot">
                <div class="certificate__field">
                  <span class="certificate__value">{{issue_date}}</span>
                  <span class="certificate__label">Date of issue</span>
                </div>
                <div class="certificate__field">
                  <span class="certificate__value">{{certificate_number}}</span>
                  <span class="certificate__label">Certificate number</span>
                </div>
                <div class="certificate__field">
                  <span class="certificate__value">{{valid_until}}</span>
                  <span class="certificate__label">Valid until</span>
                </div>
              </footer>

              <p class="certificate__verify">
                Verify at {{verification_url}} with code {{verification_code}}
              </p>
            </div>
            HTML;
    }

    /**
     * Sized in millimetres against A4 landscape, so the page the print
     * dialogue shows is the page the design was drawn on.
     */
    private static function styles(): string
    {
        return <<<'CSS'
            .certificate {
              box-sizing: border-box;
              width: 297mm;
              height: 210mm;
              padding: 18mm 22mm;
              display: flex;
              flex-direction: column;
              justify-content: space-between;
              font-family: Georgia, "Times New Roman", serif;
              color: #1c1c1c;
              background: #fff;
              border: 2mm solid #303234;
              outline: 0.4mm solid #303234;
              outline-offset: -4mm;
            }
            .certificate__head { text-align: center; }
            .certificate__org {
              margin: 0 0 4mm;
              font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
              font-size: 11pt;
              letter-spacing: .28em;
              text-transform: uppercase;
              color: #5a5a5a;
            }
            .certificate__kind { margin: 0; font-size: 30pt; font-weight: 400; letter-spacing: .04em; }
            .certificate__body { text-align: center; }
            .certificate__lead { margin: 0; font-size: 12pt; font-style: italic; color: #5a5a5a; }
            .certificate__name {
              margin: 4mm 0 6mm;
              font-size: 34pt;
              line-height: 1.1;
              border-bottom: 0.4mm solid #303234;
              display: inline-block;
              padding: 0 12mm 3mm;
            }
            .certificate__title { margin: 4mm 0 0; font-size: 19pt; font-weight: 700; }
            /* Empty when the source carries no grade, and then it takes no room. */
            .certificate__grade:empty { display: none; }
            .certificate__grade { margin: 3mm 0 0; font-size: 12pt; color: #5a5a5a; }
            .certificate__foot {
              display: flex;
              justify-content: space-between;
              gap: 12mm;
              font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            }
            .certificate__field { text-align: center; flex: 1; }
            /* A lifetime certificate has no expiry, and a bare "Valid until"
               label over an empty rule reads as a missing value rather than
               an absent one. The column keeps its width so the other two
               stay where they were. */
            .certificate__field:has(.certificate__value:empty) { visibility: hidden; }
            .certificate__value {
              display: block;
              font-size: 11pt;
              padding-bottom: 2mm;
              border-bottom: 0.3mm solid #9a9a9a;
              min-height: 6mm;
            }
            .certificate__label {
              display: block;
              margin-top: 2mm;
              font-size: 8pt;
              letter-spacing: .14em;
              text-transform: uppercase;
              color: #5a5a5a;
            }
            .certificate__verify {
              margin: 6mm 0 0;
              text-align: center;
              font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
              font-size: 7.5pt;
              color: #7a7a7a;
              word-break: break-all;
            }
            CSS;
    }
}
