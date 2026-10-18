<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use RuntimeException;
use Throwable;

/**
 * Persists changed settings back to var/config.php.
 *
 * The browser installer writes this file once; from then on the admin panel
 * (the mail wizard) edits it. Writes are atomic (a temp file in the same
 * directory, tightened to 0600, then renamed into place) so a reader — the
 * front controller on the next request — never sees a half-written file. This
 * mirrors the installer's own writer; the technique is shared, kept here as the
 * one place ongoing config edits go through.
 *
 * A save merges the given changes over whatever the file already holds, so keys
 * the wizard does not touch (APP_SECRET, the database settings) are preserved
 * untouched. Environment variables still OVERRIDE every file value at load time
 * (see Config::load()); this writer only ever changes the file layer.
 */
final class ConfigWriter
{
    public function __construct(private string $configPath)
    {
    }

    /**
     * The raw values currently stored in the config file (before defaults and
     * before any environment overrides). Empty when the file does not yet
     * exist. Used both to preserve untouched keys on save and to detect when an
     * environment variable is shadowing a stored value.
     *
     * @return array<string, string>
     */
    public function currentFileValues(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        /** @psalm-suppress UnresolvableInclude */
        $values = require $this->configPath;
        if (!is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * A single stored file value, or null when absent. Used for the
     * "blank field keeps the existing secret" convenience (SMTP password),
     * which must preserve the stored file value rather than any environment
     * override.
     */
    public function fileValue(string $key): ?string
    {
        $values = $this->currentFileValues();

        return array_key_exists($key, $values) ? $values[$key] : null;
    }

    /**
     * Merge $changes over the stored values and write the file atomically.
     *
     * @param array<string, string> $changes
     *
     * @throws RuntimeException when the file cannot be written.
     */
    public function save(array $changes): void
    {
        $values = $this->currentFileValues();
        foreach ($changes as $key => $value) {
            $values[$key] = (string) $value;
        }

        $this->atomicWrite($this->configPath, $this->render($values));
    }

    // --- Rendering --------------------------------------------------------

    /**
     * @param array<string, string> $values
     */
    private function render(array $values): string
    {
        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= '    ' . var_export((string) $key, true)
                . ' => ' . var_export((string) $value, true) . ",\n";
        }

        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "/*\n"
            . " * openSendForm configuration.\n"
            . " *\n"
            . " * Written by the browser installer and updated by the admin panel (Email\n"
            . " * settings). It lives under var/ (outside the web root) and is never served,\n"
            . " * but treat it as private regardless.\n"
            . " *\n"
            . " * Environment variables OVERRIDE every value here (see Config::load()). To\n"
            . " * change a value on a normal install, edit it below or use the admin panel.\n"
            . " */\n\n"
            . "return [\n"
            . $lines
            . "];\n";
    }

    /**
     * Write a file atomically: a temp file in the same directory, permissions
     * tightened to 0600 where the host honours it, then renamed into place.
     *
     * @throws RuntimeException on any failure to write.
     */
    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('The configuration folder is not writable.');
        }

        try {
            $tmp = @tempnam($dir, '.osf-');
            // tempnam falls back to the system temp dir on failure; reject
            // anything not landing in our target directory so rename stays atomic.
            if ($tmp === false || dirname($tmp) !== rtrim($dir, '/')) {
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
                throw new RuntimeException('Could not create a temporary file next to the configuration.');
            }

            if (@file_put_contents($tmp, $content) === false) {
                @unlink($tmp);
                throw new RuntimeException('Could not write the configuration file.');
            }

            @chmod($tmp, 0600);

            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Could not save the configuration file.');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Could not save the configuration file.');
        }
    }
}
