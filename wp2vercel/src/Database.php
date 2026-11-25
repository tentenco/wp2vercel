<?php

declare(strict_types=1);

namespace WP2Vercel;

final class Database
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'wp2vercel_db_version';

    public static function builds(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wp2vercel_builds';
    }

    public static function urls(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wp2vercel_urls';
    }

    public static function logs(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wp2vercel_logs';
    }

    public static function migrate(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE ' . self::builds() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'full',
            status varchar(20) NOT NULL DEFAULT 'pending',
            step varchar(40) NOT NULL DEFAULT 'setup',
            state longtext NOT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset;");

        dbDelta('CREATE TABLE ' . self::urls() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            build_id bigint(20) unsigned NOT NULL,
            url_hash char(40) NOT NULL,
            url text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            claimed_at int(10) unsigned NOT NULL DEFAULT 0,
            http_status smallint(5) unsigned NOT NULL DEFAULT 0,
            content_type varchar(120) NOT NULL DEFAULT '',
            file_path text NOT NULL,
            found_on text NOT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY build_url (build_id,url_hash),
            KEY build_status (build_id,status,id)
        ) $charset;");

        dbDelta('CREATE TABLE ' . self::logs() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            build_id bigint(20) unsigned NOT NULL,
            level varchar(10) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY build_id (build_id,id)
        ) $charset;");

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    public static function drop(): void
    {
        global $wpdb;
        foreach ([self::builds(), self::urls(), self::logs()] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        delete_option(self::VERSION_OPTION);
    }
}
