<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Utf8;

final class Utf8Test extends TestCase
{
    private const TEXT = "Ünïcödé & <b>bold</b> 'single' \"double\" &amp; 中文 🚀";

    public function testTextThatIsValidStaysExactlyTheSameWithoutMbstring(): void
    {
        self::assertSame(self::TEXT, Utf8::scrubWithoutMbstring(self::TEXT));
    }

    public function testBrokenBytesAreReplacedWithoutMbstring(): void
    {
        $scrubbed = Utf8::scrubWithoutMbstring("before \xC3\x28 after \xFF");

        self::assertSame(1, preg_match('//u', $scrubbed));
        self::assertStringStartsWith('before ', $scrubbed);
        self::assertStringContainsString(' after ', $scrubbed);
    }

    public function testCuttingWithoutMbstringNeverSplitsACharacterAndMatchesMbstring(): void
    {
        for ($bytes = 0; $bytes <= strlen(self::TEXT) + 2; $bytes++) {
            self::assertSame(mb_strcut(self::TEXT, 0, $bytes, 'UTF-8'), Utf8::cutWithoutMbstring(self::TEXT, $bytes), 'at ' . $bytes . ' bytes');
        }
    }

    public function testTheChosenFunctionsGiveValidTextOfTheAskedLength(): void
    {
        self::assertSame('中', Utf8::cut('中文', 5));
        self::assertSame(1, preg_match('//u', Utf8::scrub("a\xC3\x28b")));
    }

    public function testNoCodeCallsMultibyteFunctionsThatWordPressDoesNotProvide(): void
    {
        $plugin = dirname(__DIR__, 2);
        $calls = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plugin . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getFilename() === 'Utf8.php') {
                continue;
            }
            preg_match_all('/\bmb_(?!substr\b|strlen\b|chr\b|ord\b)[a-z_]+\s*\(/', (string) file_get_contents($file->getPathname()), $found);
            foreach ($found[0] as $call) {
                $calls[] = $file->getFilename() . ': ' . $call;
            }
        }

        self::assertSame([], $calls);
    }
}
