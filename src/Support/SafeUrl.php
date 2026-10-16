<?php

namespace Aurora\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Links editors (and AI assistants) type in, made safe to put in an href: an address on the
 * site (/about, #team), a web address, an e-mail address or a phone number. Anything else
 * (javascript:, data:, //elsewhere) is refused when saved and dropped when shown.
 */
class SafeUrl
{
    public static function isSafe(?string $url): bool
    {
        $url = trim((string) $url);

        return match (true) {
            $url === '' => false,
            str_starts_with($url, '#') => true,
            // A path on this site, not a protocol-relative address (//evil.com, /\evil.com).
            str_starts_with($url, '/') => ! preg_match('#^/[/\\\\]#', $url),
            default => (bool) preg_match('#^(https?://[^\s/$.?\#][^\s]*|mailto:[^\s]+|tel:[+0-9()\s.-]+)$#i', $url),
        };
    }

    /**
     * The link if it is safe, else null.
     */
    public static function href(?string $url): ?string
    {
        return static::isSafe($url) ? trim((string) $url) : null;
    }

    /**
     * A validation rule for link fields.
     */
    public static function rule(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (filled($value) && ! SafeUrl::isSafe(is_string($value) ? $value : null)) {
                    $fail(__('Enter a web address (https://…), an address on this site (/…), an e-mail (mailto:…) or a phone number (tel:…).'));
                }
            }
        };
    }
}
