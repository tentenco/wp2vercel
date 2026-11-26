<?php

declare(strict_types=1);

namespace WP2Vercel;

final class Utf8
{
    private const WHOLE_CHARACTERS = '/^(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3})*/';

    public static function scrub(string $text): string
    {
        $scrubbed = extension_loaded('mbstring') ? mb_scrub($text, 'UTF-8') : null;
        return is_string($scrubbed) ? $scrubbed : self::scrubWithoutMbstring($text);
    }

    public static function scrubWithoutMbstring(string $text): string
    {
        return htmlspecialchars_decode(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES);
    }

    public static function cut(string $text, int $maxBytes): string
    {
        return extension_loaded('mbstring') ? mb_strcut($text, 0, $maxBytes, 'UTF-8') : self::cutWithoutMbstring($text, $maxBytes);
    }

    public static function cutWithoutMbstring(string $text, int $maxBytes): string
    {
        $cut = substr($text, 0, max(0, $maxBytes));
        return preg_match(self::WHOLE_CHARACTERS, $cut, $whole) === 1 ? $whole[0] : '';
    }
}
