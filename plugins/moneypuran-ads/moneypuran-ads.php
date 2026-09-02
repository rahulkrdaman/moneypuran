<?php
/**
 * Plugin Name: MoneyPuran Ads and Consent
 * Description: Google Publisher Policy compliant ad infrastructure - Consent Mode v2 defaults, ads.txt, a policy-safe ad-slot renderer (labelled, spaced, never on utility/thin screens) and the AdSense loader. Dormant until an AdSense Publisher ID is set (Settings > Reading, or the MP_ADSENSE_PUB_ID constant, or the mp_adsense_pub_id filter). Safe to deactivate.
 * Version: 1.0.0
 * Author: moneypuran.com
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

/* ============================ Configuration ============================ */

/** Publisher id like "pub-1234567890123456" (or the full "ca-pub-..."). Empty = everything dormant. */
function mp_ads_pub_id() {
    $id = '';
    if (defined('MP_ADSENSE_PUB_ID')) $id = (string) MP_ADSENSE_PUB_ID;
    if (!$id) $id = (string) get_option('mp_adsense_pub_id', '');
    $id = apply_filters('mp_adsense_pub_id', $id);
    $id = trim(preg_replace('/[^a-z0-9\-]/i', '', $id));
    if ($id && strpos($id, 'pub-') !== 0) $id = 'pub-' . ltrim($id, 'pub-');
    return $id; // "" or "pub-XXXXXXXXXXXXXXXX"
}
function mp_ads_active() { return mp_ads_pub_id() !== ''; }

/** Per-slot ad unit ids (the 10-digit data-ad-slot from AdSense). Filterable. */
function mp_ads_slot_id($slot) {
    $map = apply_filters('mp_ads_slot_ids', array(
        'header'  => '',
        'in_feed' => '',
        'sidebar' => '',
        'in_article' => '',
    ));
    return isset($map[$slot]) ? preg_replace('/\D/', '', (string) $map[$slot]) : '';
}

/* Tiny settings field on Settings > Reading so a non-developer can flip it on. */
add_action('admin_init', function () {
    register_setting('reading', 'mp_adsense_pub_id', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'));
    add_settings_field('mp_adsense_pub_id', 'AdSense Publisher ID', function () {
        printf(
            '<input type="text" name="mp_adsense_pub_id" value="%s" class="regular-text" placeholder="pub-XXXXXXXXXXXXXXXX" />'
            . '<p class="description">From your approved Google AdSense account. Leave blank to keep all ad code off. '
            . 'Ads never render on search, 404, attachment or thin pages regardless.</p>',
            esc_attr(get_option('mp_adsense_pub_id', ''))
        );
    }, 'reading');
});

/* ============================ Consent Mode v2 (default: denied) ============================ */
/* Emitted for every visitor before any ad/analytics tag. When you enable Google's
   certified CMP (AdSense > Privacy & messaging / Funding Choices) it will call
   gtag('consent','update',...) after the user chooses. Non-EEA visitors can be
   granted by that CMP's region settings. */

add_action('wp_head', function () {
    ?>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {
  ad_storage: 'denied',
  ad_user_data: 'denied',
  ad_personalization: 'denied',
  analytics_storage: 'denied',
  functionality_storage: 'granted',
  security_storage: 'granted',
  wait_for_update: 500
});
gtag('set', 'url_passthrough', true);
gtag('set', 'ads_data_redaction', true);
</script>
<?php
    if (mp_ads_active()) {
        printf('<meta name="google-adsense-account" content="ca-%s">' . "\n", esc_attr(mp_ads_pub_id()));
    }
}, 0);

/* AdSense loader - only when a Publisher ID is set. Google's own CMP (loaded by this
   same tag) handles the EEA/UK consent messaging. */
add_action('wp_head', function () {
    if (!mp_ads_active()) return;
    if (is_admin() || is_feed() || is_embed()) return;
    printf(
        '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-%s" crossorigin="anonymous"></script>' . "\n",
        esc_attr(mp_ads_pub_id())
    );
}, 20);

/* ============================ Where ads are allowed ============================ */

/** Policy: no ads on screens "without publisher-content or with low-value content",
 *  under-construction, alerts/navigation, search, 404, dead-ends. */
function mp_ads_screen_allowed() {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_preview()) return false;
    if (is_404() || is_search() || is_attachment()) return false;
    if (is_singular()) {
        $post = get_queried_object();
        if (!$post || empty($post->post_content)) return false;
        // require a real amount of body copy
        $words = str_word_count(wp_strip_all_tags(strip_shortcodes($post->post_content)));
        if ($words < 250) return false;
        // never on the dedicated policy / utility pages
        $slug = $post->post_name;
        $utility = array('privacy-policy','advertising-disclosure','disclaimer','terms','terms-of-use',
                         'contact','about','about-us','editorial-policy','corrections-policy','ownership-and-funding');
        if (in_array($slug, $utility, true)) return false;
        return true;
    }
    if (is_home() || is_front_page()) return true;                 // has the hero + feed + dashboard
    if ((is_category() || is_tag() || is_tax()) && !is_paged()) {   // only real, populated archives, page 1
        $term = get_queried_object();
        return $term && !empty($term->count) && $term->count >= 3;
    }
    return false;
}

/* ============================ The ad slot renderer ============================ */

function mp_ads_render($slot, $format = 'auto') {
    if (!mp_ads_screen_allowed()) return;

    $pub  = mp_ads_pub_id();
    $unit = mp_ads_slot_id($slot);

    // Not configured yet: show a quiet inactive marker to admins only, nothing to visitors.
    if (!$pub || !$unit) {
        if (current_user_can('manage_options')) {
            printf('<div class="mp-ad-inactive" style="margin:18px auto;max-width:970px;padding:8px;border:1px dashed #cbd5e1;border-radius:8px;text-align:center;font-size:11px;color:#94a3b8">Ad slot &ldquo;%s&rdquo; &mdash; inactive (set the AdSense Publisher ID and unit id)</div>', esc_html($slot));
        }
        return;
    }

    $is_sidebar = ($slot === 'sidebar');
    printf(
        '<div class="mp-ad mp-ad--%1$s"><span class="mp-ad__label">Advertisement</span>'
        . '<ins class="adsbygoogle" style="display:block;%2$s" data-ad-client="ca-%3$s" data-ad-slot="%4$s" data-ad-format="%5$s" data-full-width-responsive="%6$s"></ins>'
        . '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script></div>',
        esc_attr($slot),
        $is_sidebar ? 'min-height:250px' : 'min-height:100px',
        esc_attr($pub),
        esc_attr($unit),
        esc_attr($format),
        $is_sidebar ? 'false' : 'true'
    );
}

/* Wire into the theme's existing hooks, replacing its placeholder output. */
add_action('init', function () {
    foreach (array('mp_ad_header', 'mp_ad_in_feed', 'mp_ad_sidebar', 'mp_ad_in_article') as $hook) {
        remove_all_actions($hook);
    }
    add_action('mp_ad_header',     function () { mp_ads_render('header'); });
    add_action('mp_ad_in_feed',    function () { mp_ads_render('in_feed'); });
    add_action('mp_ad_sidebar',    function () { mp_ads_render('sidebar'); });
    add_action('mp_ad_in_article', function () { mp_ads_render('in_article'); });
}, 20);

/* Auto-insert one in-article unit into long single posts (after ~55% of the blocks),
   away from headings/buttons so it can't sit against an action item. */
add_filter('the_content', function ($content) {
    if (!is_singular('post') || !in_the_loop() || !is_main_query()) return $content;
    if (!mp_ads_active() || !mp_ads_slot_id('in_article')) return $content;
    if (!mp_ads_screen_allowed()) return $content;

    $blocks = preg_split('/(?<=<\/p>)/', $content);
    if (count($blocks) < 8) return $content;
    $at = (int) floor(count($blocks) * 0.55);
    ob_start(); mp_ads_render('in_article', 'fluid'); $ad = ob_get_clean();
    array_splice($blocks, $at, 0, $ad);
    return implode('', $blocks);
}, 20);

/* ============================ Ad styling (labelled, spaced, never sticky) ============================ */

add_action('wp_head', function () {
    ?>
<style id="mp-ads-css">
.mp-ad{margin:24px auto;max-width:100%;text-align:center;clear:both;overflow:hidden}
.mp-ad--header{max-width:970px}
.mp-ad--in_feed,.mp-ad--in_article{max-width:728px}
.mp-ad--sidebar{max-width:300px;margin:18px auto}
.mp-ad__label{display:block;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--mp-muted,#94a3b8);margin-bottom:4px}
.mp-ad .adsbygoogle{background:transparent}
/* keep ads clear of navigation / buttons / the sticky header */
.mp-header + .mp-ad,.mp-nav + .mp-ad{margin-top:28px}
.mp-ad + .mp-cta,.mp-ad + .mp-nav,.mp-ad + button{margin-top:20px}
</style>
<?php
}, 21);

/* ============================ ads.txt ============================ */
/* Only served once a Publisher ID is configured - an ads.txt that names no
   authorised seller (or a wrong one) is itself a policy problem. */

add_action('init', function () {
    add_rewrite_rule('^ads\.txt$', 'index.php?mp_ads_txt=1', 'top');
});
add_filter('query_vars', function ($v) { $v[] = 'mp_ads_txt'; return $v; });
add_action('template_redirect', function () {
    if (!get_query_var('mp_ads_txt')) return;
    $pub = mp_ads_pub_id();
    header('Content-Type: text/plain; charset=utf-8');
    if (!$pub) { status_header(404); echo "# ads.txt not configured yet\n"; exit; }
    $lines = array(
        '# MoneyPuran authorised digital sellers',
        'google.com, ' . $pub . ', DIRECT, f08c47fec0942fa0',
    );
    echo implode("\n", apply_filters('mp_ads_txt_lines', $lines, $pub)) . "\n";
    exit;
});

register_activation_hook(__FILE__, function () {
    add_rewrite_rule('^ads\.txt$', 'index.php?mp_ads_txt=1', 'top');
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

/* ============================ Thin / utility screens: keep them out of the index ============================ */
/* "Inventory value" + Search-spam policies: don't let low-value auto-generated
   listing pages get indexed (or, later, carry ads). */

add_filter('wp_robots', function ($robots) {
    if (is_search() || is_404()
        || is_author()
        || is_date()
        || ((is_tag() || is_tax()) && (int) (get_queried_object()->count ?? 0) < 3)
        || (is_paged() && (is_archive() || is_home()))) {
        $robots['noindex']  = true;
        $robots['follow']   = true;
        unset($robots['index']);
    }
    return $robots;
}, 20);
