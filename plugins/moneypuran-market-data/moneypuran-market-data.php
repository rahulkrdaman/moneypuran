<?php
/**
 * Plugin Name: MoneyPuran Market Data
 * Description: Real market data (Yahoo Finance, server-side, cached) for the theme's index bar and the "Live Markets" stock widget. Replaces the simulated fallback and neutralises the fabricated "STRONG BUY" trade ideas. Safe to deactivate.
 * Version: 1.1.0
 * Author: moneypuran.com
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

/* Split caches: the index bar is small + polled often; the stock list is bigger. */
const MP_MD_IDX_KEY   = 'mp_md_idx_v2';
const MP_MD_STK_KEY   = 'mp_md_stk_v2';
const MP_MD_LOCK_IDX  = 'mp_md_lock_idx_v2';
const MP_MD_LOCK_STK  = 'mp_md_lock_stk_v2';

const MP_MD_IDX_SOFT  = 10;    // index bar: fresh for 10s
const MP_MD_STK_SOFT  = 45;    // stock list: fresh for 45s
const MP_MD_HARD_TTL  = 1800;  // keep a stale copy up to 30 min as a fallback
const MP_MD_BUDGET    = 12;    // max seconds spent fetching per refresh

/* ─────────────────────────── Symbol universe ─────────────────────────── */

function mp_md_index_symbols() {
    return array('^BSESN', '^NSEI', '^NSEBANK', 'INR=X', 'GC=F', 'CL=F', 'BTC-USD');
}

function mp_md_stock_map() {
    return array(
        'RELIANCE' => 'Reliance Industries', 'TCS' => 'Tata Consultancy Services',
        'HDFCBANK' => 'HDFC Bank', 'ICICIBANK' => 'ICICI Bank', 'INFY' => 'Infosys',
        'BHARTIARTL' => 'Bharti Airtel', 'SBIN' => 'State Bank of India',
        'BAJFINANCE' => 'Bajaj Finance', 'HCLTECH' => 'HCL Technologies',
        'AXISBANK' => 'Axis Bank', 'MARUTI' => 'Maruti Suzuki', 'SUNPHARMA' => 'Sun Pharma',
        'WIPRO' => 'Wipro', 'LT' => 'Larsen & Toubro', 'TITAN' => 'Titan Company',
        'KOTAKBANK' => 'Kotak Mahindra Bank', 'NTPC' => 'NTPC', 'ONGC' => 'ONGC',
        'JSWSTEEL' => 'JSW Steel', 'ADANIPORTS' => 'Adani Ports',
        'ITC' => 'ITC', 'HINDUNILVR' => 'Hindustan Unilever', 'BAJAJFINSV' => 'Bajaj Finserv',
        'TECHM' => 'Tech Mahindra', 'POWERGRID' => 'Power Grid',
    );
}

/* ─────────────────────────── Yahoo fetch ─────────────────────────── */

function mp_md_yahoo_one($symbol) {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
         . rawurlencode($symbol) . '?range=1d&interval=1d';
    $res = wp_remote_get($url, array(
        'timeout' => 6,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (compatible; MoneyPuran/1.0; +https://moneypuran.com)',
            'Accept'     => 'application/json',
        ),
    ));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
    $j = json_decode(wp_remote_retrieve_body($res), true);
    $m = $j['chart']['result'][0]['meta'] ?? null;
    if (!$m || !isset($m['regularMarketPrice'])) return null;

    $price = (float) $m['regularMarketPrice'];
    $prev  = isset($m['previousClose']) ? (float) $m['previousClose']
           : (isset($m['chartPreviousClose']) ? (float) $m['chartPreviousClose'] : null);
    $chgPct = ($prev && $prev != 0) ? (($price - $prev) / $prev) * 100 : null;

    return array(
        'price'    => round($price, 2),
        'prev'     => $prev !== null ? round($prev, 2) : null,
        'change'   => $prev !== null ? round($price - $prev, 2) : null,
        'chgPct'   => $chgPct !== null ? round($chgPct, 2) : null,
        'currency' => $m['currency'] ?? 'USD',
        'high'     => isset($m['regularMarketDayHigh']) ? round((float) $m['regularMarketDayHigh'], 2) : null,
        'low'      => isset($m['regularMarketDayLow'])  ? round((float) $m['regularMarketDayLow'], 2)  : null,
        'volume'   => isset($m['regularMarketVolume'])  ? (int) $m['regularMarketVolume'] : null,
        'w52_high' => isset($m['fiftyTwoWeekHigh']) ? round((float) $m['fiftyTwoWeekHigh'], 2) : null,
        'w52_low'  => isset($m['fiftyTwoWeekLow'])  ? round((float) $m['fiftyTwoWeekLow'], 2)  : null,
        'state'    => $m['marketState'] ?? null,   // REGULAR | PRE | POST | CLOSED
        'asOf'     => isset($m['regularMarketTime']) ? gmdate('c', (int) $m['regularMarketTime']) : gmdate('c'),
    );
}

/* ─────────────────────────── Index snapshot ─────────────────────────── */

function mp_md_build_indices() {
    $deadline = microtime(true) + MP_MD_BUDGET;
    $out = array('indices' => array(), 'state' => 'UNKNOWN', 'asOf' => gmdate('c'));
    foreach (mp_md_index_symbols() as $sym) {
        if (microtime(true) > $deadline) break;
        $q = mp_md_yahoo_one($sym);
        if (!$q) continue;
        $out['indices'][$sym] = array(
            'sym' => $sym, 'price' => $q['price'], 'chgPct' => $q['chgPct'], 'change' => $q['change'],
        );
        if ($sym === '^NSEI' && $q['state']) $out['state'] = $q['state'];
    }
    return $out;
}

function mp_md_get_indices() {
    $snap = get_transient(MP_MD_IDX_KEY);
    $age  = is_array($snap) && !empty($snap['_at']) ? (time() - $snap['_at']) : PHP_INT_MAX;
    if (is_array($snap) && $age < MP_MD_IDX_SOFT) return $snap;

    if (!get_transient(MP_MD_LOCK_IDX)) {
        set_transient(MP_MD_LOCK_IDX, 1, MP_MD_BUDGET + 3);
        $fresh = mp_md_build_indices();
        delete_transient(MP_MD_LOCK_IDX);
        if (!empty($fresh['indices'])) {
            $fresh['_at'] = time();
            set_transient(MP_MD_IDX_KEY, $fresh, MP_MD_HARD_TTL);
            return $fresh;
        }
    }
    return is_array($snap) ? $snap : array('indices' => array(), 'state' => 'UNKNOWN', 'asOf' => gmdate('c'));
}

/* ─────────────────────────── Stock snapshot ─────────────────────────── */

function mp_md_build_stocks() {
    $deadline = microtime(true) + MP_MD_BUDGET;
    $out = array('stocks' => array(), 'asOf' => gmdate('c'));
    foreach (mp_md_stock_map() as $sym => $name) {
        if (microtime(true) > $deadline) break;
        $q = mp_md_yahoo_one($sym . '.NS');
        if (!$q) continue;
        $out['stocks'][$sym] = array(
            'symbol' => $sym, 'name' => $name, 'exchange' => 'NSE',
            'price' => $q['price'], 'change' => $q['change'], 'change_pct' => $q['chgPct'],
            'is_up' => $q['chgPct'] !== null ? $q['chgPct'] >= 0 : true,
            'open' => $q['prev'], 'high' => $q['high'], 'low' => $q['low'], 'volume' => $q['volume'],
            'w52_high' => $q['w52_high'], 'w52_low' => $q['w52_low'],
            'updated_at' => $q['asOf'], 'data_source' => 'yahoo',
        );
    }
    return $out;
}

function mp_md_get_stocks() {
    $snap = get_transient(MP_MD_STK_KEY);
    $age  = is_array($snap) && !empty($snap['_at']) ? (time() - $snap['_at']) : PHP_INT_MAX;
    if (is_array($snap) && $age < MP_MD_STK_SOFT) return $snap;

    if (!get_transient(MP_MD_LOCK_STK)) {
        set_transient(MP_MD_LOCK_STK, 1, MP_MD_BUDGET + 3);
        $fresh = mp_md_build_stocks();
        delete_transient(MP_MD_LOCK_STK);
        if (!empty($fresh['stocks'])) {
            $fresh['_at'] = time();
            set_transient(MP_MD_STK_KEY, $fresh, MP_MD_HARD_TTL);
            return $fresh;
        }
    }
    return is_array($snap) ? $snap : array('stocks' => array(), 'asOf' => gmdate('c'));
}

/* ─────────────────────────── Background warm-up ─────────────────────────── */

add_action('mp_md_cron_refresh', function () {
    delete_transient(MP_MD_LOCK_IDX);
    delete_transient(MP_MD_LOCK_STK);
    $i = mp_md_build_indices();
    if (!empty($i['indices'])) { $i['_at'] = time(); set_transient(MP_MD_IDX_KEY, $i, MP_MD_HARD_TTL); }
    $s = mp_md_build_stocks();
    if (!empty($s['stocks'])) { $s['_at'] = time(); set_transient(MP_MD_STK_KEY, $s, MP_MD_HARD_TTL); }
});
add_action('init', function () {
    if (!wp_next_scheduled('mp_md_cron_refresh')) {
        wp_schedule_event(time() + 20, 'mp_md_1min', 'mp_md_cron_refresh');
    }
});
add_filter('cron_schedules', function ($s) {
    $s['mp_md_1min'] = array('interval' => 60, 'display' => 'Every minute');
    return $s;
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('mp_md_cron_refresh');
    foreach (array(MP_MD_IDX_KEY, MP_MD_STK_KEY, MP_MD_LOCK_IDX, MP_MD_LOCK_STK) as $k) delete_transient($k);
});

/* ─────────────────────────── AJAX handlers (theme, back-compat) ─────────────────────────── */

add_action('wp_loaded', function () {
    foreach (array('mp_get_market_indices', 'mps_get_top_stocks') as $a) {
        remove_all_actions('wp_ajax_' . $a);
        remove_all_actions('wp_ajax_nopriv_' . $a);
    }
    add_action('wp_ajax_mp_get_market_indices', 'mp_md_ajax_indices');
    add_action('wp_ajax_nopriv_mp_get_market_indices', 'mp_md_ajax_indices');
    add_action('wp_ajax_mps_get_top_stocks', 'mp_md_ajax_stocks');
    add_action('wp_ajax_nopriv_mps_get_top_stocks', 'mp_md_ajax_stocks');
}, 99);

function mp_md_ajax_indices() {
    wp_send_json_success(array_values(mp_md_get_indices()['indices']));
}

function mp_md_ajax_stocks() {
    wp_send_json_success(mp_md_sorted_stocks(isset($_REQUEST['filter']) ? sanitize_key($_REQUEST['filter']) : 'trending'));
}

function mp_md_sorted_stocks($filter) {
    $stocks = array_values(mp_md_get_stocks()['stocks']);
    if ($filter === 'gainers') {
        usort($stocks, fn($a, $b) => ($b['change_pct'] ?? -99) <=> ($a['change_pct'] ?? -99));
    } elseif ($filter === 'losers') {
        usort($stocks, fn($a, $b) => ($a['change_pct'] ?? 99) <=> ($b['change_pct'] ?? 99));
    } else {
        usort($stocks, fn($a, $b) => abs($b['change_pct'] ?? 0) <=> abs($a['change_pct'] ?? 0));
    }
    return array_slice($stocks, 0, 12);
}

/* ─────────────────────────── REST endpoint (primary; edge-cacheable) ─────────────────────────── */

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/markets', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'args'                => array(
            'only'   => array('default' => 'all'),
            'filter' => array('default' => 'trending'),
        ),
        'callback' => function (WP_REST_Request $req) {
            $only = $req->get_param('only');
            $idx  = mp_md_get_indices();
            $body = array(
                'indices' => array_values($idx['indices']),
                'state'   => $idx['state'] ?? 'UNKNOWN',
                'asOf'    => gmdate('c'),
                'source'  => 'Yahoo Finance',
                'note'    => 'Prices may be delayed. Not investment advice.',
            );
            if ($only !== 'indices') {
                $body['stocks'] = mp_md_sorted_stocks($req->get_param('filter'));
            }
            $resp = rest_ensure_response($body);
            // Let LiteSpeed / the browser reuse a response for a few seconds so a
            // fast poll from many visitors is one PHP hit, not N.
            $resp->header('Cache-Control', 'public, max-age=6, s-maxage=8, stale-while-revalidate=30');
            return $resp;
        },
    ));
});

/* ─────────────────────────── Front-end: flash animation + LIVE dot ─────────────────────────── */

add_action('wp_head', function () {
    ?>
<style id="mp-md-live">
@keyframes mpMdFlashUp   { 0%{background:rgba(22,163,74,.28)} 100%{background:transparent} }
@keyframes mpMdFlashDown { 0%{background:rgba(220,38,38,.28)} 100%{background:transparent} }
.mp-md-flash-up   { animation: mpMdFlashUp   .9s ease-out; border-radius:3px; }
.mp-md-flash-down { animation: mpMdFlashDown .9s ease-out; border-radius:3px; }
.mp-live-dot{ display:inline-block; width:7px; height:7px; border-radius:50%; background:#16a34a;
  margin-right:5px; vertical-align:middle; animation:mpMdPulse 1.6s infinite; }
@keyframes mpMdPulse { 0%{box-shadow:0 0 0 0 rgba(22,163,74,.55)} 70%{box-shadow:0 0 0 7px rgba(22,163,74,0)} 100%{box-shadow:0 0 0 0 rgba(22,163,74,0)} }
.mp-live-meta{ font-size:11px; color:var(--mp-muted,#6b7280); white-space:nowrap; }
.mp-live-dot.mp-live-off{ background:#9ca3af; animation:none; box-shadow:none; }
</style>
    <?php
});

/* ─────────────────────────── Neutralise the fake analyser ─────────────────────────── */

add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (strpos($request->get_route(), '/moneypuran/v1/top-stocks') === false) return $response;
    $data = $response->get_data();
    if (!is_array($data)) return $response;
    $real = mp_md_get_stocks()['stocks'];

    foreach ($data as &$row) {
        if (!is_array($row) || empty($row['symbol'])) continue;
        $sym = $row['symbol'];
        if (isset($real[$sym])) {
            $r = $real[$sym];
            $row['price'] = $r['price']; $row['change'] = $r['change']; $row['change_pct'] = $r['change_pct'];
            $row['is_up'] = $r['is_up']; $row['open'] = $r['open']; $row['high'] = $r['high'];
            $row['low'] = $r['low']; $row['volume'] = $r['volume'];
            $row['w52_high'] = $r['w52_high']; $row['w52_low'] = $r['w52_low'];
            $row['updated_at'] = $r['updated_at']; $row['data_source'] = 'yahoo';
        }
        $pct = $row['change_pct'] ?? 0;
        $row['signal_label'] = $pct > 1.5 ? 'Bullish' : ($pct < -1.5 ? 'Bearish' : 'Neutral');
        $row['signal_color'] = $pct > 1.5 ? 'green' : ($pct < -1.5 ? 'red' : 'gray');
        unset($row['signal'], $row['score'], $row['momentum'], $row['technical'],
              $row['rank_reason'], $row['buy_target'], $row['stop_loss'], $row['news_snippets']);
        if (isset($row['free_analysis']) && is_array($row['free_analysis'])) {
            $name = $row['name'] ?? $sym;
            $price = number_format((float) ($row['price'] ?? 0), 2);
            $dir = $pct >= 0 ? 'up' : 'down';
            $row['free_analysis'] = array(
                'summary' => sprintf('%s (%s) is trading at &#8377;%s, %s %s%% today. Prices via Yahoo Finance and may be delayed.',
                    esc_html($name), esc_html($sym), $price, $dir, number_format(abs($pct), 2)),
                'trade_idea' => 'MoneyPuran does not publish buy/sell targets. This is market data, not investment advice.',
            );
        }
    }
    unset($row);
    $response->set_data($data);
    return $response;
}, 10, 3);
