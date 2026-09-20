<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Config;
use OpenSendForm\Mail\MailerFactory;
use OpenSendForm\Mail\MailerInterface;

/**
 * A MailerFactory test double: always returns the same injected mailer,
 * ignoring the config passed to make(). Lets the installer's "Email sending"
 * test-send be driven against a FakeMailer without any real SMTP connection.
 */
final class FixedMailerFactory implements MailerFactory
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function make(Config $config): MailerInterface
    {
        return $this->mailer;
    }
}
