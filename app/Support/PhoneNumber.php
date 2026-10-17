<?php

namespace App\Support;

use Closure;

/**
 * Philippine mobile numbers: exactly 11 digits (e.g. 0917-123-4567).
 * Spaces, dashes and parentheses are accepted as separators.
 */
class PhoneNumber
{
    public const DIGITS = 11;

    /**
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:20', self::rule()];
    }

    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null || trim((string) $value) === '') {
                return;
            }
            if (! preg_match('/^[\d\s\-()]+$/', (string) $value) || strlen(preg_replace('/\D/', '', (string) $value)) !== self::DIGITS) {
                $fail('The phone number must be exactly '.self::DIGITS.' digits (e.g. 0917-123-4567).');
            }
        };
    }
}
