<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidDomain implements ValidationRule
{
    public function __construct(
        private readonly bool $allowWildcard = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->passes($value)) {
            $fail((string) trans('certificates::messages.invalid_domain', [
                'domain' => is_string($value) ? $value : (string) $attribute,
            ]));
        }
    }

    /**
     * Whether the given value is a syntactically valid hostname (FQDN).
     */
    public function passes(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $host = $value;

        if ($this->allowWildcard && str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }

        if (strlen($host) > 253) {
            return false;
        }

        // No scheme, path, spaces, or a trailing dot.
        if (preg_match('#[\s/:]#', $host) === 1 || str_ends_with($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return false;
            }
        }

        // The top-level label must not be all-numeric.
        return preg_match('/^[0-9]+$/', $labels[count($labels) - 1]) !== 1;
    }
}
