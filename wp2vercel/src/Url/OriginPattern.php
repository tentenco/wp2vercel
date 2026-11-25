<?php

declare(strict_types=1);

namespace WP2Vercel\Url;

final class OriginPattern
{
    private const NOT_PART_OF_LONGER_HOST = '(?![A-Za-z0-9\-]|\.[A-Za-z0-9]|:\d)';
    private const NOT_INSIDE_LONGER_HOST = '(?:(?<![A-Za-z0-9\-.])|(?<=%[0-9A-Fa-f]{2}))';
    private const NOT_PART_OF_LONGER_ENCODED_HOST = '(?![A-Za-z0-9\-]|\.[A-Za-z0-9]|%3A\d)';

    public static function plain(Origin $origin): string
    {
        return '~(?:https?:)?//' . preg_quote($origin->authority, '~') . self::NOT_PART_OF_LONGER_HOST . '~i';
    }

    public static function jsonEscaped(Origin $origin): string
    {
        return '~(?:https?:)?\\\\/\\\\/' . preg_quote($origin->authority, '~') . self::NOT_PART_OF_LONGER_HOST . '~i';
    }

    public static function urlEncoded(Origin $origin): string
    {
        return '~https?%3A%2F%2F' . preg_quote(rawurlencode($origin->authority), '~') . self::NOT_PART_OF_LONGER_ENCODED_HOST . '~i';
    }

    public static function bareAuthority(Origin $origin): string
    {
        return '~' . self::NOT_INSIDE_LONGER_HOST . preg_quote($origin->authority, '~') . self::NOT_PART_OF_LONGER_HOST . '~i';
    }

    public static function bareEncodedAuthority(Origin $origin): ?string
    {
        $encoded = rawurlencode($origin->authority);
        if ($encoded === $origin->authority) {
            return null;
        }
        return '~' . self::NOT_INSIDE_LONGER_HOST . preg_quote($encoded, '~') . self::NOT_PART_OF_LONGER_ENCODED_HOST . '~i';
    }
}
