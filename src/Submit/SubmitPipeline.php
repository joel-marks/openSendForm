<?php

declare(strict_types=1);

namespace OpenSendForm\Submit;

use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Mail\DeliveryService;
use OpenSendForm\RateLimit\RateLimiter;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Submit\Stages\DeliveryStage;
use OpenSendForm\Submit\Stages\EmailValidationStage;
use OpenSendForm\Submit\Stages\FieldHygieneStage;
use OpenSendForm\Submit\Stages\FormLookupStage;
use OpenSendForm\Submit\Stages\HoneypotStage;
use OpenSendForm\Submit\Stages\MethodBodyStage;
use OpenSendForm\Submit\Stages\OriginStage;
use OpenSendForm\Submit\Stages\RateLimitStage;
use OpenSendForm\Submit\Stages\StoreStage;
use OpenSendForm\Submit\Stages\TokenStage;
use OpenSendForm\Submit\Stages\TurnstileStage;
use OpenSendForm\Turnstile\CurlTurnstileVerifier;
use OpenSendForm\Turnstile\TurnstileVerifierInterface;
use OpenSendForm\Validation\DnsChecker;

/**
 * Runs the ordered submission pipeline.
 *
 * The stage order is the security contract of the endpoint and is fixed by
 * create(): cheapest and most abuse-relevant checks first, storage last.
 * run() executes stages until one returns an outcome.
 */
final class SubmitPipeline
{
    /** @var array<int, Stage> */
    private array $stages;

    /**
     * @param array<int, Stage> $stages
     */
    public function __construct(array $stages)
    {
        $this->stages = $stages;
    }

    /**
     * Assemble the pipeline in its canonical order.
     */
    public static function create(
        Config $config,
        FormRepository $forms,
        RateLimiter $limiter,
        SubmitToken $tokens,
        DnsChecker $dns,
        SubmissionRepository $submissions,
        ?DeliveryService $delivery = null,
        ?TurnstileVerifierInterface $turnstile = null
    ): self {
        $turnstile ??= new CurlTurnstileVerifier();

        return new self([
            new MethodBodyStage($config),        // a. method + body size + parse
            new FieldHygieneStage($config),      // b. field count/size + reserved split
            new FormLookupStage($forms),         // c. URL form_key -> active form
            new OriginStage(),                   // d. origin allowlist + CORS
            new RateLimitStage($limiter, $config), // e. per-IP then per-form
            new HoneypotStage(),                 // f. honeypot -> silent success
            new TokenStage($tokens),             // g. submit-token
            new TurnstileStage($turnstile),      // g'. optional per-form Turnstile
            new EmailValidationStage($dns),      // h. email syntax + MX/A
            new StoreStage($submissions, $config->monitorSecret()), // i. persist (non-terminal)
            new DeliveryStage($delivery, $config), // j. mail relay -> success
        ]);
    }

    public function run(SubmitContext $context): SubmitOutcome
    {
        foreach ($this->stages as $stage) {
            $outcome = $stage->process($context);
            if ($outcome !== null) {
                return $outcome;
            }
        }

        // Unreachable: the terminal DeliveryStage always returns an outcome.
        return SubmitOutcome::error(500, 'internal_error', 'The pipeline produced no result.');
    }

    /**
     * The ordered stages, exposed for order-enforcement tests.
     *
     * @return array<int, Stage>
     */
    public function stages(): array
    {
        return $this->stages;
    }
}
