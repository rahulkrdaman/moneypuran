<?php
/**
 * Plugin Name: MoneyPuran News SEO
 * Description: Google News sitemap (/news-sitemap.xml), AI-crawler allow rules, Organization/NewsMediaOrganization + BreadcrumbList + author schema (works with or without Rank Math), instant IndexNow ping on publish, and a footer trust/policy bar. Built for moneypuran.com; safe to deactivate any time.
 * Version: 1.1.3
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
 * 0c. Make WordPress REST API Application Password auth work on this
 *     Hostinger + custom-theme stack. Three fixes:
 *   (a) restore $_SERVER['HTTP_AUTHORIZATION'] / PHP_AUTH_USER|PW from
 *       the CGI REDIRECT_* copies + an .htaccess pass-through rule;
 *   (b) force `application_password_is_api_request` true for REST/XML-RPC
 *       (the custom MoneyPuran plugin/theme had removed core's filter, so
 *       app-password auth never ran and every call was `rest_not_logged_in`).
 * ------------------------------------------------------------------ */
add_action('plugins_loaded', function () {
    if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
        foreach (array('REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION') as $k) {
            if (!empty($_SERVER[$k])) { $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER[$k]; break; }
        }
        if (empty($_SERVER['HTTP_AUTHORIZATION']) && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $val) {
                if (strtolower($name) === 'authorization') { $_SERVER['HTTP_AUTHORIZATION'] = $val; break; }
            }
        }
    }
    // Populate PHP_AUTH_USER/PW from a Basic header PHP didn't split itself.
    if (empty($_SERVER['PHP_AUTH_USER']) && !empty($_SERVER['HTTP_AUTHORIZATION'])
        && stripos($_SERVER['HTTP_AUTHORIZATION'], 'basic ') === 0) {
        $creds = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6));
        if ($creds && strpos($creds, ':') !== false) {
            list($u, $p) = explode(':', $creds, 2);
            $_SERVER['PHP_AUTH_USER'] = $u;
            $_SERVER['PHP_AUTH_PW']   = $p;
        }
    }
}, 0);

/*
 * The core filter that makes Application Password auth apply to REST / XML-RPC
 * requests has been removed or overridden on this site (the custom MoneyPuran
 * plugin/theme does its own API auth). Without it `wp_authenticate_application_password()`
 * bails before checking the password and every REST call is anonymous
 * (`rest_not_logged_in`). Restore core's intended behaviour.
 */
add_filter('application_password_is_api_request', function ($is) {
    if ($is) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return true;
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (strpos($uri, '/wp-json/') !== false || strpos($uri, 'rest_route=') !== false) return true;
    return $is;
}, 99);

// Add the .htaccess rule once (idempotent, marker-delimited, non-fatal).
add_action('admin_init', function () {
    if (get_option('mp_htaccess_auth') === 'v3') return;
    $htaccess = get_home_path() . '.htaccess';
    if (!file_exists($htaccess) || !is_writable($htaccess)) {
        update_option('mp_htaccess_auth', 'skip', false);
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    // Only set the env var when a non-empty Authorization header is actually
    // present (a bare "(.*)" match sets it to "" and shadows the real value).
    $lines = array(
        '<IfModule mod_rewrite.c>',
        'RewriteEngine On',
        'RewriteCond %{HTTP:Authorization} .',
        'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
        '</IfModule>',
        '<IfModule mod_setenvif.c>',
        'SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1',
        '</IfModule>',
    );
    if (insert_with_markers($htaccess, 'MoneyPuran REST Auth', $lines)) {
        update_option('mp_htaccess_auth', 'v3', false);
    }
});

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
        'About'               => '/about-us/',
        'Editorial Policy'    => '/editorial-policy/',
        'Corrections'         => '/corrections-policy/',
        'Ownership & Funding' => '/ownership-and-funding/',
        'Advertising'         => '/advertising-disclosure/',
        'Disclaimer'          => '/disclaimer/',
        'Privacy Policy'      => '/privacy-policy/',
        'Terms'              => '/terms/',
        'Contact'            => '/contact/',
    );
    $out = array();
    foreach ($links as $label => $path) {
        $out[] = '<a href="' . esc_url(home_url($path)) . '">' . esc_html($label) . '</a>';
    }
    echo '<div class="mp-policy-bar" style="text-align:center;font-size:13px;line-height:2;'
       . 'padding:14px 12px;background:#0b0f14;color:#9aa7b4;border-top:1px solid #1c2733">'
       . implode(' <span style="opacity:.4">&middot;</span> ', $out)
       . '<div style="margin-top:6px;opacity:.75;max-width:820px;margin-left:auto;margin-right:auto">'
       . 'MoneyPuran publishes business &amp; markets news and education. Nothing on this site is '
       . 'investment advice or a recommendation to buy or sell any security. Ads are served by Google; see '
       . '<a href="' . esc_url(home_url('/privacy-policy/')) . '">Privacy Policy</a> and '
       . '<a href="https://policies.google.com/technologies/partner-sites" rel="nofollow noopener" target="_blank">how Google uses data</a>. '
       . '&copy; ' . esc_html(date('Y')) . ' ' . esc_html(MP_NEWS_PUBLICATION) . '</div></div>'
       . '<style>.mp-policy-bar a{color:#9aa7b4;text-decoration:none}.mp-policy-bar a:hover{color:#fff}</style>';
}, 99);

/* ------------------------------------------------------------------ *
 * 7. Front-end performance + noise cleanup (platform hygiene).
 *    - drop the wp-emoji script (the theme's topbar/nav emoji were
 *      being swapped for dozens of remote s.w.org SVG requests,
 *      which also kept the page from ever going network-idle);
 *    - stop self-pingbacks (the News Desk's internal links were
 *      generating a flood of pingback "comments").
 * ------------------------------------------------------------------ */
add_action('init', function () {
    // wp-emoji
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    add_filter('tiny_mce_plugins', function ($plugins) {
        return is_array($plugins) ? array_diff($plugins, array('wpemoji')) : array();
    });
    add_filter('emoji_svg_url', '__return_false');
});

// Don't ping ourselves when an article links to another MoneyPuran article.
add_action('pre_ping', function (&$links) {
    $home = home_url();
    foreach ($links as $i => $l) {
        if (strpos($l, $home) === 0) unset($links[$i]);
    }
});

/* ============================================================================
 * ADS & CONSENT  (Google Publisher Policy compliance)  - added v1.1.0
 * Consent Mode v2 defaults, AdSense loader, a policy-safe ad-slot renderer,
 * /ads.txt and thin-screen noindex. Everything ad-serving stays dormant until
 * an AdSense Publisher ID is set (Settings > Reading, the MP_ADSENSE_PUB_ID
 * constant, or the mp_adsense_pub_id filter).
 * ==========================================================================*/

function mp_ads_pub_id() {
    $id = '';
    if (defined('MP_ADSENSE_PUB_ID')) $id = (string) MP_ADSENSE_PUB_ID;
    if (!$id) $id = (string) get_option('mp_adsense_pub_id', '');
    $id = (string) apply_filters('mp_adsense_pub_id', $id);
    $id = trim(preg_replace('/[^a-z0-9\-]/i', '', $id));
    if ($id !== '' && strpos($id, 'pub-') !== 0) $id = 'pub-' . preg_replace('/^pub-?/', '', $id);
    return $id;
}
function mp_ads_active() { return mp_ads_pub_id() !== ''; }
function mp_ads_slot_id($slot) {
    $map = apply_filters('mp_ads_slot_ids', array('header' => '', 'in_feed' => '', 'sidebar' => '', 'in_article' => ''));
    return isset($map[$slot]) ? preg_replace('/\D/', '', (string) $map[$slot]) : '';
}

add_action('admin_init', function () {
    register_setting('reading', 'mp_adsense_pub_id', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'));
    add_settings_field('mp_adsense_pub_id', 'AdSense Publisher ID', function () {
        printf(
            '<input type="text" name="mp_adsense_pub_id" value="%s" class="regular-text" placeholder="pub-XXXXXXXXXXXXXXXX" />'
            . '<p class="description">From your approved Google AdSense account. Blank = all ad code stays off. '
            . 'Ads never render on search, 404, attachment, thin or policy pages regardless.</p>',
            esc_attr(get_option('mp_adsense_pub_id', ''))
        );
    }, 'reading');
});

/* Consent Mode v2 - denied by default; Google's certified CMP (AdSense > Privacy
   & messaging) updates it once the visitor chooses. */
add_action('wp_head', function () {
    echo "<script>\n"
        . "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
        . "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});\n"
        . "gtag('set','url_passthrough',true);gtag('set','ads_data_redaction',true);\n"
        . "</script>\n";
    if (mp_ads_active()) {
        echo '<meta name="google-adsense-account" content="ca-' . esc_attr(mp_ads_pub_id()) . '">' . "\n";
    }
}, 0);

add_action('wp_head', function () {
    if (!mp_ads_active() || is_admin() || is_feed() || is_embed()) return;
    echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-'
        . esc_attr(mp_ads_pub_id()) . '" crossorigin="anonymous"></script>' . "\n";
}, 20);

/* Screens where Google-served ads are allowed (Inventory value / Ads interfering). */
function mp_ads_screen_allowed() {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_preview()) return false;
    if (is_404() || is_search() || is_attachment()) return false;
    if (is_singular()) {
        $post = get_queried_object();
        if (!$post || empty($post->post_content)) return false;
        if (str_word_count(wp_strip_all_tags(strip_shortcodes($post->post_content))) < 250) return false;
        $utility = array('privacy-policy', 'advertising-disclosure', 'disclaimer', 'terms', 'terms-of-use', 'terms-of-service',
                         'contact', 'about', 'about-us', 'editorial-policy', 'corrections-policy', 'ownership-and-funding');
        if (in_array($post->post_name, $utility, true)) return false;
        return true;
    }
    if (is_home() || is_front_page()) return true;
    if ((is_category() || is_tag() || is_tax()) && !is_paged()) {
        $t = get_queried_object();
        return $t && !empty($t->count) && $t->count >= 3;
    }
    return false;
}

function mp_ads_render($slot, $format = 'auto') {
    if (!mp_ads_screen_allowed()) return;
    $pub  = mp_ads_pub_id();
    $unit = mp_ads_slot_id($slot);
    if ($pub === '' || $unit === '') {
        if (current_user_can('manage_options')) {
            printf('<div class="mp-ad-inactive" style="margin:18px auto;max-width:970px;padding:8px;border:1px dashed #cbd5e1;border-radius:8px;text-align:center;font-size:11px;color:#94a3b8">Ad slot &ldquo;%s&rdquo; &mdash; inactive (set the AdSense Publisher ID and unit id)</div>', esc_html($slot));
        }
        return;
    }
    $is_sidebar = ($slot === 'sidebar');
    printf(
        '<div class="mp-ad mp-ad--%1$s"><span class="mp-ad__label">Advertisement</span>'
        . '<ins class="adsbygoogle" style="display:block;%2$s" data-ad-client="ca-%3$s" data-ad-slot="%4$s" data-ad-format="%5$s" data-full-width-responsive="%6$s"></ins>'
        . '<script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>',
        esc_attr($slot), $is_sidebar ? 'min-height:250px' : 'min-height:100px',
        esc_attr($pub), esc_attr($unit), esc_attr($format), $is_sidebar ? 'false' : 'true'
    );
}

add_action('init', function () {
    foreach (array('mp_ad_header', 'mp_ad_in_feed', 'mp_ad_sidebar', 'mp_ad_in_article') as $h) remove_all_actions($h);
    add_action('mp_ad_header',     function () { mp_ads_render('header'); });
    add_action('mp_ad_in_feed',    function () { mp_ads_render('in_feed'); });
    add_action('mp_ad_sidebar',    function () { mp_ads_render('sidebar'); });
    add_action('mp_ad_in_article', function () { mp_ads_render('in_article'); });
}, 20);

add_filter('the_content', function ($content) {
    if (!is_singular('post') || !in_the_loop() || !is_main_query()) return $content;
    if (!mp_ads_active() || mp_ads_slot_id('in_article') === '' || !mp_ads_screen_allowed()) return $content;
    $blocks = preg_split('/(?<=<\/p>)/', $content);
    if (count($blocks) < 8) return $content;
    ob_start(); mp_ads_render('in_article', 'fluid'); $ad = ob_get_clean();
    array_splice($blocks, (int) floor(count($blocks) * 0.55), 0, $ad);
    return implode('', $blocks);
}, 20);

add_action('wp_head', function () {
    echo "<style id=\"mp-ads-css\">\n"
        . ".mp-ad{margin:24px auto;max-width:100%;text-align:center;clear:both;overflow:hidden}\n"
        . ".mp-ad--header{max-width:970px}.mp-ad--in_feed,.mp-ad--in_article{max-width:728px}.mp-ad--sidebar{max-width:300px;margin:18px auto}\n"
        . ".mp-ad__label{display:block;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--mp-muted,#94a3b8);margin-bottom:4px}\n"
        . ".mp-header + .mp-ad,.mp-nav + .mp-ad{margin-top:28px}.mp-ad + .mp-cta,.mp-ad + .mp-nav,.mp-ad + button{margin-top:20px}\n"
        . "</style>\n";
}, 21);

/* /ads.txt - served directly (no rewrite rules), only once a Publisher ID is set. */
add_action('init', function () {
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (!preg_match('#^/+ads\.txt(?:$|[?/])#i', $uri)) return;
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $pub = mp_ads_pub_id();
    if ($pub === '') { status_header(404); echo "# ads.txt not configured yet\n"; exit; }
    $lines = apply_filters('mp_ads_txt_lines', array(
        '# MoneyPuran authorised digital sellers',
        'google.com, ' . $pub . ', DIRECT, f08c47fec0942fa0',
    ), $pub);
    echo implode("\n", $lines) . "\n";
    exit;
}, 0);

/* Inventory value + web-search-spam: keep thin auto-generated listing screens
   out of the index. */
add_filter('wp_robots', function ($robots) {
    $obj   = get_queried_object();
    $count = ($obj && isset($obj->count)) ? (int) $obj->count : 0;
    $thin  = is_search() || is_404() || is_author() || is_date()
        || ((is_category() || is_tag() || is_tax()) && $count < 3)
        || (is_paged() && (is_archive() || is_home()));
    if ($thin) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
        unset($robots['index']);
    }
    return $robots;
}, 20);
// Rank Math prints its own robots meta and ignores wp_robots on archives - mirror the rule there.
add_filter('rank_math/frontend/robots', function ($robots) {
    $obj   = get_queried_object();
    $count = ($obj && isset($obj->count)) ? (int) $obj->count : 0;
    $thin  = is_search() || is_404() || is_author() || is_date()
        || ((is_category() || is_tag() || is_tax()) && $count < 3)
        || (is_paged() && (is_archive() || is_home()));
    if ($thin) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
    }
    return $robots;
}, 20);

/* List the Google-News sitemap inside Rank Math's sitemap index too. */
add_filter('rank_math/sitemap/index', function ($xml) {
    $entry = "\t<sitemap>\n"
           . "\t\t<loc>" . esc_url(home_url('/news-sitemap.xml')) . "</loc>\n"
           . "\t\t<lastmod>" . gmdate('c') . "</lastmod>\n"
           . "\t</sitemap>\n";
    return $entry . $xml;
});
