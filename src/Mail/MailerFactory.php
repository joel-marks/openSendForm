<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

use OpenSendForm\Config;

/**
 * Builds a mailer from an arbitrary configuration.
 *
 * The seam exists for the browser installer's "Email sending" step: there the
 * operator can send a test using the SMTP details they have just typed, BEFORE
 * anything is written to var/config.php. The step therefore needs a mailer wired
 * to those just-entered settings rather than the (still empty) installed config.
 * Production returns a real PhpMailerMailer; tests inject a fake so no real SMTP
 * connection is ever attempted.
 */
interface MailerFactory
{
    public function make(Config $config): MailerInterface;
}
