<?php
use function OpenSendForm\Admin\h;

/**
 * @var string $csrf
 * @var string $dbSummary
 * @var bool   $mailConfigured
 * @var string $mailSummary
 */
?>
<h1>Ready to finish</h1>

<p>Everything checks out. Here’s what will be set up:</p>

<ul>
    <li><strong>Database:</strong> <?= h($dbSummary) ?></li>
    <li><strong>Administrator:</strong> the account you just created.</li>
    <?php if (($mailConfigured ?? false) === true): ?>
        <li><strong>Email sending:</strong> on — <?= h($mailSummary) ?>.</li>
    <?php else: ?>
        <li><strong>Email sending:</strong> off for now — submissions are saved,
            and you’ll turn on email from the admin panel after signing in.</li>
    <?php endif; ?>
</ul>

<p>
    Clicking below writes your settings and locks the installer so it can’t be
    run again by accident.
</p>

<form method="post" action="/install/finish">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <button type="submit">Finish setup</button>
</form>
