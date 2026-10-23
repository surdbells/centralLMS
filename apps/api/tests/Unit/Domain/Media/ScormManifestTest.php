<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Media;

use Central\Api\Domain\Media\ScormManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScormManifest::class)]
final class ScormManifestTest extends TestCase
{
    #[Test]
    public function reads_a_scorm_1_2_manifest(): void
    {
        $manifest = ScormManifest::read(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <manifest identifier="M1" version="1.2"
              xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
              xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2">
              <metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>
              <organizations default="ORG1">
                <organization identifier="ORG1">
                  <item identifier="ITEM1" identifierref="RES1"><title>Module 1</title></item>
                </organization>
              </organizations>
              <resources>
                <resource identifier="RES1" type="webcontent" adlcp:scormtype="sco" href="content/index.html"/>
              </resources>
            </manifest>
            XML);

        self::assertSame('content/index.html', $manifest->launchUrl);
        self::assertSame('1.2', $manifest->version);
    }

    #[Test]
    public function reads_a_scorm_2004_manifest(): void
    {
        $manifest = ScormManifest::read(<<<'XML'
            <?xml version="1.0"?>
            <manifest identifier="M2"
              xmlns="http://www.imsglobal.org/xsd/imscp_v1p1"
              xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_v1p3">
              <metadata><schema>ADL SCORM</schema><schemaversion>2004 3rd Edition</schemaversion></metadata>
              <organizations default="O">
                <organization identifier="O"><item identifier="I" identifierref="R"/></organization>
              </organizations>
              <resources><resource identifier="R" href="story.html"/></resources>
            </manifest>
            XML);

        self::assertSame('story.html', $manifest->launchUrl);
        self::assertSame('2004', $manifest->version);
    }

    #[Test]
    public function the_adlcp_namespace_pins_2004_when_schemaversion_is_absent(): void
    {
        $manifest = ScormManifest::read(<<<'XML'
            <?xml version="1.0"?>
            <manifest identifier="M" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_v1p3">
              <organizations default="O"><organization identifier="O"><item identifierref="R"/></organization></organizations>
              <resources><resource identifier="R" href="index.html"/></resources>
            </manifest>
            XML);

        self::assertSame('2004', $manifest->version);
    }

    #[Test]
    public function falls_back_to_the_first_resource_with_an_href(): void
    {
        // No item references a resource, so the first resource that actually
        // declares an href wins — not the one before it with none.
        $manifest = ScormManifest::read(<<<'XML'
            <?xml version="1.0"?>
            <manifest xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2">
              <organizations><organization identifier="O"><item identifier="I"/></organization></organizations>
              <resources>
                <resource identifier="A" type="webcontent"/>
                <resource identifier="B" type="webcontent" href="launch.htm"/>
              </resources>
            </manifest>
            XML);

        self::assertSame('launch.htm', $manifest->launchUrl);
    }

    #[Test]
    public function a_leading_slash_on_the_href_is_trimmed(): void
    {
        $manifest = ScormManifest::read(<<<'XML'
            <?xml version="1.0"?>
            <manifest>
              <organizations default="O"><organization identifier="O"><item identifierref="R"/></organization></organizations>
              <resources><resource identifier="R" href="/index.html"/></resources>
            </manifest>
            XML);

        self::assertSame('index.html', $manifest->launchUrl);
    }

    #[Test]
    public function a_manifest_with_no_launchable_resource_is_refused(): void
    {
        // A package that names nothing to launch cannot be played, so it is
        // refused rather than guessed at.
        $this->expectException(\RuntimeException::class);

        ScormManifest::read(<<<'XML'
            <?xml version="1.0"?>
            <manifest>
              <organizations><organization identifier="O"><item identifier="I"/></organization></organizations>
              <resources><resource identifier="R" type="webcontent"/></resources>
            </manifest>
            XML);
    }
}
