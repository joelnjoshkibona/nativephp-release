<?php

namespace Blutrixx\NativeRelease;

final class Cli
{
    public static function run(string $projectRoot, array $argv): int
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        set_time_limit(0);

        $args = array_slice($argv, 1);

        // The environment label only selects which Vite --mode to build with
        // (matching the already-swapped .env.<environment> file) — it's not tied to
        // any hardcoded per-environment secrets table; use the BUILD_* env vars
        // below for those instead.
        $environment = 'production';
        if (isset($args[0]) && !str_starts_with($args[0], '--')) {
            $environment = array_shift($args);
        }

        $appName = getenv('BUILD_APP_NAME') ?: 'mobile-app';

        $config = [
            // Laravel / NativePHP project root (where artisan lives)
            'projectRoot'        => $projectRoot,

            // Refresh the native Android scaffold before each build.
            'runNativeInstall'   => true,

            'viteMode'           => getenv('BUILD_VITE_MODE') ?: $environment,

            'appName'            => $appName,
            'downloaderApkName'  => getenv('BUILD_DOWNLOADER_APK_NAME') ?: $appName,

            // Signing — unset (null) uses --build-only's auto-generated local debug
            // keystore (Android packaging always requires *some* keystore; there's no
            // unsigned path). Set these via env vars for a real signed release; never
            // hardcode them here.
            'keystoreFile'       => getenv('BUILD_KEYSTORE_FILE') ?: null,
            'keystorePassword'   => getenv('BUILD_KEYSTORE_PASSWORD') ?: null,
            'keyAlias'           => getenv('BUILD_KEY_ALIAS') ?: null,
            'keyPassword'        => getenv('BUILD_KEY_PASSWORD') ?: null,

            // Remote deploy target (SSH/rsync) — deliberately obvious placeholders.
            // Ignored entirely when BUILD_BACKEND_URL is set below. --build-only never
            // reads these; the default mode and --deploy-only will fail loudly if you
            // don't set the env vars first, rather than silently deploying somewhere
            // unintended.
            'remoteHost'         => getenv('BUILD_REMOTE_HOST') ?: 'CHANGEME.example.com',
            'remoteUser'         => getenv('BUILD_REMOTE_USER') ?: 'CHANGEME',
            'remotePort'         => (int) (getenv('BUILD_REMOTE_PORT') ?: 22),
            'remoteBaseDir'      => getenv('BUILD_REMOTE_BASE_DIR') ?: '/CHANGEME/apps/',
            'baseUrl'            => getenv('BUILD_BASE_URL') ?: 'https://CHANGEME.example.com/apps',

            // Alternative deploy target: POST the built APK to a BACKEND's MobileReleases
            // API instead of SSH/rsync — for hosting on the local network with no SSH
            // server required. Set BUILD_BACKEND_URL (e.g. http://192.168.1.50:8000)
            // to switch modes; the remote* keys above are then ignored. BUILD_BACKEND_TOKEN
            // is a Sanctum token for a user with the MobileReleases.list, .create and .edit
            // permissions — generate one on the BACKEND via `php artisan tinker`:
            //   $user->createToken('mobile-deploy')->plainTextToken
            'backendUrl'         => getenv('BUILD_BACKEND_URL') ?: null,
            'backendToken'       => getenv('BUILD_BACKEND_TOKEN') ?: null,
            // Per-release content, not a static secret -- BUILD_CHANGELOG wins if
            // set (CI, or exporting it inline); left null otherwise is fine, since
            // NativePHPDeployment::checkPrerequisites() prompts for one
            // interactively (git-commit-editor style) before the build starts.
            'backendChangelog'   => getenv('BUILD_CHANGELOG') ?: null,
            'backendMandatory'   => filter_var(getenv('BUILD_MANDATORY') ?: false, FILTER_VALIDATE_BOOLEAN),

            // APK output path (null = auto-detect: nativephp/android/app/build/outputs/apk/release/)
            'apkOutputPath'      => null,
        ];

        return (new NativePHPDeployment($config))->run($args) ? 0 : 1;
    }
}
