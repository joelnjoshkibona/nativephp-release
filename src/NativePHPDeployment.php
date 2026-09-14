<?php

namespace Blutrixx\NativeRelease;

use CURLFile;
use DateTime;
use DateTimeZone;

/**
 * NativePHP Deployment - Build and Deploy Class for NativePHP Mobile Apps
 *
 * Builds, signs and publishes a NativePHP Mobile (Laravel + Vue SPA wrapped as
 * a native Android app) release. The usual entry point is `Cli::run()`, called
 * from `vendor/bin/native-release` once this package is required with `--dev`.
 *
 * Key properties of a NativePHP release, vs a typical Expo/React Native one:
 *   - Build command: `php artisan native:build android`  (not Gradle directly)
 *   - Single universal APK — no per-architecture splits
 *   - No JS bundle OTA updates (NativePHP does full APK updates only)
 *   - No Gradle template generation (NativePHP manages its own Gradle config)
 *   - No Android folder regeneration (NativePHP manages it internally)
 *   - Version read from `.env` NATIVEPHP_APP_VERSION  (not app.json)
 *
 * Usage:
 *   $config = [
 *       'projectRoot'       => '/path/to/MOBILE_APP',
 *       'appName'           => 'CHANGEME',
 *       'downloaderApkName' => 'CHANGEME',
 *       'apkOutputPath'     => null,       // auto-detect from standard Gradle path
 *
 *       // Keystore — required for signed release APK via native:package
 *       // Omit (or set to null) for unsigned debug builds via native:build
 *       'keystoreFile'      => '/path/to/keystore.jks',
 *       'keystorePassword'  => 'yourpassword',
 *       'keyAlias'          => 'youralias',
 *       'keyPassword'       => 'yourpassword',
 *
 *       'remoteHost'        => 'CHANGEME.example.com',
 *       'remoteUser'        => 'CHANGEME',
 *       'remotePort'        => 22,
 *       'remoteBaseDir'     => '/CHANGEME/apps/',
 *       'baseUrl'           => 'https://CHANGEME.example.com/apps',
 *
 *       // Opt-in alternative to the SSH/rsync config above: POST the built APK
 *       // to a BACKEND's MobileReleases API instead (no SSH needed — e.g. for
 *       // LAN hosting). Set backendUrl to switch deploy modes; everything above
 *       // is then ignored.
 *       'backendUrl'        => null,   // e.g. 'http://192.168.1.50:8000'
 *       'backendToken'      => null,   // Sanctum token, permission MobileReleases.list, .create and .edit
 *       'backendChangelog'  => null,   // REQUIRED for backend uploads -- JSON array string,
 *                                      // e.g. '[{"type":"NEW","description":"..."}]'
 *                                      // (type: NEW|IMPROVED|FIXED|SECURITY|ISSUE).
 *                                      // Left null/empty, checkPrerequisites() prompts for
 *                                      // one interactively (opens $VISUAL/$EDITOR on a
 *                                      // git-commit-editor-style temp file) before the build
 *                                      // starts, rather than failing after it -- see
 *                                      // resolveBackendChangelog(). Only on a real TTY; a
 *                                      // non-interactive run (CI) must still pass this in.
 *       'backendMandatory'  => false,
 *   ];
 *   $deployment = new NativePHPDeployment($config);
 *   $deployment->run(array_slice($argv, 1));
 */

class NativePHPDeployment
{
    private string $version = '';
    private string $projectRoot;
    private string $buildsDir;

    // Project configuration
    private string $appName;
    private string $downloaderApkName;
    private ?string $apkOutputPath;  // null = auto-detect

    // Keystore (signing) — required for release APKs via native:package
    private ?string $keystoreFile;
    private ?string $keystorePassword;
    private ?string $keyAlias;
    private ?string $keyPassword;

    // Remote server (SSH/rsync path)
    private string $remoteHost;
    private string $remoteUser;
    private int    $remotePort;
    private string $remoteBaseDir;
    private string $baseUrl;

    // Alternative deploy target: POST the built APK to a BACKEND's MobileReleases
    // API instead of SSH/rsync (LAN hosting, no SSH required). Opt-in — when
    // backendUrl is unset, deploy behavior is 100% unchanged (SSH/rsync path).
    private ?string $backendUrl;
    private ?string $backendToken;
    private ?string $backendChangelog;
    private bool    $backendMandatory;

    // Opt-in: run `native:install android` to refresh the Android scaffold
    // before building. Off by default for backward compatibility with
    // existing callers of this class.
    private bool $runNativeInstall;

    // Vite build mode (--mode). Determines which .env.<mode> file Vite loads
    // on top of the live .env when baking VITE_* values into the JS bundle.
    // Defaults to 'production' — Vite's own default when no --mode is passed —
    // so existing callers that don't set this see no behavior change.
    private string $viteMode;

    // Timing
    private float $startTime;
    private float $operationStartTime = 0.0;

    // ── Constructor ──────────────────────────────────────────────────────────

    public function __construct(array $config = [])
    {
        $this->projectRoot      = $config['projectRoot']      ?? getcwd();
        $this->buildsDir        = $this->projectRoot . '/builds';
        $this->appName          = $config['appName']          ?? 'app';
        $this->downloaderApkName = $config['downloaderApkName'] ?? $this->appName;
        $this->apkOutputPath      = $config['apkOutputPath']      ?? null;
        $this->keystoreFile       = $config['keystoreFile']       ?? null;
        $this->keystorePassword   = $config['keystorePassword']   ?? null;
        $this->keyAlias           = $config['keyAlias']           ?? null;
        $this->keyPassword        = $config['keyPassword']        ?? null;
        $this->remoteHost       = $config['remoteHost']       ?? 'CHANGEME.example.com';
        $this->remoteUser       = $config['remoteUser']       ?? 'CHANGEME';
        $this->remotePort       = (int)($config['remotePort'] ?? 22);
        $this->remoteBaseDir    = $config['remoteBaseDir']    ?? '/CHANGEME/apps/';
        $this->baseUrl          = $config['baseUrl']          ?? 'https://CHANGEME.example.com/apps';
        $this->backendUrl        = $config['backendUrl']        ?? null;
        $this->backendToken      = $config['backendToken']      ?? null;
        $this->backendChangelog  = $config['backendChangelog']  ?? null;
        $this->backendMandatory  = (bool)($config['backendMandatory'] ?? false);
        $this->runNativeInstall = (bool)($config['runNativeInstall'] ?? false);
        $this->viteMode         = $config['viteMode']         ?? 'production';
        $this->startTime        = microtime(true);
    }

    // ── Output helpers ───────────────────────────────────────────────────────

    private function printColored(string $msg, string $color = 'blue'): void
    {
        $map = [
            'red'    => "\033[0;31m", 'green'  => "\033[0;32m",
            'yellow' => "\033[1;33m", 'blue'   => "\033[0;34m",
            'purple' => "\033[0;35m", 'cyan'   => "\033[0;36m",
            'nc'     => "\033[0m",
        ];
        echo ($map[$color] ?? $map['blue']) . $msg . $map['nc'] . "\n";
    }

    private function printStatus(string $m): void  { $this->printColored("📤 $m", 'blue');   }
    private function printSuccess(string $m): void { $this->printColored("✅ $m", 'green');  }
    private function printError(string $m): void   { $this->printColored("❌ $m", 'red');    }
    private function printWarning(string $m): void { $this->printColored("⚠️  $m", 'yellow'); }
    private function printInfo(string $m): void    { $this->printColored("ℹ️  $m", 'purple'); }
    private function printStep(string $m): void    { $this->printColored("🔧 $m", 'cyan');   }

    // ── Timing helpers ───────────────────────────────────────────────────────

    private function startOperation(string $name): void
    {
        $this->operationStartTime = microtime(true);
        $this->printInfo("⏱️  Starting: $name");
    }

    private function endOperation(string $name): void
    {
        $dur = microtime(true) - $this->operationStartTime;
        $this->printSuccess("✅ Completed: $name (took {$this->formatDuration($dur)})");
    }

    private function formatDuration(float $s): string
    {
        if ($s < 60)   return number_format($s, 2) . 's';
        if ($s < 3600) return floor($s / 60) . 'm ' . number_format(fmod($s, 60), 1) . 's';
        return floor($s / 3600) . 'h ' . floor(fmod($s, 3600) / 60) . 'm ' . number_format(fmod($s, 60), 1) . 's';
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function getRemoteBaseDir(): string
    {
        return rtrim($this->remoteBaseDir, '/') . '/' . $this->appName;
    }

    /**
     * True when a backendUrl is configured — deploy uses uploadToBackend()
     * (POST to a BACKEND's MobileReleases API) instead of SSH/rsync.
     */
    private function usesBackendUpload(): bool
    {
        return !empty($this->backendUrl);
    }

    // ── Shell execution ──────────────────────────────────────────────────────

    private function executeCommand(string $cmd, string $description = ''): bool
    {
        if ($description) $this->printStatus($description);
        $code = 0;
        passthru($cmd . ' 2>&1', $code);
        if ($code !== 0) {
            $this->printError("Command failed: $cmd");
            return false;
        }
        return true;
    }

    /**
     * Execute a command that requires a real TTY (e.g. native:package / Gradle / apksigner).
     *
     * PHP's passthru() has no attached terminal, so processes that open /dev/tty directly
     * fail with "TTY mode requires /dev/tty to be read/writable". The fix is `script -q -e`
     * which allocates a pseudo-TTY and passes the child's exit code through (-e flag).
     *
     * The command is written to a temp shell script first to avoid quoting issues with
     * complex arguments (keystore paths, passwords).
     */
    private function executeWithTty(string $cmd, string $description = ''): bool
    {
        if ($description) $this->printStatus($description);

        $tmpScript = tempnam(sys_get_temp_dir(), 'nativephp_build_');
        file_put_contents($tmpScript, "#!/bin/bash\nset -e\n" . $cmd . "\n");
        chmod($tmpScript, 0755);

        // script -q  = quiet (no "Script started/done" lines)
        // script -e  = exit with child's exit code
        // /dev/null  = discard the transcript file
        $wrapped = 'script -q -e -c ' . escapeshellarg("bash {$tmpScript}") . ' /dev/null';

        $code = 0;
        passthru($wrapped . ' 2>&1', $code);
        unlink($tmpScript);

        if ($code !== 0) {
            $this->printError("Build command failed (exit $code)");
            return false;
        }
        return true;
    }

    // ── Version reading ──────────────────────────────────────────────────────

    /**
     * Read NATIVEPHP_APP_VERSION from .env, fallback to package.json version.
     *
     * $allowDevFallback (opt-in, used for --build-only/local builds only):
     * synthesize a local dev version instead of failing when .env has no real
     * version set (e.g. a freshly scaffolded project still on the default
     * NATIVEPHP_APP_VERSION=DEBUG). Full build+deploy and --deploy-only never
     * pass this — those still require an explicit real version, so nothing
     * ever ships to a real server labeled "DEBUG".
     */
    private function getAppVersion(bool $allowDevFallback = false): ?string
    {
        // 1. Try .env
        $envFile = $this->projectRoot . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'NATIVEPHP_APP_VERSION=')) {
                    $val = trim(substr($line, strlen('NATIVEPHP_APP_VERSION=')), " \t\n\r\"'");
                    if ($val && $val !== 'DEBUG') {
                        $this->printInfo("Version from .env: $val");
                        return $val;
                    }
                }
            }
        }

        if ($allowDevFallback) {
            $dev = '0.0.0-dev-' . date('YmdHis');
            $this->printWarning("No NATIVEPHP_APP_VERSION set — using local dev version: $dev");
            return $dev;
        }

        $this->printError("Could not determine version. Set NATIVEPHP_APP_VERSION in .env or version in package.json.");
        return null;
    }

    /**
     * Read NATIVEPHP_APP_VERSION_CODE from .env — required by BACKEND's
     * MobileReleases.version_code (integer, unique) when using uploadToBackend().
     *
     * ONLY a fallback for when aapt/aapt2 can't be found — see
     * readApkVersionCode()'s docblock for why this alone is NOT safe to
     * upload: `native:package android` increments this value in .env AFTER
     * using the pre-increment value to build the manifest, so by the time
     * uploadToBackend() runs (a separate pipeline stage, after the build
     * already finished), this always reads one HIGHER than what's actually
     * baked into the APK being uploaded.
     */
    private function readEnvVersionCode(): ?int
    {
        $envFile = $this->projectRoot . '/.env';
        if (!file_exists($envFile)) return null;

        foreach (file($envFile) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'NATIVEPHP_APP_VERSION_CODE=')) {
                $val = trim(substr($line, strlen('NATIVEPHP_APP_VERSION_CODE=')), " \t\n\r\"'");
                if ($val !== '' && ctype_digit($val)) {
                    return (int) $val;
                }
            }
        }
        return null;
    }

    /**
     * Read the version code ACTUALLY BAKED INTO the built APK's own manifest,
     * via `aapt dump badging` — ground truth, unlike .env's
     * NATIVEPHP_APP_VERSION_CODE. Confirmed live (2026-08-30): a real device
     * that installed a build whose .env read N right after `native:package
     * android` finished reported the REAL manifest's versionCode as N-1 —
     * `native:package` bakes the pre-increment value into the manifest, then
     * bumps .env for the *next* build. Uploading readEnvVersionCode()'s value
     * to BACKEND was recording a version_code no real device would ever
     * actually report back, permanently breaking the update-check comparison
     * for that release (every device would see a perpetual false "update
     * available", since the real installed code could never reach the
     * inflated recorded one). Falls back to readEnvVersionCode() only if
     * aapt/aapt2 can't be found at all.
     */
    private function readApkVersionCode(string $apkFile): ?int
    {
        $aapt = $this->findAapt();
        if (!$aapt) {
            return null;
        }

        $output = shell_exec(escapeshellarg($aapt) . ' dump badging ' . escapeshellarg($apkFile) . ' 2>/dev/null');
        if ($output && preg_match("/versionCode='(\d+)'/", $output, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Locate aapt2 (preferred) or aapt in the Android SDK's build-tools
     * (newest version first), falling back to PATH.
     */
    private function findAapt(): ?string
    {
        $sdkRoot = getenv('ANDROID_SDK_ROOT') ?: getenv('ANDROID_HOME') ?: (getenv('HOME') . '/Android/Sdk');
        $buildToolsDir = $sdkRoot . '/build-tools';
        if (is_dir($buildToolsDir)) {
            $versions = glob($buildToolsDir . '/*', GLOB_ONLYDIR) ?: [];
            rsort($versions);
            foreach ($versions as $dir) {
                foreach (['aapt2', 'aapt'] as $bin) {
                    $path = $dir . '/' . $bin;
                    if (is_executable($path)) {
                        return $path;
                    }
                }
            }
        }

        foreach (['aapt2', 'aapt'] as $bin) {
            $found = trim((string) shell_exec("command -v {$bin} 2>/dev/null"));
            if ($found !== '') {
                return $found;
            }
        }

        return null;
    }

    // ── Prerequisites ────────────────────────────────────────────────────────

    private function checkPrerequisites(bool $requiresRemote = true): bool
    {
        $this->printStep("Checking prerequisites...");

        // artisan must be present (we're in a Laravel project)
        if (!file_exists($this->projectRoot . '/artisan')) {
            $this->printError("artisan not found — make sure projectRoot points to the Laravel/NativePHP project.");
            return false;
        }

        // PHP
        if (!$this->executeCommand('php --version', 'Checking PHP...')) {
            $this->printError("PHP is not available!");
            return false;
        }

        // Composer dependencies
        if (!is_dir($this->projectRoot . '/vendor')) {
            $this->printWarning("vendor/ directory not found — running composer install...");
            if (!$this->executeCommand("cd {$this->projectRoot} && composer install --no-interaction", "Installing Composer dependencies...")) {
                $this->printError("composer install failed!");
                return false;
            }
        }

        // Node / npm (NativePHP native:build compiles Vite assets)
        if (!$this->executeCommand('node --version', 'Checking Node.js...')) {
            $this->printError("Node.js is not installed!");
            return false;
        }
        if (!$this->executeCommand('npm --version', 'Checking npm...')) {
            $this->printError("npm is not installed!");
            return false;
        }

        // Android SDK
        if (empty(getenv('ANDROID_HOME'))) {
            $this->printWarning("ANDROID_HOME is not set — Android builds may fail.");
        }

        // Keystore — required for signed release APKs (native:package)
        if ($this->keystoreFile) {
            if (!file_exists($this->keystoreFile)) {
                $this->printError("Keystore file not found: {$this->keystoreFile}");
                return false;
            }
            $this->printSuccess("Keystore: {$this->keystoreFile}");
        } else {
            $this->printWarning("No keystore configured — a local debug keystore will be generated for --build-only (real deploys still require a real one).");
        }

        // SSH / rsync for deployment — not needed for a local-only (--build-only) build,
        // nor for backend-upload mode (POSTs over HTTP via cURL instead).
        if ($requiresRemote && !$this->usesBackendUpload()) {
            if (!$this->executeCommand('ssh -V', 'Checking SSH...')) {
                $this->printError("SSH is not installed!");
                return false;
            }
            if (!$this->executeCommand('rsync --version', 'Checking rsync...')) {
                $this->printError("rsync is not installed!");
                return false;
            }
        }

        // cURL for backend-upload mode
        if ($requiresRemote && $this->usesBackendUpload() && !function_exists('curl_init')) {
            $this->printError("PHP's cURL extension is required for backend-upload mode (ext-curl).");
            return false;
        }

        // Changelog for backend-upload mode -- BACKEND's MobileReleases service
        // rejects an upload with none, so resolve/prompt for it now, before the
        // (potentially long) build, not after uploadToBackend()'s own late check.
        if ($requiresRemote && $this->usesBackendUpload() && !$this->resolveBackendChangelog()) {
            return false;
        }

        $this->printSuccess("All prerequisites met");
        return true;
    }

    /**
     * Resolves $this->backendChangelog for a backend-upload release. Called
     * from checkPrerequisites(), gated the same way as the cURL/keystore
     * checks right above it, so it only ever runs when a changelog is
     * actually going to be needed.
     *
     * An already-set value wins (a caller's own $config['backendChangelog'],
     * e.g. from a BUILD_CHANGELOG env var) -- this never prompts over an
     * explicit value. Otherwise, on a real interactive terminal, opens
     * $VISUAL/$EDITOR -- falling back to `code --wait`, then `nano` -- on a
     * git-commit-style temp file (one "TYPE: description" entry per line,
     * comments stripped) and parses it into the JSON array uploadToBackend()
     * requires. A non-interactive run (CI, no TTY) gets a clear error here
     * instead of hanging on input that will never arrive -- callers that run
     * in CI must still pass backendChangelog in via $config themselves.
     */
    private function resolveBackendChangelog(): bool
    {
        if ($this->backendChangelog) {
            return true;
        }

        if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
            $this->printError("backendChangelog is required for a backend-upload release and no interactive terminal is available to prompt for one.");
            $this->printError('Pass it in directly, e.g. BUILD_CHANGELOG=\'[{"type":"FIXED","description":"..."}]\'.');
            return false;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'deploy-changelog-') . '.txt';
        file_put_contents($tmpFile, implode("\n", [
            '',
            '# Changelog for this release -- one entry per line, saved and closed to continue.',
            '# Format:  TYPE: description',
            '# TYPE is one of: NEW, IMPROVED, FIXED, SECURITY, ISSUE',
            '#',
            '# Lines starting with # are ignored. Leaving this empty (or closing without',
            '# saving) cancels the release -- BACKEND requires at least one entry.',
            '',
        ]));

        $editor = getenv('VISUAL') ?: getenv('EDITOR');
        if (!$editor) {
            $hasCode = trim((string) shell_exec('command -v code 2>/dev/null')) !== '';
            $editor = $hasCode ? 'code --wait' : 'nano';
        }

        $this->printStep("Opening changelog in {$editor}...");
        // passthru(), not exec()/shell_exec() -- a terminal editor (vim, nano)
        // needs the child process attached to the real TTY, which
        // output-capturing functions break. $editor is used as-is (may itself
        // contain args, e.g. 'code --wait') -- only the file path is
        // shell-escaped, same convention git's own $GIT_EDITOR invocation
        // follows.
        passthru($editor . ' ' . escapeshellarg($tmpFile), $exitCode);

        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);

        if ($exitCode !== 0) {
            $this->printError("Editor exited with an error (code {$exitCode}) -- aborting release.");
            return false;
        }

        $entries = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, ':')) {
                $this->printError("Changelog line doesn't match 'TYPE: description': {$line}");
                return false;
            }
            [$type, $description] = array_map('trim', explode(':', $line, 2));
            $type = strtoupper($type);
            if (!in_array($type, ['NEW', 'IMPROVED', 'FIXED', 'SECURITY', 'ISSUE'], true)) {
                $this->printError("Unknown changelog type '{$type}' (expected NEW/IMPROVED/FIXED/SECURITY/ISSUE): {$line}");
                return false;
            }
            if ($description === '') {
                $this->printError("Changelog entry needs a description: {$line}");
                return false;
            }
            $entries[] = ['type' => $type, 'description' => $description];
        }

        if (empty($entries)) {
            $this->printError("Empty changelog -- release cancelled.");
            return false;
        }

        $this->backendChangelog = json_encode($entries);
        return true;
    }

    // ── SSH test ─────────────────────────────────────────────────────────────

    private function testSshConnection(): bool
    {
        $this->printStep("Testing SSH connection to {$this->remoteHost}...");
        $cmd = "ssh -p {$this->remotePort} -o ConnectTimeout=10 -o BatchMode=yes "
             . "{$this->remoteUser}@{$this->remoteHost} 'echo SSH OK'";
        if ($this->executeCommand($cmd)) {
            $this->printSuccess("SSH connection OK");
            return true;
        }
        $this->printError("SSH connection failed");
        return false;
    }

    // ── Build ────────────────────────────────────────────────────────────────

    /**
     * Build a signed release APK:
     *   1. npx vite build       — compile frontend assets
     *   2. native:package android — release build + keystore signing
     *
     * This NativePHP version has no unsigned-Android-debug path at all —
     * `native:build` only handles iOS; `native:package android` unconditionally
     * requires a real keystore ("Missing required signing configuration" if
     * omitted, confirmed live). $allowLocalDebugKeystore (only passed true for
     * --build-only) auto-generates a throwaway local debug keystore instead of
     * failing when no real one is configured — see ensureDebugKeystore().
     *
     * --no-interaction + < /dev/null suppress the TTY requirement when called
     * from a non-interactive PHP subprocess.
     */
    private function buildApk(bool $forceClean = false, bool $allowLocalDebugKeystore = false): bool
    {
        $this->startOperation("Building signed release APK with NativePHP");

        // Bump version in .env so the native bridge reports the correct version
        $this->updateEnvVersion();

        // Optional: refresh the native Android scaffold from the vendor template
        // before building. Not strictly required — native:package already re-applies
        // NATIVEPHP_APP_ID / version / app name from the live .env on every build via
        // its own updateAndroidConfiguration() step, and recompiles plugin native code
        // from packages/*/resources/android via compileAndroidPlugins() — but this
        // guarantees a clean scaffold matching the currently pinned nativephp/mobile
        // version. Deliberately omits -F/--force, which only forces re-download of
        // cached PHP binaries and is unrelated to the app-id/version refresh.
        if ($this->runNativeInstall) {
            if (!$this->executeCommand(
                "cd {$this->projectRoot} && php artisan native:install android",
                "Refreshing native Android scaffold (native:install)..."
            )) {
                $this->printError("native:install failed");
                return false;
            }
        }

        // Optional clean
        if ($forceClean) {
            $this->printStatus("Cleaning previous Gradle build...");
            foreach (['nativephp/android', 'android'] as $sub) {
                $androidDir = $this->projectRoot . '/' . $sub;
                if (is_dir($androidDir) && file_exists($androidDir . '/gradlew')) {
                    $this->executeCommand("cd {$androidDir} && ./gradlew clean", "Gradle clean ($sub)...");
                    break;
                }
            }
        }

        // Step 1: compile frontend assets
        // Vite defaults to --mode=production (loading .env + .env.production,
        // with .env.production taking precedence) regardless of what the live
        // .env currently holds. Pass --mode explicitly so a staging build loads
        // .env + .env.staging (matching content) instead of silently picking up
        // .env.production's VITE_* values.
        $viteMode = escapeshellarg($this->viteMode);
        if (!$this->executeCommand(
            "cd {$this->projectRoot} && npx vite build --mode {$viteMode}",
            "Building Vite assets (mode: {$this->viteMode})..."
        )) {
            $this->printError("Vite build failed");
            return false;
        }

        // Step 2: release build + keystore signing
        $keystoreFile     = $this->keystoreFile;
        $keystorePassword = $this->keystorePassword;
        $keyAlias         = $this->keyAlias;
        $keyPassword      = $this->keyPassword;

        if (!$keystoreFile) {
            if (!$allowLocalDebugKeystore) {
                $this->printError("No keystore configured. Set BUILD_KEYSTORE_FILE (and the matching password/alias/key env vars) for a real signed build — Android packaging always requires one.");
                return false;
            }
            [$keystoreFile, $keystorePassword, $keyAlias, $keyPassword] = $this->ensureDebugKeystore();
        }

        $ks     = escapeshellarg($keystoreFile);
        $ksPwd  = escapeshellarg($keystorePassword ?? '');
        $alias  = escapeshellarg($keyAlias ?? '');
        $keyPwd = escapeshellarg($keyPassword ?? '');
        $buildCmd = "cd {$this->projectRoot} && php artisan native:package android"
                  . " --no-interaction"
                  . " --keystore={$ks}"
                  . " --keystore-password={$ksPwd}"
                  . " --key-alias={$alias}"
                  . " --key-password={$keyPwd}";
        $this->printInfo("Signing with keystore: {$keystoreFile} (alias: {$keyAlias})");
        if (!$this->executeWithTty($buildCmd, "Running: php artisan native:package android")) {
            $this->printError("NativePHP package/sign failed");
            return false;
        }

        // Locate and copy the APK
        $result = $this->collectApk();
        $this->endOperation("Building signed release APK with NativePHP");
        return $result;
    }

    /**
     * Generate (or reuse) a throwaway local debug keystore so --build-only
     * can package an Android APK with zero real secrets configured. Mirrors
     * Android's own debug-keystore convention (well-known alias/passwords —
     * that's fine, it's local-only and never used for a real release).
     * Stored outside builds/ (which gets wiped by cleanBuildOutputs()) so it
     * survives across local builds instead of regenerating every time.
     *
     * @return array{0:string,1:string,2:string,3:string} [file, storePassword, alias, keyPassword]
     */
    private function ensureDebugKeystore(): array
    {
        $keystoreDir  = $this->projectRoot . '/storage/app';
        $keystorePath = $keystoreDir . '/local-debug.keystore';
        $storePass    = 'android';
        $alias        = 'androiddebugkey';
        $keyPass      = 'android';

        if (!file_exists($keystorePath)) {
            if (!is_dir($keystoreDir)) {
                mkdir($keystoreDir, 0777, true);
            }
            $this->printWarning("No keystore configured — generating a throwaway LOCAL DEBUG keystore (never use this for a real release).");
            $cmd = 'keytool -genkeypair -v'
                 . ' -keystore ' . escapeshellarg($keystorePath)
                 . ' -storepass ' . escapeshellarg($storePass)
                 . ' -alias ' . escapeshellarg($alias)
                 . ' -keypass ' . escapeshellarg($keyPass)
                 . ' -keyalg RSA -keysize 2048 -validity 10000'
                 . ' -dname ' . escapeshellarg('CN=Local Debug,O=Local,C=US');
            $this->executeCommand($cmd, "Generating local debug keystore...");
        } else {
            $this->printInfo("Reusing existing local debug keystore: $keystorePath");
        }

        return [$keystorePath, $storePass, $alias, $keyPass];
    }

    /**
     * Write the current version back into NATIVEPHP_APP_VERSION in .env.
     */
    private function updateEnvVersion(): void
    {
        $envFile = $this->projectRoot . '/.env';
        if (!file_exists($envFile)) return;

        $content = file_get_contents($envFile);
        $updated = preg_replace(
            '/^NATIVEPHP_APP_VERSION=.*/m',
            "NATIVEPHP_APP_VERSION={$this->version}",
            $content
        );

        if ($updated !== $content) {
            file_put_contents($envFile, $updated);
            $this->printSuccess("Updated NATIVEPHP_APP_VERSION={$this->version} in .env");
        }
    }

    /**
     * Find the APK produced by NativePHP and copy it to builds/.
     */
    private function collectApk(): bool
    {
        $this->printStatus("Locating built APK...");

        // Candidate paths — native:package always produces a release APK
        $candidates = [
            $this->apkOutputPath,                                             // explicit override
            $this->projectRoot . '/nativephp/android/app/build/outputs/apk/release/app-release.apk',
            $this->projectRoot . '/android/app/build/outputs/apk/release/app-release.apk',
        ];

        $sourcePath = null;
        foreach (array_filter($candidates) as $candidate) {
            if (file_exists($candidate)) {
                $sourcePath = $candidate;
                break;
            }
        }

        // Fallback: glob search — release only
        $globPaths = [
            $this->projectRoot . '/nativephp/android/app/build/outputs/apk/release/*.apk',
            $this->projectRoot . '/android/app/build/outputs/apk/release/*.apk',
        ];
        foreach ($globPaths as $pattern) {
            $found = glob($pattern);
            if (!empty($found)) { $sourcePath = $found[0]; break; }
        }

        if (!$sourcePath) {
            $this->printError("Could not find built APK. Checked standard NativePHP output paths.");
            $this->printInfo("If the APK is elsewhere, set 'apkOutputPath' in the config.");
            return false;
        }

        $this->printSuccess("Found APK: $sourcePath");

        // Ensure builds/ directory exists
        if (!is_dir($this->buildsDir)) {
            mkdir($this->buildsDir, 0777, true);
        }

        $targetName = "{$this->appName}-universal.apk";
        $targetPath = $this->buildsDir . '/' . $targetName;

        if (!copy($sourcePath, $targetPath)) {
            $this->printError("Failed to copy APK to builds/");
            return false;
        }

        $fileSize = $this->formatFileSize(filesize($targetPath));
        $this->printSuccess("Built: $targetName ($fileSize)");
        return true;
    }

    // ── APK config generation ────────────────────────────────────────────────

    /**
     * Generate apk-updates.json in builds/ (same format as agrovetapp, universal only).
     */
    private function generateApkConfig(): bool
    {
        $this->printStep("Generating apk-updates.json...");

        $apkFile = $this->buildsDir . "/{$this->appName}-universal.apk";
        if (!file_exists($apkFile)) {
            $this->printError("Universal APK not found in builds/");
            return false;
        }

        $isoDate  = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $checksum = hash_file('sha256', $apkFile);
        $fileSize = filesize($apkFile);
        $filename = "{$this->appName}-universal.apk";

        $config = [
            'latestVersion' => $this->version,
            'updateDate'    => $isoDate,
            'versions'      => [
                [
                    'version'         => $this->version,
                    'architecture'    => 'universal',
                    'apkUrl'          => "{$this->baseUrl}/{$this->appName}/assets/v{$this->version}/apks/{$filename}",
                    'checksum'        => "sha256:{$checksum}",
                    'size'            => $fileSize,
                    'changelog'       => "Version {$this->version} — Bug fixes and performance improvements",
                    'mandatory'       => false,
                    'releaseDate'     => $isoDate,
                    'buildNumber'     => str_replace('.', '', $this->version),
                    'minSdkVersion'   => 26,
                    'targetSdkVersion'=> 34,
                ],
            ],
        ];

        $configPath = $this->buildsDir . '/apk-updates.json';
        if (file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT))) {
            $this->printSuccess("Generated builds/apk-updates.json");
            return true;
        }

        $this->printError("Failed to write apk-updates.json");
        return false;
    }

    // ── Clean ────────────────────────────────────────────────────────────────

    private function cleanBuildOutputs(): void
    {
        $this->printStep("Cleaning old build outputs...");
        $cleaned = [];

        foreach (glob($this->buildsDir . '/*.apk') ?: [] as $f) {
            if (unlink($f)) $cleaned[] = basename($f);
        }
        $configFile = $this->buildsDir . '/apk-updates.json';
        if (file_exists($configFile) && unlink($configFile)) {
            $cleaned[] = 'apk-updates.json';
        }

        if ($cleaned) {
            $this->printSuccess("Cleaned " . count($cleaned) . " old file(s)");
        } else {
            $this->printInfo("No old build files to clean");
        }
    }

    // ── Remote deployment ────────────────────────────────────────────────────

    private function createRemoteDirectories(): bool
    {
        $this->printStep("Creating remote directories...");
        $versionDir = "{$this->getRemoteBaseDir()}/assets/v{$this->version}";
        $cmd = "ssh -p {$this->remotePort} {$this->remoteUser}@{$this->remoteHost} "
             . "'mkdir -p {$versionDir}/apks'";
        if ($this->executeCommand($cmd)) {
            $this->printSuccess("Remote directories ready");
            return true;
        }
        $this->printError("Failed to create remote directories");
        return false;
    }

    private function uploadWithRsync(): void
    {
        $this->printStep("Uploading files to server via rsync...");

        $versionDir = "{$this->getRemoteBaseDir()}/assets/v{$this->version}";

        // ── Universal APK ────────────────────────────────────────────────────
        $apkFile = $this->buildsDir . "/{$this->appName}-universal.apk";
        if (file_exists($apkFile)) {
            $remoteApk = "{$versionDir}/apks/{$this->appName}-universal.apk";

            // Remove stale file, then upload
            $this->executeCommand(
                "ssh -p {$this->remotePort} {$this->remoteUser}@{$this->remoteHost} 'rm -f {$remoteApk}'",
                "Removing old APK from server..."
            );
            $size = $this->formatFileSize(filesize($apkFile));
            $this->executeCommand(
                "rsync -rvz --progress -I -e 'ssh -p {$this->remotePort}' \"{$apkFile}\" {$this->remoteUser}@{$this->remoteHost}:{$remoteApk}",
                "Uploading {$this->appName}-universal.apk ({$size})..."
            ) ? $this->printSuccess("APK uploaded")
              : $this->printError("APK upload failed");
        }

        // ── apk-updates.json ─────────────────────────────────────────────────
        $configFile = $this->buildsDir . '/apk-updates.json';
        if (file_exists($configFile)) {
            $remoteConfig = "{$this->getRemoteBaseDir()}/apk-updates.json";
            $this->executeCommand(
                "ssh -p {$this->remotePort} {$this->remoteUser}@{$this->remoteHost} 'rm -f {$remoteConfig}'",
                "Removing old apk-updates.json..."
            );
            $this->executeCommand(
                "rsync -rvz --progress -I -e 'ssh -p {$this->remotePort}' \"{$configFile}\" {$this->remoteUser}@{$this->remoteHost}:{$remoteConfig}",
                "Uploading apk-updates.json..."
            ) ? $this->printSuccess("apk-updates.json uploaded")
              : $this->printError("apk-updates.json upload failed");
        }

        // ── Downloader APK (root-level convenience copy via server-side cp) ─────
        // The versioned APK is already on the server — no second upload needed.
        $downloaderRemote = "{$this->getRemoteBaseDir()}/{$this->downloaderApkName}.apk";
        if (file_exists($apkFile)) {
            $remoteApkPath = "{$versionDir}/apks/{$this->appName}-universal.apk";
            $this->executeCommand(
                "ssh -p {$this->remotePort} {$this->remoteUser}@{$this->remoteHost} "
                . "'cp {$remoteApkPath} {$downloaderRemote}'",
                "Copying {$this->appName}-universal.apk → {$this->downloaderApkName}.apk on server..."
            ) ? $this->printSuccess("{$this->downloaderApkName}.apk ready")
              : $this->printWarning("{$this->downloaderApkName}.apk copy failed");
        }
    }

    /**
     * POST the built APK to a BACKEND's MobileReleases API — creating a new
     * release (POST {backendUrl}/api/mobile-releases/create) if this version
     * doesn't exist there yet, or replacing the APK on the EXISTING row in
     * place (PUT {backendUrl}/api/mobile-releases/{uuid}/edit, via Laravel's
     * standard _method-spoofing convention — the same technique
     * MobileReleasesEditForm.vue already uses for this exact endpoint) if it
     * does. Chosen over SSH/rsync so releases can be hosted with no SSH
     * server required. Requires a Sanctum bearer token for a user with both
     * the MobileReleases.create AND MobileReleases.edit permissions
     * (generate one via `php artisan tinker` on the BACKEND:
     * $user->createToken('mobile-deploy')->plainTextToken).
     *
     * The create-or-replace check exists so a release caught bad within
     * minutes of publishing can be fixed by re-running the exact same
     * command, instead of every failed attempt leaving a permanent "dirty"
     * row someone has to clean up by hand — matching the old rsync-based
     * workflow's "just overwrite the file" mental model. This is ONLY safe
     * for a release nobody has installed yet: a device that already
     * downloaded the bad APK is on that version_code as far as its own
     * update-check call is concerned, and won't be re-prompted just because
     * the file behind it changed server-side. It is not a rollback mechanism
     * for a release that's been out for days.
     *
     * version_code comes from .env's NATIVEPHP_APP_VERSION_CODE (BACKEND
     * requires it, unique per release) — bump it before every real NEW
     * release, same as NATIVEPHP_APP_VERSION_CODE's own existing "must
     * increase with every store release" convention. Only leave it unchanged
     * when deliberately replacing THIS SAME version's bad build.
     */
    private function uploadToBackend(): bool
    {
        $apkFile = $this->buildsDir . "/{$this->appName}-universal.apk";
        if (!file_exists($apkFile)) {
            $this->printError("APK not found in builds/ — run a build first.");
            return false;
        }

        if (!$this->backendToken) {
            $this->printError("No backendToken configured — required to authenticate against BACKEND's MobileReleases.create/.edit endpoints.");
            return false;
        }

        $versionCode = $this->readApkVersionCode($apkFile) ?? $this->readEnvVersionCode();
        if ($versionCode === null) {
            $this->printError("Could not determine the APK's version code (aapt dump badging failed and NATIVEPHP_APP_VERSION_CODE isn't in .env either) — BACKEND requires it.");
            return false;
        }

        // BUILD_CHANGELOG must be a JSON array of {type, description} rows
        // (type one of NEW/IMPROVED/FIXED/SECURITY/ISSUE) -- BACKEND's
        // MobileReleasesCreateService/EditService now REQUIRE at least one
        // changelog entry on every release (a release can't ship without
        // saying what changed), so failing fast here beats building a whole
        // APK only to have the upload rejected with a 422 at the very end.
        // Passed straight through as a JSON string, not re-encoded -- matches
        // the admin form's own multipart encoding (see
        // MobileReleasesCreateForm.vue), which BACKEND json_decode()s.
        $changelogEntries = json_decode((string) $this->backendChangelog, true);
        if (!is_array($changelogEntries) || empty($changelogEntries)) {
            $this->printError(
                "BUILD_CHANGELOG must be a JSON array of {type, description} entries, e.g. " .
                '\'[{"type":"NEW","description":"..."}]\' -- got: ' . var_export($this->backendChangelog, true)
            );
            return false;
        }

        $existingUuid = $this->findExistingReleaseUuid($this->version);

        $fields = [
            'version'           => $this->version,
            'version_code'      => $versionCode,
            'is_active'         => '1',
            'is_mandatory'      => $this->backendMandatory ? '1' : '0',
            'apk_file'          => new CURLFile($apkFile, 'application/vnd.android.package-archive', basename($apkFile)),
            'changelog_entries' => $this->backendChangelog,
        ];

        if ($existingUuid) {
            $this->printStep("Found existing release v{$this->version} on BACKEND — replacing its APK in place ({$this->backendUrl})...");
            $fields['_method'] = 'PUT';
            $url = rtrim($this->backendUrl, '/') . "/api/mobile-releases/{$existingUuid}/edit";
        } else {
            $this->printStep("Publishing new release to BACKEND ({$this->backendUrl})...");
            $url = rtrim($this->backendUrl, '/') . '/api/mobile-releases/create';
        }

        $apkSizeMb = filesize($apkFile) / 1_048_576;
        $this->printInfo(sprintf('Uploading %.1f MB — this can take a minute on a slow connection.', $apkSizeMb));

        $result = $this->performMultipartUpload($url, $fields);

        if ($result['success']) {
            $verb = $existingUuid ? 'replaced in place' : 'published';
            $this->printSuccess("Release {$verb} on BACKEND: v{$this->version} (code {$versionCode})");
            return true;
        }

        $this->printError("BACKEND rejected the release (HTTP {$result['httpCode']}): {$result['message']}");
        foreach ($result['fieldErrors'] as $field => $errs) {
            $this->printError("  $field: " . (is_array($errs) ? implode(', ', $errs) : $errs));
        }
        return false;
    }

    /**
     * Looks up an existing MobileReleases row by exact version string via the
     * standard generated list endpoint's filter support (GET
     * .../mobile-releases/list?filter[version]=X.Y.Z) — returns its uuid, or
     * null if no release with this version exists yet (including on any
     * lookup failure, which deliberately falls back to "create": the safer
     * of the two wrong guesses is a rejected duplicate-version error, not a
     * silent overwrite of the wrong release). Used by uploadToBackend() to
     * decide create vs. replace-in-place.
     */
    private function findExistingReleaseUuid(string $version): ?string
    {
        $url = rtrim($this->backendUrl, '/') . '/api/mobile-releases/list?' . http_build_query([
            'filter' => ['version' => $version],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->backendToken,
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $decoded = json_decode($response, true);
        $rows    = $decoded['data']['data'] ?? [];

        foreach ($rows as $row) {
            if (($row['version'] ?? null) === $version) {
                return $row['uuid'] ?? null;
            }
        }

        return null;
    }

    /**
     * Shared multipart POST + upload-progress-bar logic behind both the
     * create and replace-in-place paths in uploadToBackend() — see that
     * method's own docblock for why an upload this large needs a progress
     * indicator, and why create vs. replace share this instead of each
     * having their own copy.
     *
     * @return array{success: bool, httpCode: int, message: string, fieldErrors: array}
     */
    private function performMultipartUpload(string $url, array $fields): array
    {
        // Without a progress callback, curl_exec() below blocks silently for the
        // entire upload — for a 40+ MB APK on a slow link that's long enough to
        // read as a hang, not "still working" (confirmed live: a real upload
        // looked stuck with no output at all until it either finished or timed
        // out). $lastPct is captured by reference purely to throttle printing —
        // XFERINFOFUNCTION fires far more often than the percentage actually
        // changes, and reprinting the same "42%" hundreds of times is as
        // unhelpful as printing nothing.
        $lastPct = -1;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->backendToken,
                'Accept: application/json',
            ],
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => function ($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use (&$lastPct) {
                if ($uploadTotal <= 0) {
                    return 0;
                }
                $pct = (int) floor(($uploaded / $uploadTotal) * 100);
                if ($pct === $lastPct) {
                    return 0;
                }
                $lastPct = $pct;
                $barWidth = 30;
                $filled   = (int) round($barWidth * $pct / 100);
                $bar      = str_repeat('#', $filled) . str_repeat('-', $barWidth - $filled);
                fwrite(STDOUT, sprintf("\r  [%s] %3d%% (%.1f / %.1f MB)", $bar, $pct, $uploaded / 1_048_576, $uploadTotal / 1_048_576));
                if ($pct >= 100) {
                    fwrite(STDOUT, "\n");
                }
                return 0;
            },
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // curl_close() is a deprecated no-op since PHP 8 (CurlHandle is GC'd automatically).

        if ($lastPct >= 0 && $lastPct < 100) {
            fwrite(STDOUT, "\n"); // the bar never reached 100% (early failure/timeout) -- don't leave the cursor mid-line
        }

        if ($response === false) {
            return ['success' => false, 'httpCode' => 0, 'message' => "Upload failed: $curlError", 'fieldErrors' => []];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['status'])) {
            return ['success' => true, 'httpCode' => $httpCode, 'message' => '', 'fieldErrors' => []];
        }

        // BACKEND's own real error shapes: Helpers::error()/validationError()
        // ({status,code,message,...}) or Laravel's raw ValidationException
        // ({message, errors}) — both put the message and any field errors at
        // the top level, so this handles either without guessing which one hit.
        $fieldErrors = (!empty($decoded['errors']) && is_array($decoded['errors'])) ? $decoded['errors'] : [];
        return [
            'success'     => false,
            'httpCode'    => $httpCode,
            'message'     => $decoded['message'] ?? $response,
            'fieldErrors' => $fieldErrors,
        ];
    }

    // ── Deploy-only mode ─────────────────────────────────────────────────────

    private function deployOnly(): bool
    {
        $this->printStep("Deploy-only: uploading existing build files...");

        $apkFile    = $this->buildsDir . "/{$this->appName}-universal.apk";
        $configFile = $this->buildsDir . '/apk-updates.json';

        if (!file_exists($apkFile) && !file_exists($configFile)) {
            $this->printError("No build files found in builds/ — run a build first.");
            return false;
        }

        if (file_exists($apkFile)) {
            $this->printInfo("APK: " . $this->formatFileSize(filesize($apkFile)));
        }
        $this->printInfo("Config: " . (file_exists($configFile) ? 'found' : 'missing'));

        if ($this->usesBackendUpload()) {
            return $this->uploadToBackend();
        }

        $this->createRemoteDirectories();
        $this->uploadWithRsync();
        return true;
    }

    // ── Summary ──────────────────────────────────────────────────────────────

    /**
     * $deployed = false for a --build-only run: reports the real local
     * builds/ output path instead of remote-server paths that were never
     * touched (previously this always printed remote paths, even when
     * nothing was uploaded — misleading for a local-only build).
     */
    private function showSummary(bool $deployed = true): void
    {
        $total = microtime(true) - $this->startTime;
        echo "\n";
        $this->printSuccess($deployed
            ? "🎉 Build and deploy completed — v{$this->version}"
            : "🎉 Local build completed — v{$this->version}");
        echo "\n";
        echo "📊 Summary:\n";
        if ($deployed && $this->usesBackendUpload()) {
            echo "   📱 APK:    published to {$this->backendUrl} (v{$this->version})\n";
            echo "   🔧 Check:  {$this->backendUrl}/api/mobile/ota/check\n";
        } elseif ($deployed) {
            echo "   📱 APK:    {$this->getRemoteBaseDir()}/assets/v{$this->version}/apks/\n";
            echo "   🔧 Config: {$this->getRemoteBaseDir()}/apk-updates.json\n";
            echo "   ⬇️  Direct: {$this->baseUrl}/{$this->appName}/{$this->downloaderApkName}.apk\n";
        } else {
            echo "   📱 APK:    {$this->buildsDir}/{$this->appName}-universal.apk\n";
            echo "   🔧 Config: {$this->buildsDir}/apk-updates.json\n";
        }
        echo "   ⏱️  Time:   {$this->formatDuration($total)}\n\n";
        if ($deployed && $this->usesBackendUpload()) {
            echo "📡 Update check URL:\n";
            echo "   {$this->backendUrl}/api/mobile/ota/check?version=<installed>\n\n";
        } elseif ($deployed) {
            echo "📡 Update check URL:\n";
            echo "   {$this->baseUrl}/apk-updates.php?app={$this->appName}&type=apk&architecture=universal\n\n";
        }
    }

    // ── Help ─────────────────────────────────────────────────────────────────

    private function showUsage(): void
    {
        echo "NativePHP Deployment — Build and Deploy Script\n";
        echo "===============================================\n\n";
        echo "Usage: php scripts/buildAndDeploy.php [OPTIONS]\n\n";
        echo "Modes:\n";
        echo "  (default)        Build APK + generate config + deploy\n";
        echo "  --deploy-only    Upload existing builds/ files without rebuilding\n";
        echo "  --build-only     Local build only — no SSH/rsync/keystore/remote config\n";
        echo "                   required. APK + config land in this project's own\n";
        echo "                   builds/ directory. Tolerates a missing/DEBUG\n";
        echo "                   NATIVEPHP_APP_VERSION by synthesizing a dev version.\n";
        echo "\nDeploy target:\n";
        echo "  SSH/rsync (default) — set remoteHost/remoteUser/remoteBaseDir/baseUrl.\n";
        echo "  BACKEND upload (opt-in, no SSH) — set backendUrl + backendToken (a\n";
        echo "  Sanctum token with MobileReleases.create) to POST the built APK to a\n";
        echo "  BACKEND's MobileReleases API instead — for LAN hosting.\n";
        echo "\nOptions:\n";
        echo "  --version=X.X.X  Override version (default: reads from .env)\n";
        echo "  --force-clean    Run Gradle clean before building\n";
        echo "  --help           Show this message\n\n";
        echo "Examples:\n";
        echo "  php scripts/buildAndDeploy.php                      # Full build + deploy\n";
        echo "  php scripts/buildAndDeploy.php --build-only         # Build only\n";
        echo "  php scripts/buildAndDeploy.php --deploy-only        # Deploy existing build\n";
        echo "  php scripts/buildAndDeploy.php --version=1.0.1      # Pin version\n";
        echo "  php scripts/buildAndDeploy.php --force-clean        # Clean build\n\n";
    }

    // ── Entry point ──────────────────────────────────────────────────────────

    public function run(array $args = []): bool
    {
        $deployOnly  = false;
        $buildOnly   = false;
        $forceClean  = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--version=')) {
                $this->version = substr($arg, 10);
            } elseif ($arg === '--deploy-only') {
                $deployOnly = true;
            } elseif ($arg === '--build-only') {
                $buildOnly = true;
            } elseif ($arg === '--force-clean') {
                $forceClean = true;
            } elseif ($arg === '--help') {
                $this->showUsage();
                return true;
            }
        }

        echo "🚀 NativePHP Deployment\n";
        echo "=======================\n\n";

        // --build-only needs none of the remote/SSH/rsync machinery — it's a
        // local build that lands in this project's own builds/ directory.
        $requiresRemote = !$buildOnly;

        // Resolve version (build-only tolerates no real version being set yet —
        // see getAppVersion()'s docblock)
        if (empty($this->version)) {
            $this->version = $this->getAppVersion($buildOnly) ?? '';
            if (empty($this->version)) return false;
        }

        $this->printInfo("App:     {$this->appName}");
        $this->printInfo("Version: {$this->version}");
        if ($requiresRemote) {
            if ($this->usesBackendUpload()) {
                $this->printInfo("Backend: {$this->backendUrl}");
            } else {
                $this->printInfo("Remote:  {$this->remoteUser}@{$this->remoteHost}:{$this->remotePort}");
            }
        }

        // Check prerequisites
        if (!$this->checkPrerequisites($requiresRemote)) return false;

        // Deploy-only shortcut
        if ($deployOnly) {
            $ok = $this->deployOnly();
            if ($ok) $this->showSummary(true);
            return $ok;
        }

        // Clean old outputs
        $this->cleanBuildOutputs();

        // Build
        if (!$this->buildApk($forceClean, $buildOnly)) return false;

        // Generate apk-updates.json
        if (!$this->generateApkConfig()) return false;

        // Deploy (unless build-only)
        if (!$buildOnly) {
            if ($this->usesBackendUpload()) {
                if (!$this->uploadToBackend()) return false;
            } else {
                $this->createRemoteDirectories();
                $this->uploadWithRsync();
            }
        } else {
            $this->printInfo("--build-only: skipping upload — build is in " . $this->buildsDir);
        }

        $this->showSummary($requiresRemote);
        return true;
    }
}
