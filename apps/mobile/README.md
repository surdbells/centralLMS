# @central/mobile

The Central LMS **learner app** — an [Ionic](https://ionicframework.com) +
Angular app in a [Capacitor](https://capacitorjs.com) native shell.

It is a purpose-built, touch-first experience (bottom tabs, native transitions,
pull-to-refresh), not the admin web app in a WebView. It talks to the **same
API** as `@central/web` — the tenant is resolved from whoever signs in, so there
is no subdomain on a device and none is needed.

- **App id:** `com.centrallms.app` · **Name:** Central LMS
- **API:** `src/environments/environment.ts` (dev) / `environment.prod.ts` (the
  production host that ships in the store)
- **Design:** Ionic in `ios` mode for one consistent look across platforms; the
  brand palette lives in `src/theme/variables.scss`.

## Screens (v1 — learner core)

| Screen | What it shows |
| --- | --- |
| Login | Email + password → `/auth/login`; the session is stored on device |
| Home | Greeting, stats, **Continue learning**, deadlines, recommendations (`/dashboards/me`) |
| Courses | **My Courses** (`/me/enrollments`) and the **Catalogue** (`/courses/catalogue`), searchable |
| Course detail | Outline + your progress; enrol; open lessons |
| Lesson | Lesson info + **Mark as complete** (records real progress) |
| Progress | Overall ring, points, streak, **certificates** |
| Profile | Identity + sign out |

## Develop

```bash
pnpm --filter @central/mobile dev      # ng serve on :8100 (an allowed CORS origin)
```

Sign in with a learner account; it uses the local API by default.

## Build & ship

```bash
pnpm --filter @central/mobile mobile   # ng build (→ www) + cap sync
pnpm --filter @central/mobile exec cap open android   # Android Studio
pnpm --filter @central/mobile exec cap open ios        # Xcode (macOS only)
```

The native `android/` and `ios/` projects are **generated, not committed** (the
repo's root `.gitignore` excludes them). Recreate them once after cloning:

```bash
pnpm --filter @central/mobile exec cap add android
pnpm --filter @central/mobile exec cap add ios
```

Producing an installable **APK/IPA** needs the Android SDK (`ANDROID_HOME`) for
Android and macOS + Xcode for iOS — neither builds on a bare Windows/Linux box.

## Not done yet

- **In-lesson content** (video/reading/quiz playback) — the lesson screen shows
  the lesson and records completion; rich content rendering is the next step.
- **Token refresh** — a 401 currently returns you to sign-in; a silent refresh
  using the stored refresh token can slot into `core/auth.interceptor.ts`.
- **App icon & splash art**, push notifications, deep links, offline caching.
- More of the learner web module (paths, skills, self-assessment, notes).
