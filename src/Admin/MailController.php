<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Auth\SessionInterface;
use OpenSendForm\Config;
use OpenSendForm\Install\ConfigWriter;
use OpenSendForm\Install\CronCommands;
use OpenSendForm\Mail\MailerInterface;
use OpenSendForm\Mail\MailSettingsForm;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * The mail-setup wizard, living in the admin panel at /admin/mail (the browser
 * installer is locked by the time anyone reaches here). It does three things on
 * one re-enterable page:
 *
 *   1. Edit the SMTP settings and the From identity, persisted to var/config.php
 *      via the atomic ConfigWriter. The password is write-only (blank keeps the
 *      stored one, same rule as the Turnstile secret), and when an environment
 *      variable is shadowing a stored value a notice names the setting.
 *   2. Send a test email through the currently SAVED settings; a success offers
 *      a one-click "turn sending on" when it is still off.
 *   3. Check the sending domain's SPF / DKIM / DMARC records and, for anything
 *      missing, show the exact record to add and where to add it.
 *
 * Audience is non-technical, so every concept gets one plain sentence and every
 * value is copy-paste ready.
 */
final class MailController
{
    /** Session flag: a test send just succeeded while sending was off. */
    private const S_CAN_ENABLE = 'mail.can_enable';

    /** Config keys the wizard owns, paired with the label shown in the shadow notice. */
    private const MAIL_KEYS = [
        'SMTP_HOST'         => 'SMTP host',
        'SMTP_PORT'         => 'SMTP port',
        'SMTP_ENCRYPTION'   => 'Encryption',
        'SMTP_USER'         => 'SMTP username',
        'SMTP_PASS'         => 'SMTP password',
        'MAIL_FROM_ADDRESS' => 'From address',
        'MAIL_FROM_NAME'    => 'From name',
        'MAIL_ENABLED'      => 'Email sending on/off',
    ];

    // --- Screen -----------------------------------------------------------

    public static function index(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (self::auth($c)->currentAdmin() === null) {
            return self::redirect($response, '/admin/login');
        }

        return self::renderMail($c, $response);
    }

    // --- Save settings ----------------------------------------------------

    public static function save(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (self::auth($c)->currentAdmin() === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderMail($c, $response, $data, 'Your session expired. Please try again.', 400);
        }

        $host = trim((string) ($data['smtp_host'] ?? ''));
        $port = trim((string) ($data['smtp_port'] ?? ''));
        $encryption = MailSettingsForm::normaliseEncryption((string) ($data['smtp_encryption'] ?? 'none'));
        $user = trim((string) ($data['smtp_user'] ?? ''));
        $passwordInput = (string) ($data['smtp_pass'] ?? '');
        $fromAddress = trim((string) ($data['mail_from_address'] ?? ''));
        $fromName = trim((string) ($data['mail_from_name'] ?? ''));
        $enabled = isset($data['mail_enabled']);

        // Validation (plain-language, re-rendered inline with values preserved).
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return self::invalid($c, $response, $data, 'Please enter a valid From address (e.g. hello@yourdomain.com).');
        }
        if ($fromName === '') {
            return self::invalid($c, $response, $data, 'Please enter a From name (what recipients see as the sender).');
        }
        if ($port === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return self::invalid($c, $response, $data, 'Please enter a port number between 1 and 65535 (usually 587 or 465).');
        }
        if ($enabled && $host === '') {
            return self::invalid($c, $response, $data, 'Enter your SMTP host before turning email sending on.');
        }

        $changes = [
            'SMTP_HOST'         => $host,
            'SMTP_PORT'         => $port,
            'SMTP_ENCRYPTION'   => $encryption,
            'SMTP_USER'         => $user,
            'MAIL_FROM_ADDRESS' => $fromAddress,
            'MAIL_FROM_NAME'    => $fromName,
            'MAIL_ENABLED'      => $enabled ? '1' : '0',
        ];
        // Write-only password: a non-empty value replaces the stored secret; a
        // blank field keeps whatever is already stored (same rule as the
        // Turnstile secret). The kept value is the FILE's, never an env override.
        if ($passwordInput !== '') {
            $changes['SMTP_PASS'] = $passwordInput;
        }

        try {
            self::configWriter($c)->save($changes);
        } catch (RuntimeException $e) {
            return self::invalid($c, $response, $data, 'Could not save the settings: ' . self::sanitise($e->getMessage()));
        }

        // Settings changed: any earlier "test passed, offer enable" is stale.
        self::session($c)->remove(self::S_CAN_ENABLE);

        self::flash($c)->success('Email settings saved.');

        return self::redirect($response, '/admin/mail');
    }

    // --- Test send --------------------------------------------------------

    public static function test(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/mail');
        }

        $to = trim((string) ($data['test_recipient'] ?? ''));
        if ($to === '') {
            $to = (string) ($admin['email'] ?? '');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            self::flash($c)->error('Please enter a valid email address to send the test to.');

            return self::redirect($response, '/admin/mail');
        }

        $config = self::config($c);
        /** @var MailerInterface $mailer */
        $mailer = $c->get(MailerInterface::class);

        try {
            $mailer->send(
                $to,
                null,
                'OpenSendForm test email',
                "This is a test email from OpenSendForm.\n\n"
                . "If it reached you, your email settings are working and submissions "
                . "will be delivered."
            );
        } catch (Throwable $e) {
            self::flash($c)->error('The test email could not be sent: ' . self::sanitise($e->getMessage()));

            return self::redirect($response, '/admin/mail');
        }

        self::flash($c)->success('Test email sent to ' . $to . '. Check that inbox to confirm it arrived.');

        // A working test with sending still off unlocks the one-click enable.
        if (!$config->mailEnabled()) {
            self::session($c)->set(self::S_CAN_ENABLE, true);
        }

        return self::redirect($response, '/admin/mail');
    }

    // --- Enable sending ---------------------------------------------------

    public static function enable(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (self::auth($c)->currentAdmin() === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/mail');
        }

        try {
            self::configWriter($c)->save(['MAIL_ENABLED' => '1']);
        } catch (RuntimeException $e) {
            self::flash($c)->error('Could not turn sending on: ' . self::sanitise($e->getMessage()));

            return self::redirect($response, '/admin/mail');
        }

        self::session($c)->remove(self::S_CAN_ENABLE);
        self::flash($c)->success('Email sending is now on. New submissions will be emailed.');

        return self::redirect($response, '/admin/mail');
    }

    // --- Scheduled tasks (cron) -------------------------------------------

    /**
     * Mark the two scheduled tasks (cron) as set up, clearing the dashboard
     * reminder. Records CRON_SETUP=done; a no-op on a bad CSRF token. The app
     * cannot see cron from a request, so this is the operator's own confirmation
     * (the same promise the installer's Scheduled tasks step recorded).
     */
    public static function markCronDone(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (self::auth($c)->currentAdmin() === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/mail');
        }

        try {
            self::configWriter($c)->save(['CRON_SETUP' => 'done']);
        } catch (RuntimeException $e) {
            self::flash($c)->error('Could not save that: ' . self::sanitise($e->getMessage()));

            return self::redirect($response, '/admin/mail');
        }

        self::flash($c)->success('Marked your scheduled tasks as set up.');

        return self::redirect($response, '/admin/mail');
    }

    // --- Rendering --------------------------------------------------------

    /**
     * Assemble the page: settings form values, the env-shadow notice and the
     * enable-now offer. On a brand-new setup (mail never configured) the form
     * leads with SSL/TLS on port 465 — the most common working cPanel choice —
     * rather than the shipped plaintext default. The SPF/DKIM/DMARC report has
     * moved to its own DeliverabilityController.
     *
     * @param array<string, mixed> $data  Submitted values to preserve on error.
     */
    private static function renderMail(
        ContainerInterface $c,
        ResponseInterface $response,
        array $data = [],
        string $error = '',
        int $status = 200
    ): ResponseInterface {
        $config = self::config($c);
        $writer = self::configWriter($c);
        $admin = self::auth($c)->currentAdmin();
        $adminEmail = (string) ($admin['email'] ?? '');

        $useSubmitted = $data !== [];
        $fromAddress = $useSubmitted ? (string) ($data['mail_from_address'] ?? '') : $config->mailFromAddress();

        // "Fresh" = mail has never been saved to the config file, so no stored
        // SMTP choice exists to honour. Then we preselect the friendly default
        // (SSL/TLS + 465) instead of the shipped none/25.
        $fileValues = $writer->currentFileValues();
        $mailConfigured = array_key_exists('SMTP_HOST', $fileValues) || array_key_exists('SMTP_ENCRYPTION', $fileValues);
        $encryption = $useSubmitted
            ? MailSettingsForm::normaliseEncryption((string) ($data['smtp_encryption'] ?? ''))
            : ($mailConfigured ? $config->smtpEncryption() : MailSettingsForm::DEFAULT_ENCRYPTION);
        $port = $useSubmitted
            ? (string) ($data['smtp_port'] ?? '')
            : ($mailConfigured ? (string) $config->smtpPort() : MailSettingsForm::defaultPortFor($encryption));

        $vars = [
            'title'          => 'Email',
            'error'          => $error,
            // Form field values (submitted-on-error take precedence; never the password).
            'smtpHost'       => $useSubmitted ? (string) ($data['smtp_host'] ?? '') : ($mailConfigured ? $config->smtpHost() : ''),
            'smtpPort'       => $port,
            'smtpEncryption' => $encryption,
            'smtpUser'       => $useSubmitted ? (string) ($data['smtp_user'] ?? '') : $config->smtpUser(),
            'passwordSet'    => $config->smtpPass() !== '' || ($writer->fileValue('SMTP_PASS') ?? '') !== '',
            'fromAddress'    => $fromAddress,
            'fromName'       => $useSubmitted ? (string) ($data['mail_from_name'] ?? '') : $config->mailFromName(),
            'mailEnabled'    => $useSubmitted ? isset($data['mail_enabled']) : $config->mailEnabled(),
            // Handoff / notices.
            'shadowed'       => self::shadowedSettings($config, $writer),
            'offerEnable'    => !$config->mailEnabled() && self::session($c)->get(self::S_CAN_ENABLE) === true,
            'testRecipient'  => $adminEmail,
            // The scheduled-tasks (cron) block: the two commands with real paths,
            // findable here post-install (the installer's Finish + dashboard both
            // point at it). "Mark as set up" clears the dashboard reminder.
            'cronMonitorCmd' => CronCommands::monitorCommand(),
            'cronRetryCmd'   => CronCommands::retryCommand(),
            'cronPhp'        => CronCommands::phpBinary(),
            'cronDone'       => $config->cronSetup() === 'done',
        ];

        return AdminView::renderPage($c, $response, 'mail', $vars, 'mail', $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function invalid(
        ContainerInterface $c,
        ResponseInterface $response,
        array $data,
        string $message
    ): ResponseInterface {
        return self::renderMail($c, $response, $data, $message, 422);
    }

    /**
     * Settings whose stored file value is being overridden by an environment
     * variable (so an edit here will not take visible effect until the override
     * is removed). Returns their human labels.
     *
     * @return array<int, string>
     */
    private static function shadowedSettings(Config $config, ConfigWriter $writer): array
    {
        $fileValues = $writer->currentFileValues();
        $shadowed = [];
        foreach (self::MAIL_KEYS as $key => $label) {
            if (!array_key_exists($key, $fileValues)) {
                continue; // Nothing stored for this key, so nothing to shadow.
            }
            if ($fileValues[$key] !== $config->get($key)) {
                $shadowed[] = $label;
            }
        }

        return $shadowed;
    }

    // --- Helpers ----------------------------------------------------------

    /**
     * Make a mailer/exception message safe to flash: collapse control
     * characters and truncate.
     */
    private static function sanitise(string $message): string
    {
        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        $message = trim($message);
        if ($message === '') {
            return 'unknown error.';
        }

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    }

    // --- Container accessors ----------------------------------------------

    private static function auth(ContainerInterface $c): AuthService
    {
        /** @var AuthService $s */
        $s = $c->get(AuthService::class);

        return $s;
    }

    private static function csrf(ContainerInterface $c): Csrf
    {
        /** @var Csrf $s */
        $s = $c->get(Csrf::class);

        return $s;
    }

    private static function session(ContainerInterface $c): SessionInterface
    {
        /** @var SessionInterface $s */
        $s = $c->get(SessionInterface::class);

        return $s;
    }

    private static function flash(ContainerInterface $c): Flash
    {
        /** @var Flash $f */
        $f = $c->get(Flash::class);

        return $f;
    }

    private static function config(ContainerInterface $c): Config
    {
        /** @var Config $s */
        $s = $c->get(Config::class);

        return $s;
    }

    private static function configWriter(ContainerInterface $c): ConfigWriter
    {
        /** @var ConfigWriter $s */
        $s = $c->get(ConfigWriter::class);

        return $s;
    }

    /**
     * @return array<string, mixed>
     */
    private static function formData(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        parse_str((string) $request->getBody(), $data);

        return $data;
    }

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
