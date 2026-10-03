<?php

namespace App\Support;

use Closure;

/**
 * Course / department options for patient records (config/academic_programs.php).
 */
class AcademicPrograms
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function grouped(): array
    {
        return config('academic_programs', []);
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return collect(self::grouped())->flatten()->values()->all();
    }

    public static function departmentOf(?string $course): ?string
    {
        foreach (self::grouped() as $department => $courses) {
            if (in_array($course, $courses, true)) {
                return $department;
            }
        }

        return null;
    }

    /**
     * Validation rule: the value must be one of the configured courses. An
     * unchanged legacy value (typed before the dropdown existed) is still
     * accepted so older records can be re-saved without forcing a change.
     */
    public static function rule(?string $currentValue = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($currentValue) {
            if ($value === null || $value === '') {
                return;
            }
            if ($currentValue !== null && $value === $currentValue) {
                return;
            }
            if (! in_array($value, self::all(), true)) {
                $fail('Please select a course / department from the list.');
            }
        };
    }
}
