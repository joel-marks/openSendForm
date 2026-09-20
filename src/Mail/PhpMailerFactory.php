<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

use OpenSendForm\Config;

/**
 * The production MailerFactory: a real PHPMailer-backed transport built from the
 * given configuration.
 */
final class PhpMailerFactory implements MailerFactory
{
    public function make(Config $config): MailerInterface
    {
        return new PhpMailerMailer($config);
    }
}
