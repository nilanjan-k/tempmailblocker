<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use NilanjanK\TempMailBlocker\Facades\TempMailBlocker as TempMailBlockerFacade;

/**
 * Laravel validation rule object that rejects disposable email addresses.
 *
 * Usage:
 *   $rules = ['email' => ['required', 'email', new Indisposable()]];
 *   $rules = ['email' => ['required', 'email', new Indisposable('Custom message.')]];
 *
 * When the submitted value is not a valid email format the rule passes
 * silently, deferring domain-format errors to Laravel's built-in 'email' rule.
 */
class Indisposable implements ValidationRule
{
    /**
     * Create a new Indisposable rule instance.
     *
     * @param string|null $message Optional custom error message. When null the
     *                             message from config('tempmailblocker.message') is used.
     */
    public function __construct(
        private readonly ?string $message = null
    ) {}

    /**
     * Run the validation rule.
     *
     * Passes silently for non-email-format values so this rule composes
     * cleanly alongside the 'email' rule without producing duplicate errors.
     *
     * @param string                                                       $attribute Field name under validation.
     * @param mixed                                                        $value     The submitted value.
     * @param Closure(string, string|null=): \Illuminate\Translation\PotentiallyTranslatedString $fail  Callback invoked with the error message on failure.
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        if (TempMailBlockerFacade::isDisposable((string) $value)) {
            $fail(
                $this->message
                ?? (string) config('tempmailblocker.message', 'Disposable or temporary email addresses are not allowed.')
            );
        }
    }
}
