#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/build-release.php — assemble the distributable release zip.
 *
 * Runs in the DEV CONTAINER (or CI), never in production. It:
 *   1. Exports a clean copy of HEAD with `git archive` (only committed,
 *      tracked files — no working-tree cruft, no .git).
 *   2. Runs `composer install --no-dev --optimize-autoloader` in the copy so
 *      the production dependencies are vendored inside the artefact.
 *   3. Prunes the exclusion list (tests, CI/dev config, state files, …).
 *   4. Writes INSTALL.txt and vendor/.htaccess (vendor/ is not tracked, so its
 *      deny rule can't be committed and is written here instead).
 *   5. Zips everything under a single top-level opensendform/ folder to
 *      dist/opensendform-v{VERSION}.zip, where VERSION is read from
 *      src/Version.php — the one place the version is defined.
 *
 * The exclusion list below is the authoritative build-side copy;
 * bin/verify-release.php keeps its own copy and a PHPUnit test asserts the two
 * never drift apart.
 */

require_once __DIR__ . '/release_lib.php';

/**
 * Paths (relative to the export root) pruned from the release. git archive only
 * ever includes tracked files, so this is really about dropping the tracked-but-
 * dev-only files: the test suite, CI/dev-container/editor config, the project
 * state files the architect reads, and the PHPUnit config. dev-router.php is
 * deliberately NOT here — it ships (inert in production).
 *
 * @return array<int, string>
 */
function osf_build_exclusions(): array
{
    return [
        'tests',
        '.devcontainer',
        '.github',
        '.claude',
        '.git',
        '.gitignore',
        '.gitattributes',
        '.phpunit.cache',
        'phpunit.xml',
        'CLAUDE.md',
        'CONTEXT.md',
        'HISTORY.md',
        'QUESTIONS.md',
        // Dev-only Node manifest for the tests/browser/ pixel checks. Production
        // has no Node/build step, so these have no place in the release zip.
        'package.json',
        'package-lock.json',
        'node_modules',
        // The build tooling itself is dev-only (needs git + composer) and has
        // no purpose inside a shipped release, so it prunes itself out.
        'bin/build-release.php',
        'bin/verify-release.php',
        'bin/release_lib.php',
    ];
}

/**
 * The short, human-facing install note dropped at the root of the zip. It
 * intentionally stays brief and points at the full docs (README / the docs
 * site); the detailed cPanel walk-through lives there.
 */
function osf_build_install_txt(string $version): string
{
    return <<<TXT
    OpenSendForm {$version}
    =========================

    Free, self-hostable form-to-email for shared cPanel/PHP hosting.

    INSTALL (first time)
    --------------------
    1. Upload this zip to your host and extract it. You get one folder,
       "opensendform/". Its contents are what you deploy.
    2. Point your domain or subdomain's document root at the "public/" folder.
       If your host will not let you move the document root, upload the whole
       "opensendform/" folder into the web root instead — the bundled .htaccess
       files keep the non-public folders private on Apache hosts.
    3. Visit your site in a browser. Every page redirects to /install until
       setup is finished. Follow the wizard (hosting check, database,
       administrator account, finish).
    4. Sign in at /admin/login. Set up email delivery from the admin panel.

    UPGRADE (already installed)
    ---------------------------
    1. Download the new zip and extract it.
    2. Copy the new files over your existing installation, REPLACING everything
       EXCEPT the "var/" folder. Your config, database and install lock live in
       var/ and must be kept.
    3. Run "php bin/osf migrate" (or open the admin dashboard and follow the
       "update required" banner) to apply any new database migrations.

    What survives an upgrade: everything in var/ (var/config.php, the SQLite
    database under var/data/, var/install.lock). Everything else is replaced.

    Full documentation: see README.md, or https://github.com/joel-marks/OpenSendForm

    TXT;
}

/**
 * The deny-all .htaccess written into vendor/. vendor/ is created by composer
 * at build time and is not tracked in git, so unlike the other server-side
 * directories this rule cannot be committed — it is generated here.
 */
function osf_build_vendor_htaccess(): string
{
    return <<<TXT
    # Block all web access to this directory (fallback layout only).
    #
    # vendor/ holds third-party PHP libraries and must never be served over
    # HTTP. Under the recommended layout (document root at public/) it already
    # sits outside the web root; this file is the safety net for hosts where the
    # whole opensendform/ folder is the document root.

    # Apache 2.4 and newer: deny every request.
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>

    # Apache 2.2 and older: same effect, older directive syntax.
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>

    TXT;
}

function osf_build_main(): int
{
    $projectRoot = dirname(__DIR__);
    $version = osf_release_version($projectRoot . '/src/Version.php');
    $folder = 'opensendform';

    $distDir = $projectRoot . '/dist';
    $zipPath = $distDir . '/opensendform-v' . $version . '.zip';

    // A unique-enough temp workspace without Date/random (unavailable): the PID.
    $work = sys_get_temp_dir() . '/osf-build-' . getmypid();
    osf_rrmdir($work);
    if (!mkdir($work, 0775, true) && !is_dir($work)) {
        fwrite(STDERR, "Cannot create work dir: {$work}\n");
        return 1;
    }
    $exportRoot = $work . '/' . $folder;

    try {
        fwrite(STDOUT, "Building OpenSendForm v{$version}\n");

        // 1. Clean export of committed HEAD via git archive piped into tar.
        fwrite(STDOUT, "  - git archive HEAD -> {$exportRoot}\n");
        mkdir($exportRoot, 0775, true);
        // `git archive HEAD | tar -x -C export` — done as two steps so a
        // failure in either half is reported clearly.
        $tar = $work . '/export.tar';
        osf_run(['git', 'archive', '--format=tar', '-o', $tar, 'HEAD'], $projectRoot);
        osf_run(['tar', '-xf', $tar, '-C', $exportRoot]);
        unlink($tar);

        // 2. Production dependencies vendored into the export.
        fwrite(STDOUT, "  - composer install --no-dev --optimize-autoloader\n");
        osf_run([
            'composer', 'install', '--no-dev', '--optimize-autoloader',
            '--no-interaction', '--no-progress',
        ], $exportRoot);

        // 3. Prune the exclusion list.
        fwrite(STDOUT, "  - pruning dev-only paths\n");
        foreach (osf_build_exclusions() as $rel) {
            $target = $exportRoot . '/' . $rel;
            if (file_exists($target)) {
                osf_rrmdir($target);
            }
        }

        // 4. Generated files: INSTALL.txt and vendor/.htaccess.
        fwrite(STDOUT, "  - writing INSTALL.txt and vendor/.htaccess\n");
        file_put_contents($exportRoot . '/INSTALL.txt', osf_build_install_txt($version));
        file_put_contents($exportRoot . '/vendor/.htaccess', osf_build_vendor_htaccess());

        // 5. Zip it up under dist/.
        if (!is_dir($distDir) && !mkdir($distDir, 0775, true) && !is_dir($distDir)) {
            throw new RuntimeException("Cannot create dist dir: {$distDir}");
        }
        fwrite(STDOUT, "  - zipping -> {$zipPath}\n");
        // Stamp the shared mode policy into the archive: dirs 755, files 644,
        // bin/osf 755. verify-release asserts these exact modes.
        osf_zip_dir($work, $folder, $zipPath, 'osf_release_mode');
    } catch (Throwable $e) {
        fwrite(STDERR, 'Build failed: ' . $e->getMessage() . "\n");
        osf_rrmdir($work);
        return 1;
    }

    osf_rrmdir($work);

    $size = is_file($zipPath) ? filesize($zipPath) : 0;
    fwrite(STDOUT, sprintf("Built %s (%d bytes)\n", $zipPath, (int) $size));

    return 0;
}

// Only run the build when invoked directly (php bin/build-release.php). When
// this file is require()d — e.g. by the manifest-sync test — $argv[0] is the
// including script, so the guard is false and only the functions above load.
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    exit(osf_build_main());
}
