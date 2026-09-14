#!/bin/bash
# Behaviour tests for templates/release.sh. Run from the package root:
#   bash tests/release-sh.test.sh
#
# Each case builds a disposable fake app tree (never the real MOBILE_APP)
# and runs the real template against it with a stubbed `php`, so the
# assertions exercise release.sh's own control flow -- env-file swapping,
# the mktemp-based backup, the deploy-env lookup/refusal logic -- without
# needing a real Laravel app, a real build, or real secrets anywhere.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="$SCRIPT_DIR/../templates/release.sh"
FAILED=0

# Builds <root>/app (a copy of the template + scripts/ + dummy .env files),
# <root>/home, <root>/tmp and <root>/bin/php (the build-call stub).
setup_case() {
    local root="$1"
    mkdir -p "$root/app/scripts" "$root/home" "$root/tmp" "$root/bin"

    cp "$TEMPLATE" "$root/app/release.sh"
    chmod +x "$root/app/release.sh"

    cat > "$root/app/.env" <<'EOF'
MARKER=dev
NATIVEPHP_APP_VERSION=1.0.0
EOF

    cat > "$root/app/.env.staging" <<'EOF'
MARKER=staging
NATIVEPHP_APP_ID=com.example.probe
NATIVEPHP_APP_VERSION=1.0.0
EOF

    cat > "$root/bin/php" <<EOF
#!/bin/bash
echo "php \$*" >> "$root/php.log"
if [[ "\$1" == "scripts/buildAndDeploy.php" ]]; then
    echo "BUILD_APP_NAME=\$BUILD_APP_NAME" >> "$root/php.log"
    if [[ -f .env.backup ]]; then
        exit 9
    fi
    if [[ -f .env ]]; then
        sed -i.bak 's/^NATIVEPHP_APP_VERSION=.*/NATIVEPHP_APP_VERSION=9.9.9/' .env
        rm -f .env.bak
    fi
    exit "\${PHP_SHIM_EXIT:-0}"
fi
exit 0
EOF
    chmod +x "$root/bin/php"
}

run_release_sh() {
    local root="$1"
    shift
    (cd "$root/app" && HOME="$root/home" TMPDIR="$root/tmp" PATH="$root/bin:$PATH" "$@" ./release.sh staging)
}

assert_eq() {
    local label="$1" expected="$2" actual="$3"
    if [[ "$expected" != "$actual" ]]; then
        echo "  assertion failed: $label — expected [$expected], got [$actual]"
        return 1
    fi
    return 0
}

pass() { echo "PASS $1"; }
fail() { echo "FAIL $1"; FAILED=1; }

# ── success ─────────────────────────────────────────────────────────────
case_success() {
    local name="success"
    local root
    root="$(mktemp -d)"
    setup_case "$root"

    run_release_sh "$root"
    local status=$?

    local ok=1
    assert_eq "exit code" "0" "$status" || ok=0
    assert_eq ".env MARKER" "MARKER=dev" "$(command grep '^MARKER=' "$root/app/.env")" || ok=0
    assert_eq ".env.staging version" "NATIVEPHP_APP_VERSION=9.9.9" "$(command grep '^NATIVEPHP_APP_VERSION=' "$root/app/.env.staging")" || ok=0
    [[ -z "$(ls -A "$root/tmp" 2>/dev/null)" ]] || { echo "  assertion failed: tmp dir not empty"; ok=0; }
    command grep -qF 'native:install --force' "$root/php.log" 2>/dev/null || { echo "  assertion failed: native:install --force not logged"; ok=0; }

    [[ "$ok" == "1" ]] && pass "$name" || fail "$name"
    rm -rf "$root"
}

# ── failure ─────────────────────────────────────────────────────────────
case_failure() {
    local name="failure"
    local root
    root="$(mktemp -d)"
    setup_case "$root"

    PHP_SHIM_EXIT=3 run_release_sh "$root"
    local status=$?

    local ok=1
    assert_eq "exit code" "3" "$status" || ok=0
    assert_eq ".env MARKER" "MARKER=dev" "$(command grep '^MARKER=' "$root/app/.env")" || ok=0
    assert_eq ".env.staging version" "NATIVEPHP_APP_VERSION=1.0.0" "$(command grep '^NATIVEPHP_APP_VERSION=' "$root/app/.env.staging")" || ok=0

    [[ "$ok" == "1" ]] && pass "$name" || fail "$name"
    rm -rf "$root"
}

# ── explicit-deploy-env ─────────────────────────────────────────────────
case_explicit_deploy_env() {
    local name="explicit-deploy-env"
    local root
    root="$(mktemp -d)"
    setup_case "$root"

    echo 'BUILD_APP_NAME=probe' > "$root/deploy.env"

    NATIVEPHP_DEPLOY_ENV="$root/deploy.env" run_release_sh "$root"
    local status=$?

    local ok=1
    assert_eq "exit code" "0" "$status" || ok=0
    command grep -qF 'BUILD_APP_NAME=probe' "$root/php.log" 2>/dev/null || { echo "  assertion failed: BUILD_APP_NAME=probe not logged"; ok=0; }

    [[ "$ok" == "1" ]] && pass "$name" || fail "$name"
    rm -rf "$root"
}

# ── default-deploy-env ──────────────────────────────────────────────────
case_default_deploy_env() {
    local name="default-deploy-env"
    local root
    root="$(mktemp -d)"
    setup_case "$root"

    mkdir -p "$root/home/.config/nativephp-deploy"
    echo 'BUILD_APP_NAME=probe' > "$root/home/.config/nativephp-deploy/com.example.probe.env"

    run_release_sh "$root"
    local status=$?

    local ok=1
    assert_eq "exit code" "0" "$status" || ok=0
    command grep -qF 'BUILD_APP_NAME=probe' "$root/php.log" 2>/dev/null || { echo "  assertion failed: BUILD_APP_NAME=probe not logged"; ok=0; }

    [[ "$ok" == "1" ]] && pass "$name" || fail "$name"
    rm -rf "$root"
}

# ── in-tree-secret-refused ──────────────────────────────────────────────
case_in_tree_secret_refused() {
    local name="in-tree-secret-refused"
    local root
    root="$(mktemp -d)"
    setup_case "$root"

    touch "$root/app/.env.deploy"

    run_release_sh "$root"
    local status=$?

    local ok=1
    [[ "$status" != "0" ]] || { echo "  assertion failed: expected non-zero exit, got 0"; ok=0; }
    [[ ! -s "$root/php.log" ]] || { echo "  assertion failed: php.log not empty"; ok=0; }
    assert_eq ".env MARKER untouched" "MARKER=dev" "$(command grep '^MARKER=' "$root/app/.env")" || ok=0

    [[ "$ok" == "1" ]] && pass "$name" || fail "$name"
    rm -rf "$root"
}

case_success
case_failure
case_explicit_deploy_env
case_default_deploy_env
case_in_tree_secret_refused

if [[ "$FAILED" == "0" ]]; then
    echo "ALL PASS"
    exit 0
else
    exit 1
fi
