<?php
/**
 * The Deliverability tab: live SPF / DKIM / DMARC checks of the sending domain.
 * Non-technical audience — plain language, copy-paste values, and every result
 * names its source (a live DNS lookup of a named record for a named domain).
 *
 * The results readout itself lives in the shared partial _deliverability.php so
 * the Status page's Mail section renders the identical component (one markup
 * source, never forked).
 *
 * @var array<string, mixed> $report
 * @var string $selector
 * @var string $csrf
 */
?>
<h1>Deliverability</h1>

<?php require __DIR__ . '/_deliverability.php'; ?>
