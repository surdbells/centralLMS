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
     *
     * Resume works by a handshake before launch. The page loads with the
     * content frame empty and posts `scorm_wrapper_ready` to its parent; the
     * player answers `{type: 'scorm_seed', lessonId, state}` with the saved
     * `{location, suspendData, lessonStatus}`, and only then is the package
     * loaded — a package reads its bookmark (`cmi.core.entry`,
     * `cmi.suspend_data`, ...) once, at Initialize, so seeding later is too
     * late. Seeds from anything but the parent frame are ignored. With no
     * seed in 3 s (the preview) it launches unseeded.
     *
     * Every message carries the seeded `lessonId`, so a commit that arrives
     * after the learner has moved on is saved against the right lesson. Besides
     * the package's own Commit/Finish, it commits (debounced) whenever the
     * bookmark keys change and once more on `pagehide`, because many packages
     * write their suspend data and never call Commit before the frame dies.
     *
     * The launch URL is put in a `data-src` attribute rather than `src`, so the
     * same htmlspecialchars escaping still applies and the script reads it back
     * decoded.
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
            </head>
            <body>
            <iframe id="sco" data-src="__LAUNCH_SRC__" allowfullscreen
                    allow="autoplay; fullscreen; microphone; camera"></iframe>
            <script>
            (function () {
              // The CMI map. Empty until seeded: the package is not launched
              // until it holds the learner's saved state, because a package
              // reads its bookmark once, at LMSInitialize, and never again.
              var data = {};
              var lessonId = null;      // echoed on every message, see post()
              var version = null;       // '1.2' or '2004', from the calls made
              var launched = false;
              var initialized = false;
              var finished = false;
              var dirty = false;
              var commitTimer = null;
              var fallbackTimer = null;
              var hasParent = window.parent && window.parent !== window;

              // Changes worth saving straight away: these are what put the
              // learner back on the right slide, and many packages write them
              // without ever calling Commit.
              var WATCHED = {
                'cmi.suspend_data': 1, 'cmi.core.lesson_location': 1, 'cmi.location': 1,
                'cmi.core.exit': 1, 'cmi.exit': 1
              };

              function str(v) { return v == null ? '' : String(v); }
              function lower(v) { return str(v).toLowerCase(); }

              function post(type, extra) {
                if (!hasParent) { return; }
                try {
                  // lessonId is the lesson this wrapper was seeded for, so a
                  // commit that lands after the learner has moved to another
                  // lesson is still saved against this one.
                  var msg = { type: type, cmiData: data, lessonId: lessonId };
                  if (extra) { for (var k in extra) { msg[k] = extra[k]; } }
                  window.parent.postMessage(msg, '*');
                } catch (e) {}
              }

              function status() {
                if (version === '1.2') { return lower(data['cmi.core.lesson_status']); }
                // The seed fills both models' keys, so 'unknown' (seeded,
                // never touched by a 2004 package) must not mask what the
                // package actually reported.
                var c = lower(data['cmi.completion_status']);
                if (c === 'unknown') { c = ''; }
                var sc = lower(data['cmi.success_status']);
                if (sc === 'unknown') { sc = ''; }
                if (version === '2004') { return c || sc; }
                return c || sc || lower(data['cmi.core.lesson_status']);
              }
              function score() {
                var s = data['cmi.score.raw'];
                if (s == null || s === '') { s = data['cmi.core.score.raw']; }
                return (s == null || s === '') ? undefined : Number(s);
              }

              function commitNow() {
                if (commitTimer) { clearTimeout(commitTimer); commitTimer = null; }
                dirty = false;
                post('scorm_commit', { status: status(), score: score() });
              }
              function scheduleCommit() {
                if (commitTimer) { clearTimeout(commitTimer); }
                commitTimer = setTimeout(function () { commitTimer = null; commitNow(); }, 1500);
              }

              function get(k) { return data[k] != null ? String(data[k]) : ''; }
              function set(k, v) {
                var before = data[k];
                data[k] = v;
                // The seed fills both models' location keys; mirror whichever
                // one the package writes, so the other never holds a stale
                // bookmark that a reader checking it first would pick up.
                if (k === 'cmi.core.lesson_location') { data['cmi.location'] = v; }
                else if (k === 'cmi.location') { data['cmi.core.lesson_location'] = v; }
                dirty = true;
                if (!version && String(k).indexOf('cmi.core.') === 0) { version = '1.2'; }
                if (k === 'cmi.core.lesson_status' || k === 'cmi.completion_status' || k === 'cmi.success_status') {
                  post('scorm_status_change', { status: status(), score: score() });
                }
                if (WATCHED[k] && str(before) !== str(v)) {
                  // Keep the player's copy current at once, so its own
                  // unload/destroy flush carries this bookmark even when the
                  // debounced commit (or our pagehide commit) never reaches it.
                  post('scorm_state');
                  scheduleCommit();
                }
                return 'true';
              }
              function start(v) {
                version = version || v;
                initialized = true;
                finished = false;
                post('scorm_initialize');
                return 'true';
              }
              function finish() {
                if (commitTimer) { clearTimeout(commitTimer); commitTimer = null; }
                finished = true;
                dirty = false;
                post('scorm_finish', { status: status(), score: score() });
                return 'true';
              }

              var api = {
                LMSInitialize: function () { return start('1.2'); },
                LMSFinish: function () { return finish(); },
                LMSGetValue: function (k) { return get(k); },
                LMSSetValue: function (k, v) { return set(k, v); },
                LMSCommit: function () { commitNow(); return 'true'; },
                LMSGetLastError: function () { return '0'; },
                LMSGetErrorString: function () { return 'No error'; },
                LMSGetDiagnostic: function () { return 'No error'; },
                Initialize: function () { return start('2004'); },
                Terminate: function () { return finish(); },
                GetValue: function (k) { return get(k); },
                SetValue: function (k, v) { return set(k, v); },
                Commit: function () { commitNow(); return 'true'; },
                GetLastError: function () { return '0'; },
                GetErrorString: function () { return 'No error'; },
                GetDiagnostic: function () { return 'No error'; }
              };
              window.API = api;
              window.API_1484_11 = api;

              // Fills the map for both SCORM 1.2 and 2004 from the saved
              // state (or from nothing, for a first launch or a preview).
              function seed(id, state) {
                lessonId = id == null || id === '' ? null : String(id);
                var s = state && typeof state === 'object' ? state : {};
                var location = str(s.location);
                var suspend = str(s.suspendData);
                var lessonStatus = lower(s.lessonStatus);
                var done = lessonStatus === 'completed' || lessonStatus === 'passed';
                var entry = (location !== '' || suspend !== '') ? 'resume' : 'ab-initio';

                data['cmi.core.lesson_location'] = location;
                data['cmi.location'] = location;
                data['cmi.suspend_data'] = suspend;
                data['cmi.core.lesson_status'] = lessonStatus || 'not attempted';
                data['cmi.completion_status'] = done ? 'completed'
                  : (lessonStatus !== '' && lessonStatus !== 'not attempted' ? 'incomplete' : 'unknown');
                // Restore a 2004 package's pass/fail too, so a passed quiz is
                // not offered again on resume.
                data['cmi.success_status'] = (lessonStatus === 'passed' || lessonStatus === 'failed')
                  ? lessonStatus : 'unknown';
                data['cmi.core.entry'] = entry;
                data['cmi.entry'] = entry;
                data['cmi.core.credit'] = 'credit';
                data['cmi.credit'] = 'credit';
                data['cmi.core.lesson_mode'] = 'normal';
                data['cmi.mode'] = 'normal';
                data['cmi.core.exit'] = '';
                data['cmi.exit'] = '';
                dirty = false;
              }

              function launch() {
                if (launched) { return; }
                launched = true;
                if (fallbackTimer) { clearTimeout(fallbackTimer); fallbackTimer = null; }
                var frame = document.getElementById('sco');
                if (frame) { frame.src = frame.getAttribute('data-src') || ''; }
              }

              window.addEventListener('message', function (event) {
                // Only the frame that embedded us may seed us. Anything else
                // (including the package itself) is ignored.
                if (!hasParent || event.source !== window.parent) { return; }
                var m = event.data;
                if (!m || m.type !== 'scorm_seed' || launched) { return; }
                seed(m.lessonId, m.state);
                launch();
              });

              // A last commit as the frame goes away: switching lessons or
              // leaving the player tears this page down, often before the
              // package has committed what it just wrote. Only once the
              // package has actually started, so an unseeded launch that never
              // ran cannot overwrite saved state with an empty bookmark.
              window.addEventListener('pagehide', function () {
                if (!launched || !initialized) { return; }
                if (finished && !dirty && !commitTimer) { return; }
                commitNow();
              });

              seed(null, null);

              if (hasParent) {
                post('scorm_wrapper_ready');
                // The admin preview has no player listening, so nobody will
                // ever seed it: launch unseeded rather than hang on a blank page.
                fallbackTimer = setTimeout(launch, 3000);
              } else {
                launch();
              }
            })();
            </script>
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
