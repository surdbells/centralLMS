<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Media;

use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Media\MediaItem;
use Central\Api\Domain\Media\MediaKind;
use Central\Api\Domain\Media\MediaRepository;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * One file out of an unpacked SCORM package.
 *
 * GET /v1/media/{id}/scorm/{ticket}/{path}
 *
 * A SCORM package is a little website: an entry page that pulls in its own JS,
 * CSS, images and audio by *relative* path. The entry page is loaded into an
 * iframe, and the browser then fetches those assets itself — with no
 * Authorization header and no `?ticket`, because it is just resolving
 * `./js/app.js` against the current URL.
 *
 * So the ticket is carried in the **path**, not a header, a query or a cookie.
 * The entry URL is `…/scorm/{ticket}/index.html`; a relative `./js/app.js`
 * resolves to `…/scorm/{ticket}/js/app.js`, which carries the same ticket. A
 * cookie would be the obvious alternative, but the content is served from the
 * API origin and embedded from the web app's — a cross-site context where a
 * cookie is third-party and increasingly not sent at all.
 *
 * The ticket is the same short-lived, single-file ticket {@see StreamMediaAction}
 * uses, minted by `POST /v1/media/{id}/ticket`, so this route sits outside the
 * authenticated group for the same reason that one does.
 */
final class ScormContentAction extends MediaAction
{
    /** Serve a known handful inline; anything else as bytes. */
    private const TYPES = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'js'   => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
        'svg'  => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'mp3'  => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'oga' => 'audio/ogg',
        'ogg'  => 'audio/ogg', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'eot'  => 'application/vnd.ms-fontobject',
        'pdf'  => 'application/pdf', 'wasm' => 'application/wasm', 'swf' => 'application/x-shockwave-flash',
    ];

    public function __construct(
        MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly JwtTokenService $tokens,
    ) {
        parent::__construct($media);
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $item = $this->authorise($args['id'] ?? '', $args['ticket'] ?? '');

        if ($item->kind !== MediaKind::Scorm || $item->extractedPath === null) {
            // A SCORM file still being unpacked, or a non-SCORM id — nothing to
            // serve from a package folder that does not exist yet.
            throw new EntityNotFound('SCORM content', (string) $item->id);
        }

        $path = $this->within($args['path'] ?? '');

        // The player wrapper. The app embeds `…/scorm/{ticket}/` (empty path):
        // a synthetic page, served from THIS origin, that holds the SCORM API
        // and frames the real entry file. The content's window.parent is then
        // this wrapper — same origin — so it can actually reach the API; the
        // app is a further frame up, cross-origin, which it never has to touch.
        // The wrapper relays the outcome to the app by postMessage, the way the
        // course player's message handler expects.
        if ($path === '') {
            return $this->wrapper($response, ltrim((string) $item->launchUrl, '/'));
        }

        $key = $item->extractedPath . '/' . $path;

        if (!$this->storage->has($key)) {
            throw new EntityNotFound('SCORM file', $path);
        }

        $stream = new Stream($this->storage->read($key));

        return $response
            ->withHeader('Content-Type', $this->typeOf($path))
            ->withHeader('Content-Length', (string) ($stream->getSize() ?? 0))
            // Private so a shared proxy never serves one tenant's package to
            // another; short so a re-uploaded package is picked up.
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    /**
     * The player wrapper page.
     *
     * Carries a SCORM 1.2 / 2004 runtime on its own `window`, so the content it
     * frames — same origin, one level down — finds the API by the standard
     * parent scan without ever touching the cross-origin app. Every call is
     * relayed to the app (this wrapper's own parent) by postMessage, in the
     * shape the course player's message handler reads; the app records the
     * status and score as lesson progress. In the admin preview there is no
     * handler listening, and the content simply plays.
     */
    private function wrapper(ResponseInterface $response, string $launchUrl): ResponseInterface
    {
        $html = str_replace(
            '__LAUNCH_SRC__',
            htmlspecialchars($launchUrl, ENT_QUOTES),
            <<<'HTML'
            <!doctype html>
            <html>
            <head>
            <meta charset="utf-8">
            <style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#fff}iframe{border:0;width:100%;height:100%;display:block}</style>
            <script>
            (function () {
              var data = {};
              function post(type, extra) {
                try {
                  var msg = { type: type, cmiData: data };
                  if (extra) { for (var k in extra) { msg[k] = extra[k]; } }
                  parent.postMessage(msg, '*');
                } catch (e) {}
              }
              function status() {
                return String(data['cmi.completion_status'] || data['cmi.success_status']
                  || data['cmi.core.lesson_status'] || '').toLowerCase();
              }
              function score() {
                var s = data['cmi.score.raw'];
                if (s == null) { s = data['cmi.core.score.raw']; }
                return (s == null || s === '') ? undefined : Number(s);
              }
              function get(k) { return data[k] != null ? String(data[k]) : ''; }
              function set(k, v) {
                data[k] = v;
                if (k === 'cmi.core.lesson_status' || k === 'cmi.completion_status' || k === 'cmi.success_status') {
                  post('scorm_status_change', { status: status(), score: score() });
                }
                return 'true';
              }
              var api = {
                LMSInitialize: function () { post('scorm_initialize'); return 'true'; },
                LMSFinish: function () { post('scorm_finish', { status: status(), score: score() }); return 'true'; },
                LMSGetValue: function (k) { return get(k); },
                LMSSetValue: function (k, v) { return set(k, v); },
                LMSCommit: function () { post('scorm_commit'); return 'true'; },
                LMSGetLastError: function () { return '0'; },
                LMSGetErrorString: function () { return 'No error'; },
                LMSGetDiagnostic: function () { return 'No error'; },
                Initialize: function () { post('scorm_initialize'); return 'true'; },
                Terminate: function () { post('scorm_finish', { status: status(), score: score() }); return 'true'; },
                GetValue: function (k) { return get(k); },
                SetValue: function (k, v) { return set(k, v); },
                Commit: function () { post('scorm_commit'); return 'true'; },
                GetLastError: function () { return '0'; },
                GetErrorString: function () { return 'No error'; },
                GetDiagnostic: function () { return 'No error'; }
              };
              window.API = api;
              window.API_1484_11 = api;
              post('scorm_wrapper_ready');
            })();
            </script>
            </head>
            <body>
            <iframe src="__LAUNCH_SRC__" allowfullscreen allow="autoplay; fullscreen; microphone; camera"></iframe>
            </body>
            </html>
            HTML,
        );

        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, max-age=60');
    }

    /**
     * The file this ticket is for, or an authentication failure.
     *
     * @throws AuthenticationFailed
     * @throws EntityNotFound
     */
    private function authorise(string $id, string $ticket): MediaItem
    {
        if ($id === '' || $ticket === '') {
            throw new AuthenticationFailed(reason: 'missing_media_ticket');
        }

        // Throws unless signed by us, unexpired, and issued for this exact file.
        $claims   = $this->tokens->verifyMediaTicket($ticket, $id);
        $tenantId = $claims['tid'] ?? null;

        if (!is_string($tenantId) || $tenantId === '') {
            throw new AuthenticationFailed(reason: 'ticket_without_tenant');
        }

        return $this->media->find($tenantId, $id)
            ?? throw new EntityNotFound('Media', $id);
    }

    /**
     * A safe relative path inside the package, or '' if it escapes.
     *
     * Any `..` segment, an absolute path or a null byte is refused rather than
     * normalised: a request that tries to climb out of the package folder is
     * not a typo to be helpful about.
     */
    private function within(string $path): string
    {
        // Slim has already URL-decoded the segment; drop any stray query or
        // fragment a client appended.
        $path = (string) preg_replace('/[?#].*$/', '', $path);
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return '';
            }
        }

        return $path;
    }

    private function typeOf(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? 'application/octet-stream';
    }
}
