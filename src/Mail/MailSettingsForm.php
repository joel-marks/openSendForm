<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

/**
 * Small shared helpers for the SMTP settings form, which appears in two places
 * that must behave identically: the admin Email page (/admin/mail) and the
 * browser installer's "Email sending" step. Keeping the encryption vocabulary
 * and the encryption→default-port mapping here means the two screens can never
 * drift apart.
 *
 * Pure and stateless: it normalises a submitted encryption choice and reports
 * the conventional port for each transport. The client-side auto-fill (picking
 * an encryption fills its default port) reads the same mapping from
 * data-default-port attributes on the options, so JS and PHP agree.
 */
final class MailSettingsForm
{
    /**
     * The encryption preselected for a brand-new, never-configured setup.
     * SSL/TLS on port 465 is the most common working choice on shared cPanel
     * mailboxes, so it is the safer default than plaintext.
     */
    public const DEFAULT_ENCRYPTION = 'smtps';

    /**
     * The three transport choices, in the order they are offered, each paired
     * with its conventional port and the label shown in the select.
     *
     * @return array<int, array{value:string, port:string, label:string}>
     */
    public static function encryptionOptions(): array
    {
        return [
            ['value' => 'starttls', 'port' => '587', 'label' => 'STARTTLS — port 587'],
            ['value' => 'smtps',    'port' => '465', 'label' => 'SSL/TLS — port 465'],
            ['value' => 'none',     'port' => '25',  'label' => 'None — only for a local test server'],
        ];
    }

    /**
     * Constrain a submitted encryption value to one of the three known
     * transports; anything else becomes 'none'.
     */
    public static function normaliseEncryption(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['none', 'starttls', 'smtps'], true) ? $value : 'none';
    }

    /**
     * The conventional SMTP port for a transport: 587 STARTTLS, 465 SSL/TLS,
     * 25 none. Used to seed the port field and to drive the client-side
     * auto-fill when the encryption choice changes.
     */
    public static function defaultPortFor(string $encryption): string
    {
        return match (self::normaliseEncryption($encryption)) {
            'starttls' => '587',
            'smtps'    => '465',
            default    => '25',
        };
    }
}
