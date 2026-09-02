<?php
/**
 * Plugin Name: MoneyPuran Market Data
 * Description: Real market data (Yahoo Finance, server-side, cached) for the theme's index bar and the "Live Markets" stock widget. Replaces the simulated fallback and neutralises the fabricated "STRONG BUY" trade ideas. Safe to deactivate.
 * Version: 1.0.0
 * Author: moneypuran.com
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

const MP_MD_CACHE_KEY   = 'mp_md_snapshot_v1';
const MP_MD_LOCK_KEY    = 'mp_md_lock_v1';
const MP_MD_TTL         = 75;   // seconds a snapshot is considered fresh
const MP_MD_HARD_TTL    = 1800; // keep a stale snapshot this long as a fallback
const MP_MD_HTTP_BUDGET = 12;   // total seconds we'll spend fetching per refresh

/* ─────────────────────────── Symbol universe ─────────────────────────── */

/** Header index bar — must match markets.js INDEX_MAP `sym` values. */
function mp_md_index_symbols() {
    return array('^BSESN', '^NSEI', '^NSEBANK', 'INR=X', 'GC=F', 'CL=F', 'BTC-USD');
}

/** "Live Markets" widget — NSE large caps (Yahoo needs the .NS suffix). */
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
        'asOf'     => isset($m['regularMarketTime']) ? gmdate('c', (int) $m['regularMarketTime']) : gmdate('c'),
    );
}

/**
 * Build a full snapshot. Bounded by MP_MD_HTTP_BUDGET so a slow upstream can't
 * hang a page — whatever we have when the budget runs out is what we cache.
 */
function mp_md_build_snapshot() {
    $deadline = microtime(true) + MP_MD_HTTP_BUDGET;
    $out = array('indices' => array(), 'stocks' => array(), 'asOf' => gmdate('c'), 'partial' => false);

    foreach (mp_md_index_symbols() as $sym) {
        if (microtime(true) > $deadline) { $out['partial'] = true; break; }
        $q = mp_md_yahoo_one($sym);
        if ($q) $out['indices'][$sym] = array('sym' => $sym, 'price' => $q['price'], 'chgPct' => $q['chgPct']);
    }

    foreach (mp_md_stock_map() as $sym => $name) {
        if (microtime(true) > $deadline) { $out['partial'] = true; break; }
        $q = mp_md_yahoo_one($sym . '.NS');
        if (!$q) continue;
        $out['stocks'][$sym] = array(
            'symbol'     => $sym,
            'name'       => $name,
            'exchange'   => 'NSE',
            'price'      => $q['price'],
            'change'     => $q['change'],
            'change_pct' => $q['chgPct'],
            'is_up'      => $q['chgPct'] !== null ? $q['chgPct'] >= 0 : true,
            'open'       => $q['prev'],
            'high'       => $q['high'],
            'low'        => $q['low'],
            'volume'     => $q['volume'],
            'w52_high'   => $q['w52_high'],
            'w52_low'    => $q['w52_low'],
            'updated_at' => $q['asOf'],
            'data_source'=> 'yahoo',
        );
    }
    return $out;
}

/**
 * Return the current snapshot, refreshing if stale. A short lock prevents a
 * cache-miss stampede (only one request does the upstream work).
 */
function mp_md_get_snapshot() {
    $snap = get_transient(MP_MD_CACHE_KEY);
    $age  = is_array($snap) && !empty($snap['_cachedAt']) ? (time() - $snap['_cachedAt']) : PHP_INT_MAX;

    if (is_array($snap) && $age < MP_MD_TTL) return $snap;

    // stale or missing → try to refresh, but don't let two requests both fetch
    if (!get_transient(MP_MD_LOCK_KEY)) {
        set_transient(MP_MD_LOCK_KEY, 1, MP_MD_HTTP_BUDGET + 5);
        $fresh = mp_md_build_snapshot();
        delete_transient(MP_MD_LOCK_KEY);
        if (!empty($fresh['indices']) || !empty($fresh['stocks'])) {
            $fresh['_cachedAt'] = time();
            set_transient(MP_MD_CACHE_KEY, $fresh, MP_MD_HARD_TTL);
            return $fresh;
        }
    }
    // couldn't refresh — serve whatever stale copy we have (may be null)
    return is_array($snap) ? $snap : array('indices' => array(), 'stocks' => array(), 'asOf' => gmdate('c'));
}

// Warm the cache in the background so visitors rarely wait on the upstream.
add_action('mp_md_cron_refresh', function () {
    delete_transient(MP_MD_LOCK_KEY);
    $fresh = mp_md_build_snapshot();
    if (!empty($fresh['indices']) || !empty($fresh['stocks'])) {
        $fresh['_cachedAt'] = time();
        set_transient(MP_MD_CACHE_KEY, $fresh, MP_MD_HARD_TTL);
    }
});
add_action('init', function () {
    if (!wp_next_scheduled('mp_md_cron_refresh')) {
        wp_schedule_event(time() + 30, 'mp_md_2min', 'mp_md_cron_refresh');
    }
});
add_filter('cron_schedules', function ($s) {
    $s['mp_md_2min'] = array('interval' => 120, 'display' => 'Every 2 minutes');
    return $s;
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('mp_md_cron_refresh');
    delete_transient(MP_MD_CACHE_KEY);
    delete_transient(MP_MD_LOCK_KEY);
});

/* ─────────────────────────── AJAX handlers (theme) ─────────────────────────── */

/**
 * Take over the theme's two AJAX actions with real data. Registered for both
 * logged-in and anonymous visitors. We remove any prior handlers first so the
 * theme's (failing, nonce-gated) versions don't win.
 */
add_action('wp_loaded', function () {
    remove_all_actions('wp_ajax_mp_get_market_indices');
    remove_all_actions('wp_ajax_nopriv_mp_get_market_indices');
    remove_all_actions('wp_ajax_mps_get_top_stocks');
    remove_all_actions('wp_ajax_nopriv_mps_get_top_stocks');

    add_action('wp_ajax_mp_get_market_indices', 'mp_md_ajax_indices');
    add_action('wp_ajax_nopriv_mp_get_market_indices', 'mp_md_ajax_indices');
    add_action('wp_ajax_mps_get_top_stocks', 'mp_md_ajax_stocks');
    add_action('wp_ajax_nopriv_mps_get_top_stocks', 'mp_md_ajax_stocks');
}, 99);

function mp_md_ajax_indices() {
    $snap = mp_md_get_snapshot();
    wp_send_json_success(array_values($snap['indices'] ?? array()));
}

function mp_md_ajax_stocks() {
    $filter = isset($_REQUEST['filter']) ? sanitize_key($_REQUEST['filter']) : 'trending';
    $snap = mp_md_get_snapshot();
    $stocks = array_values($snap['stocks'] ?? array());

    if ($filter === 'gainers') {
        usort($stocks, fn($a, $b) => ($b['change_pct'] ?? -99) <=> ($a['change_pct'] ?? -99));
    } elseif ($filter === 'losers') {
        usort($stocks, fn($a, $b) => ($a['change_pct'] ?? 99) <=> ($b['change_pct'] ?? 99));
    } else {
        // "trending" = biggest absolute move
        usort($stocks, fn($a, $b) => abs($b['change_pct'] ?? 0) <=> abs($a['change_pct'] ?? 0));
    }
    wp_send_json_success(array_slice($stocks, 0, 12));
}

/* ─────────────────────────── REST endpoint ─────────────────────────── */

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/markets', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $snap = mp_md_get_snapshot();
            return rest_ensure_response(array(
                'indices' => array_values($snap['indices'] ?? array()),
                'stocks'  => array_values($snap['stocks'] ?? array()),
                'asOf'    => $snap['asOf'] ?? gmdate('c'),
                'source'  => 'yahoo',
                'note'    => 'Prices may be delayed. Not investment advice.',
            ));
        },
    ));
});

/* ─────────────────────────── Neutralise the fake analyser ─────────────────────────── *
 * The "MoneyPuran Setup & Stock Analyzer" plugin's /moneypuran/v1/top-stocks
 * returns simulated prices AND fabricated "STRONG BUY" trade ideas with targets
 * and stop-losses. Rewrite that response: real prices where we have them, and
 * replace the invented signal/trade-idea with a factual momentum label.
 * ------------------------------------------------------------------ */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (strpos($request->get_route(), '/moneypuran/v1/top-stocks') === false) return $response;
    $data = $response->get_data();
    if (!is_array($data)) return $response;

    $snap = mp_md_get_snapshot();
    $real = $snap['stocks'] ?? array();

    foreach ($data as &$row) {
        if (!is_array($row) || empty($row['symbol'])) continue;
        $sym = $row['symbol'];
        if (isset($real[$sym])) {
            $r = $real[$sym];
            $row['price']       = $r['price'];
            $row['change']      = $r['change'];
            $row['change_pct']  = $r['change_pct'];
            $row['is_up']       = $r['is_up'];
            $row['open']        = $r['open'] ?? $row['open'] ?? null;
            $row['high']        = $r['high'] ?? null;
            $row['low']         = $r['low'] ?? null;
            $row['volume']      = $r['volume'] ?? null;
            $row['w52_high']    = $r['w52_high'] ?? $row['w52_high'] ?? null;
            $row['w52_low']     = $r['w52_low'] ?? $row['w52_low'] ?? null;
            $row['updated_at']  = $r['updated_at'];
            $row['data_source'] = 'yahoo';
        }
        // strip the fabricated recommendation layer
        $pct = $row['change_pct'] ?? 0;
        $row['signal_label'] = $pct > 1.5 ? 'Bullish' : ($pct < -1.5 ? 'Bearish' : 'Neutral');
        $row['signal_color'] = $pct > 1.5 ? 'green' : ($pct < -1.5 ? 'red' : 'gray');
        unset($row['signal'], $row['score'], $row['momentum'], $row['technical'],
              $row['rank_reason'], $row['buy_target'], $row['stop_loss']);
        if (isset($row['free_analysis']) && is_array($row['free_analysis'])) {
            $name = $row['name'] ?? $sym;
            $price = number_format((float) ($row['price'] ?? 0), 2);
            $dir = $pct >= 0 ? 'up' : 'down';
            $row['free_analysis'] = array(
                'summary' => sprintf(
                    '%s (%s) is trading at &#8377;%s, %s %s%% today. Prices via Yahoo Finance and may be delayed.',
                    esc_html($name), esc_html($sym), $price, $dir, number_format(abs($pct), 2)
                ),
                'trade_idea' => 'MoneyPuran does not publish buy/sell targets. This is market data, not investment advice.',
            );
        }
        unset($row['news_snippets']); // these were invented too
    }
    unset($row);

    $response->set_data($data);
    return $response;
}, 10, 3);
