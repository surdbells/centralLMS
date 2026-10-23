<?php

declare(strict_types=1);

namespace Central\Api\Domain\Media;

/**
 * What a SCORM `imsmanifest.xml` says about how to play a package.
 *
 * Just the two things the player needs: the entry file to point an iframe at,
 * and which SCORM dialect the content speaks. The manifest holds a great deal
 * more — a full activity tree, sequencing rules, metadata — none of which this
 * runtime acts on, so none of which is read here.
 *
 * Namespace prefixes vary between authoring tools (`imscp_rootv1p1p2` vs
 * `imscp_v1p1`, `adlcp` bound to different URIs), so elements are matched by
 * local name rather than qualified name — the one reading that survives a
 * package built by a tool nobody here has seen.
 */
final readonly class ScormManifest
{
    private function __construct(
        /** The entry file, relative to the manifest. */
        public string $launchUrl,
        /** '1.2' or '2004'. */
        public string $version,
    ) {
    }

    /**
     * Reads a manifest, or throws if it names nothing playable.
     *
     * The launch file is the resource the default organisation's first item
     * references; failing that, the first resource that declares an `href` at
     * all. A manifest with no launchable resource is not a package that can be
     * played, so it is refused rather than guessed at.
     */
    public static function read(string $xml): self
    {
        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $launch = self::launch($dom);

        if ($launch === null || trim($launch) === '') {
            throw new \RuntimeException('The manifest names no launchable resource.');
        }

        return new self(ltrim(trim($launch), '/'), self::version($dom));
    }

    private static function launch(\DOMDocument $dom): ?string
    {
        $resources = $dom->getElementsByTagNameNS('*', 'resource');
        $ref       = self::defaultItemRef($dom);

        if ($ref !== null) {
            foreach ($resources as $resource) {
                if ($resource instanceof \DOMElement
                    && $resource->getAttribute('identifier') === $ref
                    && $resource->getAttribute('href') !== ''
                ) {
                    return $resource->getAttribute('href');
                }
            }
        }

        foreach ($resources as $resource) {
            if ($resource instanceof \DOMElement && $resource->getAttribute('href') !== '') {
                return $resource->getAttribute('href');
            }
        }

        return null;
    }

    private static function defaultItemRef(\DOMDocument $dom): ?string
    {
        $organizations = $dom->getElementsByTagNameNS('*', 'organizations')->item(0);
        $default       = $organizations instanceof \DOMElement ? $organizations->getAttribute('default') : '';

        $target = null;

        foreach ($dom->getElementsByTagNameNS('*', 'organization') as $organization) {
            if (!$organization instanceof \DOMElement) {
                continue;
            }

            if ($default === '' || $organization->getAttribute('identifier') === $default) {
                $target = $organization;
                break;
            }
        }

        if (!$target instanceof \DOMElement) {
            return null;
        }

        foreach ($target->getElementsByTagNameNS('*', 'item') as $item) {
            if ($item instanceof \DOMElement && $item->getAttribute('identifierref') !== '') {
                return $item->getAttribute('identifierref');
            }
        }

        return null;
    }

    private static function version(\DOMDocument $dom): string
    {
        $schema = '';

        foreach ($dom->getElementsByTagNameNS('*', 'schemaversion') as $node) {
            $schema = (string) $node->textContent;
            break;
        }

        if (str_contains($schema, '2004') || stripos($schema, 'cam 1.3') !== false) {
            return '2004';
        }

        // The adlcp namespace pins it even when schemaversion is absent:
        // v1p3 is 2004, v1p2 is 1.2.
        return str_contains($dom->saveXML() ?: '', 'adlcp_v1p3') ? '2004' : '1.2';
    }
}
