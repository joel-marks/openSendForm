<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (i): store the submission.
 *
 * User fields (reserved _osf_* already removed) are serialised to JSON and
 * handed to the repository, which always persists them as the in-flight
 * delivery payload (metadata is always recorded too); the delivery service
 * clears content after a successful send unless the form's store_content
 * toggle is on. Status is 'received'; the delivery stage that follows may
 * advance it. The new row's id is stashed on the context so that stage
 * knows what to send.
 *
 * This stage does not terminate the pipeline: it returns null so the always
 * present delivery stage runs next and produces the success outcome.
 *
 * Synthetic marking happens HERE, at the store, and nowhere earlier: a request
 * that carried the reserved _osf_monitor field with a value matching the
 * configured MONITOR_SECRET has its stored row flagged is_synthetic. The secret
 * is compared in constant time; an empty configured secret never matches, so a
 * forged or absent marker is always an ordinary submission. Crucially the flag
 * is decided only once every abuse/validation stage before this one has passed
 * — the reserved field can never let a submission skip a stage.
 */
final class StoreStage implements Stage
{
    private SubmissionRepository $submissions;
    private string $monitorSecret;

    public function __construct(SubmissionRepository $submissions, string $monitorSecret = '')
    {
        $this->submissions = $submissions;
        $this->monitorSecret = $monitorSecret;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $form = $context->form ?? [];
        $contentJson = json_encode($context->userFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $context->submissionId = $this->submissions->recordSubmission(
            (int) $form['id'],
            $context->remoteIp,
            $context->matchedOrigin,
            $context->userAgent,
            $contentJson,
            'received',
            $this->isSynthetic($context)
        );

        return null;
    }

    /**
     * True only when a non-empty MONITOR_SECRET is configured AND the request's
     * reserved marker matches it exactly (constant-time compare).
     */
    private function isSynthetic(SubmitContext $context): bool
    {
        if ($this->monitorSecret === '' || $context->monitorSecret === null) {
            return false;
        }

        return hash_equals($this->monitorSecret, $context->monitorSecret);
    }
}
