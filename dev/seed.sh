#!/bin/sh
set -eu
WP="docker compose -f $(dirname "$0")/docker-compose.yml run --rm -T cli"

$WP core is-installed 2>/dev/null || $WP core install --url=http://localhost:8089 --title="Private Origin Demo" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
$WP rewrite structure '/%postname%/' --hard
$WP plugin activate wp2vercel

$WP post list --post_type=page --name=about --format=ids | grep -q . && exit 0

IMAGE=$($WP media import /fixtures/hero.png --title="Hero" --porcelain)
$WP term create category News --slug=news
$WP term create category 產品 --slug=products
$WP post create --post_type=page --post_status=publish --post_title="About" --post_name=about --post_content='<!-- wp:paragraph --><p>About us. <a href="http://localhost:8089/contact/">Contact</a> or <a href="/downloads/">downloads</a>.</p><!-- /wp:paragraph -->'
$WP post create --post_type=page --post_status=publish --post_title="Contact" --post_name=contact --post_content='<!-- wp:paragraph --><p>Write to us.</p><!-- /wp:paragraph -->'
$WP post create --post_type=page --post_status=publish --post_title="關於我們" --post_name="關於我們" --post_content='<!-- wp:paragraph --><p>中文網址的頁面。</p><!-- /wp:paragraph -->'
$WP post create --post_type=page --post_status=publish --post_title="Orphan landing" --post_name=orphan-landing --post_content='<!-- wp:paragraph --><p>No menu links here.</p><!-- /wp:paragraph -->'
$WP post create --post_type=page --post_status=draft --post_title="Secret draft" --post_name=secret-draft --post_content='draft'
$WP post create --post_type=page --post_status=publish --post_password=hunter2 --post_title="Protected" --post_name=protected --post_content='protected'
for n in 1 2 3 4 5 6 7 8 9 10 11 12; do
  ID=$($WP post create --post_status=publish --post_title="News item $n" --post_name="news-item-$n" --post_category=news --post_content="<!-- wp:paragraph --><p>Body of item $n with a <a href=\"http://localhost:8089/about/\">link</a>.</p><!-- /wp:paragraph --><!-- wp:image --><figure class=\"wp-block-image\"><img src=\"http://localhost:8089/wp-content/uploads/$(date +%Y/%m)/hero.png\" alt=\"\"/></figure><!-- /wp:image -->" --porcelain)
  $WP post meta set "$ID" _thumbnail_id "$IMAGE" >/dev/null
done
$WP post create --post_status=publish --post_title="產品發表" --post_name="產品發表" --post_category=products --post_content='<!-- wp:paragraph --><p>產品。</p><!-- /wp:paragraph -->'
$WP option update posts_per_page 5
echo seeded
