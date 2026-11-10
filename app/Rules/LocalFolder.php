<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Symfony\Component\Filesystem\Path;

/**
 * A folder that exists on this computer, given by its full path. A relative path would be looked up from wherever the
 * app's server happens to be running.
 */
class LocalFolder implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Path::isAbsolute($value)) {
            $fail("Enter the folder's full path, like /Users/you/code.");

            return;
        }

        if (! is_dir($value)) {
            $fail('That folder does not exist on this computer.');
        }
    }
}
