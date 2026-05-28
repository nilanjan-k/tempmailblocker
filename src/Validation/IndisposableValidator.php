<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker\Validation;

use Illuminate\Validation\Validator;
use NilanjanK\TempMailBlocker\Facades\TempMailBlocker as TempMailBlockerFacade;

/**
 * Implicit validator callback for the "indisposable" string-based rule.
 *
 * Registered via Validator::extend() in the service provider so that the rule
 * can be used as a plain string in validation rule arrays:
 *
 *   $rules = ['email' => ['required', 'email', 'indisposable']];
 *
 * Non-email-format values are silently passed to avoid double-reporting errors
 * that the 'email' rule will already surface.
 */
class IndisposableValidator
{
    /**
     * Determine whether the attribute value passes the "indisposable" rule.
     *
     * @param string    $attribute  The attribute name under validation.
     * @param mixed     $value      The value submitted for the attribute.
     * @param array<int, mixed> $parameters  Rule parameters (unused but required by the extension API).
     * @param Validator $validator  The underlying validator instance.
     * @return bool True when the email is not disposable or the format is invalid.
     */
    public function validate(
        string $attribute,
        mixed $value,
        array $parameters,
        Validator $validator
    ): bool {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return TempMailBlockerFacade::isIndisposable((string) $value);
    }
}
