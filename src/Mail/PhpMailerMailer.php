<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

use OpenSendForm\Config;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * PHPMailer-backed SMTP transport.
 *
 * Reads its connection details from Config. From is ALWAYS the configured
 * service identity; the submitter influences only the (already validated)
 * Reply-To, subject and body handed in by the delivery layer. PHPMailer is
 * put in exception mode so any failure surfaces as a thrown exception, which
 * is exactly the contract MailerInterface promises.
 */
final class PhpMailerMailer implements MailerInterface
{
    /** Connection/greeting timeout in seconds — kept short for in-request sends. */
    private const TIMEOUT_SECONDS = 15;

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function send(string $to, ?string $replyTo, string $subject, string $textBody): void
    {
        // Accept single-label hosts such as 'noreply@localhost' (the dev /
        // Mailpit default) while still enforcing a real email shape. Our own
        // boundaries validate addresses more strictly: form recipients at
        // creation and the submitter Reply-To in the MessageBuilder.
        PHPMailer::$validator = 'html5';

        // Exceptions on: send() throws PhpMailerException on any failure.
        $mail = new PHPMailer(true);

        $this->configure($mail);

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isHTML(false);

        // From is fixed service identity — never submitter-derived.
        $mail->setFrom($this->config->mailFromAddress(), $this->config->mailFromName());
        $mail->addAddress($to);
        if ($replyTo !== null && $replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }

        $mail->Subject = $subject;
        $mail->Body = $textBody;

        try {
            $mail->send();
        } catch (PhpMailerException $e) {
            // Normalise to a plain runtime failure so callers need not depend
            // on PHPMailer's exception type.
            throw new \RuntimeException('Mail delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Apply the SMTP connection settings (host, port, encryption and optional
     * auth) to a PHPMailer instance, without touching the message or opening a
     * socket. Exposed so the auth policy — anonymous when the username is empty
     * — can be asserted in tests against a real PHPMailer object.
     */
    public function configure(PHPMailer $mail): void
    {
        $mail->isSMTP();
        $mail->Host = $this->config->smtpHost();
        $mail->Port = $this->config->smtpPort();
        $mail->Timeout = self::TIMEOUT_SECONDS;

        $this->applyEncryption($mail);
        $this->applyAuthentication($mail);
    }

    private function applyEncryption(PHPMailer $mail): void
    {
        switch ($this->config->smtpEncryption()) {
            case 'starttls':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                break;
            case 'smtps':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            default:
                // No transport security (e.g. Mailpit in dev). Also stop
                // PHPMailer from opportunistically upgrading to TLS.
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
                break;
        }
    }

    private function applyAuthentication(PHPMailer $mail): void
    {
        $user = $this->config->smtpUser();
        if ($user === '') {
            $mail->SMTPAuth = false;
            return;
        }

        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $this->config->smtpPass();
    }
}
