<?php use function OpenSendForm\Admin\h; ?>
<?php /* The shared "Step N of 7" progress indicator for the onboarding shell.
         Every configurable step includes it; the terminal Finish page omits it
         (it passes no stepNo). */ ?>
<?php if ((int) ($stepNo ?? 0) > 0): ?>
    <p class="osf-step-progress">Step <?= h((string) (int) $stepNo) ?> of <?= h((string) (int) ($stepCount ?? 7)) ?></p>
<?php endif; ?>
