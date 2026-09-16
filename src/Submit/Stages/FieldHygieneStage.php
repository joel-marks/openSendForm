<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Config;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (b): field hygiene.
 *
 * Enforces the field-count and name/value size caps, rejects non-scalar
 * values, then splits the reserved _osf_* fields out from the user data.
 * After this stage the context holds validated string fields and a
 * user-only subset for storage.
 */
final class FieldHygieneStage implements Stage
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $fields = $context->fields;

        if (count($fields) > $this->config->maxFields()) {
            return $this->invalid();
        }

        $maxName = $this->config->maxFieldNameBytes();
        $maxValue = $this->config->maxFieldValueBytes();

        $strings = [];
        foreach ($fields as $name => $value) {
            $name = (string) $name;
            if (strlen($name) > $maxName) {
                return $this->invalid();
            }

            // Nested arrays/objects have no place in a flat submission.
            if (!is_scalar($value)) {
                return $this->invalid();
            }

            $string = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if (strlen($string) > $maxValue) {
                return $this->invalid();
            }

            $strings[$name] = $string;
        }

        $context->fields = $strings;
        $context->token = $strings[SubmitContext::FIELD_TOKEN] ?? null;
        $context->honeypot = $strings[SubmitContext::FIELD_HONEYPOT] ?? null;
        $context->turnstileToken = $strings[SubmitContext::FIELD_TURNSTILE] ?? null;
        $context->monitorSecret = $strings[SubmitContext::FIELD_MONITOR] ?? null;

        $user = [];
        foreach ($strings as $name => $string) {
            if (str_starts_with($name, '_osf_')) {
                continue;
            }
            $user[$name] = $string;
        }
        $context->userFields = $user;

        return null;
    }

    private function invalid(): SubmitOutcome
    {
        return SubmitOutcome::error(400, 'invalid_fields', 'One or more fields are invalid.');
    }
}
