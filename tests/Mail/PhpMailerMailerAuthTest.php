<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Mail;

use OpenSendForm\Config;
use OpenSendForm\Mail\PhpMailerMailer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * The SMTP auth policy of the PHPMailer transport: auth is OPTIONAL. An empty
 * username means anonymous SMTP (SMTPAuth off) — legitimate for localhost
 * relays and Mailpit — while a username turns auth on and carries the password.
 * Asserted against a real PHPMailer via the configure() seam so no socket opens.
 */
final class PhpMailerMailerAuthTest extends TestCase
{
    public function testEmptyUsernameDisablesAuth(): void
    {
        $config = Config::fromValues([
            'SMTP_HOST'       => 'mailpit',
            'SMTP_PORT'       => '1025',
            'SMTP_ENCRYPTION' => 'none',
            'SMTP_USER'       => '',
            'SMTP_PASS'       => '',
        ]);

        $mail = new PHPMailer(true);
        (new PhpMailerMailer($config))->configure($mail);

        self::assertFalse($mail->SMTPAuth);
    }

    public function testPasswordWithEmptyUsernameStillDisablesAuth(): void
    {
        // Defensive: even if a stray password lingers, no username => no auth.
        $config = Config::fromValues([
            'SMTP_HOST'       => 'mailpit',
            'SMTP_PORT'       => '1025',
            'SMTP_ENCRYPTION' => 'none',
            'SMTP_USER'       => '',
            'SMTP_PASS'       => 'ignored',
        ]);

        $mail = new PHPMailer(true);
        (new PhpMailerMailer($config))->configure($mail);

        self::assertFalse($mail->SMTPAuth);
    }

    public function testUsernameEnablesAuthWithCredentials(): void
    {
        $config = Config::fromValues([
            'SMTP_HOST'       => 'smtp.example.com',
            'SMTP_PORT'       => '587',
            'SMTP_ENCRYPTION' => 'starttls',
            'SMTP_USER'       => 'user@example.com',
            'SMTP_PASS'       => 'hunter2',
        ]);

        $mail = new PHPMailer(true);
        (new PhpMailerMailer($config))->configure($mail);

        self::assertTrue($mail->SMTPAuth);
        self::assertSame('user@example.com', $mail->Username);
        self::assertSame('hunter2', $mail->Password);
    }
}
