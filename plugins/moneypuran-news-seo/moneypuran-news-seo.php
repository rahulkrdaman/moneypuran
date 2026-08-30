<?php
/**
 * Plugin Name: MoneyPuran News SEO
 * Description: Google News sitemap (/news-sitemap.xml), AI-crawler allow rules, Organization/NewsMediaOrganization + BreadcrumbList + author schema (works with or without Rank Math), instant IndexNow ping on publish, and a footer trust/policy bar. Built for moneypuran.com; safe to deactivate any time.
 * Version: 1.0.3
 * Author: moneypuran.com
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

const MP_NEWS_PUBLICATION = 'MoneyPuran';
const MP_NEWS_LANG        = 'en';
const MP_NEWS_POST_TYPE   = 'post';

// Must match worker/config.json -> indexNowKey and Rank Math -> Instant Indexing.
const MP_INDEXNOW_KEY = '45d502c0fe78c9b0cd484adca03ee5b4';

// Search-engine site verification (public meta tags). Value = the content of the
// google-site-verification / msvalidate.01 tag from Search Console / Bing.
// Filterable so it can be changed without editing this file.
const MP_GOOGLE_SITE_VERIFICATION = '-YYgIBomvWMtymxAjdNAfSeSipeIOMBaK5TZtgaSmGs';
const MP_BING_SITE_VERIFICATION   = '';

add_action('wp_head', function () {
    $g = apply_filters('mp_google_site_verification', MP_GOOGLE_SITE_VERIFICATION);
    $b = apply_filters('mp_bing_site_verification', MP_BING_SITE_VERIFICATION);
    if ($g) echo '<meta name="google-site-verification" content="' . esc_attr($g) . '">' . "\n";
    if ($b) echo '<meta name="msvalidate.01" content="' . esc_attr($b) . '">' . "\n";
}, 1);

// Public profiles for the Organization `sameAs` (Google "About this source").
function mp_news_same_as() {
    return array(
        'https://twitter.com/moneypuran',
        'https://www.facebook.com/moneypuran',
        'https://www.instagram.com/moneypuran',
        'https://t.me/moneypuran',
        'https://www.linkedin.com/company/moneypuran',
        'https://www.youtube.com/@moneypuran',
    );
}

function mp_has_rankmath() {
    return class_exists('RankMath') || function_exists('rank_math');
}

/* ------------------------------------------------------------------ *
 * 1. Serve /news-sitemap.xml  (Google News; last 48h of posts)
 *    Caught early on parse_request so a *sitemap*.xml handler in an
 *    SEO plugin doesn't 404 it. No rewrite rule / flush needed.
 * ------------------------------------------------------------------ */
add_action('parse_request', function () {
    $path = isset($_SERVER['REQUEST_URI'])
        ? trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')
        : '';
    if ($path === 'news-sitemap.xml') mp_news_render_sitemap();
    if ($path === MP_INDEXNOW_KEY . '.txt') {
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/plain; charset=UTF-8', true);
            header('X-Robots-Tag: noindex', true);
        }
        echo MP_INDEXNOW_KEY;
        exit;
    }
}, 0);

function mp_news_render_sitemap() {
    $q = new WP_Query(array(
        'post_type'           => MP_NEWS_POST_TYPE,
        'post_status'         => 'publish',
        'posts_per_page'      => 1000,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'date_query'          => array(array('after' => '48 hours ago', 'inclusive' => true)),
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
    ));

    if (!headers_sent()) {
        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8', true);
        header('X-Robots-Tag: noindex, follow', true);
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
       . 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

    while ($q->have_posts()) {
        $q->the_post();
        $robots = get_post_meta(get_the_ID(), 'rank_math_robots', true);
        if (is_array($robots) && in_array('noindex', $robots, true)) continue;

        printf(
            "  <url>\n    <loc>%s</loc>\n    <news:news>\n      <news:publication>\n"
          . "        <news:name>%s</news:name>\n        <news:language>%s</news:language>\n"
          . "      </news:publication>\n      <news:publication_date>%s</news:publication_date>\n"
          . "      <news:title>%s</news:title>\n    </news:news>\n  </url>\n",
            esc_url(get_permalink()),
            esc_html(MP_NEWS_PUBLICATION),
            esc_html(MP_NEWS_LANG),
            esc_html(get_the_date('c')),
            esc_html(get_the_title())
        );
    }
    wp_reset_postdata();
    echo '</urlset>';
    exit;
}

/* ------------------------------------------------------------------ *
 * 2. robots.txt — news sitemap line + explicit AI-crawler allow rules
 * ------------------------------------------------------------------ */
add_filter('robots_txt', function ($output) {
    $add  = "\n# --- MoneyPuran News SEO ---\n";
    $add .= 'Sitemap: ' . home_url('/news-sitemap.xml') . "\n";
    foreach (array('OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Google-Extended', 'CCBot', 'Applebot-Extended', 'Amazonbot', 'Bytespider') as $bot) {
        $add .= "\nUser-agent: {$bot}\nAllow: /\n";
    }
    return $output . $add;
}, 20);

/* ------------------------------------------------------------------ *
 * 3. IndexNow — instant submission of every newly published post.
 * ------------------------------------------------------------------ */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status !== 'publish' || $new_status === $old_status) return;
    if (!($post instanceof WP_Post) || $post->post_type !== MP_NEWS_POST_TYPE) return;
    if (wp_is_post_revision($post) || wp_is_post_autosave($post)) return;

    $url = get_permalink($post);
    if (!$url) return;
    if (get_post_meta($post->ID, '_mp_indexnow_done', true)) return;
    update_post_meta($post->ID, '_mp_indexnow_done', current_time('mysql'));

    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $body = wp_json_encode(array(
        'host'        => $host,
        'key'         => MP_INDEXNOW_KEY,
        'keyLocation' => home_url('/' . MP_INDEXNOW_KEY . '.txt'),
        'urlList'     => array($url),
    ));
    wp_remote_post('https://api.indexnow.org/indexnow', array(
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => array('Content-Type' => 'application/json; charset=utf-8'),
        'body'     => $body,
    ));
}, 20, 3);

/* ------------------------------------------------------------------ *
 * 4. REST: sideload an external image and (optionally) set it as a
 *    post's featured image.  POST /wp-json/mp/v1/sideload {url,post_id?,alt?}
 *    Requires upload_files + edit_posts.
 * ------------------------------------------------------------------ */
add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/sideload', array(
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('upload_files') && current_user_can('edit_posts');
        },
        'args' => array(
            'url'     => array('required' => true),
            'post_id' => array('required' => false),
            'alt'     => array('required' => false),
        ),
        'callback' => function (WP_REST_Request $req) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $url = esc_url_raw($req['url']);
            if (!$url || !preg_match('#^https?://#i', $url)) {
                return new WP_Error('bad_url', 'A valid http(s) image URL is required.', array('status' => 400));
            }
            $post_id = absint($req['post_id']);
            $alt     = sanitize_text_field((string) $req['alt']);

            $tmp = download_url($url, 30);
            if (is_wp_error($tmp)) {
                return new WP_Error('download_failed', $tmp->get_error_message(), array('status' => 502));
            }
            $name = preg_replace('/\?.*$/', '', wp_basename($url));
            if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $name)) $name .= '.jpg';

            $att_id = media_handle_sideload(array('name' => $name, 'tmp_name' => $tmp), $post_id ?: 0, $alt ?: null);
            if (is_wp_error($att_id)) {
                @unlink($tmp);
                return new WP_Error('sideload_failed', $att_id->get_error_message(), array('status' => 502));
            }
            if ($alt) update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
            if ($post_id) set_post_thumbnail($post_id, $att_id);

            return array('id' => $att_id, 'url' => wp_get_attachment_url($att_id), 'thumbnail' => (bool) $post_id);
        },
    ));
});

/* ------------------------------------------------------------------ *
 * 5a. WITH Rank Math: upgrade the Organization node + add E-E-A-T /
 *     BreadcrumbList to posts.
 * ------------------------------------------------------------------ */
add_filter('rank_math/json_ld', function ($data, $jsonld) {
    if (!is_array($data)) return $data;

    $has_org = false;
    foreach ($data as $key => $node) {
        if (!is_array($node) || !isset($node['@type'])) continue;
        $type = $node['@type'];
        $is_org = (is_string($type) && in_array($type, array('Organization', 'NewsMediaOrganization'), true))
               || (is_array($type) && (in_array('Organization', $type, true) || in_array('NewsMediaOrganization', $type, true)));
        if ($is_org) {
            $has_org = true;
            $data[$key]['@type'] = 'NewsMediaOrganization';
            $data[$key]['sameAs'] = array_values(array_unique(array_merge(
                isset($node['sameAs']) ? (array) $node['sameAs'] : array(),
                mp_news_same_as()
            )));
            $data[$key] += array(
                'publishingPrinciples' => home_url('/editorial-policy/'),
                'correctionsPolicy'    => home_url('/corrections-policy/'),
                'ownershipFundingInfo' => home_url('/ownership-and-funding/'),
                'diversityPolicy'      => home_url('/editorial-policy/'),
                'knowsLanguage'        => array('en', 'en-US', 'en-IN'),
                'foundingDate'         => '2026',
            );
        }
        // Base Article -> NewsArticle for posts (Rank Math emits "Article" by default).
        if (is_singular('post')) {
            $t = is_array($type) ? $type : array($type);
            if (in_array('Article', $t, true) && !in_array('NewsArticle', $t, true)) {
                $data[$key]['@type'] = 'NewsArticle';
            }
        }
    }

    // No Organization node anywhere (e.g. Knowledge Graph set to Person)? Add one
    // so posts have a real publisher entity and "About this source" has data.
    if (!$has_org) {
        $logo = get_site_icon_url(512);
        $org = array(
            '@type'  => 'NewsMediaOrganization',
            '@id'    => home_url('/#organization'),
            'name'   => MP_NEWS_PUBLICATION,
            'url'    => home_url('/'),
            'sameAs' => mp_news_same_as(),
            'publishingPrinciples' => home_url('/editorial-policy/'),
            'correctionsPolicy'    => home_url('/corrections-policy/'),
            'ownershipFundingInfo' => home_url('/ownership-and-funding/'),
            'knowsLanguage'        => array('en', 'en-US', 'en-IN'),
            'foundingDate'         => '2026',
        );
        if ($logo) $org['logo'] = array('@type' => 'ImageObject', 'url' => $logo);
        $data['mp_organization'] = $org;
        foreach ($data as $k => $node) {
            if (is_array($node) && in_array(($node['@type'] ?? ''), array('NewsArticle', 'Article', 'BlogPosting'), true) && empty($node['publisher'])) {
                $data[$k]['publisher'] = array('@id' => home_url('/#organization'));
            }
        }
    }

    if (is_singular('post')) {
        $pid = get_queried_object_id();
        $author_id = (int) get_post_field('post_author', $pid);
        $bio = get_the_author_meta('description', $author_id);
        foreach ($data as $k => $node) {
            if (is_array($node) && ($node['@type'] ?? '') === 'Person') {
                if ($bio && empty($node['description'])) $data[$k]['description'] = wp_strip_all_tags($bio);
                if (empty($node['jobTitle']))  $data[$k]['jobTitle'] = 'Editor, ' . MP_NEWS_PUBLICATION;
                if (empty($node['worksFor']))  $data[$k]['worksFor'] = array('@id' => home_url('/#organization'));
                if (empty($node['knowsAbout'])) $data[$k]['knowsAbout'] = array('Stock markets', 'Investing', 'Personal finance', 'Economy');
            }
        }
        $has_bc = false;
        foreach ($data as $node) {
            if (is_array($node) && ($node['@type'] ?? '') === 'BreadcrumbList') { $has_bc = true; break; }
        }
        if (!$has_bc) {
            $items = array(array('@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')));
            $cats = get_the_category($pid);
            if (!empty($cats)) {
                $items[] = array('@type' => 'ListItem', 'position' => 2, 'name' => $cats[0]->name, 'item' => get_category_link($cats[0]->term_id));
            }
            $items[] = array('@type' => 'ListItem', 'position' => count($items) + 1, 'name' => wp_strip_all_tags(get_the_title($pid)), 'item' => get_permalink($pid));
            $data['breadcrumb'] = array(
                '@type'           => 'BreadcrumbList',
                '@id'             => get_permalink($pid) . '#breadcrumb',
                'itemListElement' => $items,
            );
        }
    }
    return $data;
}, 99, 2);

/* ------------------------------------------------------------------ *
 * 5b. Publisher entity + breadcrumbs, emitted directly in wp_head.
 *
 *     The moneypuran-theme already outputs a clean NewsArticle node,
 *     so we do NOT duplicate the article. We add the pieces the theme
 *     leaves out: a rich NewsMediaOrganization (trust signals for
 *     Google "About this source"), the WebSite node, and a
 *     BreadcrumbList on single posts. Runs whether or not Rank Math's
 *     Schema module is on; if it is on, filter 5a enriches its output
 *     and this stays complementary (multiple JSON-LD blocks are fine).
 * ------------------------------------------------------------------ */
add_action('wp_head', function () {
    if (is_admin() || is_feed()) return;

    $logo = get_site_icon_url(512);
    $org = array(
        '@type'    => 'NewsMediaOrganization',
        '@id'      => home_url('/#organization'),
        'name'     => MP_NEWS_PUBLICATION,
        'url'      => home_url('/'),
        'sameAs'   => mp_news_same_as(),
        'publishingPrinciples' => home_url('/editorial-policy/'),
        'correctionsPolicy'    => home_url('/corrections-policy/'),
        'ownershipFundingInfo' => home_url('/ownership-and-funding/'),
        'knowsLanguage'        => array('en', 'en-US', 'en-IN'),
        'foundingDate'         => '2026',
    );
    if ($logo) $org['logo'] = array('@type' => 'ImageObject', 'url' => $logo);

    $graph = array($org, array(
        '@type'      => 'WebSite',
        '@id'        => home_url('/#website'),
        'url'        => home_url('/'),
        'name'       => MP_NEWS_PUBLICATION,
        'publisher'  => array('@id' => home_url('/#organization')),
        'inLanguage' => 'en-US',
    ));

    if (is_singular('post')) {
        $pid   = get_queried_object_id();
        $items = array(array('@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')));
        $cats  = get_the_category($pid);
        if (!empty($cats)) {
            $items[] = array('@type' => 'ListItem', 'position' => 2, 'name' => $cats[0]->name, 'item' => get_category_link($cats[0]->term_id));
        }
        $items[] = array('@type' => 'ListItem', 'position' => count($items) + 1, 'name' => wp_strip_all_tags(get_the_title($pid)), 'item' => get_permalink($pid));
        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => get_permalink($pid) . '#breadcrumb',
            'itemListElement' => $items,
        );
    }

    echo "\n<script type=\"application/ld+json\">"
       . wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $graph))
       . "</script>\n";
}, 5);

/* ------------------------------------------------------------------ *
 * 6. Footer trust/policy bar — Google News wants Editorial Policy /
 *    Corrections / Ownership / About linked from every page, plus a
 *    visible "not investment advice" line for a finance site.
 * ------------------------------------------------------------------ */
add_action('wp_footer', function () {
    if (is_admin() || is_feed()) return;
    $links = array(
        'About'              => '/about-us/',
        'Editorial Policy'   => '/editorial-policy/',
        'Corrections'        => '/corrections-policy/',
        'Ownership & Funding' => '/ownership-and-funding/',
        'Privacy Policy'     => '/privacy-policy/',
        'Contact'            => '/contact/',
    );
    $out = array();
    foreach ($links as $label => $path) {
        $out[] = '<a href="' . esc_url(home_url($path)) . '">' . esc_html($label) . '</a>';
    }
    echo '<div class="mp-policy-bar" style="text-align:center;font-size:13px;line-height:2;'
       . 'padding:14px 12px;background:#0b0f14;color:#9aa7b4;border-top:1px solid #1c2733">'
       . implode(' <span style="opacity:.4">&middot;</span> ', $out)
       . '<div style="margin-top:6px;opacity:.75;max-width:760px;margin-left:auto;margin-right:auto">'
       . 'MoneyPuran publishes business &amp; markets news and education. Nothing on this site is '
       . 'investment advice or a recommendation to buy or sell any security. &copy; ' . esc_html(date('Y')) . ' '
       . esc_html(MP_NEWS_PUBLICATION) . '</div></div>'
       . '<style>.mp-policy-bar a{color:#9aa7b4;text-decoration:none}.mp-policy-bar a:hover{color:#fff}</style>';
}, 99);
