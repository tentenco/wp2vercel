<?php

declare(strict_types=1);

namespace WP2Vercel\Url;

enum ContentKind: string
{
    case Html = 'html';
    case Css = 'css';
    case Script = 'js';
    case Json = 'json';
    case Xml = 'xml';
    case Text = 'txt';
    case Binary = 'bin';

    private const BY_MIME = [
        'text/html' => self::Html,
        'application/xhtml+xml' => self::Html,
        'text/css' => self::Css,
        'application/javascript' => self::Script,
        'text/javascript' => self::Script,
        'application/x-javascript' => self::Script,
        'application/json' => self::Json,
        'application/ld+json' => self::Json,
        'application/manifest+json' => self::Json,
        'application/xml' => self::Xml,
        'text/xml' => self::Xml,
        'application/rss+xml' => self::Xml,
        'application/atom+xml' => self::Xml,
        'application/rdf+xml' => self::Xml,
        'text/xsl' => self::Xml,
        'image/svg+xml' => self::Xml,
        'text/plain' => self::Text,
    ];

    private const BY_EXTENSION = [
        'html' => self::Html,
        'htm' => self::Html,
        'css' => self::Css,
        'js' => self::Script,
        'mjs' => self::Script,
        'json' => self::Json,
        'map' => self::Json,
        'webmanifest' => self::Json,
        'xml' => self::Xml,
        'xsl' => self::Xml,
        'rss' => self::Xml,
        'atom' => self::Xml,
        'svg' => self::Xml,
        'txt' => self::Text,
    ];

    public static function detect(string $contentType, string $path): self
    {
        $mime = strtolower(trim(explode(';', $contentType)[0]));
        if (isset(self::BY_MIME[$mime])) {
            return self::BY_MIME[$mime];
        }
        $extension = UrlResolver::extension($path);
        if ($mime === '' || $mime === 'application/octet-stream') {
            return self::BY_EXTENSION[$extension] ?? self::Binary;
        }
        return self::Binary;
    }

    public static function fromExtension(string $path): self
    {
        return self::BY_EXTENSION[UrlResolver::extension($path)] ?? self::Binary;
    }

    public function isText(): bool
    {
        return $this !== self::Binary;
    }

    public function indexFileName(): string
    {
        return match ($this) {
            self::Html => 'index.html',
            self::Xml => 'index.xml',
            self::Json => 'index.json',
            self::Css => 'index.css',
            self::Script => 'index.js',
            self::Text => 'index.txt',
            self::Binary => 'index.bin',
        };
    }
}
