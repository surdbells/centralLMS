<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Settings;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Notification\EmailBrandingResolver;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Settings\SettingKey;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * An organisation's logo, at an address an inbox can load.
 *
 * POST /v1/settings/branding/{asset}               upload (settings.manage)
 * GET  /v1/public/branding/{tenantId}/{asset}      the image, no sign-in
 *
 * `asset` is `logo` or `email-logo`.
 *
 * Email clients fetch images anonymously, sometimes days after the mail was
 * sent, through their own proxies. The media library's links carry a ticket
 * that expires in minutes, so a logo pasted from there shows as a broken image
 * in every inbox. This stores the upload under the tenant, sets the matching
 * Branding setting to its public address, and serves exactly that file —
 * nothing else in storage is reachable through it. The address carries a
 * version so a replaced logo is not hidden behind a cached old one.
 */
final class BrandingAssetAction
{
    private const MAX_BYTES = 1024 * 1024;

    /**
     * PNG, JPEG and GIF only: what every mail client renders. SVG is not
     * shown by Gmail or Outlook, and WebP not by Outlook.
     */
    private const TYPES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
    ];

    private const ASSETS = [
        'logo'       => SettingKey::LogoUrl,
        'email-logo' => SettingKey::EmailHeaderLogoUrl,
    ];

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly SettingsRepository $settings,
        private readonly EmailBrandingResolver $branding,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function upload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::SettingsManage);
        $tenantId = $caller->tenantId ?? throw new AccessDenied(Permission::SettingsManage->value);
        $asset    = $this->asset($args);

        $upload    = $this->uploadedFile($request);
        $extension = strtolower(pathinfo((string) $upload->getClientFilename(), PATHINFO_EXTENSION));

        if (!array_key_exists($extension, self::TYPES)) {
            throw ValidationFailed::fromArray(['file' => 'Upload a PNG, JPEG or GIF — those show in every email client.']);
        }

        if (($upload->getSize() ?? 0) > self::MAX_BYTES) {
            throw ValidationFailed::fromArray(['file' => 'Keep a logo under 1 MB.']);
        }

        // The bytes decide, not the name: a renamed file is refused.
        $probe = @getimagesizefromstring((string) $upload->getStream());

        if ($probe === false || !in_array($probe['mime'], self::TYPES, true)) {
            throw ValidationFailed::fromArray(['file' => 'That file is not a PNG, JPEG or GIF image.']);
        }

        // Stored under the type the bytes are, not the name it came with, so
        // a PNG renamed .jpg is still served as image/png.
        $extension = (string) array_search($probe['mime'], self::TYPES, true);

        $upload->getStream()->rewind();

        // One file per asset: remove whatever was there under another extension.
        foreach (array_unique(array_keys(self::TYPES)) as $old) {
            $this->storage->remove($this->key($tenantId, $asset, $old));
        }

        $stored = $this->storage->store($upload, $this->key($tenantId, $asset, $extension));

        if ($stored['size'] > self::MAX_BYTES) {
            $this->storage->remove($this->key($tenantId, $asset, $extension));

            throw ValidationFailed::fromArray(['file' => 'Keep a logo under 1 MB.']);
        }

        $path = sprintf('/v1/public/branding/%s/%s?v=%s', $tenantId, $asset, substr(md5((string) microtime(true)), 0, 8));
        $base = $this->branding->apiUrl();
        $url  = $base !== '' ? $base . $path : $path;

        $this->settings->save($tenantId, [self::ASSETS[$asset]->value => $url], $caller->id);
        $this->branding->forget($tenantId);

        return Json::created($response, [
            'asset'   => $asset,
            'setting' => self::ASSETS[$asset]->value,
            'url'     => $url,
            // https and absolute: what an inbox needs. False on a plain-http
            // development server, where emails will show the name instead.
            'isPublic' => str_starts_with($url, 'https://'),
        ]);
    }

    /**
     * Unauthenticated. Serves only the tenant's own branding images.
     *
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = (string) ($args['tenantId'] ?? '');
        $asset    = $this->asset($args);

        foreach (self::TYPES as $extension => $mime) {
            $key = $this->key($tenantId, $asset, $extension);

            if (!$this->storage->has($key)) {
                continue;
            }

            $stream = new Stream($this->storage->read($key));

            return $response
                ->withHeader('Content-Type', $mime)
                ->withHeader('Content-Length', (string) ($stream->getSize() ?? 0))
                // Public and long-lived: the address changes when the logo does.
                ->withHeader('Cache-Control', 'public, max-age=86400')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withBody($stream);
        }

        throw new EntityNotFound('Branding image', $asset);
    }

    /**
     * @param array<string, string> $args
     */
    private function asset(array $args): string
    {
        $asset = (string) ($args['asset'] ?? '');

        if (!array_key_exists($asset, self::ASSETS)) {
            throw new EntityNotFound('Branding image', $asset);
        }

        return $asset;
    }

    private function key(string $tenantId, string $asset, string $extension): string
    {
        return sprintf('branding/%s/%s.%s', $tenantId, $asset, $extension);
    }

    private function uploadedFile(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file  = $files['file'] ?? $files['image'] ?? reset($files);

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach an image.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'That file did not arrive in one piece.']);
        }

        return $file;
    }
}
