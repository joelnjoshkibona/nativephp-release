# blutrixx/nativephp-release

Builds, signs and publishes NativePHP Mobile Android releases. This is release
*tooling*, not runtime code — it is required with `--dev` and must never be
required as a normal dependency, so it never ships inside a built APK.

## What it is

`NativePHPDeployment` drives the whole release: it runs the Vite build,
packages the app via `php artisan native:package android`, signs it with a
real keystore (or an auto-generated throwaway debug keystore for local
builds), and deploys the result — either over SSH/rsync to a remote server,
or by POSTing the signed APK to a Laravel BACKEND's `MobileReleases` API (no
SSH server required, e.g. for LAN hosting). `Cli` wires that class up to
`getcwd()`/`$argv` and the same `BUILD_*` environment variables the app-side
shims used to read directly.

`php artisan native:release` (NativePHP's own bundled command) is a
**different tool** — it only bumps the version number in `.env` and
`config/nativephp.php`. It has nothing to do with building, signing or
deploying and is not a substitute for anything here.

## Install

```bash
composer require --dev blutrixx/nativephp-release
```

**Always `--dev`, never a plain `require`.** The Android bundler
(`vendor/nativephp/mobile`'s `PackageCommand`) runs `composer install
--no-dev` inside a copy of the project before zipping it into the APK — a
`require-dev` package is never present in that copy, so it never ships. A
plain `require` would ship this tooling (and, transitively, whatever's in
its own dependency tree) inside every release build.

### Interim: `NATIVEPHP_RELEASE_HOME`

Until this package is published on Packagist, point the app-side shim at a
local checkout instead of `vendor/`:

```bash
NATIVEPHP_RELEASE_HOME=/path/to/nativephp-release php scripts/buildAndDeploy.php --help
```

`scripts/buildAndDeploy.php` checks `NATIVEPHP_RELEASE_HOME` **before**
`vendor/blutrixx/nativephp-release`, so it stays useful afterwards too — as a
real dev override for testing an unreleased change to this package without
bumping the app's `composer.json` constraint.

## Usage

From an app that has this package available (via `vendor/` or
`NATIVEPHP_RELEASE_HOME`):

```bash
./release.sh staging              # swap in .env.staging, build, deploy, restore .env
./release.sh production --build-only
php scripts/buildAndDeploy.php --help
vendor/bin/native-release --help  # equivalent, run directly against the current directory
```

## The `BUILD_*` environment variables

Set these before a real signed build + deploy (shell export, CI secret, or
the deploy-env file below — never commit them):

| Env var | Purpose |
|---|---|
| `BUILD_KEYSTORE_FILE` / `_PASSWORD`, `BUILD_KEY_ALIAS` / `_PASSWORD` | Real Android signing keystore |
| `BUILD_APP_NAME`, `BUILD_DOWNLOADER_APK_NAME` | App slug used in deploy paths/filenames |

Then pick **one** deploy target — SSH/rsync to a remote server, or POST to a
BACKEND hosted on your local network (no SSH required):

| SSH/rsync (`BUILD_REMOTE_*`) | BACKEND upload (`BUILD_BACKEND_*`) |
|---|---|
| `BUILD_REMOTE_HOST` / `_USER` / `_PORT` / `_BASE_DIR`, `BUILD_BASE_URL` | `BUILD_BACKEND_URL` (e.g. `http://192.168.1.50:8000`) |
| — | `BUILD_BACKEND_TOKEN` — a Sanctum token for a user with `MobileReleases.list`, `.create` and `.edit` |
| — | `BUILD_CHANGELOG` — optional on an interactive terminal (prompted via `$VISUAL`/`$EDITOR`, git-commit-editor style); required in CI. JSON array, e.g. `[{"type":"NEW","description":"..."}]`; `type` is one of `NEW`/`IMPROVED`/`FIXED`/`SECURITY`/`ISSUE`. `BUILD_MANDATORY` (optional, `true`/`false`) |

Without any of these set, the defaults are obvious placeholders
(`CHANGEME.example.com` etc.) so a deploy attempt fails loudly rather than
silently hitting somewhere unintended.

## Deploy-env location

`templates/release.sh` never sources an in-tree secrets file — NativePHP
zips the whole app directory into the APK, so a secrets file there would
ship inside the build. Instead it sources `$NATIVEPHP_DEPLOY_ENV` if set,
else `~/.config/nativephp-deploy/<NATIVEPHP_APP_ID>.env` (one file per app ID
if staging and production use different secrets), and refuses to run at all
while an in-tree `.env.deploy` or `.env.backup` is present. Create the file
with `install -d -m 700 ~/.config/nativephp-deploy` and `chmod 600` on the
file itself. A missing deploy-env file is not an error — it's fine for
`--build-only`, and only a signed build or a real deploy needs the secrets
in it.

## Token permissions

The backend-upload deploy target needs a Sanctum token with all three of
`MobileReleases.list`, `.create` and `.edit` — the release tool looks up
whether a release with this version already exists (`.list`, via
`filters[version]=X.Y.Z`) before deciding whether to create a new row
(`.create`) or replace an existing one's APK in place (`.edit`).

## Adopting `templates/release.sh`

`templates/release.sh` is the single canonical copy of the release script —
copy it verbatim into your app as `release.sh`; never hand-edit a project's
copy. `diff <app>/release.sh <path-to-this-package>/templates/release.sh`
should always come back empty. Propose changes here instead, then re-copy
the template into every app that uses it.
