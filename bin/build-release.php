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
 * The human-facing install note dropped at the root of the zip. It prescribes
 * the friction-free order (upload + extract FIRST, then point the document root
 * at public/) and teaches how to DISCOVER paths rather than hard-coding any —
 * shared hosts put sites in different places. The fuller walk-through lives in
 * the README / the docs site.
 */
function osf_build_install_txt(string $version): string
{
    return <<<TXT
    OpenSendForm {$version}
    =========================

    Free, self-hostable form-to-email for shared cPanel/PHP hosting.

    INSTALL (first time)
    --------------------
    1. UPLOAD & EXTRACT FIRST. Upload this zip to your hosting account and
       extract it there (cPanel: File Manager -> Upload, then Extract). You get
       one folder, "opensendform/", containing "bin", "public", "src", "var"
       and others. Do this before touching any domain settings.

    2. FIND WHERE IT LANDED. You do not need to know absolute paths — look in
       File Manager for the "opensendform" folder you just extracted (it is the
       folder that contains both "bin" and "public"). If you are unsure where
       your host keeps sites, cPanel's own Cron Jobs page shows an example path
       to your home directory.

    3. FIX PERMISSIONS. Some hosts extract zips with the wrong permissions,
       which can leave the app unable to run or, worse, expose files. From
       inside the "opensendform" folder (File Manager's Terminal, or SSH), run:

           find . -type d -exec chmod 755 {} \\;
           find . -type f -exec chmod 644 {} \\;
           chmod 755 bin/osf

       This sets directories to 755, files to 644 and the command-line tool
       bin/osf to 755 — the safe modes the app expects on shared hosting.

    4. POINT THE DOCUMENT ROOT AT public/. Two supported layouts:

       a) Dedicated document root (RECOMMENDED). In cPanel -> Domains (or
          "Addon/Subdomains"), set the domain's Document Root to the "public"
          folder INSIDE the extracted folder (e.g. .../opensendform/public).
          Only public/ is ever web-served; everything else stays private.

       b) Shared public_html. If you cannot change the document root, move the
          CONTENTS of "opensendform/" into public_html so that public_html
          holds "bin", "public", "src", "var", etc. The bundled .htaccess files
          then keep the non-public folders private on Apache hosts.

    5. VERIFY HTTPS. Make sure the domain has a valid certificate before you
       finish setup (cPanel -> SSL/TLS Status, or "AutoSSL" — run it and wait
       for the padlock). OpenSendForm's sessions and tokens are safest over
       HTTPS.

    6. RUN THE WIZARD. Visit the site in a browser; every page redirects to
       /install until setup is done. Follow it (hosting check, database,
       administrator account, email sending, finish), then sign in at
       /admin/login. The final screen gives you the two cron commands to add.

    A note on testing email: a test message sent to a mailbox ON THE SAME
    SERVER can bypass the SPF/DKIM/DMARC checks that real recipients apply, so
    it can look fine even when delivery to the outside world is not. For a true
    test, send to an EXTERNAL mailbox (e.g. a Gmail/Outlook address).

    UPGRADE (already installed)
    ---------------------------
    1. Download the new zip and extract it.
    2. Replace ALL of your installation's files with the new ones EXCEPT the
       "var/" folder. Your config, database and install lock live in var/ and
       must be kept. (Re-apply the permission commands from step 3 if your host
       reset them on upload.)
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
