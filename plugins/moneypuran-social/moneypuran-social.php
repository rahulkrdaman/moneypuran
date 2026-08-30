<?php
/**
 * Plugin Name: MoneyPuran Social Auto-Post
 * Description: Auto-shares newly published posts to X (Twitter), a Facebook Page and a Telegram channel using the platforms' own free APIs. A self-hosted alternative to dlvr.it — no monthly fee. Add your keys under Settings → MoneyPuran Social. Safe to deactivate any time.
 * Version: 1.0.0
 * Author: moneypuran.com
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

class MP_Social {

    const OPT        = 'mp_social_opts';
    const COUNTS     = 'mp_social_counts';      // { 'YYYY-MM-DD': { x:int, fb:int, tg:int } }
    const META_DONE  = '_mp_social_result';
    const CRON_ONE   = 'mp_social_share_event';
    const CRON_SWEEP = 'mp_social_catchup';

    static function init() {
        add_action('admin_menu',             [__CLASS__, 'menu']);
        add_action('admin_init',             [__CLASS__, 'register_settings']);
        add_action('transition_post_status', [__CLASS__, 'on_transition'], 20, 3);
        add_action(self::CRON_ONE,           [__CLASS__, 'share_post'], 10, 1);
        add_action(self::CRON_SWEEP,         [__CLASS__, 'sweep']);
        add_action('add_meta_boxes',         [__CLASS__, 'metabox']);
        add_action('save_post',              [__CLASS__, 'save_metabox']);
        add_action('wp_ajax_mp_social_test', [__CLASS__, 'ajax_test']);
        add_action('wp_ajax_mp_social_now',  [__CLASS__, 'ajax_now']);

        if (!wp_next_scheduled(self::CRON_SWEEP)) {
            wp_schedule_event(time() + 600, 'hourly', self::CRON_SWEEP);
        }
    }

    static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_SWEEP);
        wp_clear_scheduled_hook(self::CRON_ONE);
    }

    static function opts() {
        return wp_parse_args(get_option(self::OPT, array()), array(
            'x_enabled'  => 0,
            'x_api_key'  => '', 'x_api_secret' => '',
            'x_token'    => '', 'x_token_secret' => '',
            'x_daily'    => 15,
            'x_template' => "{title}\n{url}",
            'fb_enabled' => 0,
            'fb_page_id' => '', 'fb_token' => '',
            'fb_daily'   => 25,
            'fb_template'=> "{title}",
            'tg_enabled' => 0,
            'tg_bot_token' => '', 'tg_chat_id' => '',
            'tg_daily'   => 50,
            'tg_template'=> "<b>{title}</b>\n\n{url}",
            'delay_min'  => 3,
            'categories' => array(),
            'hashtags'   => 1,
        ));
    }

    static function on_transition($new, $old, $post) {
        if ($new !== 'publish' || $old === 'publish') return;
        if (!$post || $post->post_type !== 'post') return;
        if (get_post_meta($post->ID, '_mp_social_optout', true)) return;
        if (get_post_meta($post->ID, self::META_DONE, true)) return;

        $o = self::opts();
        if (!empty($o['categories'])) {
            $cats = wp_get_post_categories($post->ID);
            if (!array_intersect($cats, array_map('intval', $o['categories']))) {
                update_post_meta($post->ID, self::META_DONE, 'skip:category');
                return;
            }
        }
        $delay = max(0, min(60, (int) $o['delay_min'])) * MINUTE_IN_SECONDS;
        wp_schedule_single_event(time() + $delay, self::CRON_ONE, array($post->ID));
    }

    static function sweep() {
        $q = new WP_Query(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'date_query'     => array(array('after' => '24 hours ago', 'inclusive' => true)),
            'meta_query'     => array(array('key' => self::META_DONE, 'compare' => 'NOT EXISTS')),
            'no_found_rows'  => true,
        ));
        foreach ($q->posts as $p) {
            if (get_post_meta($p->ID, '_mp_social_optout', true)) {
                update_post_meta($p->ID, self::META_DONE, 'skip:optout');
                continue;
            }
            self::share_post($p->ID);
        }
        wp_reset_postdata();
    }

    static function share_post($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') return;
        if (get_post_meta($post_id, self::META_DONE, true)) return;

        $o   = self::opts();
        $url = get_permalink($post_id);
        $title = self::seo_title($post_id, $post);
        $tags  = $o['hashtags'] ? self::hashtags($post_id) : '';
        $results = array();

        if ($o['x_enabled'] && self::budget_ok('x', $o['x_daily'])) {
            $r = self::post_x($o, self::render($o['x_template'], $title, $url, $tags, 260));
            $results['x'] = $r;
            if (!is_wp_error($r)) self::bump('x');
        }
        if ($o['fb_enabled'] && self::budget_ok('fb', $o['fb_daily'])) {
            $r = self::post_fb($o, self::render($o['fb_template'], $title, '', $tags, 0), $url);
            $results['fb'] = $r;
            if (!is_wp_error($r)) self::bump('fb');
        }
        if ($o['tg_enabled'] && self::budget_ok('tg', $o['tg_daily'])) {
            $r = self::post_tg($o, self::render($o['tg_template'], $title, $url, $tags, 0));
            $results['tg'] = $r;
            if (!is_wp_error($r)) self::bump('tg');
        }

        update_post_meta($post_id, self::META_DONE, self::flatten($results));
    }

    /* ---- X (Twitter) API v2, OAuth 1.0a (HMAC-SHA1). Free tier ~500 writes/mo. */
    static function post_x($o, $text) {
        foreach (array('x_api_key','x_api_secret','x_token','x_token_secret') as $k) {
            if (empty($o[$k])) return new WP_Error('x_config', 'X API keys missing.');
        }
        $url = 'https://api.twitter.com/2/tweets';
        $oauth = array(
            'oauth_consumer_key'     => $o['x_api_key'],
            'oauth_nonce'            => wp_generate_password(24, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $o['x_token'],
            'oauth_version'          => '1.0',
        );
        ksort($oauth);
        $param_str = array();
        foreach ($oauth as $k => $v) $param_str[] = self::rfc3986($k) . '=' . self::rfc3986($v);
        $base = 'POST&' . self::rfc3986($url) . '&' . self::rfc3986(implode('&', $param_str));
        $key  = self::rfc3986($o['x_api_secret']) . '&' . self::rfc3986($o['x_token_secret']);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        $header = array();
        foreach ($oauth as $k => $v) $header[] = self::rfc3986($k) . '="' . self::rfc3986($v) . '"';

        $res = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'OAuth ' . implode(', ', $header), 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('text' => $text)),
        ));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 200 && $code < 300 && !empty($body['data']['id'])) return 'ok:' . $body['data']['id'];
        return new WP_Error('x_api', 'HTTP ' . $code . ' ' . wp_remote_retrieve_body($res));
    }

    /* ---- Facebook Page feed ---- */
    static function post_fb($o, $message, $link) {
        if (empty($o['fb_page_id']) || empty($o['fb_token'])) {
            return new WP_Error('fb_config', 'Facebook Page ID / token missing.');
        }
        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($o['fb_page_id']) . '/feed';
        $res = wp_remote_post($url, array(
            'timeout' => 20,
            'body'    => array('message' => $message, 'link' => $link, 'access_token' => $o['fb_token']),
        ));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 200 && $code < 300 && !empty($body['id'])) return 'ok:' . $body['id'];
        return new WP_Error('fb_api', 'HTTP ' . $code . ' ' . wp_remote_retrieve_body($res));
    }

    /* ---- Telegram channel (Bot API). Add the bot as an admin of the channel. */
    static function post_tg($o, $text) {
        if (empty($o['tg_bot_token']) || empty($o['tg_chat_id'])) {
            return new WP_Error('tg_config', 'Telegram bot token / chat ID missing.');
        }
        $url = 'https://api.telegram.org/bot' . trim($o['tg_bot_token']) . '/sendMessage';
        $res = wp_remote_post($url, array(
            'timeout' => 20,
            'body'    => array(
                'chat_id'                  => trim($o['tg_chat_id']),
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview'  => 'false',
            ),
        ));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 200 && $code < 300 && !empty($body['ok'])) return 'ok:' . ($body['result']['message_id'] ?? '1');
        return new WP_Error('tg_api', 'HTTP ' . $code . ' ' . wp_remote_retrieve_body($res));
    }

    /* ---- helpers ---- */
    static function rfc3986($s) { return str_replace('%7E', '~', rawurlencode($s)); }

    static function seo_title($id, $post) {
        $t = get_post_meta($id, 'rank_math_title', true);
        $t = $t ? trim(preg_replace('/\s*%[^%]+%\s*/', '', $t)) : '';
        return $t !== '' ? $t : get_the_title($post);
    }

    static function hashtags($id) {
        $out = array();
        foreach (wp_get_post_categories($id, array('fields' => 'names')) as $n) {
            $tag = preg_replace('/[^A-Za-z0-9]/', '', $n);
            if ($tag !== '') $out[] = '#' . $tag;
            if (count($out) >= 2) break;
        }
        return implode(' ', $out);
    }

    static function render($tpl, $title, $url, $tags, $limit) {
        $s = strtr($tpl, array('{title}' => $title, '{url}' => $url, '{tags}' => $tags));
        if ($tags && strpos($s, $tags) === false) $s = rtrim($s) . "\n" . $tags;
        $s = trim(preg_replace("/\n{3,}/", "\n\n", $s));
        if ($limit > 0 && mb_strlen($s) > $limit) {
            $tailparts = array_filter(array($url, $tags));
            $tail = $tailparts ? "\n" . implode(' ', $tailparts) : '';
            $room = $limit - mb_strlen($tail) - 1;
            $s = mb_substr($title, 0, max(10, $room)) . '…' . $tail;
        }
        return $s;
    }

    static function budget_ok($net, $daily) {
        $daily = max(1, (int) $daily);
        $c = get_option(self::COUNTS, array());
        $today = current_time('Y-m-d');
        $used = isset($c[$today][$net]) ? (int) $c[$today][$net] : 0;
        return $used < $daily;
    }
    static function bump($net) {
        $c = get_option(self::COUNTS, array());
        $today = current_time('Y-m-d');
        if (!isset($c[$today])) $c[$today] = array('x' => 0, 'fb' => 0, 'tg' => 0);
        $c[$today][$net] = (isset($c[$today][$net]) ? (int) $c[$today][$net] : 0) + 1;
        krsort($c);
        $c = array_slice($c, 0, 10, true);
        update_option(self::COUNTS, $c, false);
    }

    static function flatten($results) {
        $out = array();
        foreach ($results as $net => $r) {
            $out[$net] = is_wp_error($r) ? ('error: ' . $r->get_error_message()) : $r;
        }
        return $out ?: 'skip:disabled';
    }

    /* ---- editor metabox ---- */
    static function metabox() {
        add_meta_box('mp_social', 'Social auto-post', function ($post) {
            $done = get_post_meta($post->ID, self::META_DONE, true);
            $out  = get_post_meta($post->ID, '_mp_social_optout', true);
            wp_nonce_field('mp_social_mb', 'mp_social_mb_n');
            echo '<label><input type="checkbox" name="mp_social_optout" value="1" ' . checked($out, '1', false) . '> Do not auto-post this one</label>';
            echo '<p style="margin:8px 0 0;color:#555">';
            echo $done ? 'Status: <code>' . esc_html(is_array($done) ? wp_json_encode($done) : $done) . '</code>' : 'Not shared yet.';
            echo '</p>';
            if ($post->post_status === 'publish') {
                echo '<p><button type="button" class="button" id="mp-social-now" data-id="' . (int) $post->ID . '">Share now</button> <span id="mp-social-now-msg"></span></p>';
                echo '<script>jQuery("#mp-social-now").on("click",function(){var b=jQuery(this);b.prop("disabled",true);jQuery("#mp-social-now-msg").text("sending…");jQuery.post(ajaxurl,{action:"mp_social_now",id:b.data("id"),_n:"' . wp_create_nonce('mp_social_now') . '"},function(r){jQuery("#mp-social-now-msg").text(r&&r.data?r.data:"done");b.prop("disabled",false);});});</script>';
            }
        }, 'post', 'side', 'low');
    }
    static function save_metabox($post_id) {
        if (!isset($_POST['mp_social_mb_n']) || !wp_verify_nonce($_POST['mp_social_mb_n'], 'mp_social_mb')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!empty($_POST['mp_social_optout'])) update_post_meta($post_id, '_mp_social_optout', '1');
        else delete_post_meta($post_id, '_mp_social_optout');
    }

    static function ajax_now() {
        check_ajax_referer('mp_social_now', '_n');
        $id = absint($_POST['id'] ?? 0);
        if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error('no permission');
        delete_post_meta($id, self::META_DONE);
        self::share_post($id);
        wp_send_json_success(wp_json_encode(get_post_meta($id, self::META_DONE, true)));
    }

    static function ajax_test() {
        check_ajax_referer('mp_social_test', '_n');
        if (!current_user_can('manage_options')) wp_send_json_error('no permission');
        $o = self::opts();
        $net = sanitize_key($_POST['net'] ?? '');
        $stamp = current_time('H:i');
        if ($net === 'x')       $r = self::post_x($o, "MoneyPuran test — {$stamp}");
        elseif ($net === 'fb')  $r = self::post_fb($o, "MoneyPuran test — {$stamp}", home_url('/'));
        elseif ($net === 'tg')  $r = self::post_tg($o, "MoneyPuran test — {$stamp}");
        else wp_send_json_error('unknown network');
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
        wp_send_json_success('OK — ' . $r);
    }

    /* ---- settings screen ---- */
    static function menu() {
        add_options_page('MoneyPuran Social', 'MoneyPuran Social', 'manage_options', 'mp-social', [__CLASS__, 'screen']);
    }
    static function register_settings() {
        register_setting('mp_social', self::OPT, array('sanitize_callback' => [__CLASS__, 'sanitize']));
    }
    static function sanitize($in) {
        $o = self::opts();
        $b = function ($k) use ($in) { return !empty($in[$k]) ? 1 : 0; };
        $t = function ($k) use ($in) { return isset($in[$k]) ? sanitize_text_field($in[$k]) : ''; };
        return array(
            'x_enabled'      => $b('x_enabled'),
            'x_api_key'      => $t('x_api_key'),
            'x_api_secret'   => $t('x_api_secret'),
            'x_token'        => $t('x_token'),
            'x_token_secret' => $t('x_token_secret'),
            'x_daily'        => max(1, min(480, (int) ($in['x_daily'] ?? 15))),
            'x_template'     => isset($in['x_template']) ? wp_kses_post($in['x_template']) : $o['x_template'],
            'fb_enabled'     => $b('fb_enabled'),
            'fb_page_id'     => $t('fb_page_id'),
            'fb_token'       => $t('fb_token'),
            'fb_daily'       => max(1, min(200, (int) ($in['fb_daily'] ?? 25))),
            'fb_template'    => isset($in['fb_template']) ? wp_kses_post($in['fb_template']) : $o['fb_template'],
            'tg_enabled'     => $b('tg_enabled'),
            'tg_bot_token'   => $t('tg_bot_token'),
            'tg_chat_id'     => $t('tg_chat_id'),
            'tg_daily'       => max(1, min(500, (int) ($in['tg_daily'] ?? 50))),
            'tg_template'    => isset($in['tg_template']) ? wp_kses_post($in['tg_template']) : $o['tg_template'],
            'delay_min'      => max(0, min(60, (int) ($in['delay_min'] ?? 3))),
            'categories'     => isset($in['categories']) ? array_map('intval', (array) $in['categories']) : array(),
            'hashtags'       => $b('hashtags'),
        );
    }

    static function screen() {
        $o = self::opts();
        $counts = get_option(self::COUNTS, array());
        $today = current_time('Y-m-d');
        $O = self::OPT;
        ?>
        <div class="wrap">
          <h1>MoneyPuran Social Auto-Post</h1>
          <p>When a post is published it is shared automatically to the networks you enable below &mdash; a free, self-hosted alternative to dlvr.it. Enter each platform's own API keys.</p>
          <p><strong>Sent today:</strong>
             X &mdash; <?php echo (int) ($counts[$today]['x'] ?? 0); ?>/<?php echo (int) $o['x_daily']; ?>,
             Facebook &mdash; <?php echo (int) ($counts[$today]['fb'] ?? 0); ?>/<?php echo (int) $o['fb_daily']; ?>,
             Telegram &mdash; <?php echo (int) ($counts[$today]['tg'] ?? 0); ?>/<?php echo (int) $o['tg_daily']; ?></p>

          <form method="post" action="options.php">
            <?php settings_fields('mp_social'); ?>

            <h2>X (Twitter)</h2>
            <p>Create keys at <a href="https://developer.x.com/en/portal/dashboard" target="_blank" rel="noopener">developer.x.com</a> &rarr; Project &amp; App &rarr; Keys and tokens. App permissions must be <strong>Read and write</strong>. Free plan: ~500 posts/month.</p>
            <table class="form-table">
              <tr><th>Enable</th><td><label><input type="checkbox" name="<?php echo $O; ?>[x_enabled]" value="1" <?php checked($o['x_enabled'], 1); ?>> Auto-post to X</label></td></tr>
              <tr><th>API Key</th><td><input type="text" class="regular-text" name="<?php echo $O; ?>[x_api_key]" value="<?php echo esc_attr($o['x_api_key']); ?>"></td></tr>
              <tr><th>API Key Secret</th><td><input type="password" class="regular-text" name="<?php echo $O; ?>[x_api_secret]" value="<?php echo esc_attr($o['x_api_secret']); ?>"></td></tr>
              <tr><th>Access Token</th><td><input type="text" class="regular-text" name="<?php echo $O; ?>[x_token]" value="<?php echo esc_attr($o['x_token']); ?>"></td></tr>
              <tr><th>Access Token Secret</th><td><input type="password" class="regular-text" name="<?php echo $O; ?>[x_token_secret]" value="<?php echo esc_attr($o['x_token_secret']); ?>"></td></tr>
              <tr><th>Daily limit</th><td><input type="number" min="1" max="480" name="<?php echo $O; ?>[x_daily]" value="<?php echo esc_attr($o['x_daily']); ?>"> <span class="description">15/day &approx; 450/month (under the free 500 cap)</span></td></tr>
              <tr><th>Template</th><td><textarea rows="2" class="large-text" name="<?php echo $O; ?>[x_template]"><?php echo esc_textarea($o['x_template']); ?></textarea><span class="description">{title} {url} {tags}</span></td></tr>
              <tr><th>Test</th><td><button type="button" class="button mp-test" data-net="x">Send test to X</button> <span class="mp-test-msg" data-net="x"></span></td></tr>
            </table>

            <h2>Facebook Page</h2>
            <p>Needs a Page ID and a long-lived Page Access Token. Use the <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Graph API Explorer</a> with <code>pages_show_list</code>, <code>pages_manage_posts</code>, <code>pages_read_engagement</code>, then <code>/me/accounts</code> for the Page token, then extend it in the <a href="https://developers.facebook.com/tools/debug/accesstoken/" target="_blank" rel="noopener">Access Token Debugger</a> (~60 days).</p>
            <table class="form-table">
              <tr><th>Enable</th><td><label><input type="checkbox" name="<?php echo $O; ?>[fb_enabled]" value="1" <?php checked($o['fb_enabled'], 1); ?>> Auto-post to Facebook</label></td></tr>
              <tr><th>Page ID</th><td><input type="text" class="regular-text" name="<?php echo $O; ?>[fb_page_id]" value="<?php echo esc_attr($o['fb_page_id']); ?>"></td></tr>
              <tr><th>Page Access Token</th><td><input type="password" class="large-text" name="<?php echo $O; ?>[fb_token]" value="<?php echo esc_attr($o['fb_token']); ?>"></td></tr>
              <tr><th>Daily limit</th><td><input type="number" min="1" max="200" name="<?php echo $O; ?>[fb_daily]" value="<?php echo esc_attr($o['fb_daily']); ?>"></td></tr>
              <tr><th>Template</th><td><textarea rows="2" class="large-text" name="<?php echo $O; ?>[fb_template]"><?php echo esc_textarea($o['fb_template']); ?></textarea><span class="description">{title} {tags} &mdash; the link is attached automatically</span></td></tr>
              <tr><th>Test</th><td><button type="button" class="button mp-test" data-net="fb">Send test to Facebook</button> <span class="mp-test-msg" data-net="fb"></span></td></tr>
            </table>

            <h2>Telegram channel</h2>
            <p>Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> (<code>/newbot</code>) to get the token, then add that bot as an <strong>administrator</strong> of your channel. Chat ID is <code>@yourchannel</code> for a public channel, or the numeric <code>-100...</code> ID for a private one.</p>
            <table class="form-table">
              <tr><th>Enable</th><td><label><input type="checkbox" name="<?php echo $O; ?>[tg_enabled]" value="1" <?php checked($o['tg_enabled'], 1); ?>> Auto-post to Telegram</label></td></tr>
              <tr><th>Bot Token</th><td><input type="password" class="large-text" name="<?php echo $O; ?>[tg_bot_token]" value="<?php echo esc_attr($o['tg_bot_token']); ?>"></td></tr>
              <tr><th>Channel / Chat ID</th><td><input type="text" class="regular-text" name="<?php echo $O; ?>[tg_chat_id]" value="<?php echo esc_attr($o['tg_chat_id']); ?>" placeholder="@moneypuran"></td></tr>
              <tr><th>Daily limit</th><td><input type="number" min="1" max="500" name="<?php echo $O; ?>[tg_daily]" value="<?php echo esc_attr($o['tg_daily']); ?>"></td></tr>
              <tr><th>Template</th><td><textarea rows="2" class="large-text" name="<?php echo $O; ?>[tg_template]"><?php echo esc_textarea($o['tg_template']); ?></textarea><span class="description">HTML allowed: &lt;b&gt; &lt;i&gt; &lt;a&gt;. {title} {url} {tags}</span></td></tr>
              <tr><th>Test</th><td><button type="button" class="button mp-test" data-net="tg">Send test to Telegram</button> <span class="mp-test-msg" data-net="tg"></span></td></tr>
            </table>

            <h2>General</h2>
            <table class="form-table">
              <tr><th>Delay after publish</th><td><input type="number" min="0" max="60" name="<?php echo $O; ?>[delay_min]" value="<?php echo esc_attr($o['delay_min']); ?>"> minutes <span class="description">gives you a moment for quick edits</span></td></tr>
              <tr><th>Add hashtags</th><td><label><input type="checkbox" name="<?php echo $O; ?>[hashtags]" value="1" <?php checked($o['hashtags'], 1); ?>> 2 hashtags from the post's categories</label></td></tr>
              <tr><th>Only these categories</th><td>
                <?php $sel = (array) $o['categories']; foreach (get_categories(array('hide_empty' => 0)) as $c): ?>
                  <label style="display:inline-block;margin:2px 10px 2px 0"><input type="checkbox" name="<?php echo $O; ?>[categories][]" value="<?php echo (int) $c->term_id; ?>" <?php checked(in_array($c->term_id, $sel)); ?>> <?php echo esc_html($c->name); ?></label>
                <?php endforeach; ?>
                <p class="description">Select none = all categories</p>
              </td></tr>
            </table>

            <?php submit_button(); ?>
          </form>

          <script>
          jQuery('.mp-test').on('click', function () {
            var net = jQuery(this).data('net'), msg = jQuery('.mp-test-msg[data-net="' + net + '"]');
            msg.text('sending…');
            jQuery.post(ajaxurl, { action: 'mp_social_test', net: net, _n: '<?php echo wp_create_nonce('mp_social_test'); ?>' }, function (r) {
              msg.text((r && r.success ? '✅ ' : '❌ ') + (r && r.data ? r.data : ''));
            });
          });
          </script>
        </div>
        <?php
    }
}

register_deactivation_hook(__FILE__, array('MP_Social', 'deactivate'));
add_action('plugins_loaded', array('MP_Social', 'init'));
