#!/bin/bash
# Run from MOBILE_APP/ directory.
#
# The canonical copy of this script lives in blutrixx/nativephp-release's
# templates/release.sh and is copied verbatim into each app — never edited
# per project. Propose changes there; `diff <app>/release.sh
# <path-to-nativephp-release>/templates/release.sh` should always come back
# empty.
#
# Usage: ./release.sh <staging|production> [options...]
#   Extra options are passed through to scripts/buildAndDeploy.php
#   (e.g. --build-only, --deploy-only, --version=X.X.X, --force-clean).
#
# Swaps .env for .env.<environment> before building so the build picks up
# the right NATIVEPHP_APP_VERSION / NATIVEPHP_APP_ID / update URL, then
# restores the original .env afterwards no matter how the script exits --
# and re-runs `native:install --force` against that restored dev .env, so
# the native Android scaffold (which a staging/production native:package
# can bake environment-specific values into, not just .env itself) matches
# development again too, same first step start.sh always runs.
#
# Deployment secrets (the BUILD_* shell vars a signed build + remote/backend
# deploy needs -- keystore, remote server, backend token; see
# scripts/buildAndDeploy.php's own docblock) live OUTSIDE this directory:
# $NATIVEPHP_DEPLOY_ENV if set, else
# ~/.config/nativephp-deploy/<NATIVEPHP_APP_ID>.env (one file per app ID if
# staging and production differ). Not required for a local --build-only run,
# so a missing file is not an error -- but an in-tree .env.deploy or
# .env.backup is refused outright, since NativePHP zips this whole directory
# into the APK.

set -e

usage() {
    echo "Usage: ./release.sh <staging|production> [options...]" >&2
}

ENVIRONMENT="$1"

if [[ "$ENVIRONMENT" != "staging" && "$ENVIRONMENT" != "production" ]]; then
    usage
    exit 1
fi
shift

ENV_FILE=".env.${ENVIRONMENT}"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Error: ${ENV_FILE} not found — create it before releasing to ${ENVIRONMENT}." >&2
    exit 1
fi

APP_ID="$(grep -E '^NATIVEPHP_APP_ID=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d "\"' " || true)"
DEPLOY_ENV="${NATIVEPHP_DEPLOY_ENV:-}"
if [[ -z "$DEPLOY_ENV" && -n "$APP_ID" ]]; then
    DEPLOY_ENV="$HOME/.config/nativephp-deploy/${APP_ID}.env"
fi

for secret_file in .env.deploy .env.backup; do
    if [[ -f "$secret_file" ]]; then
        echo "Error: ${secret_file} is present in this directory. NativePHP zips this whole directory into the APK, so a secrets file here would ship inside the build. Move it to ${DEPLOY_ENV:-\$NATIVEPHP_DEPLOY_ENV} (mode 600) and remove it from here." >&2
        exit 1
    fi
done

if [[ -f "$DEPLOY_ENV" ]]; then
    echo "Sourcing $DEPLOY_ENV for BUILD_* deployment secrets."
    set -a
    # shellcheck disable=SC1090
    source "$DEPLOY_ENV"
    set +a
else
    echo "No .env.deploy found — proceeding without deployment secrets (fine for --build-only, will fail on a signed build/deploy that needs them)."
fi

# Back up the dev .env OUTSIDE the app tree: the NativePHP bundler zips the whole working
# tree into the APK, so a backup inside MOBILE_APP/ would ship (plan 012).
ENV_BACKUP="$(mktemp "${TMPDIR:-/tmp}/mobile-app-env-backup.XXXXXX")"
cp .env "$ENV_BACKUP"
echo "Dev .env backed up to $ENV_BACKUP (restored on exit; if this run is killed, copy it back to .env by hand)."

restore_env() {
    local status=$?
    if [[ -f "$ENV_BACKUP" ]]; then
        cp "$ENV_BACKUP" .env
        rm -f "$ENV_BACKUP"

        # Refresh the native Android scaffold for the now-restored dev .env --
        # matches start.sh's own first step. Best-effort: a failure here must
        # never override the real build/deploy exit status ($status) below.
        echo "Restoring native scaffold for the development environment..."
        php artisan native:install --force || echo "Warning: 'php artisan native:install --force' failed while restoring the dev environment — run it manually before native:run if the app misbehaves." >&2
    fi
    exit $status
}
trap restore_env EXIT

cp "$ENV_FILE" .env

# Use the command directly as the `if` condition (not `cmd; status=$?`) so
# `set -e` does not abort the script before we can branch on the result.
if php scripts/buildAndDeploy.php "$ENVIRONMENT" "$@"; then
    build_status=0
    # buildAndDeploy.php (via NativePHPDeployment::updateEnvVersion) may have
    # bumped NATIVEPHP_APP_VERSION in the live .env during the build. Persist
    # that back to its source file now, before the EXIT trap below restores
    # .env from the backup — otherwise the bump is lost and the next release
    # would rebuild from a stale version.
    cp .env "$ENV_FILE"
else
    build_status=$?
    # Build/deploy failed: leave $ENV_FILE untouched, whatever state .env is in.
fi

exit $build_status
