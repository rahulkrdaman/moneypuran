<?php
/**
 * Plugin Name: MoneyPuran Market Data
 * Description: Real market data (server-side, cached) - index bar, Live Markets widget, Markets Dashboard, session-aware news ticker, and city Gold/Silver + Fuel rate tools. Safe to deactivate.
 * Version: 1.24.4
 * Author: moneypuran.com
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

/* Split caches: the index bar is small + polled often; everything else moves slower. */
const MP_MD_IDX_KEY   = 'mp_md_idx_v2';
const MP_MD_STK_KEY   = 'mp_md_stk_v2';
const MP_MD_GRP_KEY   = 'mp_md_grp_v2';   // dashboard groups bundle
const MP_MD_LOCK_IDX  = 'mp_md_lock_idx_v2';
const MP_MD_LOCK_STK  = 'mp_md_lock_stk_v2';
const MP_MD_LOCK_GRP  = 'mp_md_lock_grp_v2';

const MP_MD_IDX_SOFT  = 10;    // index bar: fresh for 10s
const MP_MD_STK_SOFT  = 45;    // stock list: fresh for 45s
const MP_MD_GRP_SOFT  = 60;    // dashboard groups: fresh for 60s
const MP_MD_HARD_TTL  = 1800;  // keep a stale copy up to 30 min as a fallback
const MP_MD_BUDGET    = 12;    // max seconds spent fetching per refresh

/* --------------------------- Symbol universe --------------------------- */

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

/* Dashboard groups: label map per symbol. */
function mp_md_groups() {
    return array(
        'world' => array(
            '^GSPC' => 'S&P 500', '^DJI' => 'Dow Jones', '^IXIC' => 'Nasdaq',
            '^FTSE' => 'FTSE 100', '^GDAXI' => 'DAX', '^N225' => 'Nikkei 225', '^HSI' => 'Hang Seng',
        ),
        'currencies' => array(
            'INR=X' => 'USD / INR', 'EURINR=X' => 'EUR / INR', 'GBPINR=X' => 'GBP / INR',
            'JPYINR=X' => 'JPY / INR', 'EURUSD=X' => 'EUR / USD', 'GBPUSD=X' => 'GBP / USD',
        ),
        'commodities' => array(
            'GC=F' => 'Gold (COMEX)', 'SI=F' => 'Silver (COMEX)', 'CL=F' => 'Crude Oil (WTI)',
            'BZ=F' => 'Brent Crude', 'NG=F' => 'Natural Gas', 'HG=F' => 'Copper',
        ),
        'sectors' => array(
            '^NSEBANK' => 'Nifty Bank', '^CNXIT' => 'Nifty IT', '^CNXPHARMA' => 'Nifty Pharma',
            '^CNXAUTO' => 'Nifty Auto', '^CNXFMCG' => 'Nifty FMCG', '^CNXMETAL' => 'Nifty Metal',
            '^CNXENERGY' => 'Nifty Energy', '^CNXREALTY' => 'Nifty Realty',
        ),
        // US mega-caps for the session-aware ticker (v1.23.0) — fetched with no
        // suffix (Yahoo default = US listing), same cadence as the world indices.
        'us_stocks' => array(
            'AAPL' => 'Apple', 'MSFT' => 'Microsoft', 'GOOGL' => 'Alphabet', 'AMZN' => 'Amazon',
            'NVDA' => 'Nvidia', 'META' => 'Meta Platforms', 'TSLA' => 'Tesla', 'JPM' => 'JPMorgan Chase',
            'V' => 'Visa', 'WMT' => 'Walmart', 'XOM' => 'ExxonMobil', 'UNH' => 'UnitedHealth',
        ),
    );
}

/* --------------------------- Market data fetch --------------------------- */

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
        'price'    => round($price, 4),
        'prev'     => $prev !== null ? round($prev, 4) : null,
        'change'   => $prev !== null ? round($price - $prev, 4) : null,
        'chgPct'   => $chgPct !== null ? round($chgPct, 2) : null,
        'currency' => $m['currency'] ?? 'USD',
        'high'     => isset($m['regularMarketDayHigh']) ? round((float) $m['regularMarketDayHigh'], 2) : null,
        'low'      => isset($m['regularMarketDayLow'])  ? round((float) $m['regularMarketDayLow'], 2)  : null,
        'volume'   => isset($m['regularMarketVolume'])  ? (int) $m['regularMarketVolume'] : null,
        'w52_high' => isset($m['fiftyTwoWeekHigh']) ? round((float) $m['fiftyTwoWeekHigh'], 2) : null,
        'w52_low'  => isset($m['fiftyTwoWeekLow'])  ? round((float) $m['fiftyTwoWeekLow'], 2)  : null,
        'state'    => $m['marketState'] ?? null,
        'asOf'     => isset($m['regularMarketTime']) ? gmdate('c', (int) $m['regularMarketTime']) : gmdate('c'),
    );
}

/* --------------------------- Index snapshot --------------------------- */

function mp_md_build_indices() {
    $deadline = microtime(true) + MP_MD_BUDGET;
    $out = array('indices' => array(), 'state' => 'UNKNOWN', 'asOf' => gmdate('c'));
    foreach (mp_md_index_symbols() as $sym) {
        if (microtime(true) > $deadline) break;
        $q = mp_md_yahoo_one($sym);
        if (!$q) continue;
        $out['indices'][$sym] = array(
            'sym' => $sym, 'price' => round($q['price'], 2), 'chgPct' => $q['chgPct'], 'change' => round($q['change'], 2),
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

/* --------------------------- Stock snapshot --------------------------- */

function mp_md_build_stocks() {
    $deadline = microtime(true) + MP_MD_BUDGET;
    $out = array('stocks' => array(), 'asOf' => gmdate('c'));
    foreach (mp_md_stock_map() as $sym => $name) {
        if (microtime(true) > $deadline) break;
        $q = mp_md_yahoo_one($sym . '.NS');
        if (!$q) continue;
        $out['stocks'][$sym] = array(
            'symbol' => $sym, 'name' => $name, 'exchange' => 'NSE',
            'price' => round($q['price'], 2), 'change' => round($q['change'], 2), 'change_pct' => $q['chgPct'],
            'is_up' => $q['chgPct'] !== null ? $q['chgPct'] >= 0 : true,
            'open' => $q['prev'], 'high' => $q['high'], 'low' => $q['low'], 'volume' => $q['volume'],
            'w52_high' => $q['w52_high'], 'w52_low' => $q['w52_low'],
            'updated_at' => $q['asOf'], 'data_source' => 'live',
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

/* --------------------------- Dashboard groups --------------------------- */

function mp_md_build_groups() {
    $deadline = microtime(true) + (MP_MD_BUDGET * 2);
    $out = array('asOf' => gmdate('c'));
    foreach (mp_md_groups() as $key => $map) {
        $rows = array();
        foreach ($map as $sym => $label) {
            if (microtime(true) > $deadline) break 2;
            $q = mp_md_yahoo_one($sym);
            if (!$q) continue;
            $rows[] = array(
                'sym' => $sym, 'label' => $label,
                'price' => $q['price'], 'change' => $q['change'], 'chgPct' => $q['chgPct'],
                'currency' => $q['currency'],
            );
        }
        $out[$key] = $rows;
    }
    // Indicative INR gold/silver from COMEX + USD/INR (straight FX conversion of a real quote).
    $usdinr = null;
    foreach (($out['currencies'] ?? array()) as $r) { if ($r['sym'] === 'INR=X') $usdinr = $r['price']; }
    $gold = $silver = null;
    foreach (($out['commodities'] ?? array()) as $r) {
        if ($r['sym'] === 'GC=F') $gold = $r;
        if ($r['sym'] === 'SI=F') $silver = $r;
    }
    $fxRow = null;
    foreach (($out['currencies'] ?? array()) as $r) { if ($r['sym'] === 'INR=X') $fxRow = $r; }
    if ($usdinr && $gold) {
        $g10 = $gold['price'] / 31.1035 * $usdinr * 10; // $/oz -> INR/10g (24K)
        // Day change of the INR rate ~= combine the metal's % move with the rupee's % move.
        $fxPct = ($fxRow && isset($fxRow['chgPct']) && $fxRow['chgPct'] !== null) ? (float) $fxRow['chgPct'] : 0.0;
        $comb  = function ($mPct) use ($fxPct) {
            if ($mPct === null) return null;
            return round(((1 + $mPct / 100) * (1 + $fxPct / 100) - 1) * 100, 2);
        };
        $out['bullion_inr'] = array(
            'usdinr'      => round($usdinr, 2),
            'gold_24k_10g' => round($g10),
            'gold_22k_10g' => round($g10 * 0.916),
            'gold_18k_10g' => round($g10 * 0.75),
            'silver_kg'   => $silver ? round($silver['price'] / 31.1035 * $usdinr * 1000) : null,
            'gold_chg_pct'   => $comb(isset($gold['chgPct']) ? $gold['chgPct'] : null),
            'silver_chg_pct' => $silver ? $comb(isset($silver['chgPct']) ? $silver['chgPct'] : null) : null,
            'note'        => 'Indicative, from COMEX spot x USD/INR. Excludes GST, import duty and jeweller making charges. Not a retail quote.',
        );
    }
    return $out;
}

function mp_md_get_groups() {
    $snap = get_transient(MP_MD_GRP_KEY);
    $age  = is_array($snap) && !empty($snap['_at']) ? (time() - $snap['_at']) : PHP_INT_MAX;
    if (is_array($snap) && $age < MP_MD_GRP_SOFT) return $snap;

    if (!get_transient(MP_MD_LOCK_GRP)) {
        set_transient(MP_MD_LOCK_GRP, 1, (MP_MD_BUDGET * 2) + 3);
        $fresh = mp_md_build_groups();
        delete_transient(MP_MD_LOCK_GRP);
        if (!empty($fresh['world']) || !empty($fresh['currencies'])) {
            $fresh['_at'] = time();
            set_transient(MP_MD_GRP_KEY, $fresh, MP_MD_HARD_TTL);
            return $fresh;
        }
    }
    return is_array($snap) ? $snap : array('asOf' => gmdate('c'));
}

/* --------------------------- Background warm-up --------------------------- */

add_action('mp_md_cron_refresh', function () {
    foreach (array(MP_MD_LOCK_IDX, MP_MD_LOCK_STK, MP_MD_LOCK_GRP) as $l) delete_transient($l);
    $i = mp_md_build_indices();
    if (!empty($i['indices'])) { $i['_at'] = time(); set_transient(MP_MD_IDX_KEY, $i, MP_MD_HARD_TTL); }
    $s = mp_md_build_stocks();
    if (!empty($s['stocks'])) { $s['_at'] = time(); set_transient(MP_MD_STK_KEY, $s, MP_MD_HARD_TTL); }
});
add_action('mp_md_cron_groups', function () {
    delete_transient(MP_MD_LOCK_GRP);
    $g = mp_md_build_groups();
    if (!empty($g['world']) || !empty($g['currencies'])) { $g['_at'] = time(); set_transient(MP_MD_GRP_KEY, $g, MP_MD_HARD_TTL); }
    // Warm the rate-page history transients so the chart/insights never block a page render.
    if (function_exists('mp_md_series')) {
        foreach (array('gold', 'silver', 'crude') as $s) {
            mp_md_series($s, '6mo');
            if ($s === 'gold') mp_md_series($s, '1mo');
        }
    }
    // Warm the stock screener so its page never triggers a cold multi-request build.
    if (function_exists('mp_md_screener_build')) {
        $sc = get_transient(MP_SCR_KEY);
        $age = is_array($sc) && !empty($sc['_at']) ? (time() - $sc['_at']) : PHP_INT_MAX;
        if ($age > 150) {
            delete_transient(MP_SCR_LOCK);
            $f = mp_md_screener_build();
            if (!empty($f['sectors'])) set_transient(MP_SCR_KEY, $f, MP_MD_HARD_TTL);
        }
    }
});
add_action('init', function () {
    if (!wp_next_scheduled('mp_md_cron_refresh')) wp_schedule_event(time() + 20, 'mp_md_1min', 'mp_md_cron_refresh');
    if (!wp_next_scheduled('mp_md_cron_groups'))  wp_schedule_event(time() + 40, 'mp_md_2min', 'mp_md_cron_groups');
});
add_filter('cron_schedules', function ($s) {
    $s['mp_md_1min'] = array('interval' => 60,  'display' => 'Every minute');
    $s['mp_md_2min'] = array('interval' => 120, 'display' => 'Every 2 minutes');
    return $s;
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('mp_md_cron_refresh');
    wp_clear_scheduled_hook('mp_md_cron_groups');
    foreach (array(MP_MD_IDX_KEY, MP_MD_STK_KEY, MP_MD_GRP_KEY, MP_MD_LOCK_IDX, MP_MD_LOCK_STK, MP_MD_LOCK_GRP) as $k) delete_transient($k);
});

/* --------------------------- AJAX handlers (theme, back-compat) --------------------------- */

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
function mp_md_stock_sector() {
    return array(
        'TCS' => 'Nifty IT', 'INFY' => 'Nifty IT', 'WIPRO' => 'Nifty IT', 'HCLTECH' => 'Nifty IT', 'TECHM' => 'Nifty IT',
        'HDFCBANK' => 'Nifty Bank', 'ICICIBANK' => 'Nifty Bank', 'SBIN' => 'Nifty Bank',
        'AXISBANK' => 'Nifty Bank', 'KOTAKBANK' => 'Nifty Bank', 'BAJFINANCE' => 'Nifty Bank', 'BAJAJFINSV' => 'Nifty Bank',
        'SUNPHARMA' => 'Nifty Pharma', 'MARUTI' => 'Nifty Auto',
        'ITC' => 'Nifty FMCG', 'HINDUNILVR' => 'Nifty FMCG', 'JSWSTEEL' => 'Nifty Metal',
        'NTPC' => 'Nifty Energy', 'ONGC' => 'Nifty Energy', 'POWERGRID' => 'Nifty Energy', 'RELIANCE' => 'Nifty Energy',
    );
}

function mp_md_sorted_stocks($filter) {
    $stocks = array_values(mp_md_get_stocks()['stocks']);

    // Enrich each row with its sector index move, for the "what's moving it" note.
    $grp = get_transient(MP_MD_GRP_KEY);
    $secBy = array();
    if (is_array($grp) && !empty($grp['sectors'])) {
        foreach ($grp['sectors'] as $r) $secBy[$r['label']] = $r['chgPct'];
    }
    $secMap = mp_md_stock_sector();
    foreach ($stocks as &$s) {
        $lbl = isset($secMap[$s['symbol']]) ? $secMap[$s['symbol']] : '';
        $s['sector']     = $lbl;
        $s['sector_chg'] = ($lbl !== '' && isset($secBy[$lbl])) ? $secBy[$lbl] : null;
    }
    unset($s);

    if ($filter === 'gainers' || $filter === 'momentum' || $filter === 'buy') {
        usort($stocks, fn($a, $b) => ($b['change_pct'] ?? -99) <=> ($a['change_pct'] ?? -99));
    } elseif ($filter === 'losers') {
        usort($stocks, fn($a, $b) => ($a['change_pct'] ?? 99) <=> ($b['change_pct'] ?? 99));
    } elseif ($filter === 'volume') {
        usort($stocks, fn($a, $b) => ((int) ($b['volume'] ?? 0)) <=> ((int) ($a['volume'] ?? 0)));
    } else {
        usort($stocks, fn($a, $b) => abs($b['change_pct'] ?? 0) <=> abs($a['change_pct'] ?? 0));
    }
    return array_slice($stocks, 0, 12);
}

/* --------------------------- REST endpoint --------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/markets', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'args'                => array(
            'only'    => array('default' => 'all'),
            'filter'  => array('default' => 'trending'),
            'session' => array('default' => ''),
        ),
        'callback' => function (WP_REST_Request $req) {
            $only = $req->get_param('only');
            $session = sanitize_key($req->get_param('session'));
            $body = array('asOf' => gmdate('c'), 'source' => 'Market data', 'note' => 'Prices may be delayed. Not investment advice.');

            if ($only === 'dashboard') {
                $body = array_merge($body, mp_md_get_groups());
                unset($body['_at']);
            } elseif ($only === 'stocks') {
                $body['stocks'] = mp_md_sorted_stocks($req->get_param('filter'));
            } elseif ($only === 'all' && $session === 'us') {
                $body['indices'] = mp_md_tb_us_index_rows();
                $body['stocks']  = mp_md_us_stocks($req->get_param('filter'));
            } else {
                $idx = mp_md_get_indices();
                $body['indices'] = array_values($idx['indices']);
                $body['state']   = $idx['state'] ?? 'UNKNOWN';
                if ($only !== 'indices') $body['stocks'] = mp_md_sorted_stocks($req->get_param('filter'));
            }

            $resp = rest_ensure_response($body);
            $resp->header('Cache-Control', 'public, max-age=6, s-maxage=8, stale-while-revalidate=30');
            return $resp;
        },
    ));
});

/* --------------------------- Markets Dashboard shortcode --------------------------- */

function mp_md_fmt($n, $dec = 2) {
    return number_format((float) $n, $dec);
}
function mp_md_row_html($r) {
    $up  = ($r['chgPct'] ?? 0) >= 0;
    $cls = $up ? 'mp-md-up' : 'mp-md-dn';
    $sign = $up ? '+' : '';
    return '<div class="mp-md-row">'
        . '<span class="mp-md-label">' . esc_html($r['label']) . '</span>'
        . '<span class="mp-md-price">' . mp_md_fmt($r['price'], ($r['price'] < 10 ? 4 : 2)) . '</span>'
        . '<span class="mp-md-chg ' . $cls . '">' . $sign . number_format((float) ($r['chgPct'] ?? 0), 2) . '%</span>'
        . '</div>';
}
function mp_md_card_html($title, $rows) {
    if (empty($rows)) return '';
    $h = '<div class="mp-md-card"><h3 class="mp-md-card-title">' . esc_html($title) . '</h3><div class="mp-md-card-body">';
    foreach ($rows as $r) $h .= mp_md_row_html($r);
    return $h . '</div></div>';
}
/* Gold & Silver as a first-class dashboard card (kept at the top of the grid). */
function mp_md_bullion_card_html($b) {
    if (empty($b) || empty($b['gold_24k_10g'])) return '';
    $rows = array(
        array('Gold 24K &middot; 10g', $b['gold_24k_10g']),
        array('Gold 22K &middot; 10g', $b['gold_22k_10g']),
        array('Gold 18K &middot; 10g', $b['gold_18k_10g']),
    );
    if (!empty($b['silver_kg'])) $rows[] = array('Silver &middot; 1kg', $b['silver_kg']);
    $h = '<div class="mp-md-card mp-md-card--bullion"><h3 class="mp-md-card-title">Gold &amp; Silver &middot; INR (indicative)</h3><div class="mp-md-card-body">';
    foreach ($rows as $r) {
        $h .= '<div class="mp-md-row"><span class="mp-md-label">' . $r[0] . '</span>'
            . '<span class="mp-md-price">&#8377;' . mp_md_fmt($r[1], 0) . '</span><span class="mp-md-chg"></span></div>';
    }
    return $h . '</div><p class="mp-md-note" style="margin:8px 0 0;font-size:10px">COMEX spot &times; USD/INR &mdash; excludes GST, duty &amp; making charges.</p></div>';
}

add_shortcode('mp_markets_dashboard', function () {
    $g = mp_md_get_groups();
    if (empty($g['world']) && empty($g['currencies']) && empty($g['commodities'])) {
        return ''; // never render an empty shell
    }

    ob_start(); ?>
<section class="mp-section mp-md-dashboard" aria-label="Markets dashboard">
  <div class="mp-md-head">
    <h2 class="mp-section-title">Markets Dashboard</h2>
    <span class="mp-md-asof" id="mpMdAsOf">Market data &middot; may be delayed</span>
  </div>
  <div class="mp-md-grid" id="mpMdGrid">
    <?php
    echo mp_md_card_html('World Indices',  $g['world']       ?? array());
    echo mp_md_bullion_card_html($g['bullion_inr'] ?? array());
    echo mp_md_card_html('Currency Rates', $g['currencies']  ?? array());
    echo mp_md_card_html('Commodities',    $g['commodities'] ?? array());
    echo mp_md_card_html('Sector Indices (India)', $g['sectors'] ?? array());
    ?>
  </div>
  <p class="mp-md-disclaimer">Market data is provided for information only and may be delayed. Nothing here is investment advice.</p>
</section>
<style>
/* Theme-aware: uses the moneypuran-theme tokens (--mp-surface/--mp-ink/--mp-muted/--mp-border)
   with light fallbacks, plus an explicit dark-mode block for safety. */
.mp-md-dashboard{margin:28px 0;color:var(--mp-ink,#0f172a)}
.mp-md-head{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.mp-md-asof{font-size:11px;color:var(--mp-muted,#64748b)}
.mp-md-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
.mp-md-card--bullion .mp-md-price{color:var(--mp-brand,#1d4ed8);font-weight:700}
@media(min-width:720px){.mp-md-card--bullion{grid-column:span 2}.mp-md-card--bullion .mp-md-card-body{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}}
.mp-md-card,.mp-md-bullion{border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:14px 16px;background:var(--mp-surface,#fff);color:var(--mp-ink,#0f172a)}
.mp-md-card-title{font-size:13px;font-weight:700;margin:0 0 10px;letter-spacing:.02em;text-transform:uppercase;color:var(--mp-muted,#64748b)}
.mp-md-row{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:6px 0;border-top:1px solid var(--mp-border,#eef1f4);font-size:13px}
.mp-md-row:first-child{border-top:0}
.mp-md-label{color:var(--mp-ink,#0f172a)}
.mp-md-price{font-variant-numeric:tabular-nums;font-weight:600;color:var(--mp-ink,#0f172a)}
.mp-md-chg{font-variant-numeric:tabular-nums;font-weight:600;min-width:62px;text-align:right}
.mp-md-up{color:#16a34a}.mp-md-dn{color:#dc2626}
.mp-md-bullion{margin-top:16px}
.mp-md-bullion-row{display:flex;flex-wrap:wrap;gap:16px;font-size:14px;color:var(--mp-ink,#0f172a)}
.mp-md-bullion-row strong{color:var(--mp-brand,#1d4ed8)}
.mp-md-note,.mp-md-disclaimer{font-size:11px;color:var(--mp-muted,#64748b);margin:8px 0 0}
.mp-md-disclaimer{margin-top:12px}
html[data-theme="dark"] .mp-md-card,
html[data-theme="dark"] .mp-md-bullion{background:#111827;border-color:rgba(255,255,255,.08);color:#f1f5f9}
html[data-theme="dark"] .mp-md-label,
html[data-theme="dark"] .mp-md-price,
html[data-theme="dark"] .mp-md-bullion-row{color:#f1f5f9}
html[data-theme="dark"] .mp-md-card-title,
html[data-theme="dark"] .mp-md-note,
html[data-theme="dark"] .mp-md-disclaimer,
html[data-theme="dark"] .mp-md-asof{color:#94a3b8}
html[data-theme="dark"] .mp-md-row{border-top-color:rgba(255,255,255,.08)}
</style>
<script>
(function(){
  var GRID = document.getElementById('mpMdGrid'); if(!GRID) return;
  var REST = (location.origin||'') + '/wp-json/mp/v1/markets?only=dashboard';
  var TITLES = {world:'World Indices',currencies:'Currency Rates',commodities:'Commodities',sectors:'Sector Indices (India)'};
  function row(r){
    var up = (r.chgPct||0) >= 0, cls = up?'mp-md-up':'mp-md-dn', dec = (r.price<10?4:2);
    return '<div class="mp-md-row"><span class="mp-md-label">'+r.label+'</span>'
      +'<span class="mp-md-price">'+Number(r.price).toLocaleString('en-IN',{minimumFractionDigits:dec,maximumFractionDigits:dec})+'</span>'
      +'<span class="mp-md-chg '+cls+'">'+(up?'+':'')+Number(r.chgPct||0).toFixed(2)+'%</span></div>';
  }
  function bullionCard(b){
    if(!b || !b.gold_24k_10g) return '';
    var inr = function(n){ return '₹'+Number(n).toLocaleString('en-IN',{maximumFractionDigits:0}); };
    var rows = [['Gold 24K · 10g',b.gold_24k_10g],['Gold 22K · 10g',b.gold_22k_10g],['Gold 18K · 10g',b.gold_18k_10g]];
    if(b.silver_kg) rows.push(['Silver · 1kg',b.silver_kg]);
    return '<div class="mp-md-card mp-md-card--bullion"><h3 class="mp-md-card-title">Gold &amp; Silver · INR (indicative)</h3><div class="mp-md-card-body">'
      + rows.map(function(x){ return '<div class="mp-md-row"><span class="mp-md-label">'+x[0]+'</span><span class="mp-md-price">'+inr(x[1])+'</span><span class="mp-md-chg"></span></div>'; }).join('')
      + '</div></div>';
  }
  function paint(d){
    var cards = [];
    ['world','currencies','commodities','sectors'].forEach(function(k){
      var rows = d[k]||[]; if(!rows.length) return;
      cards.push('<div class="mp-md-card"><h3 class="mp-md-card-title">'+TITLES[k]+'</h3><div class="mp-md-card-body">'+rows.map(row).join('')+'</div></div>');
    });
    var bc = bullionCard(d.bullion_inr);
    if(bc) cards.splice(cards.length ? 1 : 0, 0, bc);
    if(cards.length) GRID.innerHTML = cards.join('');
    var a = document.getElementById('mpMdAsOf');
    if(a) a.textContent = 'Market data - updated ' + new Date().toLocaleTimeString() + ' - may be delayed';
  }
  function tick(){ fetch(REST,{headers:{'Accept':'application/json'},credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(d){ if(d) paint(d); }).catch(function(){}); }
  setInterval(tick, 30000);
}());
</script>
    <?php
    return ob_get_clean();
});

/* --------------------------- Front-end: flash animation + LIVE dot --------------------------- */

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

/* --------------------------- "Live Stock Analysis - Top 10" widget (#mpStockTool) ---------------------------
   The theme's own stock-analyzer.css/js are not enqueued on the homepage and the js
   references an undefined `mpData` object, so the widget sits unstyled on "Loading..."
   forever. We (a) enqueue the existing stylesheet and (b) supply a self-contained js
   implementation that fills the markup with real market data (no score/target/momentum
   theatre) from /wp-json/mp/v1/markets. */

add_action('wp_enqueue_scripts', function () {
    foreach (array('moneypuran-plugin', 'moneypuran-stock-analyzer') as $slug) {
        $rel = $slug . '/assets/css/stock-analyzer.css';
        $abs = WP_PLUGIN_DIR . '/' . $rel;
        if (file_exists($abs) && !wp_style_is('mp-stock-analyzer-css', 'enqueued')) {
            wp_enqueue_style('mp-stock-analyzer-css', plugins_url($rel), array(), (string) filemtime($abs));
        }
    }
});

add_action('wp_footer', function () {
    ?>
<style id="mp-md-mpst">
#mpStockTool .th-score,#mpStockTool .td-score{display:none}
#mpStockTool .mpst-sb-item:nth-child(4),#mpStockTool .mpst-sb-item:nth-child(6){display:none}
#mpStockTool .sig-bullish{color:#16a34a}#mpStockTool .sig-bearish{color:#dc2626}#mpStockTool .sig-neutral{color:#64748b}
#mpStockTool .chg-up{color:#16a34a}#mpStockTool .chg-dn{color:#dc2626}
html[data-theme="dark"] #mpStockTool .sig-neutral{color:#94a3b8}
</style>
<script>
(function(){
  var W = document.getElementById('mpStockTool');
  if (!W) return;
  var REST = (location.origin||'') + '/wp-json/mp/v1/markets?only=stocks&filter=';
  var currentFilter = 'trending';
  var $ = function(id){ return document.getElementById(id); };

  function inr(n){ return Number(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function vol(n){ n=Number(n)||0; if(n>=1e7) return (n/1e7).toFixed(2)+' Cr'; if(n>=1e5) return (n/1e5).toFixed(2)+' L'; return n.toLocaleString('en-IN'); }
  function sigOf(p){ return p > 1.5 ? {l:'Bullish',c:'bullish'} : (p < -1.5 ? {l:'Bearish',c:'bearish'} : {l:'Neutral',c:'neutral'}); }

  var LAST = [];   // last loaded stock list, for the "Why?" panel

  function rowHtml(s, i){
    var up = (s.change_pct||0) >= 0, sg = sigOf(s.change_pct||0);
    return '<tr>'
      + '<td class="td-rank">'+(i+1)+'</td>'
      + '<td class="td-stock"><strong>'+s.symbol+'</strong><span style="display:block;font-size:11px;opacity:.7">'+(s.name||'')+' · '+(s.exchange||'NSE')+'</span></td>'
      + '<td class="td-price">₹'+inr(s.price)+'</td>'
      + '<td class="td-change"><span class="chg-val '+(up?'chg-up':'chg-dn')+'">'+(up?'+':'')+Number(s.change_pct||0).toFixed(2)+'%</span></td>'
      + '<td class="td-vol">'+vol(s.volume)+'</td>'
      + '<td class="td-signal"><span class="sig-pill sig-'+sg.c+'">'+sg.l+'</span></td>'
      + '<td class="td-score">–</td>'
      + '<td class="td-action"><button type="button" class="mpst-why" data-sym="'+s.symbol+'" style="font-size:12px;font-weight:600;background:none;border:0;color:var(--mpst-accent,#3b82f6);cursor:pointer;padding:0">Why?</button></td>'
      + '</tr>';
  }
  function cardHtml(s, i){
    var up = (s.change_pct||0) >= 0, sg = sigOf(s.change_pct||0);
    return '<div class="mpst-card">'
      + '<div class="mpst-card-top"><span class="mpst-card-rank">#'+(i+1)+'</span><strong>'+s.symbol+'</strong>'
      + '<span class="chg-val '+(up?'chg-up':'chg-dn')+'">'+(up?'+':'')+Number(s.change_pct||0).toFixed(2)+'%</span></div>'
      + '<div class="mpst-card-row"><span>₹'+inr(s.price)+'</span>'
      + '<span class="sig-pill sig-'+sg.c+'">'+sg.l+'</span>'
      + '<span style="opacity:.7">Vol '+vol(s.volume)+'</span></div>'
      + '<div class="mpst-card-row"><span style="font-size:11px;opacity:.7">'+(s.name||'')+' · '+(s.exchange||'NSE')+'</span>'
      + '<button type="button" class="mpst-why" data-sym="'+s.symbol+'" style="font-size:12px;font-weight:600;background:none;border:0;color:var(--mpst-accent,#3b82f6);cursor:pointer;padding:0">Why is it moving?</button></div></div>';
  }

  /* ---- "Why?" panel: factual observations + a read of the move + related news ---- */
  function observe(s){
    var p = Number(s.change_pct)||0, dir = p>=0 ? 'up' : 'down', mag = Math.abs(p).toFixed(2), out = [];
    out.push('<strong>'+s.symbol+'</strong> is '+dir+' <strong>'+mag+'%</strong> today at ₹'+inr(s.price)+' (day range ₹'+inr(s.low||s.price)+'–₹'+inr(s.high||s.price)+').');
    var rel = null;
    if (s.sector && s.sector_chg != null){
      var sc = Number(s.sector_chg), diff = p - sc;
      var word = Math.abs(diff) < 0.4 ? 'in line with' : (diff > 0 ? 'ahead of' : 'behind');
      out.push('That is '+word+' its sector — the '+s.sector+' index is '+(sc>=0?'+':'')+sc.toFixed(2)+'% today.');
      rel = word;
    }
    if (s.w52_high && s.w52_low){
      var fh = (s.w52_high - s.price)/s.w52_high*100, fl = (s.price - s.w52_low)/s.w52_low*100;
      if (fh <= fl) out.push('It is about '+fh.toFixed(0)+'% below its 52-week high of ₹'+inr(s.w52_high)+'.');
      else out.push('It is about '+fl.toFixed(0)+'% above its 52-week low of ₹'+inr(s.w52_low)+'.');
    }
    out.push('Volume so far: '+vol(s.volume)+'.');
    return { text: out.join(' '), rel: rel, dir: dir };
  }
  function readMove(o, s){
    if (o.rel === 'in line with')
      return 'The move looks <strong>sector-driven</strong> — '+s.symbol+' is tracking the wider '+(s.sector||'market')+', so today’s change is more about the sector/market mood than the company itself.';
    if (o.rel === 'behind' || o.rel === 'ahead of')
      return 'The stock is moving <strong>faster than its sector</strong>, which usually points to something <strong>stock-specific</strong> — an earnings update, order win/loss, brokerage note, block deal or management news. Check the coverage below.';
    return 'With the sector read unavailable, watch whether this is a broad-market day or a stock-specific story — the coverage below is the fastest way to tell.';
  }
  function newsFor(s){
    var q = encodeURIComponent(s.name || s.symbol);
    return fetch((location.origin||'')+'/wp-json/wp/v2/posts?search='+q+'&per_page=4&_fields=title,link', {credentials:'omit'})
      .then(function(r){ return r.ok ? r.json() : []; })
      .then(function(posts){
        if (posts && posts.length){
          return '<ul style="margin:6px 0 0;padding-left:18px">'+posts.map(function(p){
            return '<li style="margin:4px 0"><a href="'+p.link+'">'+(p.title.rendered||'')+'</a></li>';
          }).join('')+'</ul>';
        }
        return '<p style="margin:6px 0 0">No MoneyPuran article on '+(s.name||s.symbol)+' yet. '
          + '<a target="_blank" rel="noopener" href="https://news.google.com/search?q='+q+'%20share%20price%20NSE">Latest on Google News</a> '
          + '· <a href="'+(location.origin||'')+'/category/stocks/">Our Stocks section</a></p>';
      })
      .catch(function(){ return '<p style="margin:6px 0 0"><a href="'+(location.origin||'')+'/category/stocks/">Browse our Stocks section</a></p>'; });
  }
  window.mpstWhy = function(sym){
    var s = null;
    for (var i=0;i<LAST.length;i++){ if (LAST[i].symbol === sym){ s = LAST[i]; break; } }
    if (!s) return;
    var panel = $('mpstAnalysisPanel'), body = $('mpstApBody'), title = $('mpstApTitle');
    if (!panel || !body) return;
    var gate = $('mpstPremiumGate'); if (gate) gate.style.display = 'none';
    var ai = $('mpstAiPanel'); if (ai) ai.style.display = 'none';
    var o = observe(s);
    if (title) title.textContent = s.symbol + ' — ' + (s.name || '');
    body.innerHTML =
        '<div style="font-size:14px;line-height:1.6">'
      +   '<h4 style="margin:0 0 4px">What the numbers show</h4><p style="margin:0">'+o.text+'</p>'
      +   '<h4 style="margin:14px 0 4px">A read of the move</h4><p style="margin:0">'+readMove(o, s)+'</p>'
      +   '<h4 style="margin:14px 0 6px">Price chart</h4>'
      +   '<div class="mp-cc" data-symbol="'+sym+'" data-tf="1D">'
      +     '<div class="mp-cc__head"><span class="mp-cc__title" data-role="title">'+s.symbol+'</span><span class="mp-cc__meta" data-role="meta">Loading…</span></div>'
      +     '<div class="mp-cc__tf" data-role="tf">'
      +       ['5m','15m','1h','1D','1W'].map(function(t){return '<button type="button" data-t="'+t+'"'+(t==='1D'?' class="on"':'')+'>'+t.toUpperCase()+'</button>';}).join('')
      +     '</div><div class="mp-cc__box" style="height:340px"></div>'
      +   '</div>'
      +   '<h4 style="margin:14px 0 4px">Related coverage</h4><div id="mpstWhyNews">Loading…</div>'
      +   '<p style="margin:14px 0 0;font-size:12px;opacity:.7">Candles from exchange data (Yahoo Finance), possibly delayed. This is an automated summary of publicly available market data — not investment advice, research or a recommendation to buy or sell. Reasons for a price move are our interpretation of the data, not confirmed facts. Consult a SEBI-registered investment adviser before acting.</p>'
      + '</div>';
    panel.style.display = '';
    panel.scrollIntoView({ block: 'nearest' });
    if (window.__mpLWC) window.__mpLWC.scan(true);
    newsFor(s).then(function(html){ var n = $('mpstWhyNews'); if (n) n.innerHTML = html; });
  };

  function summary(list){
    var b=0,n=0,d=0, volLeader=list[0]||{symbol:'-'}, maxV=-1;
    list.forEach(function(s){
      var p=s.change_pct||0;
      if(p>1.5) b++; else if(p<-1.5) d++; else n++;
      if((s.volume||0)>maxV){ maxV=s.volume||0; volLeader=s; }
    });
    var set=function(id,v){ var e=$(id); if(e) e.textContent=v; };
    set('sbBullCount',b); set('sbNeutCount',n); set('sbBearCount',d);
    set('sbVolLeader', volLeader.symbol || '-');
    var bar=$('mpstSummaryBar'); if(bar) bar.style.display='';
  }

  function show(which){
    ['mpstLoading','mpstError','mpstTable'].forEach(function(id){ var e=$(id); if(e) e.style.display = (id===which?'':'none'); });
  }

  window.mpstLoad = function(){
    show('mpstLoading');
    var src=$('mpstSource'); if(src) src.textContent='Loading...';
    fetch(REST + encodeURIComponent(currentFilter), {headers:{'Accept':'application/json'}, credentials:'omit'})
      .then(function(r){ return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function(d){
        var list = (d && d.stocks) ? d.stocks.slice(0,10) : [];
        if (!list.length) throw new Error('empty');
        LAST = list;
        var tb = $('mpstTableBody'); if (tb) tb.innerHTML = list.map(rowHtml).join('');
        var cc = $('mpstCards'); if (cc) cc.innerHTML = list.map(cardHtml).join('');
        summary(list);
        // relabel columns we repurposed
        var vh = W.querySelector('.th-vol'); if (vh) vh.textContent = 'Volume';
        var sh = W.querySelector('.th-action'); if (sh) sh.textContent = 'Why?';
        show('mpstTable');
        if (src) src.textContent = 'NSE · live';
        var t=$('mpstTime'); if(t) t.textContent = ' · ' + new Date().toLocaleTimeString();
      })
      .catch(function(){
        show('mpstError');
        var em=$('mpstErrorMsg'); if(em) em.textContent='Could not load market data. Please retry.';
        if (src) src.textContent='Unavailable';
      });
  };
  window.mpstFilter = function(f, btn){
    currentFilter = f || 'trending';
    var btns = W.querySelectorAll('.mpst-filter');
    for (var i=0;i<btns.length;i++) btns[i].classList.remove('active');
    if (btn) btn.classList.add('active');
    window.mpstLoad();
  };
  window.mpstRefresh = function(){ window.mpstLoad(); };
  window.mpstClosePanel = function(){ var p=$('mpstAnalysisPanel'); if(p) p.style.display='none'; };

  // "Why?" buttons (delegated, survives table re-renders)
  W.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('.mpst-why') : null;
    if (b && b.getAttribute('data-sym')) { e.preventDefault(); window.mpstWhy(b.getAttribute('data-sym')); }
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', window.mpstLoad);
  else window.mpstLoad();
  setInterval(window.mpstLoad, 90000);
}());
</script>
    <?php
}, 20);

/* --------------------------- Neutralise the fake analyser --------------------------- */

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
            $row['updated_at'] = $r['updated_at']; $row['data_source'] = 'live';
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
                'summary' => sprintf('%s (%s) is trading at &#8377;%s, %s %s%% today. Prices may be delayed.',
                    esc_html($name), esc_html($sym), $price, $dir, number_format(abs($pct), 2)),
                'trade_idea' => 'MoneyPuran does not publish buy/sell targets. This is market data, not investment advice.',
            );
        }
    }
    unset($row);
    $response->set_data($data);
    return $response;
}, 10, 3);

/* ============================================================================
 * SESSION-AWARE NEWS TICKER  (v1.3.0)
 * A thin header bar that scrolls market headlines for whichever session is
 * live now - India equity / US equity / commodities - switching without a
 * page reload. Rendered server-side for the current session (no CLS), then
 * kept in sync client-side via /wp-json/mp/v1/ticker + an Intl-timezone
 * session check. CSS transform animation only; pause on hover.
 * ==========================================================================*/

/** Active market sessions right now, highest priority first. */
function mp_md_sessions($ist = null, $et = null) {
    try {
        if (!$ist) $ist = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        if (!$et)  $et  = new DateTime('now', new DateTimeZone('America/New_York'));
    } catch (Exception $e) {
        return array('closed');
    }
    $istWd = (int) $ist->format('N'); $istM = (int) $ist->format('G') * 60 + (int) $ist->format('i');
    $etWd  = (int) $et->format('N');  $etM  = (int) $et->format('G')  * 60 + (int) $et->format('i');
    $out = array();
    if ($etWd  <= 5 && $etM  >= 570 && $etM  < 960)  $out[] = 'us';           // 09:30-16:00 ET
    if ($istWd <= 5 && $istM >= 555 && $istM < 930)  $out[] = 'india';        // 09:15-15:30 IST
    if ($istWd <= 5 && $istM >= 540 && $istM < 1410) $out[] = 'commodities';  // 09:00-23:30 IST (MCX)
    return $out ? $out : array('closed');
}

function mp_md_ticker_cats() {
    return array(
        'india'       => 'indian-markets,stocks,ipos,earnings,central-banks',
        'us'          => 'us-markets,global-markets,economy,regulation',
        'commodities' => 'commodities,economy',
        'closed'      => '',
    );
}

/** Headlines + a live market summary line for each session. Cached 5 min. */
function mp_md_ticker_data() {
    $cached = get_transient('mp_md_ticker_v1');
    if (is_array($cached)) return $cached;

    $seen = array();
    $pick = function ($slugs, $want = 8) use (&$seen) {
        $items = array();
        $args = array(
            'post_type' => 'post', 'post_status' => 'publish',
            'posts_per_page' => $want + 4, 'ignore_sticky_posts' => true, 'no_found_rows' => true,
            'orderby' => 'date', 'order' => 'DESC',
        );
        if ($slugs) $args['category_name'] = $slugs;
        $q = new WP_Query($args);
        foreach ($q->posts as $p) {
            if (isset($seen[$p->ID]) || count($items) >= $want) continue;
            $seen[$p->ID] = 1;
            $items[] = array(
                'title' => html_entity_decode(get_the_title($p), ENT_QUOTES),
                'url'   => get_permalink($p),
            );
        }
        return $items;
    };

    $cats   = mp_md_ticker_cats();
    $latest = $pick('', 10);
    $sessions = array(
        'india'       => $pick($cats['india'], 7),
        'us'          => $pick($cats['us'], 7),
        'commodities' => $pick($cats['commodities'], 6),
        'closed'      => $latest,
    );
    foreach (array('india', 'us', 'commodities') as $k) {
        if (count($sessions[$k]) < 5) {
            foreach ($latest as $it) {
                if (count($sessions[$k]) >= 6) break;
                $dup = false;
                foreach ($sessions[$k] as $x) if ($x['url'] === $it['url']) { $dup = true; break; }
                if (!$dup) $sessions[$k][] = $it;
            }
        }
    }

    // Live market lines - READ ONLY from the warmed caches (the cron keeps them
    // fresh). Never trigger a blocking upstream fetch from the ticker request.
    $idxT = get_transient(MP_MD_IDX_KEY);
    $grpT = get_transient(MP_MD_GRP_KEY);
    $idx  = is_array($idxT) && !empty($idxT['indices']) ? $idxT : array('indices' => array());
    $grp  = is_array($grpT) ? $grpT : array();
    $by   = array();
    foreach ($idx['indices'] as $r) $by[$r['sym']] = $r;
    $world = array();
    foreach (($grp['world'] ?? array()) as $r) $world[$r['sym']] = $r;
    $comm  = array();
    foreach (($grp['commodities'] ?? array()) as $r) $comm[$r['sym']] = $r;

    $line = function ($rows) {
        $parts = array();
        foreach ($rows as $r) {
            if (!$r || !isset($r['price'])) continue;
            $pct   = isset($r['chgPct']) ? (float) $r['chgPct'] : 0;
            $cls   = $pct >= 0 ? 'up' : 'dn';
            $sign  = $pct >= 0 ? '+' : '';
            $label = isset($r['label']) ? $r['label'] : '';
            $parts[] = sprintf(
                '<b>%s</b> %s <i class="%s">%s%.2f%%</i>',
                esc_html($label),
                number_format((float) $r['price'], ($r['price'] < 10 ? 4 : 2)),
                $cls, $sign, $pct
            );
        }
        return implode(' &nbsp;&middot;&nbsp; ', $parts);
    };
    $g = function ($src, $sym, $fallbackLabel = '') use ($by, $world, $comm) {
        $pool = $src === 'idx' ? $by : ($src === 'world' ? $world : $comm);
        if (isset($pool[$sym])) {
            $r = $pool[$sym];
            if (empty($r['label']) && $fallbackLabel) $r['label'] = $fallbackLabel;
            return $r;
        }
        return null;
    };

    $market = array(
        'india' => $line(array(
            $g('idx', '^NSEI', 'NIFTY 50'), $g('idx', '^BSESN', 'SENSEX'),
            $g('idx', '^NSEBANK', 'NIFTY BANK'), $g('idx', 'INR=X', 'USD/INR'),
        )),
        'us' => $line(array(
            $g('world', '^GSPC'), $g('world', '^DJI'), $g('world', '^IXIC'),
        )),
        'commodities' => $line(array(
            $g('comm', 'GC=F') ?: $g('idx', 'GC=F', 'Gold'),
            $g('comm', 'SI=F'),
            $g('comm', 'CL=F') ?: $g('idx', 'CL=F', 'Crude Oil'),
            $g('comm', 'NG=F'),
        )),
    );
    $market['closed'] = $market['us'] ?: $market['india'];

    $data = array('sessions' => $sessions, 'market' => $market, 'asOf' => gmdate('c'));
    set_transient('mp_md_ticker_v1', $data, 5 * MINUTE_IN_SECONDS);
    return $data;
}

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/ticker', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $d = mp_md_ticker_data();
            $d['active'] = mp_md_sessions();
            $resp = rest_ensure_response($d);
            $resp->header('Cache-Control', 'public, max-age=60, s-maxage=120, stale-while-revalidate=300');
            return $resp;
        },
    ));
});

function mp_md_ticker_label($session) {
    $map = array('india' => 'India Live', 'us' => 'US Live', 'commodities' => 'Commodities', 'closed' => 'Markets Closed');
    return isset($map[$session]) ? $map[$session] : $map['closed'];
}

function mp_md_ticker_items_html($session, $data, $dup = false) {
    $items = isset($data['sessions'][$session]) ? $data['sessions'][$session] : array();
    $tab   = $dup ? " tabindex=\"-1\"" : "";
    $h = "";
    foreach ($items as $it) {
        $h .= '<a class="mp-ticker__item" href="' . esc_url($it['url']) . '"' . $tab . '>' . esc_html($it['title']) . '</a>'
            . '<span class="mp-ticker__sep">&#9679;</span>';
    }
    if ($h === '') $h = '<span class="mp-ticker__item">Loading market headlines&hellip;</span>';
    return $h;
}

/** Server-render the ticker for the session that is live right now. */
function mp_md_render_ticker() {
    if (is_admin() || is_feed() || is_embed()) return;
    $data     = mp_md_ticker_data();
    $sessions = mp_md_sessions();
    $active   = $sessions[0];
    $closed   = ($active === 'closed');
    ?>
<div class="mp-ticker<?php echo $closed ? ' is-closed' : ''; ?>" id="mpTicker" aria-label="Market news ticker">
  <span class="mp-ticker__badge" id="mpTickerBadge">
    <span class="mp-ticker__dot" aria-hidden="true"></span><span id="mpTickerLabel"><?php echo esc_html(mp_md_ticker_label($active)); ?></span>
  </span>
  <div class="mp-ticker__viewport">
    <div class="mp-ticker__track" id="mpTickerTrack">
      <span class="mp-ticker__half"><?php echo mp_md_ticker_items_html($active, $data, false); ?></span>
      <span class="mp-ticker__half" aria-hidden="true"><?php echo mp_md_ticker_items_html($active, $data, true); ?></span>
    </div>
  </div>
</div>
<style id="mp-ticker-css">
.mp-ticker{display:flex;align-items:stretch;height:34px;overflow:hidden;background:#0f172a;color:#dfe6ee;
  border-bottom:1px solid rgba(255,255,255,.07);font-size:13px;line-height:1;position:relative;z-index:5}
.mp-ticker__badge{flex:0 0 auto;display:flex;align-items:center;gap:6px;padding:0 12px;font-weight:700;font-size:11px;
  letter-spacing:.05em;text-transform:uppercase;background:#0057ff;color:#fff;white-space:nowrap}
.mp-ticker.is-closed .mp-ticker__badge{background:#475569}
.mp-ticker__dot{width:7px;height:7px;border-radius:50%;background:#ff3b3b;box-shadow:0 0 0 0 rgba(255,59,59,.7);
  animation:mpTickPulse 1.5s ease-out infinite}
.mp-ticker.is-closed .mp-ticker__dot{background:#cbd5e1;animation:none;box-shadow:none}
.mp-ticker__viewport{flex:1 1 auto;overflow:hidden;position:relative;
  -webkit-mask-image:linear-gradient(90deg,transparent,#000 22px,#000 calc(100% - 22px),transparent);
          mask-image:linear-gradient(90deg,transparent,#000 22px,#000 calc(100% - 22px),transparent)}
.mp-ticker__track{display:inline-flex;align-items:center;height:100%;white-space:nowrap;will-change:transform;
  animation:mpTickScroll var(--mp-tick-duration,60s) linear infinite}
.mp-ticker__viewport:hover .mp-ticker__track,.mp-ticker__track:focus-within{animation-play-state:paused}
.mp-ticker__half{display:inline-flex;align-items:center;padding-left:16px}
.mp-ticker__item{display:inline-flex;align-items:center;color:#dfe6ee;text-decoration:none;padding:0 6px}
.mp-ticker__item:hover,.mp-ticker__item:focus{color:#7fb0ff;outline:none}
.mp-ticker__sep{opacity:.28;padding:0 5px}
.mp-ticker__mkt{color:#9aa7b4;padding:0 4px}
.mp-ticker__mkt b{color:#e9eef5;font-weight:600}
.mp-ticker__mkt i{font-style:normal}
.mp-ticker__mkt i.up{color:#22c55e}.mp-ticker__mkt i.dn{color:#f87171}
@keyframes mpTickScroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@keyframes mpTickPulse{0%{box-shadow:0 0 0 0 rgba(255,59,59,.7)}70%{box-shadow:0 0 0 6px rgba(255,59,59,0)}100%{box-shadow:0 0 0 0 rgba(255,59,59,0)}}
@media (prefers-reduced-motion:reduce){.mp-ticker__track{animation:none}.mp-ticker__viewport{overflow-x:auto}
  .mp-ticker__half:last-child{display:none}}
@media (max-width:600px){.mp-ticker{height:30px;font-size:12px}
  .mp-ticker__badge{padding:0 8px;font-size:10px;gap:4px}.mp-ticker__dot{width:6px;height:6px}}
</style>
<script>
(function(){
  var T=document.getElementById('mpTicker'); if(!T) return;
  var TRACK=document.getElementById('mpTickerTrack'), LABEL=document.getElementById('mpTickerLabel'),
      SPEED=62, DATA=null, rotIdx=0, rotTimer=null, curKey='';
  var BADGE={india:'India Live',us:'US Live',commodities:'Commodities',closed:'Markets Closed'};

  function sessionsNow(){
    var d=new Date();
    function p(tz){
      var o={};
      new Intl.DateTimeFormat('en-US',{timeZone:tz,hour12:false,weekday:'short',hour:'2-digit',minute:'2-digit'})
        .formatToParts(d).forEach(function(x){o[x.type]=x.value;});
      var wd={Sun:0,Mon:1,Tue:2,Wed:3,Thu:4,Fri:5,Sat:6}[o.weekday];
      return {wd:wd,m:(parseInt(o.hour,10)%24)*60+parseInt(o.minute,10)};
    }
    var ist=p('Asia/Kolkata'), et=p('America/New_York'), s=[];
    if(et.wd>=1&&et.wd<=5 && et.m>=570 && et.m<960)  s.push('us');
    if(ist.wd>=1&&ist.wd<=5&&ist.m>=555&&ist.m<930)  s.push('india');
    if(ist.wd>=1&&ist.wd<=5&&ist.m>=540&&ist.m<1410) s.push('commodities');
    return s.length?s:['closed'];
  }
  function esc(s){var e=document.createElement('span');e.textContent=s;return e.innerHTML;}
  function half(session,dup){
    var d=DATA||{sessions:{},market:{}};
    var items=(d.sessions&&d.sessions[session])||[], h="";
    items.forEach(function(it){
      h+='<a class="mp-ticker__item" href="'+it.url+'"'+(dup?' tabindex="-1"':'')+'>'+esc(it.title)+'</a>'
        +'<span class="mp-ticker__sep">●</span>';
    });
    return h||'<span class="mp-ticker__item">Markets are quiet right now.</span>';
  }
  function setDuration(){
    var apply=function(){
      var el=TRACK.firstElementChild; if(!el) return;
      var w=el.getBoundingClientRect().width || el.scrollWidth || 1200;
      if(w>40) TRACK.style.setProperty('--mp-tick-duration',Math.max(18,Math.round(w/SPEED))+'s');
    };
    apply();
    if(window.requestAnimationFrame) requestAnimationFrame(apply);
    setTimeout(apply,300);
  }
  function render(session){
    // Update the badge label to match the client's real session.
    T.classList.toggle('is-closed', session==='closed');
    if(LABEL) LABEL.textContent=BADGE[session]||BADGE.closed;
    // Without fetched data, keep the server-rendered headlines - just size the loop.
    if(!DATA){ setDuration(); return; }
    if(session===curKey) return;
    curKey=session;
    TRACK.innerHTML='<span class="mp-ticker__half">'+half(session,false)+'</span>'
                   +'<span class="mp-ticker__half" aria-hidden="true">'+half(session,true)+'</span>';
    setDuration();
  }
  function tick(){
    var active=sessionsNow();
    if(active.length>1){
      if(!rotTimer) rotTimer=setInterval(function(){ rotIdx++; curKey=''; render(active[rotIdx%active.length]); },28000);
    } else if(rotTimer){ clearInterval(rotTimer); rotTimer=null; rotIdx=0; }
    render(active[rotIdx%active.length]);
  }
  function load(){
    fetch((location.origin||'')+'/wp-json/mp/v1/ticker',{headers:{'Accept':'application/json'},credentials:'omit'})
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){ if(d&&d.sessions){ DATA=d; curKey=''; tick(); } })
      .catch(function(){});
  }
  tick();          // size + label the server-rendered bar immediately
  load();          // then swap in fresh, session-matched headlines
  setInterval(load,180000);
  setInterval(tick,60000);
  document.addEventListener('visibilitychange',function(){ if(!document.hidden){ setDuration(); tick(); } });
  window.addEventListener('resize',function(){ clearTimeout(window.__mpTickRz); window.__mpTickRz=setTimeout(setDuration,200); });
}());
</script>
    <?php
}
add_action('mp_news_ticker', 'mp_md_render_ticker');
add_shortcode('mp_news_ticker', function () { ob_start(); mp_md_render_ticker(); return ob_get_clean(); });

/* ============================================================================
 * Evergreen tool pages carry live data but the theme prints their (frozen)
 * publish date. Show today's date instead, and keep post_modified fresh so the
 * sitemap <lastmod> and schema dateModified track that the data is current.
 * ==========================================================================*/
function mp_md_evergreen_page_ids() {
    $ids = get_transient('mp_md_evergreen_ids');
    if (is_array($ids)) return $ids;
    $slugs = array(
        'stock-analysis', 'gold-rates', 'fuel-prices', 'commodities', 'charts', 'fii-dii-data',
        'why-market-moved-today', 'india-market-today', 'nifty-50', 'sensex', 'bank-nifty',
        'stocks', 'sector', 'top-gainers-today', 'top-losers-today',
        '52-week-high-stocks', '52-week-low-stocks',
    );
    $ids = array();
    foreach ($slugs as $s) {
        $p = get_page_by_path($s);
        if ($p) $ids[] = (int) $p->ID;
    }
    $hub = get_page_by_path('stocks'); // per-stock + sector child pages
    if ($hub) {
        $kids = get_posts(array('post_type' => 'page', 'post_parent' => $hub->ID, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'publish'));
        foreach ($kids as $k) {
            $ids[] = (int) $k;
            $gk = get_posts(array('post_type' => 'page', 'post_parent' => $k, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'publish'));
            foreach ($gk as $g) $ids[] = (int) $g;
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    set_transient('mp_md_evergreen_ids', $ids, DAY_IN_SECONDS);
    return $ids;
}
function mp_md_is_evergreen_page($post) {
    if (!$post) return false;
    $pid = is_object($post) ? (int) $post->ID : (int) $post;
    return $pid && in_array($pid, mp_md_evergreen_page_ids(), true);
}
add_filter('get_the_date', function ($the_date, $format, $post) {
    return mp_md_is_evergreen_page($post) ? wp_date($format ?: get_option('date_format')) : $the_date;
}, 10, 3);
add_filter('get_the_modified_date', function ($the_date, $format, $post) {
    return mp_md_is_evergreen_page($post) ? wp_date($format ?: get_option('date_format')) : $the_date;
}, 10, 3);

add_action('mp_md_daily_touch', function () {
    global $wpdb;
    $now = current_time('mysql');
    $gmt = current_time('mysql', true);
    foreach (mp_md_evergreen_page_ids() as $pid) {
        $wpdb->update($wpdb->posts, array('post_modified' => $now, 'post_modified_gmt' => $gmt), array('ID' => $pid));
        clean_post_cache($pid);
    }
    delete_transient('mp_md_evergreen_ids');
});
if (!wp_next_scheduled('mp_md_daily_touch')) {
    wp_schedule_event(time() + 300, 'daily', 'mp_md_daily_touch');
}

/* ============================================================================
 * [mp_hero_slider count="4"] - carousel of the newest posts (left) + a "Most
 * Used" quick-tools panel (right). One slide at a time, auto-advancing.
 * Server-rendered (slide 1 shows at rest), no library, reduced-motion aware.
 * ==========================================================================*/
function mp_md_quick_tools() {
    return apply_filters('mp_quick_tools', array(
        'Stock Analysis'    => '/stock-analysis/',
        'US Markets'        => '/us-markets/',
        'Gold Rate Today'   => '/gold-rates/',
        'Silver Rate Today' => '/silver-rate-today/',
        'Fuel Prices'       => '/fuel-prices/',
        'Commodities'       => '/commodities/',
        'Live Charts'       => '/charts/',
        'FII / DII'         => '/fii-dii-data/',
        'Nifty 50'          => '/nifty-50/',
        'Top Gainers'       => '/top-gainers-today/',
        'Why Market Moved'  => '/why-market-moved-today/',
    ));
}
add_shortcode('mp_hero_slider', function ($atts) {
    $a = shortcode_atts(array('count' => 4), $atts);
    $n = max(2, min(8, (int) $a['count']));
    $posts = get_posts(array('numberposts' => $n, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true));
    if (count($posts) < 2) return '';
    $total = count($posts);

    ob_start(); ?>
<div class="mp-hsrow">
<section class="mp-hs" id="mpHs" aria-roledescription="carousel" aria-label="Latest stories">
  <div class="mp-hs__viewport"><div class="mp-hs__track" id="mpHsTrack">
    <?php foreach ($posts as $i => $p) :
      $c   = get_the_category($p->ID); $c = $c ? $c[0] : null;
      $img = get_the_post_thumbnail_url($p->ID, 'large');
      if (!$img) $img = get_the_post_thumbnail_url($p->ID, 'full');
      $ago = human_time_diff(get_post_time('U', true, $p), current_time('timestamp', true));
    ?>
    <article class="mp-hs__slide<?php echo $i === 0 ? ' is-active' : ''; ?>" role="group" aria-roledescription="slide"
             aria-label="<?php echo esc_attr(($i + 1) . ' of ' . $total); ?>"<?php echo $i === 0 ? '' : ' aria-hidden="true"'; ?>>
      <a class="mp-hs__link" href="<?php echo esc_url(get_permalink($p)); ?>">
        <?php if ($img) : ?><span class="mp-hs__img" style="background-image:url('<?php echo esc_url($img); ?>')"></span><?php endif; ?>
        <span class="mp-hs__shade"></span>
        <span class="mp-hs__body">
          <?php if ($c) : ?><span class="mp-hs__cat"><?php echo esc_html($c->name); ?></span><?php endif; ?>
          <span class="mp-hs__h"><?php echo esc_html(get_the_title($p)); ?></span>
          <span class="mp-hs__meta"><?php echo esc_html($ago); ?> ago</span>
        </span>
      </a>
    </article>
    <?php endforeach; ?>
  </div></div>
  <button class="mp-hs__arrow mp-hs__prev" type="button" aria-label="Previous story">&#8249;</button>
  <button class="mp-hs__arrow mp-hs__next" type="button" aria-label="Next story">&#8250;</button>
  <div class="mp-hs__dots">
    <?php for ($i = 0; $i < $total; $i++) : ?>
    <button class="mp-hs__dot<?php echo $i === 0 ? ' is-active' : ''; ?>" type="button" aria-label="<?php echo esc_attr('Go to story ' . ($i + 1)); ?>"<?php echo $i === 0 ? ' aria-current="true"' : ''; ?>></button>
    <?php endfor; ?>
  </div>
</section>
<aside class="mp-hs-tools" aria-label="Most used tools">
  <h3 class="mp-hs-tools__h">Most Used</h3>
  <?php foreach (mp_md_quick_tools() as $label => $path) : ?>
  <a href="<?php echo esc_url(home_url($path)); ?>"><?php echo esc_html($label); ?></a>
  <?php endforeach; ?>
</aside>
</div>
<style id="mp-hs-css">
.mp-hsrow{display:grid;grid-template-columns:minmax(0,1fr) 288px;gap:16px;margin:14px 0 26px}
.mp-hs{position:relative;margin:0;border-radius:14px;overflow:hidden;background:var(--mp-surface,#0f172a);border:1px solid var(--mp-border,rgba(255,255,255,.08))}
.mp-hs-tools{background:var(--mp-surface2,#1e293b);border:1px solid var(--mp-border,#e2e8f0);border-radius:14px;padding:8px 10px 10px;display:flex;flex-direction:column;align-content:start;box-shadow:0 1px 3px rgba(2,6,23,.06);max-height:clamp(240px,33vw,380px);overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(148,163,184,.45) transparent}
.mp-hs-tools::-webkit-scrollbar{width:6px}
.mp-hs-tools::-webkit-scrollbar-track{background:transparent}
.mp-hs-tools::-webkit-scrollbar-thumb{background:rgba(148,163,184,.45);border-radius:3px}
.mp-hs-tools::-webkit-scrollbar-thumb:hover{background:rgba(148,163,184,.7)}
html:not([data-theme="dark"]) .mp-hs-tools{background:#fff}
.mp-hs-tools__h{position:sticky;top:0;background:inherit;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mp-muted,#94a3b8);margin:6px 6px;padding-bottom:8px;border-bottom:1px solid var(--mp-border,#e2e8f0)}
.mp-hs-tools a{display:flex;align-items:center;padding:10px 8px;font-size:13.5px;font-weight:600;color:var(--mp-ink2,#cbd5e1);border-radius:8px;text-decoration:none;transition:background .14s,color .14s}
.mp-hs-tools a+a{border-top:1px solid var(--mp-border,rgba(148,163,184,.14))}
.mp-hs-tools a:hover{background:var(--mp-brand-lt,rgba(0,87,255,.12));color:var(--mp-brand,#0057ff)}
@media(max-width:900px){.mp-hsrow{grid-template-columns:1fr}
  .mp-hs-tools{flex-direction:row;flex-wrap:wrap;gap:2px 4px;max-height:none;overflow-y:visible}.mp-hs-tools__h{flex:1 0 100%;border-bottom:0;padding-bottom:0;position:static}.mp-hs-tools a{flex:0 0 auto;padding:8px 10px}.mp-hs-tools a+a{border-top:0}}
.mp-hs__viewport{overflow:hidden}
.mp-hs__track{display:flex;transition:transform .55s cubic-bezier(.4,0,.2,1);will-change:transform}
.mp-hs__slide{flex:0 0 100%;position:relative;min-height:clamp(240px,33vw,380px)}
.mp-hs__link{display:block;position:absolute;inset:0;text-decoration:none;color:#fff}
.mp-hs__img{position:absolute;inset:0;background-size:cover;background-position:center top;transform:scale(1.02)}
.mp-hs__shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(6,10,20,.18) 0%,rgba(6,10,20,.4) 42%,rgba(6,10,20,.94) 100%)}
.mp-hs__body{position:absolute;left:0;right:0;bottom:0;display:flex;flex-direction:column;gap:8px;padding:clamp(18px,3vw,34px);padding-right:clamp(60px,10vw,96px)}
.mp-hs__cat{align-self:flex-start;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:var(--mp-brand,#0057ff);color:#fff;padding:4px 9px;border-radius:5px}
.mp-hs__h{font-size:clamp(19px,2.6vw,30px);font-weight:800;line-height:1.22;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;text-shadow:0 2px 12px rgba(0,0,0,.45)}
.mp-hs__meta{font-size:12px;color:rgba(255,255,255,.8)}
.mp-hs__link:hover .mp-hs__h{text-decoration:underline;text-underline-offset:3px}
.mp-hs__arrow{position:absolute;top:50%;transform:translateY(-50%);width:40px;height:40px;border-radius:50%;border:0;
  background:rgba(6,10,20,.55);color:#fff;font-size:22px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;
  backdrop-filter:blur(4px);transition:background .18s;z-index:3}
.mp-hs__arrow:hover{background:rgba(6,10,20,.8)}
.mp-hs__prev{left:12px}.mp-hs__next{right:12px}
.mp-hs__dots{position:absolute;left:0;right:0;bottom:12px;display:flex;justify-content:center;gap:7px;z-index:3}
.mp-hs__dot{width:8px;height:8px;border-radius:50%;border:0;padding:0;cursor:pointer;background:rgba(255,255,255,.4);transition:all .2s}
.mp-hs__dot.is-active{background:#fff;width:22px;border-radius:5px}
.mp-hs__arrow:focus-visible,.mp-hs__dot:focus-visible{outline:2px solid #fff;outline-offset:2px}
@media(max-width:640px){.mp-hs__arrow{width:34px;height:34px;font-size:19px}.mp-hs__prev{left:8px}.mp-hs__next{right:8px}}
@media(prefers-reduced-motion:reduce){.mp-hs__track{transition:none}}
</style>
<script>
(function(){
  var R=document.getElementById('mpHs'); if(!R) return;
  var track=document.getElementById('mpHsTrack');
  var slides=[].slice.call(R.querySelectorAll('.mp-hs__slide'));
  var dots=[].slice.call(R.querySelectorAll('.mp-hs__dot'));
  var n=slides.length, cur=0, timer=null;
  var RM=window.matchMedia&&window.matchMedia('(prefers-reduced-motion:reduce)').matches;
  function go(i){
    cur=(i%n+n)%n;
    track.style.transform='translateX(-'+(cur*100)+'%)';
    slides.forEach(function(s,k){ s.classList.toggle('is-active',k===cur); if(k===cur){s.removeAttribute('aria-hidden');}else{s.setAttribute('aria-hidden','true');} });
    dots.forEach(function(d,k){ d.classList.toggle('is-active',k===cur); if(k===cur){d.setAttribute('aria-current','true');}else{d.removeAttribute('aria-current');} });
  }
  function start(){ if(RM||n<2) return; stop(); timer=setInterval(function(){ go(cur+1); },6000); }
  function stop(){ if(timer){ clearInterval(timer); timer=null; } }
  R.querySelector('.mp-hs__next').addEventListener('click',function(){ go(cur+1); start(); });
  R.querySelector('.mp-hs__prev').addEventListener('click',function(){ go(cur-1); start(); });
  dots.forEach(function(d,k){ d.addEventListener('click',function(){ go(k); start(); }); });
  R.addEventListener('mouseenter',stop); R.addEventListener('mouseleave',start);
  R.addEventListener('focusin',stop); R.addEventListener('focusout',start);
  document.addEventListener('visibilitychange',function(){ document.hidden?stop():start(); });
  // basic touch swipe
  var x0=null;
  R.addEventListener('touchstart',function(e){ x0=e.touches[0].clientX; stop(); },{passive:true});
  R.addEventListener('touchend',function(e){ if(x0===null) return; var dx=e.changedTouches[0].clientX-x0; if(Math.abs(dx)>40) go(cur+(dx<0?1:-1)); x0=null; start(); },{passive:true});
  start();
}());
</script>
    <?php
    return ob_get_clean();
});

/* ============================================================================
 * [mp_ticker_block] - a two-row strip placed below the header (front page):
 *   row 1  "Latest"   - newest published posts, scrolling
 *   row 2  live quotes - key indices + top NSE movers, badge follows the
 *                        market session (NSE Live / US Live / MCX / Closed),
 *                        auto-refreshes every 25s.
 * ==========================================================================*/

function mp_md_tb_session_label($s) {
    $m = array('india' => 'NSE Live', 'us' => 'US Live', 'commodities' => 'MCX Live', 'closed' => 'Markets Closed');
    return isset($m[$s]) ? $m[$s] : 'Markets';
}

/** US S&P/Dow/Nasdaq (from the 2-min groups snapshot) + Bitcoin (fast snapshot), row-shaped like mp_md_get_indices(). */
function mp_md_tb_us_index_rows() {
    $grp  = get_transient(MP_MD_GRP_KEY);
    $rows = array();
    if (is_array($grp) && !empty($grp['world'])) {
        foreach ($grp['world'] as $r) {
            if (in_array($r['sym'], array('^GSPC', '^DJI', '^IXIC'), true) && isset($r['price'])) {
                $rows[] = array('sym' => $r['sym'], 'price' => $r['price'], 'chgPct' => $r['chgPct'], 'change' => $r['change']);
            }
        }
    }
    $idx = mp_md_get_indices();
    if (isset($idx['indices']['BTC-USD']['price'])) $rows[] = $idx['indices']['BTC-USD'];
    return $rows;
}

/** US mega-cap movers (from the 2-min groups snapshot), row-shaped like mp_md_sorted_stocks(). */
function mp_md_us_stocks($filter = 'trending') {
    $grp  = get_transient(MP_MD_GRP_KEY);
    $rows = (is_array($grp) && !empty($grp['us_stocks'])) ? $grp['us_stocks'] : array();
    $out  = array();
    foreach ($rows as $r) {
        if (!isset($r['price'])) continue;
        $out[] = array('symbol' => $r['sym'], 'name' => $r['label'], 'price' => $r['price'], 'change_pct' => $r['chgPct'], 'change' => $r['change']);
    }
    if ($filter === 'losers') {
        usort($out, function ($a, $b) { return ($a['change_pct'] ?? 99) <=> ($b['change_pct'] ?? 99); });
    } elseif ($filter === 'gainers') {
        usort($out, function ($a, $b) { return ($b['change_pct'] ?? -99) <=> ($a['change_pct'] ?? -99); });
    } else {
        usort($out, function ($a, $b) { return abs($b['change_pct'] ?? 0) <=> abs($a['change_pct'] ?? 0); });
    }
    return array_slice($out, 0, 10);
}

/** Ticker content for the active session: 'us' -> US indices + US movers + crypto; else -> Indian indices/movers + crypto. */
function mp_md_tb_quotes($session = 'india') {
    $out = array();

    if ($session === 'us') {
        $nmW = array('^GSPC' => 'S&P 500', '^DJI' => 'DOW JONES', '^IXIC' => 'NASDAQ');
        foreach (mp_md_tb_us_index_rows() as $r) {
            if (!isset($r['price'])) continue;
            $isBtc = ($r['sym'] === 'BTC-USD');
            $out[] = array(
                't' => $isBtc ? 'BITCOIN' : (isset($nmW[$r['sym']]) ? $nmW[$r['sym']] : $r['sym']),
                'p' => $r['price'], 'c' => $r['chgPct'], 'cur' => $isBtc ? '' : '$',
            );
        }
        foreach (mp_md_us_stocks('trending') as $s) {
            if (!isset($s['price'])) continue;
            $out[] = array('t' => $s['symbol'], 'p' => $s['price'], 'c' => $s['change_pct'], 'cur' => '$');
        }
        return $out;
    }

    $idx  = mp_md_get_indices();
    $rows = (is_array($idx) && !empty($idx['indices'])) ? array_values($idx['indices']) : array();
    $nm = array(
        '^BSESN' => 'SENSEX', '^NSEI' => 'NIFTY 50', '^NSEBANK' => 'BANK NIFTY',
        'INR=X' => 'USD/INR', 'GC=F' => 'GOLD', 'CL=F' => 'CRUDE OIL', 'BTC-USD' => 'BITCOIN',
    );
    $inr = array('INR=X' => 1);
    foreach ($rows as $r) {
        if (!isset($r['price']) || !isset($r['sym'])) continue;
        $cur = isset($inr[$r['sym']]) ? '&#8377;' : (($r['sym'] === 'GC=F' || $r['sym'] === 'CL=F') ? '$' : '');
        $out[] = array('t' => isset($nm[$r['sym']]) ? $nm[$r['sym']] : $r['sym'], 'p' => $r['price'], 'c' => $r['chgPct'], 'cur' => $cur);
    }
    foreach (array_slice(mp_md_sorted_stocks('trending'), 0, 10) as $s) {
        if (!isset($s['price'])) continue;
        $sym = isset($s['symbol']) ? $s['symbol'] : (isset($s['sym']) ? $s['sym'] : '');
        if ($sym === '') continue;
        $out[] = array(
            't'   => $sym, 'p' => $s['price'], 'c' => isset($s['change_pct']) ? $s['change_pct'] : (isset($s['chgPct']) ? $s['chgPct'] : null),
            'cur' => '&#8377;',
            'u'   => function_exists('mp_md_stock_slug') ? home_url('/stocks/' . mp_md_stock_slug($sym) . '/') : '',
        );
    }
    return $out;
}

function mp_md_tb_quote_html($it) {
    $c   = is_numeric(isset($it['c']) ? $it['c'] : null) ? (float) $it['c'] : null;
    $dir = $c === null ? 'flat' : ($c > 0 ? 'up' : ($c < 0 ? 'dn' : 'flat'));
    $arr = $c === null ? '' : ($c > 0 ? '&#9650;' : ($c < 0 ? '&#9660;' : ''));
    $p   = is_numeric(isset($it['p']) ? $it['p'] : null) ? number_format((float) $it['p'], 2) : '--';
    $pct = $c === null ? '' : sprintf('%+.2f%%', $c);
    $in  = '<b>' . esc_html($it['t']) . '</b> ' . (isset($it['cur']) ? $it['cur'] : '') . $p
         . ' <i class="' . $dir . '">' . $arr . ' ' . $pct . '</i>';
    $sep = '<span class="mp-tb__sep">&#9679;</span>';
    return !empty($it['u'])
        ? '<a class="mp-tb__q" href="' . esc_url($it['u']) . '">' . $in . '</a>' . $sep
        : '<span class="mp-tb__q">' . $in . '</span>' . $sep;
}

add_shortcode('mp_ticker_block', function () {
    if (is_admin() || is_feed() || is_embed()) return '';

    $posts = get_posts(array('numberposts' => 14, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'));
    if (!$posts) return '';
    $news = '';
    foreach ($posts as $p) {
        $news .= '<a class="mp-tb__q" href="' . esc_url(get_permalink($p)) . '">' . esc_html(get_the_title($p))
               . '</a><span class="mp-tb__sep">&#9679;</span>';
    }

    $sessions = mp_md_sessions();
    $active   = $sessions[0];
    $closed   = ($active === 'closed');
    $qhtml    = '';
    foreach (mp_md_tb_quotes($active) as $it) $qhtml .= mp_md_tb_quote_html($it);
    if ($qhtml === '') $qhtml = '<span class="mp-tb__q">Live quotes loading&hellip;</span>';

    ob_start(); ?>
<div class="mp-tb" aria-label="Latest news and live markets">
  <div class="mp-tb__row">
    <span class="mp-tb__badge mp-tb__badge--news">Latest</span>
    <div class="mp-tb__vp"><div class="mp-tb__track">
      <span class="mp-tb__half"><?php echo $news; ?></span>
      <span class="mp-tb__half" aria-hidden="true"><?php echo $news; ?></span>
    </div></div>
  </div>
  <div class="mp-tb__row mp-tb__row--q<?php echo $closed ? ' is-closed' : ''; ?>" id="mpTbQ"
       data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/markets?filter=trending')); ?>">
    <span class="mp-tb__badge mp-tb__badge--live"><span class="mp-tb__dot"></span><span id="mpTbLabel"><?php echo esc_html(mp_md_tb_session_label($active)); ?></span></span>
    <div class="mp-tb__vp"><div class="mp-tb__track" id="mpTbTrack">
      <span class="mp-tb__half" id="mpTbHalf"><?php echo $qhtml; ?></span>
      <span class="mp-tb__half" aria-hidden="true"><?php echo $qhtml; ?></span>
    </div></div>
  </div>
</div>
<style id="mp-tb-css">
.mp-tb{background:#0b1220;border-bottom:1px solid rgba(255,255,255,.07);font-size:12.5px;line-height:1}
.mp-tb__row{display:flex;align-items:stretch;height:32px;overflow:hidden;position:relative}
.mp-tb__row + .mp-tb__row{border-top:1px solid rgba(255,255,255,.06)}
.mp-tb__badge{flex:0 0 auto;display:flex;align-items:center;gap:6px;padding:0 12px;font-weight:700;font-size:10.5px;
  letter-spacing:.06em;text-transform:uppercase;color:#fff;white-space:nowrap}
.mp-tb__badge--news{background:#0057ff}
.mp-tb__badge--live{background:#16a34a}
.mp-tb__row--q.is-closed .mp-tb__badge--live{background:#475569}
.mp-tb__dot{width:7px;height:7px;border-radius:50%;background:#fff;animation:mpTbP 1.5s ease-out infinite}
.mp-tb__row--q.is-closed .mp-tb__dot{animation:none;background:#cbd5e1}
.mp-tb__vp{flex:1 1 auto;overflow:hidden;position:relative;
  -webkit-mask-image:linear-gradient(90deg,transparent,#000 20px,#000 calc(100% - 20px),transparent);
          mask-image:linear-gradient(90deg,transparent,#000 20px,#000 calc(100% - 20px),transparent)}
.mp-tb__track{display:inline-flex;align-items:center;height:100%;white-space:nowrap;will-change:transform;
  animation:mpTbS var(--mp-tb-dur,60s) linear infinite}
.mp-tb__vp:hover .mp-tb__track{animation-play-state:paused}
.mp-tb__half{display:inline-flex;align-items:center;padding-left:14px}
.mp-tb__q{display:inline-flex;align-items:center;gap:5px;color:#d7dee7;text-decoration:none;padding:0 5px}
.mp-tb__q:hover{color:#7fb0ff}
.mp-tb__q b{color:#f1f5f9;font-weight:600}
.mp-tb__q i{font-style:normal;color:#9aa7b4}
.mp-tb__q i.up{color:#22c55e}.mp-tb__q i.dn{color:#f87171}
.mp-tb__sep{opacity:.25;padding:0 4px;color:#fff}
@keyframes mpTbS{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@keyframes mpTbP{0%{box-shadow:0 0 0 0 rgba(255,255,255,.6)}70%{box-shadow:0 0 0 6px rgba(255,255,255,0)}100%{box-shadow:0 0 0 0 rgba(255,255,255,0)}}
@media(prefers-reduced-motion:reduce){.mp-tb__track{animation:none}.mp-tb__vp{overflow-x:auto}.mp-tb__half:last-child{display:none}}
@media(max-width:600px){.mp-tb__row{height:30px}.mp-tb__badge{padding:0 8px;font-size:9.5px}}
</style>
<script>
(function(){
  var W=document.querySelector('.mp-tb'); if(!W) return;
  var SPEED=64;
  function dur(){
    W.querySelectorAll('.mp-tb__track').forEach(function(tr){
      var h=tr.querySelector('.mp-tb__half'); if(!h) return;
      var w=h.getBoundingClientRect().width||h.scrollWidth||1000;
      if(w>40) tr.style.setProperty('--mp-tb-dur',Math.max(20,Math.round(w/SPEED))+'s');
    });
  }
  dur(); if(window.requestAnimationFrame) requestAnimationFrame(dur); setTimeout(dur,400);
  window.addEventListener('resize',function(){clearTimeout(W.__rz);W.__rz=setTimeout(dur,200);});

  var Q=document.getElementById('mpTbQ'), TRACK=document.getElementById('mpTbTrack'), LABEL=document.getElementById('mpTbLabel');
  var LAB={india:'NSE Live',us:'US Live',commodities:'MCX Live',closed:'Markets Closed'};
  function sess(){
    var d=new Date();
    function p(tz){var o={};new Intl.DateTimeFormat('en-US',{timeZone:tz,hour12:false,weekday:'short',hour:'2-digit',minute:'2-digit'}).formatToParts(d).forEach(function(x){o[x.type]=x.value;});
      return {wd:{Sun:0,Mon:1,Tue:2,Wed:3,Thu:4,Fri:5,Sat:6}[o.weekday],m:(parseInt(o.hour,10)%24)*60+parseInt(o.minute,10)};}
    var i=p('Asia/Kolkata'),e=p('America/New_York'),s=[];
    if(e.wd>=1&&e.wd<=5&&e.m>=570&&e.m<960)s.push('us');
    if(i.wd>=1&&i.wd<=5&&i.m>=555&&i.m<930)s.push('india');
    if(i.wd>=1&&i.wd<=5&&i.m>=540&&i.m<1410)s.push('commodities');
    return s[0]||'closed';
  }
  function num(n){return Number(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
  var NM={'^BSESN':'SENSEX','^NSEI':'NIFTY 50','^NSEBANK':'BANK NIFTY','INR=X':'USD/INR','GC=F':'GOLD','CL=F':'CRUDE OIL','BTC-USD':'BITCOIN','^GSPC':'S&P 500','^DJI':'DOW JONES','^IXIC':'NASDAQ'};
  var INR={'INR=X':1};
  var USDIDX={'^GSPC':1,'^DJI':1,'^IXIC':1,'GC=F':1,'CL=F':1};
  function slug(s){return String(s).toLowerCase().replace(/&/g,'-and-').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');}
  function qh(items){
    return items.map(function(it){
      var c=(it.c==null||isNaN(it.c))?null:Number(it.c);
      var dir=c==null?'flat':(c>0?'up':(c<0?'dn':'flat'));
      var arr=c==null?'':(c>0?'▲':(c<0?'▼':''));
      var pct=c==null?'':(c>0?'+':'')+c.toFixed(2)+'%';
      var s='<b>'+it.t+'</b> '+(it.cur||'')+num(it.p)+' <i class="'+dir+'">'+arr+' '+pct+'</i>';
      return it.u?'<a class="mp-tb__q" href="'+it.u+'">'+s+'</a><span class="mp-tb__sep">●</span>'
                 :'<span class="mp-tb__q">'+s+'</span><span class="mp-tb__sep">●</span>';
    }).join('');
  }
  function relabel(){ var s=sess(); if(Q) Q.classList.toggle('is-closed',s==='closed'); if(LABEL) LABEL.textContent=LAB[s]||LAB.closed; }
  function refresh(){
    if(!Q||!TRACK) return;
    var s=sess(), isUS=(s==='us');
    fetch(Q.getAttribute('data-endpoint')+'&session='+s,{headers:{Accept:'application/json'},credentials:'omit'})
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){
        if(!d) return;
        var items=[];
        (d.indices||[]).forEach(function(r){ if(r.price==null)return;
          items.push({t:NM[r.sym]||r.sym,p:r.price,c:r.chgPct,cur:INR[r.sym]?'₹':(USDIDX[r.sym]?'$':'')}); });
        (d.stocks||[]).slice(0,10).forEach(function(s){ if(s.price==null)return;
          items.push({t:s.symbol||s.sym,p:s.price,c:(s.change_pct!=null?s.change_pct:s.chgPct),cur:isUS?'$':'₹',u:isUS?null:'/stocks/'+slug(s.symbol||s.sym)+'/'}); });
        if(!items.length) return;
        var h=qh(items);
        TRACK.innerHTML='<span class="mp-tb__half">'+h+'</span><span class="mp-tb__half" aria-hidden="true">'+h+'</span>';
        dur();
      }).catch(function(){});
  }
  relabel(); setInterval(relabel,60000);
  setTimeout(refresh,1200); setInterval(refresh,25000);
  document.addEventListener('visibilitychange',function(){ if(!document.hidden){ dur(); relabel(); refresh(); }});
}());
</script>
    <?php
    return ob_get_clean();
});

/* ============================================================================
 * CITY RATES  (v1.4.0)  -  Gold / Silver / Fuel by city
 *  - Gold & silver: indicative India reference = international price (COMEX x
 *    USD/INR, already in the dashboard cache) adjusted for import duty + GST.
 *    One national reference shown per city (an optional per-city premium map
 *    defaults to 0) -> honest, and avoids thin per-city doorway pages.
 *  - Fuel: a curated, dated table (option `mp_fuel_rates`) - Indian petrol/
 *    diesel prices change rarely and city-by-city, so a maintained table with
 *    an "as of" date is accurate. Editable in wp-admin or via REST.
 *  - City picker + optional "use my location" (client-side reverse geocode).
 * ==========================================================================*/

function mp_rates_cities() {
    return array(
        'Mumbai', 'Delhi', 'Bengaluru', 'Hyderabad', 'Chennai', 'Kolkata', 'Ahmedabad',
        'Pune', 'Jaipur', 'Lucknow', 'Kanpur', 'Nagpur', 'Indore', 'Bhopal', 'Patna',
        'Surat', 'Vadodara', 'Chandigarh', 'Coimbatore', 'Kochi', 'Visakhapatnam',
        'Bhubaneswar', 'Guwahati', 'Ranchi', 'Raipur', 'Dehradun', 'Ludhiana',
        'Madurai', 'Mysuru', 'Varanasi',
    );
}
function mp_rates_norm_city($c) {
    $c = ucwords(strtolower(trim((string) $c)));
    $alias = array('Bangalore' => 'Bengaluru', 'Calcutta' => 'Kolkata', 'Bombay' => 'Mumbai',
                   'Madras' => 'Chennai', 'New Delhi' => 'Delhi', 'Gurugram' => 'Delhi',
                   'Noida' => 'Delhi', 'Navi Mumbai' => 'Mumbai', 'Thane' => 'Mumbai',
                   'Trivandrum' => 'Kochi', 'Vizag' => 'Visakhapatnam', 'Prayagraj' => 'Varanasi');
    if (isset($alias[$c])) $c = $alias[$c];
    return in_array($c, mp_rates_cities(), true) ? $c : 'Mumbai';
}

/* Bridge the COMEX-derived price to an India retail reference (import duty + GST).
   Filterable so it can be tuned without editing the file. */
function mp_rates_gold($city = 'Mumbai') {
    $grp = get_transient(MP_MD_GRP_KEY);
    $b   = is_array($grp) && !empty($grp['bullion_inr']) ? $grp['bullion_inr'] : null;
    if (!$b) return null;

    $mult      = (float) apply_filters('mp_gold_india_multiplier', 1.13); // ~6% duty + 3% GST + small premium
    $premiums  = (array) apply_filters('mp_gold_city_premium', array());  // city => extra INR per 10g
    $prem      = isset($premiums[$city]) ? (float) $premiums[$city] : 0.0;

    $g24_10 = $b['gold_24k_10g'] * $mult + $prem;
    $s_kg   = $b['silver_kg'] ? $b['silver_kg'] * $mult : null;

    $gpct = isset($b['gold_chg_pct'])   ? $b['gold_chg_pct']   : null;
    $spct = isset($b['silver_chg_pct']) ? $b['silver_chg_pct'] : null;

    return array(
        'city'      => $city,
        'gold_24k'  => array('g' => round($g24_10 / 10), 'ten_g' => round($g24_10)),
        'gold_22k'  => array('g' => round($g24_10 * 0.916 / 10), 'ten_g' => round($g24_10 * 0.916)),
        'gold_18k'  => array('g' => round($g24_10 * 0.75 / 10), 'ten_g' => round($g24_10 * 0.75)),
        'silver'    => $s_kg ? array('g' => round($s_kg / 1000, 2), 'kg' => round($s_kg), 'chg_pct' => $spct) : null,
        'chg_pct'   => $gpct,
        'silver_chg_pct' => $spct,
        'asOf'      => isset($grp['asOf']) ? $grp['asOf'] : gmdate('c'),
        'note'      => 'Indicative India reference — international price adjusted for import duty and GST. Local jeweller rates vary by purity, making charges and hallmarking; confirm before buying.',
    );
}

/* Curated fuel table. Seeded once; update in Settings -> Reading -> "Fuel prices JSON"
   or POST /wp-json/mp/v1/fuel (admin). Values in INR/litre. */
function mp_rates_fuel_default() {
    // Approx Sept 2026 pump prices; petrol/diesel have been broadly stable.
    return array(
        'updated' => '2026-09-01',
        'cities'  => array(
            'Mumbai' => array(103.44, 89.97), 'Delhi' => array(94.72, 87.62),
            'Bengaluru' => array(102.86, 88.94), 'Hyderabad' => array(107.41, 95.65),
            'Chennai' => array(100.75, 92.34), 'Kolkata' => array(103.94, 90.76),
            'Ahmedabad' => array(94.49, 90.17), 'Pune' => array(104.09, 90.57),
            'Jaipur' => array(104.72, 90.21), 'Lucknow' => array(94.65, 87.76),
            'Kanpur' => array(94.47, 87.61), 'Nagpur' => array(104.28, 90.75),
            'Indore' => array(106.48, 91.88), 'Bhopal' => array(106.44, 91.82),
            'Patna' => array(105.58, 93.80), 'Surat' => array(94.63, 90.30),
            'Vadodara' => array(94.42, 90.09), 'Chandigarh' => array(94.30, 82.45),
            'Coimbatore' => array(100.90, 92.48), 'Kochi' => array(107.56, 96.43),
            'Visakhapatnam' => array(108.35, 96.53), 'Bhubaneswar' => array(101.06, 92.91),
            'Guwahati' => array(97.14, 89.38), 'Ranchi' => array(97.83, 92.71),
            'Raipur' => array(100.42, 93.09), 'Dehradun' => array(93.35, 88.32),
            'Ludhiana' => array(94.61, 82.75), 'Madurai' => array(100.98, 92.56),
            'Mysuru' => array(102.60, 88.68), 'Varanasi' => array(95.30, 88.42),
        ),
    );
}
function mp_rates_fuel($city = 'Mumbai') {
    $data = get_option('mp_fuel_rates');
    if (!is_array($data) || empty($data['cities'])) $data = mp_rates_fuel_default();
    $c = isset($data['cities'][$city]) ? $data['cities'][$city] : $data['cities']['Mumbai'];
    return array(
        'city'    => $city,
        'petrol'  => isset($c[0]) ? (float) $c[0] : null,
        'diesel'  => isset($c[1]) ? (float) $c[1] : null,
        'updated' => isset($data['updated']) ? $data['updated'] : gmdate('Y-m-d'),
        'all'     => $data['cities'],
        'note'    => 'Pump prices are set daily by oil marketing companies and vary within a city. Figures are indicative; check at the pump.',
    );
}
add_action('init', function () {
    if (get_option('mp_fuel_rates') === false) add_option('mp_fuel_rates', mp_rates_fuel_default(), '', false);
});
add_action('admin_init', function () {
    register_setting('reading', 'mp_fuel_rates_json', array('type' => 'string'));
    add_settings_field('mp_fuel_rates_json', 'Fuel prices JSON', function () {
        $cur = get_option('mp_fuel_rates'); if (!is_array($cur)) $cur = mp_rates_fuel_default();
        echo '<textarea name="mp_fuel_rates_json" rows="6" class="large-text code" placeholder=\'{"updated":"YYYY-MM-DD","cities":{"Mumbai":[103.44,89.97]}}\'></textarea>';
        echo '<p class="description">Paste updated petrol/diesel (INR/litre) as <code>{"updated":"…","cities":{"City":[petrol,diesel]}}</code>. Leave blank to keep the current table. Last updated: <strong>' . esc_html($cur['updated'] ?? '?') . '</strong>.</p>';
    }, 'reading');
});
add_action('update_option_mp_fuel_rates_json', function ($old, $new) {
    $j = json_decode((string) $new, true);
    if (is_array($j) && !empty($j['cities'])) update_option('mp_fuel_rates', $j, '', false);
    delete_option('mp_fuel_rates_json');
}, 10, 2);

/* --------------------------- REST --------------------------- */
add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/rates', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'args' => array('type' => array('default' => 'all'), 'city' => array('default' => 'Mumbai')),
        'callback' => function (WP_REST_Request $req) {
            $city = mp_rates_norm_city($req->get_param('city'));
            $type = $req->get_param('type');
            $body = array('city' => $city, 'cities' => mp_rates_cities());
            if ($type === 'gold' || $type === 'all') $body['gold'] = mp_rates_gold($city);
            if ($type === 'silver' || $type === 'all') $body['silver'] = mp_rates_silver($city);
            if ($type === 'fuel' || $type === 'all') $body['fuel'] = mp_rates_fuel($city);
            $resp = rest_ensure_response($body);
            $resp->header('Cache-Control', 'public, max-age=120, s-maxage=300, stale-while-revalidate=600');
            return $resp;
        },
    ));
    register_rest_route('mp/v1', '/fuel', array(
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $req) {
            $j = $req->get_json_params();
            if (!is_array($j) || empty($j['cities'])) return new WP_Error('bad', 'Need {updated, cities}', array('status' => 400));
            update_option('mp_fuel_rates', $j, '', false);
            return array('ok' => true, 'updated' => $j['updated'] ?? gmdate('Y-m-d'), 'count' => count($j['cities']));
        },
    ));
});

/* --------------------------- Shared front-end assets --------------------------- */
/* Schedule the CSS/JS for the footer - never echo inline (a shortcode may be run
   by SEO plugins outside an output buffer, which would corrupt a REST response). */
function mp_rates_assets() {
    static $hooked = false;
    if ($hooked) return;
    $hooked = true;
    add_action('wp_footer', 'mp_rates_print_assets', 30);
}
function mp_rates_print_assets() {
    ?>
<style id="mp-rates-css">
.mp-rates{margin:20px 0;color:var(--mp-ink,#0f172a)}
.mp-rates__bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
.mp-rates__bar select{padding:9px 12px;border:1px solid var(--mp-border,#cbd5e1);border-radius:8px;background:var(--mp-bg,#f8fafc);color:inherit;font-size:14px}
.mp-rates__loc{padding:9px 12px;border:1px solid var(--mp-border,#cbd5e1);border-radius:8px;background:transparent;color:inherit;font-size:13px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.mp-rates__loc:hover{border-color:var(--mp-brand,#0057ff)}
.mp-rates__asof{font-size:11px;color:var(--mp-muted,#64748b)}
.mp-rates__cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.mp-rate-card{border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:14px 16px;background:var(--mp-surface,#fff)}
.mp-rate-card h4{margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--mp-muted,#64748b)}
.mp-rate-card .v{font-size:22px;font-weight:700;font-variant-numeric:tabular-nums}
.mp-rate-card .u{font-size:12px;color:var(--mp-muted,#64748b)}
.mp-rate-card__chg{display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums}
.mp-rate-card__chg small{font-weight:500;color:var(--mp-muted,#64748b)}
.mp-rate-card__chg.up{color:#16a34a}.mp-rate-card__chg.dn{color:#dc2626}.mp-rate-card__chg.flat{color:var(--mp-muted,#64748b)}
.mp-rates__calc{margin-top:18px;border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:16px;background:var(--mp-surface,#fff)}
.mp-rates__calc h4{margin:0 0 12px}
.mp-rates__row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:12px}
.mp-rates__row label{display:block;font-size:12px;color:var(--mp-muted,#64748b);margin-bottom:4px}
.mp-rates__row input,.mp-rates__row select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--mp-border,#cbd5e1);border-radius:7px;background:var(--mp-bg,#f8fafc);color:inherit}
.mp-rates__seg{display:inline-flex;border:1px solid var(--mp-border,#cbd5e1);border-radius:7px;overflow:hidden}
.mp-rates__seg button{border:0;background:transparent;color:inherit;padding:8px 12px;cursor:pointer;font-size:13px}
.mp-rates__seg button.on{background:var(--mp-brand,#0057ff);color:#fff}
.mp-rates__out{display:grid;grid-template-columns:1fr auto;gap:6px 14px;font-size:14px;border-top:1px solid var(--mp-border,#eef1f4);padding-top:10px;margin-top:6px}
.mp-rates__out .tot{font-size:18px;font-weight:800;color:#16a34a}
.mp-rates__disc{font-size:11px;color:var(--mp-muted,#64748b);margin-top:12px}
.mp-rates__tbl{width:100%;border-collapse:collapse;margin-top:14px;font-size:13px}
.mp-rates__tbl th,.mp-rates__tbl td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--mp-border,#eef1f4)}
.mp-rates__tbl td:not(:first-child){text-align:right;font-variant-numeric:tabular-nums}
html[data-theme="dark"] .mp-rate-card,html[data-theme="dark"] .mp-rates__calc{background:#111827;border-color:rgba(255,255,255,.08);color:#f1f5f9}
html[data-theme="dark"] .mp-rates__row input,html[data-theme="dark"] .mp-rates__row select,html[data-theme="dark"] .mp-rates__bar select{background:#0a0f1e;border-color:rgba(255,255,255,.12);color:#f1f5f9}
</style>
    <?php
}

/* "▲ +0.42% today" badge for a rate card, from a day-change percent. */
function mp_rates_chg_html($pct, $when = 'today') {
    if ($pct === null || $pct === '') return '';
    $p = (float) $pct;
    $cls = $p > 0.02 ? 'up' : ($p < -0.02 ? 'dn' : 'flat');
    $arrow = $p > 0.02 ? '&#9650;' : ($p < -0.02 ? '&#9660;' : '&#8226;');
    $sign = $p > 0 ? '+' : '';
    return '<div class="mp-rate-card__chg ' . $cls . '">' . $arrow . ' ' . $sign . number_format($p, 2) . '% <small>' . esc_html($when) . '</small></div>';
}

/* Geo/city helpers - emitted inline by each rate shortcode (before its IIFE). */
function mp_rates_helpers_html() {
    static $done = false; if ($done) return ''; $done = true;
    return '<script>window.mpRatesCity=window.mpRatesCity||function(){try{return localStorage.getItem("mp_city")||"";}catch(e){return "";}};window.mpRatesSetCity=window.mpRatesSetCity||function(c){try{localStorage.setItem("mp_city",c);}catch(e){}};window.mpRatesAutoCity=window.mpRatesAutoCity||function(cb){try{if(localStorage.getItem("mp_city")){cb(null);return;}}catch(e){}fetch("https://ipapi.co/json/").then(function(r){return r.json();}).then(function(d){cb(d&&d.city?d.city:null);}).catch(function(){cb(null);});};window.mpRatesGeo=window.mpRatesGeo||function(cb){if(!navigator.geolocation){cb(null);return;}navigator.geolocation.getCurrentPosition(function(p){fetch("https://api.bigdatacloud.net/data/reverse-geocode-client?latitude="+p.coords.latitude+"&longitude="+p.coords.longitude+"&localityLanguage=en").then(function(r){return r.json();}).then(function(d){cb(d.city||d.locality||d.principalSubdivision||null);}).catch(function(){cb(null);});},function(){cb(null);},{timeout:8000,maximumAge:600000});};</script>';
}

/* --------------------------- [mp_gold_rates] --------------------------- */
add_shortcode('mp_gold_rates', function ($atts) {
    mp_rates_assets();
    $city = mp_rates_norm_city($atts['city'] ?? (isset($_GET['city']) ? $_GET['city'] : 'Mumbai'));
    $g = mp_rates_gold($city);
    $opts = '';
    foreach (mp_rates_cities() as $c) $opts .= '<option value="' . esc_attr($c) . '"' . selected($c, $city, false) . '>' . esc_html($c) . '</option>';
    ob_start(); ?>
<div class="mp-rates" id="mpGoldRates" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/rates?type=gold')); ?>">
  <div class="mp-rates__bar">
    <select class="mp-rates__city" aria-label="Select city"><?php echo $opts; ?></select>
    <button type="button" class="mp-rates__loc" data-role="geo">📍 Use my location</button>
    <span class="mp-rates__asof">Updated <span data-role="asof"><?php echo $g ? esc_html(date('j M Y', strtotime($g['asOf']))) : '—'; ?></span></span>
  </div>
  <div class="mp-rates__cards" data-role="cards">
    <?php if ($g) : foreach (array('24k'=>'24K (999)','22k'=>'22K (916)','18k'=>'18K (750)') as $k=>$lbl): $row=$g['gold_'.$k]; ?>
      <div class="mp-rate-card"><h4><?php echo esc_html($lbl); ?> Gold</h4>
        <div class="v">₹<?php echo number_format($row['g']); ?><span class="u">/g</span></div>
        <div class="u">₹<?php echo number_format($row['ten_g']); ?> / 10g</div>
        <?php echo mp_rates_chg_html(isset($g['chg_pct']) ? $g['chg_pct'] : null); ?></div>
    <?php endforeach; if (!empty($g['silver'])): ?>
      <div class="mp-rate-card"><h4>Silver (999)</h4>
        <div class="v">₹<?php echo number_format($g['silver']['g'], 2); ?><span class="u">/g</span></div>
        <div class="u">₹<?php echo number_format($g['silver']['kg']); ?> / kg</div>
        <?php echo mp_rates_chg_html(isset($g['silver']['chg_pct']) ? $g['silver']['chg_pct'] : null); ?></div>
    <?php endif; else: ?><div class="mp-rate-card">Rates loading…</div><?php endif; ?>
  </div>

  <div class="mp-rates__calc" data-role="calc">
    <h4>Gold price calculator</h4>
    <div class="mp-rates__row">
      <div><label>Purity</label>
        <span class="mp-rates__seg" data-role="purity">
          <button type="button" data-p="24k" class="on">24K</button>
          <button type="button" data-p="22k">22K</button>
          <button type="button" data-p="18k">18K</button>
        </span></div>
      <div><label>Weight (grams)</label><input type="number" data-role="wt" value="10" min="0" step="0.1"></div>
      <div><label>Making charges (%)</label><input type="number" data-role="mk" value="12" min="0" step="1"></div>
      <div><label>GST</label><select data-role="gst"><option value="1">Incl. 3% GST</option><option value="0">Exclude GST</option></select></div>
    </div>
    <div class="mp-rates__out">
      <span>Base value</span><span data-role="o-base">₹0</span>
      <span>Making charges</span><span data-role="o-make">₹0</span>
      <span>GST (3%)</span><span data-role="o-gst">₹0</span>
      <span class="tot">Total</span><span class="tot" data-role="o-tot">₹0</span>
    </div>
    <div class="mp-rates__row" style="margin-top:14px">
      <div><label>Know your money's worth — enter ₹ amount</label>
        <input type="number" data-role="amt" placeholder="10000" min="0" step="100"></div>
      <div><label>You can buy</label><input type="text" data-role="amt-out" readonly value="—"></div>
    </div>
  </div>

  <p class="mp-rates__disc" data-role="note"><?php echo $g ? esc_html($g['note']) : ''; ?> Not investment advice.</p>
</div>
<script>
(function(){
  var W = document.getElementById('mpGoldRates'); if(!W) return;
  var G = null;
  var sel = W.querySelector('.mp-rates__city');
  function fmt(n){ return '₹' + Math.round(n).toLocaleString('en-IN'); }
  function chgHtml(p){
    if(p===null||p===undefined||p==='') return '';
    p=Number(p); var c=p>0.02?'up':(p<-0.02?'dn':'flat'), a=p>0.02?'▲':(p<-0.02?'▼':'•');
    return '<div class="mp-rate-card__chg '+c+'">'+a+' '+(p>0?'+':'')+p.toFixed(2)+'% <small>today</small></div>';
  }
  function perG(){
    var p = W.querySelector('[data-role=purity] .on').getAttribute('data-p');
    if (G) return G['gold_'+p].g;
    var card = W.querySelectorAll('[data-role=cards] .mp-rate-card')[p==='24k'?0:(p==='22k'?1:2)];
    if (card){ var m = card.querySelector('.v'); if(m) return parseFloat(m.textContent.replace(/[^0-9.]/g,''))||0; }
    return 0;
  }
  function calc(){
    var wt = parseFloat(W.querySelector('[data-role=wt]').value)||0;
    var mk = parseFloat(W.querySelector('[data-role=mk]').value)||0;
    var incl = W.querySelector('[data-role=gst]').value === '1';
    var base = perG()*wt, make = base*mk/100, sub = base+make, gst = incl ? sub*0.03 : 0;
    W.querySelector('[data-role=o-base]').textContent = fmt(base);
    W.querySelector('[data-role=o-make]').textContent = fmt(make);
    W.querySelector('[data-role=o-gst]').textContent = fmt(gst);
    W.querySelector('[data-role=o-tot]').textContent = fmt(sub+gst);
    var amt = parseFloat(W.querySelector('[data-role=amt]').value)||0;
    var eff = perG()*(1+mk/100)*(incl?1.03:1);
    W.querySelector('[data-role=amt-out]').value = (amt && eff) ? (amt/eff).toFixed(2)+' g' : '—';
  }
  function setCityLabel(c){
    var s = W.querySelector('.mp-rates__cityname'); if(s) s.textContent = c;
    var h1 = document.querySelector('.mp-primary h1, .entry-content h1, article h1, main h1') || document.querySelector('h1');
    if (h1) h1.textContent = 'Gold Rate Today in ' + c;
    try { document.title = 'Gold Rate Today in ' + c + ' | MoneyPuran'; } catch(e){}
  }
  function paint(d){
    G = d.gold; if(!G) return;
    setCityLabel(d.city);
    W.querySelector('[data-role=asof]').textContent = new Date(G.asOf).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'});
    var C = W.querySelector('[data-role=cards]'), h = '';
    [['24k','24K (999)'],['22k','22K (916)'],['18k','18K (750)']].forEach(function(x){
      var r = G['gold_'+x[0]];
      h += '<div class="mp-rate-card"><h4>'+x[1]+' Gold</h4><div class="v">₹'+r.g.toLocaleString('en-IN')+'<span class="u">/g</span></div><div class="u">₹'+r.ten_g.toLocaleString('en-IN')+' / 10g</div>'+chgHtml(G.chg_pct)+'</div>';
    });
    if(G.silver) h += '<div class="mp-rate-card"><h4>Silver (999)</h4><div class="v">₹'+G.silver.g.toLocaleString('en-IN')+'<span class="u">/g</span></div><div class="u">₹'+G.silver.kg.toLocaleString('en-IN')+' / kg</div>'+chgHtml(G.silver.chg_pct)+'</div>';
    C.innerHTML = h;
    var nt = W.querySelector('[data-role=note]'); if(nt) nt.textContent = G.note + ' Not investment advice.';
    calc();
  }
  function load(city){
    fetch(W.getAttribute('data-endpoint')+'&city='+encodeURIComponent(city), {credentials:'omit'})
      .then(function(r){return r.ok?r.json():null;}).then(function(d){ if(d&&d.gold) paint(d); }).catch(function(){});
  }
  W.querySelector('[data-role=purity]').addEventListener('click', function(e){
    var b = e.target.closest('button'); if(!b) return;
    W.querySelectorAll('[data-role=purity] button').forEach(function(x){x.classList.remove('on');});
    b.classList.add('on'); calc();
  });
  ['wt','mk','gst','amt'].forEach(function(k){ W.querySelector('[data-role='+k+']').addEventListener('input', calc); });
  function matchCity(c){
    if(!c) return null;
    var lc = String(c).toLowerCase(), hit = null;
    [].forEach.call(sel.options,function(o){ if(o.value.toLowerCase()===lc) hit=o.value; });
    if(!hit) [].forEach.call(sel.options,function(o){ var ol=o.value.toLowerCase(); if(lc.indexOf(ol)>-1||ol.indexOf(lc)>-1) hit=o.value; });
    return hit;
  }
  function pick(city){ if(!city) return; sel.value=city; setCityLabel(city); window.mpRatesSetCity(city); load(city); }
  sel.addEventListener('change', function(){ setCityLabel(sel.value); window.mpRatesSetCity(sel.value); load(sel.value); });
  W.querySelector('[data-role=geo]').addEventListener('click', function(){
    var btn = this; btn.textContent = '📍 Locating…';
    window.mpRatesGeo(function(c){ btn.textContent = '📍 Use my location'; pick(matchCity(c)); });
  });
  var saved = window.mpRatesCity(), ok=false;
  if(saved){ [].forEach.call(sel.options,function(o){if(o.value===saved)ok=true;}); if(ok) sel.value=saved; }
  setCityLabel(sel.value);
  load(sel.value);
  calc();
  if(!ok){ window.mpRatesAutoCity(function(c){ var m=matchCity(c); if(m && m!==sel.value) pick(m); }); }
}());
</script>
    <?php
    return mp_rates_helpers_html() . ob_get_clean();
});

/* --------------------------- [mp_fuel_prices] --------------------------- */
add_shortcode('mp_fuel_prices', function ($atts) {
    mp_rates_assets();
    $city = mp_rates_norm_city($atts['city'] ?? (isset($_GET['city']) ? $_GET['city'] : 'Mumbai'));
    $f = mp_rates_fuel($city);
    $opts = '';
    foreach (mp_rates_cities() as $c) $opts .= '<option value="' . esc_attr($c) . '"' . selected($c, $city, false) . '>' . esc_html($c) . '</option>';
    ob_start(); ?>
<div class="mp-rates" id="mpFuelPrices" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/rates?type=fuel')); ?>">
  <div class="mp-rates__bar">
    <select class="mp-rates__city" aria-label="Select city"><?php echo $opts; ?></select>
    <button type="button" class="mp-rates__loc" data-role="geo">📍 Use my location</button>
    <span class="mp-rates__asof">As of <span data-role="asof"><?php echo esc_html(date('j M Y', strtotime($f['updated']))); ?></span></span>
  </div>
  <div class="mp-rates__cards" data-role="cards">
    <div class="mp-rate-card"><h4>Petrol</h4><div class="v" data-role="petrol">₹<?php echo number_format($f['petrol'], 2); ?></div><div class="u">per litre</div></div>
    <div class="mp-rate-card"><h4>Diesel</h4><div class="v" data-role="diesel">₹<?php echo number_format($f['diesel'], 2); ?></div><div class="u">per litre</div></div>
  </div>
  <table class="mp-rates__tbl">
    <thead><tr><th>City</th><th>Petrol (₹/L)</th><th>Diesel (₹/L)</th></tr></thead>
    <tbody data-role="tbl">
      <?php foreach ($f['all'] as $c => $v) : ?>
        <tr><td><?php echo esc_html($c); ?></td><td><?php echo number_format($v[0], 2); ?></td><td><?php echo number_format($v[1], 2); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="mp-rates__disc"><?php echo esc_html($f['note']); ?></p>
</div>
<script>
(function(){
  var W = document.getElementById('mpFuelPrices'); if(!W) return;
  var sel = W.querySelector('.mp-rates__city');
  function setCityLabel(c){
    var s = W.querySelector('.mp-rates__cityname'); if(s) s.textContent = c;
    var h1 = document.querySelector('.mp-primary h1, .entry-content h1, article h1, main h1') || document.querySelector('h1');
    if (h1) h1.textContent = 'Petrol & Diesel Price Today in ' + c;
    try { document.title = 'Petrol & Diesel Price Today in ' + c + ' | MoneyPuran'; } catch(e){}
  }
  function matchCity(c){
    if(!c) return null;
    var lc=String(c).toLowerCase(), hit=null;
    [].forEach.call(sel.options,function(o){ if(o.value.toLowerCase()===lc) hit=o.value; });
    if(!hit) [].forEach.call(sel.options,function(o){ var ol=o.value.toLowerCase(); if(lc.indexOf(ol)>-1||ol.indexOf(lc)>-1) hit=o.value; });
    return hit;
  }
  function pick(city){ if(!city) return; sel.value=city; setCityLabel(city); window.mpRatesSetCity(city); load(city); }
  function load(city){
    fetch(W.getAttribute('data-endpoint')+'&city='+encodeURIComponent(city), {credentials:'omit'})
      .then(function(r){return r.ok?r.json():null;}).then(function(d){
        if(!d||!d.fuel) return;
        setCityLabel(d.city);
        W.querySelector('[data-role=petrol]').textContent = '₹'+Number(d.fuel.petrol).toFixed(2);
        W.querySelector('[data-role=diesel]').textContent = '₹'+Number(d.fuel.diesel).toFixed(2);
        W.querySelector('[data-role=asof]').textContent = new Date(d.fuel.updated).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'});
      }).catch(function(){});
  }
  sel.addEventListener('change', function(){ setCityLabel(sel.value); window.mpRatesSetCity(sel.value); load(sel.value); });
  W.querySelector('[data-role=geo]').addEventListener('click', function(){
    var btn=this; btn.textContent='📍 Locating…';
    window.mpRatesGeo(function(c){ btn.textContent='📍 Use my location'; pick(matchCity(c)); });
  });
  var saved = window.mpRatesCity(), ok=false;
  if(saved){ [].forEach.call(sel.options,function(o){if(o.value===saved)ok=true;}); if(ok) sel.value=saved; }
  setCityLabel(sel.value);
  if(ok && saved!==W.querySelector('.mp-rates__cityname').textContent) load(saved);
  if(!ok){ window.mpRatesAutoCity(function(c){ var m=matchCity(c); if(m && m!==sel.value) pick(m); }); }
}());
</script>
    <?php
    return mp_rates_helpers_html() . ob_get_clean();
});


/* --------------------------- Silver rate (v1.22.0) --------------------------- */
function mp_rates_silver($city = 'Mumbai') {
    $grp = get_transient(MP_MD_GRP_KEY);
    $b   = is_array($grp) && !empty($grp['bullion_inr']) ? $grp['bullion_inr'] : null;
    if (!$b || empty($b['silver_kg'])) return null;

    $mult = (float) apply_filters('mp_silver_india_multiplier', (float) apply_filters('mp_gold_india_multiplier', 1.13));
    $premiums = (array) apply_filters('mp_silver_city_premium', array());
    $prem = isset($premiums[$city]) ? (float) $premiums[$city] : 0.0;
    $kg = $b['silver_kg'] * $mult + $prem;

    return array(
        'city'     => $city,
        'per_g'    => round($kg / 1000, 2),
        'per_10g'  => round($kg / 100, 1),
        'per_100g' => round($kg / 10),
        'per_kg'   => round($kg),
        'chg_pct'  => isset($b['silver_chg_pct']) ? $b['silver_chg_pct'] : null,
        'asOf'     => isset($grp['asOf']) ? $grp['asOf'] : gmdate('c'),
        'note'     => 'Indicative India reference for 999 (fine) silver — the international price adjusted for import duty and GST. Local dealer and jeweller rates vary by purity, coin/bar premium and making charges; confirm before buying.',
    );
}

/* --------------------------- [mp_silver_rate] --------------------------- */
add_shortcode('mp_silver_rate', function ($atts) {
    mp_rates_assets();
    $city = mp_rates_norm_city($atts['city'] ?? (isset($_GET['city']) ? $_GET['city'] : 'Mumbai'));
    $s = mp_rates_silver($city);
    $opts = '';
    foreach (mp_rates_cities() as $c) $opts .= '<option value="' . esc_attr($c) . '"' . selected($c, $city, false) . '>' . esc_html($c) . '</option>';
    ob_start(); ?>
<div class="mp-rates" id="mpSilverRate" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/rates?type=silver')); ?>">
  <div class="mp-rates__bar">
    <select class="mp-rates__city" aria-label="Select city"><?php echo $opts; ?></select>
    <button type="button" class="mp-rates__loc" data-role="geo">📍 Use my location</button>
    <span class="mp-rates__asof">Updated <span data-role="asof"><?php echo $s ? esc_html(date('j M Y', strtotime($s['asOf']))) : '—'; ?></span></span>
  </div>
  <div class="mp-rates__cards" data-role="cards">
    <?php if ($s) :
      $cards = array(
        array('1 gram', $s['per_g'], '', 2),
        array('10 grams', $s['per_10g'], '', 1),
        array('100 grams', $s['per_100g'], '', 0),
        array('1 kilogram', $s['per_kg'], '', 0),
      );
      foreach ($cards as $c) : ?>
      <div class="mp-rate-card"><h4>999 Silver &middot; <?php echo esc_html($c[0]); ?></h4>
        <div class="v">₹<?php echo number_format($c[1], $c[3]); ?></div>
        <div class="u"><?php echo esc_html($c[0]); ?></div>
        <?php echo mp_rates_chg_html(isset($s['chg_pct']) ? $s['chg_pct'] : null); ?></div>
    <?php endforeach; else : ?><div class="mp-rate-card">Rates loading…</div><?php endif; ?>
  </div>

  <div class="mp-rates__calc" data-role="calc">
    <h4>Silver price calculator</h4>
    <div class="mp-rates__row">
      <div><label>Weight</label>
        <span class="mp-rates__seg" data-role="unit">
          <button type="button" data-u="1" class="on">grams</button>
          <button type="button" data-u="1000">kg</button>
        </span></div>
      <div><label>Amount</label><input type="number" data-role="wt" value="100" min="0" step="1"></div>
      <div><label>Making / premium (%)</label><input type="number" data-role="mk" value="5" min="0" step="1"></div>
      <div><label>GST</label><select data-role="gst"><option value="1">Incl. 3% GST</option><option value="0">Exclude GST</option></select></div>
    </div>
    <div class="mp-rates__out">
      <span>Silver value</span><span data-role="o-base">₹0</span>
      <span>Making / premium</span><span data-role="o-make">₹0</span>
      <span>GST (3%)</span><span data-role="o-gst">₹0</span>
      <span class="tot">Total</span><span class="tot" data-role="o-tot">₹0</span>
    </div>
    <div class="mp-rates__row" style="margin-top:14px">
      <div><label>Know your money's worth — enter ₹ amount</label>
        <input type="number" data-role="amt" placeholder="10000" min="0" step="100"></div>
      <div><label>You can buy</label><input type="text" data-role="amt-out" readonly value="—"></div>
    </div>
  </div>

  <p class="mp-rates__disc" data-role="note"><?php echo $s ? esc_html($s['note']) : ''; ?> Not investment advice.</p>
</div>
<script>
(function(){
  var W = document.getElementById('mpSilverRate'); if(!W) return;
  var S = null, sel = W.querySelector('.mp-rates__city');
  function fmt(n){ return '₹' + Math.round(n).toLocaleString('en-IN'); }
  function chgHtml(p){
    if(p===null||p===undefined||p==='') return '';
    p=Number(p); var c=p>0.02?'up':(p<-0.02?'dn':'flat'), a=p>0.02?'▲':(p<-0.02?'▼':'•');
    return '<div class="mp-rate-card__chg '+c+'">'+a+' '+(p>0?'+':'')+p.toFixed(2)+'% <small>today</small></div>';
  }
  function perG(){
    if(S) return S.per_g;
    var card = W.querySelectorAll('[data-role=cards] .mp-rate-card')[0];
    if(card){ var m=card.querySelector('.v'); if(m) return parseFloat(m.textContent.replace(/[^0-9.]/g,''))||0; }
    return 0;
  }
  function calc(){
    var u = parseFloat(W.querySelector('[data-role=unit] .on').getAttribute('data-u'))||1;
    var wt = (parseFloat(W.querySelector('[data-role=wt]').value)||0) * u;
    var mk = parseFloat(W.querySelector('[data-role=mk]').value)||0;
    var incl = W.querySelector('[data-role=gst]').value === '1';
    var base = perG()*wt, make = base*mk/100, sub = base+make, gst = incl ? sub*0.03 : 0;
    W.querySelector('[data-role=o-base]').textContent = fmt(base);
    W.querySelector('[data-role=o-make]').textContent = fmt(make);
    W.querySelector('[data-role=o-gst]').textContent = fmt(gst);
    W.querySelector('[data-role=o-tot]').textContent = fmt(sub+gst);
    var amt = parseFloat(W.querySelector('[data-role=amt]').value)||0;
    var eff = perG()*(1+mk/100)*(incl?1.03:1);
    W.querySelector('[data-role=amt-out]').value = (amt && eff) ? (amt/eff).toFixed(1)+' g' : '—';
  }
  function setCityLabel(c){
    var h1 = document.querySelector('.mp-primary h1, .entry-content h1, article h1, main h1') || document.querySelector('h1');
    if (h1) h1.textContent = 'Silver Rate Today in ' + c;
    try { document.title = 'Silver Rate Today in ' + c + ' | MoneyPuran'; } catch(e){}
  }
  function paint(d){
    S = d.silver; if(!S) return;
    setCityLabel(d.city);
    W.querySelector('[data-role=asof]').textContent = new Date(S.asOf).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'});
    var rows = [['1 gram',S.per_g,2],['10 grams',S.per_10g,1],['100 grams',S.per_100g,0],['1 kilogram',S.per_kg,0]];
    var h = rows.map(function(r){
      return '<div class="mp-rate-card"><h4>999 Silver · '+r[0]+'</h4><div class="v">₹'+Number(r[1]).toLocaleString('en-IN',{maximumFractionDigits:r[2]})+'</div><div class="u">'+r[0]+'</div>'+chgHtml(S.chg_pct)+'</div>';
    }).join('');
    W.querySelector('[data-role=cards]').innerHTML = h;
    var nt = W.querySelector('[data-role=note]'); if(nt) nt.textContent = S.note + ' Not investment advice.';
    calc();
  }
  function load(city){
    fetch(W.getAttribute('data-endpoint')+'&city='+encodeURIComponent(city), {credentials:'omit'})
      .then(function(r){return r.ok?r.json():null;}).then(function(d){ if(d&&d.silver) paint(d); }).catch(function(){});
  }
  W.querySelector('[data-role=unit]').addEventListener('click', function(e){
    var b=e.target.closest('button'); if(!b) return;
    W.querySelectorAll('[data-role=unit] button').forEach(function(x){x.classList.remove('on');});
    b.classList.add('on'); calc();
  });
  ['wt','mk','gst','amt'].forEach(function(k){ W.querySelector('[data-role='+k+']').addEventListener('input', calc); });
  function matchCity(c){
    if(!c) return null;
    var lc=String(c).toLowerCase(), hit=null;
    [].forEach.call(sel.options,function(o){ if(o.value.toLowerCase()===lc) hit=o.value; });
    if(!hit) [].forEach.call(sel.options,function(o){ var ol=o.value.toLowerCase(); if(lc.indexOf(ol)>-1||ol.indexOf(lc)>-1) hit=o.value; });
    return hit;
  }
  function pick(city){ if(!city) return; sel.value=city; setCityLabel(city); window.mpRatesSetCity(city); load(city); }
  sel.addEventListener('change', function(){ setCityLabel(sel.value); window.mpRatesSetCity(sel.value); load(sel.value); });
  W.querySelector('[data-role=geo]').addEventListener('click', function(){
    var btn=this; btn.textContent='📍 Locating…';
    window.mpRatesGeo(function(c){ btn.textContent='📍 Use my location'; pick(matchCity(c)); });
  });
  var saved = window.mpRatesCity(), ok=false;
  if(saved){ [].forEach.call(sel.options,function(o){if(o.value===saved)ok=true;}); if(ok) sel.value=saved; }
  setCityLabel(sel.value);
  load(sel.value);
  calc();
  if(!ok){ window.mpRatesAutoCity(function(c){ var m=matchCity(c); if(m && m!==sel.value) pick(m); }); }
}());
</script>
    <?php
    return mp_rates_helpers_html() . ob_get_clean();
});

/* --------------------------- [mp_rates_faq metal="gold|silver"] --------------------------- */
add_shortcode('mp_rates_faq', function ($atts) {
    $metal = (isset($atts['metal']) && strtolower($atts['metal']) === 'silver') ? 'silver' : 'gold';
    $today = wp_date('j M Y');

    if ($metal === 'gold') {
        $g = mp_rates_gold('Mumbai');
        $live = $g
            ? 'As of ' . $today . ', the indicative 24K (999) gold rate in India is about ₹' . number_format($g['gold_24k']['g']) . ' per gram (₹' . number_format($g['gold_24k']['ten_g']) . ' per 10 grams) and 22K (916) about ₹' . number_format($g['gold_22k']['g']) . ' per gram. It updates through the day with the international price and the rupee.'
            : 'The gold rate updates through the day with the international price and the rupee; see the live figures above.';
        $faq = array(
            array('What is the gold rate today in India?', $live),
            array('What is the 22 carat gold rate today?', ($g ? 'The 22K (916) gold rate today is about ₹' . number_format($g['gold_22k']['g']) . ' per gram, i.e. ₹' . number_format($g['gold_22k']['ten_g']) . ' per 10 grams (indicative, before making charges). ' : '') . '22 carat is 91.6% pure gold and is the standard for Indian jewellery because pure 24K gold is too soft to hold a setting.'),
            array('What is the difference between 24K, 22K and 18K gold?', '24K (999) is 99.9% pure gold, used for coins and bars. 22K (916) is 91.6% pure, the usual jewellery grade. 18K (750) is 75% pure — harder, more durable and lower cost, common in diamond and stone-set pieces. The rate per gram falls in that order.'),
            array('Why does the gold rate change every day?', 'The India rate is the international ("spot") gold price converted to rupees, plus import duty and 3% GST. Spot gold trades around the clock and moves with the US dollar, interest-rate expectations, central-bank buying and safe-haven demand; the USD/INR rate moves too. So the rupee rate is rarely the same two days running.'),
            array('Is GST included in the gold rate shown here?', 'The per-gram figures here are an indicative metal reference that already reflects import duty and 3% GST on the metal. Your final jeweller bill adds making charges (typically 8–25% of the metal value) and, on that, 5% GST on making — so it is higher than the quoted rate.'),
            array('Does the gold rate differ by city?', 'The underlying metal price is national. Small differences you see between Mumbai, Delhi, Chennai, Bengaluru, Hyderabad, Kolkata and other cities come from local jewellers\' association rates, octroi/local levies and transport. MoneyPuran shows one indicative India reference; pick your city above to frame it, and always confirm the exact rate with a local hallmarked jeweller.'),
            array('Is it a good time to buy gold?', 'MoneyPuran does not give buy or sell calls. If you are buying for a wedding or a festival, buying in smaller amounts over time (a "gold SIP") averages out the day-to-day swings. For investment exposure, sovereign gold bonds and gold ETFs avoid making charges and storage risk.'),
        );
        $head = 'Gold rate today — FAQ';
    } else {
        $s = mp_rates_silver('Mumbai');
        $live = $s
            ? 'As of ' . $today . ', the indicative 999 (fine) silver rate in India is about ₹' . number_format($s['per_g'], 2) . ' per gram, ₹' . number_format($s['per_100g']) . ' per 100 grams and ₹' . number_format($s['per_kg']) . ' per kilogram. It updates through the day with the international price and the rupee.'
            : 'The silver rate updates through the day with the international price and the rupee; see the live figures above.';
        $faq = array(
            array('What is the silver rate today in India?', $live),
            array('What is the price of 1 kg silver today?', ($s ? 'About ₹' . number_format($s['per_kg']) . ' for 1 kilogram of 999 silver (indicative reference, before dealer premium or making charges). ' : '') . 'Silver is usually bought by the 100-gram or 1-kilogram bar, or as coins that carry a small premium over the metal value.'),
            array('What is 999 silver?', '"999" (or "fine" silver) is 99.9% pure silver — the grade quoted for bars, coins and investment silver. Sterling silver used in tableware and some jewellery is 925 (92.5% silver), so it is worth proportionally less per gram.'),
            array('Why does the silver rate change every day?', 'The India rate is the international silver price converted to rupees, plus import duty and GST. Silver is both a precious metal and an industrial metal (solar panels, electronics, EVs), so it reacts to investment demand and to the manufacturing cycle — which makes it more volatile day to day than gold.'),
            array('Is GST included in the silver rate here?', 'The per-gram and per-kg figures are an indicative metal reference that already reflects import duty and 3% GST on the metal. A dealer or jeweller then adds a premium or making charge on coins, bars and jewellery.'),
            array('Silver or gold — which moves more?', 'Silver. The gold-to-silver ratio (how many grams of silver equal one gram of gold) typically swings between about 70 and 100. Silver tends to fall harder than gold in a sell-off and rise faster in a rally, so it is the more volatile of the two.'),
        );
        $head = 'Silver rate today — FAQ';
    }

    ob_start(); ?>
<div class="mp-rates-faq">
  <h2><?php echo esc_html($head); ?></h2>
  <?php foreach ($faq as $i => $f) : ?>
  <details<?php echo $i === 0 ? ' open' : ''; ?>><summary><?php echo esc_html($f[0]); ?></summary><div><?php echo wp_kses_post(wpautop($f[1])); ?></div></details>
  <?php endforeach; ?>
</div>
<script type="application/ld+json"><?php echo wp_json_encode(array(
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(function ($f) {
        return array('@type' => 'Question', 'name' => $f[0],
            'acceptedAnswer' => array('@type' => 'Answer', 'text' => $f[1]));
    }, $faq),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<style>
.mp-rates-faq{margin:26px 0}
.mp-rates-faq h2{font-size:20px;margin:0 0 10px}
.mp-rates-faq details{border-top:1px solid var(--mp-border,#e2e8f0);padding:10px 0}
.mp-rates-faq summary{font-weight:600;font-size:15px;cursor:pointer;list-style:none}
.mp-rates-faq summary::-webkit-details-marker{display:none}
.mp-rates-faq summary::before{content:"+ ";color:var(--mp-brand,#0057ff);font-weight:700}
.mp-rates-faq details[open] summary::before{content:"– "}
.mp-rates-faq details>div{font-size:14px;line-height:1.6;color:var(--mp-ink2,#475569);margin-top:6px}
.mp-rates-faq details>div p{margin:0 0 8px}
</style>
    <?php
    return ob_get_clean();
});

/* SEO titles / descriptions for the rate landing pages (Rank Math). */
add_filter('rank_math/frontend/title', function ($title) {
    if (is_page('gold-rates'))        return 'Gold Rate Today in India — 24K & 22K Gold Price, Live by City';
    if (is_page('silver-rate-today')) return 'Silver Rate Today in India — 999 Silver Price per Gram & per Kg';
    return $title;
}, 20);
add_filter('rank_math/frontend/description', function ($desc) {
    if (is_page('gold-rates'))        return 'Live gold rate today in India: 24K, 22K and 18K gold price per gram and per 10 grams, updated through the day, with a city selector, day-change indicator and a full price calculator (metal value + making charges + 3% GST).';
    if (is_page('silver-rate-today')) return 'Live silver rate today in India: 999 fine silver price per gram, per 10g, per 100g and per kilogram, updated through the day, with a city selector and a value calculator.';
    return $desc;
}, 20);


/* ============================================================================
 * RATE-PAGE EXTRAS (v1.6.0)
 *  - price history + [mp_rate_chart]  (commodity selector + range, inline SVG)
 *  - [mp_commodities_widget]          (rate-page sidebar; replaces Live Markets)
 *  - [mp_gold_insights]               (gold/silver ratio, making-charge table,
 *                                      tax share, buy-timing helper)
 * ==========================================================================*/

function mp_md_series_symbols() {
    return array(
        'gold'   => array('sym' => 'GC=F', 'label' => 'Gold 24K (INR/10g)', 'inr' => true,  'grams' => 10),
        'silver' => array('sym' => 'SI=F', 'label' => 'Silver (INR/kg)',    'inr' => true,  'grams' => 1000),
        'crude'  => array('sym' => 'CL=F', 'label' => 'Crude Oil WTI ($/bbl)', 'inr' => false),
        'brent'  => array('sym' => 'BZ=F', 'label' => 'Brent Crude ($/bbl)',   'inr' => false),
        'natgas' => array('sym' => 'NG=F', 'label' => 'Natural Gas ($/MMBtu)', 'inr' => false),
        'copper' => array('sym' => 'HG=F', 'label' => 'Copper ($/lb)',         'inr' => false),
    );
}

/** Raw daily-close series for a Yahoo symbol. Cached ~30 min. */
function mp_md_history_raw($symbol, $range = '6mo') {
    $range = in_array($range, array('1mo', '3mo', '6mo', '1y', '2y'), true) ? $range : '6mo';
    $key = 'mp_md_hist_' . md5($symbol . $range);
    $c = get_transient($key);
    if (is_array($c)) return $c;

    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
         . '?range=' . $range . '&interval=1d';
    $res = wp_remote_get($url, array('timeout' => 8, 'headers' => array(
        'User-Agent' => 'Mozilla/5.0 (compatible; MoneyPuran/1.0; +https://moneypuran.com)',
        'Accept'     => 'application/json',
    )));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return array();
    $j = json_decode(wp_remote_retrieve_body($res), true);
    $r = isset($j['chart']['result'][0]) ? $j['chart']['result'][0] : null;
    if (!$r || empty($r['timestamp'])) return array();
    $ts = $r['timestamp'];
    $cl = isset($r['indicators']['quote'][0]['close']) ? $r['indicators']['quote'][0]['close'] : array();
    $out = array();
    foreach ($ts as $i => $t) {
        if (!isset($cl[$i]) || $cl[$i] === null) continue;
        $out[] = array((int) $t, round((float) $cl[$i], 4));
    }
    if ($out) set_transient($key, $out, 30 * MINUTE_IN_SECONDS);
    return $out;
}

/** Plot-ready series for one named commodity (gold/silver converted to indicative INR). */
function mp_md_series($name, $range = '6mo') {
    $map = mp_md_series_symbols();
    if (!isset($map[$name])) return null;
    $m = $map[$name];
    $base = mp_md_history_raw($m['sym'], $range);
    if (empty($base)) return null;

    if (!empty($m['inr'])) {
        $fx = mp_md_history_raw('INR=X', $range);
        $fxBy = array();
        foreach ($fx as $p) $fxBy[gmdate('Y-m-d', $p[0])] = $p[1];
        $lastFx = end($fx);
        $lastFx = $lastFx ? $lastFx[1] : 88.0;
        $mult = (float) apply_filters('mp_gold_india_multiplier', 1.13);
        $points = array();
        foreach ($base as $p) {
            $f = isset($fxBy[gmdate('Y-m-d', $p[0])]) ? $fxBy[gmdate('Y-m-d', $p[0])] : $lastFx;
            $points[] = array($p[0], round($p[1] / 31.1035 * $f * $m['grams'] * $mult));
        }
    } else {
        $points = array();
        foreach ($base as $p) $points[] = array($p[0], round($p[1], 2));
    }

    $first = $points[0][1];
    $lastP = end($points);
    $last  = $lastP[1];
    $chg   = ($first != 0) ? round(($last - $first) / $first * 100, 1) : 0;
    return array(
        'name' => $name, 'label' => $m['label'], 'inr' => !empty($m['inr']),
        'range' => $range, 'points' => $points, 'first' => $first, 'last' => $last, 'chg' => $chg,
    );
}

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/history', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'args' => array('series' => array('default' => 'gold'), 'range' => array('default' => '6mo'), 'symbol' => array('default' => '')),
        'callback' => function (WP_REST_Request $req) {
            $range  = $req->get_param('range');
            $symbol = (string) $req->get_param('symbol');
            if ($symbol !== '') {
                $wl = apply_filters('mp_history_symbol_whitelist', array());
                if (!isset($wl[$symbol])) return new WP_Error('bad_symbol', 'Symbol not allowed', array('status' => 400));
                $raw = mp_md_history_raw($symbol, $range);
                if (empty($raw)) return new WP_Error('nodata', 'No history', array('status' => 502));
                $first = $raw[0][1];
                $lastP = end($raw);
                $last  = $lastP[1];
                $d = array(
                    'symbol' => $symbol, 'label' => $wl[$symbol], 'inr' => false, 'range' => $range,
                    'points' => $raw, 'first' => $first, 'last' => $last,
                    'chg' => ($first != 0) ? round(($last - $first) / $first * 100, 1) : 0,
                );
            } else {
                $d = mp_md_series($req->get_param('series'), $range);
            }
            if (!$d) return new WP_Error('nodata', 'No history', array('status' => 502));
            $resp = rest_ensure_response($d);
            $resp->header('Cache-Control', 'public, max-age=600, s-maxage=1800, stale-while-revalidate=3600');
            return $resp;
        },
    ));
});

/* --------------------------- [mp_rate_chart] --------------------------- */
add_shortcode('mp_rate_chart', function ($atts) {
    $def = isset($atts['series']) ? sanitize_key($atts['series']) : 'gold';
    $map = mp_md_series_symbols();
    if (!isset($map[$def])) $def = 'gold';
    $seed = mp_md_series($def, '6mo');
    $opts = '';
    foreach ($map as $k => $m) {
        $opts .= '<option value="' . esc_attr($k) . '"' . selected($k, $def, false) . '>' . esc_html($m['label']) . '</option>';
    }
    ob_start(); ?>
<section class="mp-chart" id="mpRateChart" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/history')); ?>">
  <div class="mp-chart__head">
    <h2>Price chart</h2>
    <select class="mp-chart__series" aria-label="Choose commodity"><?php echo $opts; ?></select>
    <span class="mp-chart__range" role="tablist">
      <button type="button" data-r="1mo">1M</button>
      <button type="button" data-r="3mo">3M</button>
      <button type="button" data-r="6mo" class="on">6M</button>
      <button type="button" data-r="1y">1Y</button>
    </span>
  </div>
  <div class="mp-chart__stat" data-role="stat"></div>
  <div class="mp-chart__plot" data-role="plot" aria-label="price history chart"></div>
  <p class="mp-chart__note">Indicative. Gold and silver are converted from international prices using the day's USD/INR rate with an India duty + GST adjustment, so they will differ from your local retail quote. Not investment advice.</p>
</section>
<style>
.mp-chart{margin:22px 0;border:1px solid var(--mp-border,#e5e7eb);border-radius:12px;padding:16px;background:var(--mp-surface,#fff)}
.mp-chart__head{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:8px}
.mp-chart__head h2{margin:0;font-size:18px;flex:1 1 auto}
.mp-chart__series{padding:8px 10px;border:1px solid var(--mp-border,#cbd5e1);border-radius:8px;background:var(--mp-bg,#f8fafc);color:inherit;font-size:13px;max-width:60%}
.mp-chart__range{display:inline-flex;border:1px solid var(--mp-border,#cbd5e1);border-radius:8px;overflow:hidden}
.mp-chart__range button{border:0;background:transparent;color:inherit;padding:7px 11px;cursor:pointer;font-size:12px;font-weight:600}
.mp-chart__range button.on{background:var(--mp-brand,#0057ff);color:#fff}
.mp-chart__stat{font-size:14px;margin:4px 0 10px;min-height:26px}
.mp-chart__stat b{font-size:20px}
.mp-chart__stat i{font-style:normal;font-weight:600}
.mp-chart__stat i.up{color:#16a34a}.mp-chart__stat i.dn{color:#dc2626}
.mp-chart__plot{width:100%;height:220px;position:relative}
.mp-chart__plot svg{width:100%;height:100%;display:block;overflow:visible}
.mp-chart__note{font-size:11px;color:var(--mp-muted,#64748b);margin:8px 0 0}
html[data-theme="dark"] .mp-chart{background:#111827;border-color:rgba(255,255,255,.08);color:#f1f5f9}
html[data-theme="dark"] .mp-chart__series{background:#0a0f1e;border-color:rgba(255,255,255,.12);color:#f1f5f9}
</style>
<script>
(function(){
  var W=document.getElementById('mpRateChart'); if(!W) return;
  var EP=W.getAttribute('data-endpoint'), sel=W.querySelector('.mp-chart__series'), rWrap=W.querySelector('.mp-chart__range'),
      plot=W.querySelector('[data-role=plot]'), statEl=W.querySelector('[data-role=stat]'), range='6mo', DATA=null;
  var LBL={'1mo':'1 month','3mo':'3 months','6mo':'6 months','1y':'1 year'};

  function draw(d){
    DATA=d;
    var pts=d.points||[];
    if(pts.length<2){ plot.innerHTML='<p style="opacity:.6;font-size:13px">Chart data unavailable right now.</p>'; statEl.innerHTML=''; return; }
    var w=plot.clientWidth||600, h=plot.clientHeight||220, pad=6, padB=16;
    var ys=pts.map(function(p){return p[1];}), min=Math.min.apply(null,ys), max=Math.max.apply(null,ys);
    if(min===max){ min-=1; max+=1; }
    var n=pts.length;
    function X(i){ return pad + i/(n-1)*(w-2*pad); }
    function Y(v){ return pad + (1-(v-min)/(max-min))*(h-pad-padB); }
    var line='', area='';
    pts.forEach(function(p,i){ var c=X(i).toFixed(1)+' '+Y(p[1]).toFixed(1); line+=(i?' L':'M')+c; area+=(i?' L':'M')+c; });
    area+=' L'+X(n-1).toFixed(1)+' '+(h-padB)+' L'+X(0).toFixed(1)+' '+(h-padB)+' Z';
    var up=d.chg>=0, col=up?'#16a34a':'#dc2626';
    var t0=new Date(pts[0][0]*1000), t1=new Date(pts[n-1][0]*1000);
    function dfmt(dt){ return dt.toLocaleDateString('en-IN',{month:'short',year:'2-digit'}); }
    plot.innerHTML=
      '<svg viewBox="0 0 '+w+' '+h+'" preserveAspectRatio="none" role="img">'
      +'<defs><linearGradient id="mpcg" x1="0" x2="0" y1="0" y2="1">'
      +'<stop offset="0" stop-color="'+col+'" stop-opacity="0.22"/><stop offset="1" stop-color="'+col+'" stop-opacity="0"/></linearGradient></defs>'
      +'<path d="'+area+'" fill="url(#mpcg)"/>'
      +'<path d="'+line+'" fill="none" stroke="'+col+'" stroke-width="2" stroke-linejoin="round"/>'
      +'<text x="'+pad+'" y="'+h+'" font-size="10" fill="#94a3b8">'+dfmt(t0)+'</text>'
      +'<text x="'+(w-pad)+'" y="'+h+'" font-size="10" fill="#94a3b8" text-anchor="end">'+dfmt(t1)+'</text>'
      +'</svg>';
    var sym=d.inr?'₹':'', dec=d.inr?0:2;
    var last=Number(d.last).toLocaleString('en-IN',{minimumFractionDigits:dec,maximumFractionDigits:dec});
    var lo=Number(min).toLocaleString('en-IN',{maximumFractionDigits:dec}), hi=Number(max).toLocaleString('en-IN',{maximumFractionDigits:dec});
    statEl.innerHTML='<b>'+sym+last+'</b> <i class="'+(up?'up':'dn')+'">'+(up?'▲ ':'▼ ')+Math.abs(d.chg)+'% over '+(LBL[range]||range)+'</i>'
      +' <span style="color:#94a3b8;font-size:12px">· range '+sym+lo+'–'+sym+hi+'</span>';
  }
  function load(){
    plot.style.opacity='.5';
    fetch(EP+'?series='+encodeURIComponent(sel.value)+'&range='+range,{credentials:'omit'})
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){ plot.style.opacity='1'; if(d&&d.points) draw(d); else { plot.innerHTML='<p style="opacity:.6;font-size:13px">Chart data unavailable right now.</p>'; } })
      .catch(function(){ plot.style.opacity='1'; });
  }
  sel.addEventListener('change', load);
  rWrap.addEventListener('click', function(e){
    var b=e.target.closest('button'); if(!b) return;
    rWrap.querySelectorAll('button').forEach(function(x){x.classList.remove('on');});
    b.classList.add('on'); range=b.getAttribute('data-r'); load();
  });
  var rt; window.addEventListener('resize', function(){ clearTimeout(rt); rt=setTimeout(function(){ if(DATA) draw(DATA); }, 150); });
  <?php if ($seed) : ?>draw(<?php echo wp_json_encode($seed); ?>);<?php endif; ?>
  load();
}());
</script>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_commodities_widget] (sidebar) --------------------------- */
add_shortcode('mp_commodities_widget', function () {
    $grp  = mp_md_get_groups();
    $rows = (is_array($grp) && !empty($grp['commodities'])) ? $grp['commodities'] : array();
    $b    = (is_array($grp) && !empty($grp['bullion_inr'])) ? $grp['bullion_inr'] : null;
    $mult = (float) apply_filters('mp_gold_india_multiplier', 1.13);
    ob_start(); ?>
<div class="mp-widget mp-commodw">
  <h3 class="mp-widget-title">Commodities &amp; bullion</h3>
  <div class="mp-commodw__list" id="mpCommodWidget" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/markets?only=dashboard')); ?>" data-mult="<?php echo esc_attr($mult); ?>">
    <?php if ($b) : ?>
      <div class="mp-commodw__row"><span>Gold 24K &middot; 10g</span><span>&#8377;<?php echo number_format($b['gold_24k_10g'] * $mult); ?></span><span></span></div>
      <?php if (!empty($b['silver_kg'])) : ?>
      <div class="mp-commodw__row"><span>Silver &middot; 1kg</span><span>&#8377;<?php echo number_format($b['silver_kg'] * $mult); ?></span><span></span></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php foreach ($rows as $r) : $up = (isset($r['chgPct']) ? $r['chgPct'] : 0) >= 0; $p = (float) $r['price']; ?>
      <div class="mp-commodw__row"><span><?php echo esc_html($r['label']); ?></span>
        <span><?php echo ($p < 10 ? number_format($p, 3) : number_format($p, 2)); ?></span>
        <span class="<?php echo $up ? 'up' : 'dn'; ?>"><?php echo ($up ? '+' : '') . number_format((float) (isset($r['chgPct']) ? $r['chgPct'] : 0), 2); ?>%</span></div>
    <?php endforeach; ?>
  </div>
  <a href="<?php echo esc_url(home_url('/gold-rates/')); ?>" class="mp-commodw__more">Full gold &amp; silver rates &rarr;</a>
</div>
<style>
.mp-commodw__row{display:grid;grid-template-columns:1fr auto auto;gap:8px;padding:7px 0;border-top:1px solid var(--mp-border,#eef1f4);font-size:12.5px;font-variant-numeric:tabular-nums;align-items:baseline}
.mp-commodw__row:first-child{border-top:0}
.mp-commodw__row span:nth-child(3){min-width:52px;text-align:right}
.mp-commodw__row .up{color:#16a34a}.mp-commodw__row .dn{color:#dc2626}
.mp-commodw__more{display:inline-block;margin-top:10px;font-size:12px;font-weight:600;color:var(--mp-brand,#0057ff)}
html[data-theme="dark"] .mp-commodw__row{border-color:rgba(255,255,255,.08)}
</style>
<script>
(function(){
  var W=document.getElementById('mpCommodWidget'); if(!W) return;
  var mult=parseFloat(W.getAttribute('data-mult'))||1.13;
  function inr(n){ return '₹'+Math.round(n).toLocaleString('en-IN'); }
  fetch(W.getAttribute('data-endpoint'),{credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
    if(!d) return;
    var h='';
    if(d.bullion_inr){
      h+='<div class="mp-commodw__row"><span>Gold 24K · 10g</span><span>'+inr(d.bullion_inr.gold_24k_10g*mult)+'</span><span></span></div>';
      if(d.bullion_inr.silver_kg) h+='<div class="mp-commodw__row"><span>Silver · 1kg</span><span>'+inr(d.bullion_inr.silver_kg*mult)+'</span><span></span></div>';
    }
    (d.commodities||[]).forEach(function(r){
      var up=(r.chgPct||0)>=0, p=Number(r.price);
      h+='<div class="mp-commodw__row"><span>'+r.label+'</span><span>'+(p<10?p.toFixed(3):p.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}))+'</span>'
        +'<span class="'+(up?'up':'dn')+'">'+(up?'+':'')+Number(r.chgPct||0).toFixed(2)+'%</span></div>';
    });
    if(h) W.innerHTML=h;
  }).catch(function(){});
}());
</script>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_gold_insights] --------------------------- */
add_shortcode('mp_gold_insights', function () {
    $grp = mp_md_get_groups();
    $b   = (is_array($grp) && !empty($grp['bullion_inr'])) ? $grp['bullion_inr'] : null;
    if (!$b) return '';
    $mult = (float) apply_filters('mp_gold_india_multiplier', 1.13);

    $g24_10  = $b['gold_24k_10g'] * $mult;
    $g_per_g = $g24_10 / 10;
    $s_per_g = !empty($b['silver_kg']) ? $b['silver_kg'] * $mult / 1000 : null;
    $ratio   = $s_per_g ? round($g_per_g / $s_per_g, 1) : null;

    $ratio_read = '';
    if ($ratio !== null) {
        if ($ratio > 88)     $ratio_read = 'Silver is historically cheap against gold right now - the ratio usually sits in a 60-90 band.';
        elseif ($ratio < 62) $ratio_read = 'Gold is historically cheap against silver right now - the ratio usually sits in a 60-90 band.';
        else                 $ratio_read = 'The ratio is inside its usual 60-90 band, so neither metal looks stretched versus the other.';
    }

    $intl       = $b['gold_24k_10g'];
    $bridge     = $g24_10 - $intl;
    $bridge_pct = $g24_10 ? round($bridge / $g24_10 * 100) : 0;

    $hist = mp_md_series('gold', '1mo');
    $mom  = ($hist && !empty($hist['points']) && count($hist['points']) > 3) ? $hist['chg'] : null;

    ob_start(); ?>
<section class="mp-ins">
  <h2>Gold &amp; silver insights</h2>
  <div class="mp-ins__grid">
    <div class="mp-ins__card">
      <h4>Gold&ndash;silver ratio</h4>
      <div class="mp-ins__big"><?php echo $ratio !== null ? esc_html($ratio) : '&mdash;'; ?></div>
      <p><?php echo esc_html($ratio_read); ?> One gram of gold currently buys about <?php echo $ratio !== null ? esc_html($ratio) : '&mdash;'; ?>&nbsp;g of silver.</p>
    </div>
    <div class="mp-ins__card">
      <h4>Duty &amp; tax share</h4>
      <div class="mp-ins__big">~<?php echo (int) $bridge_pct; ?>%</div>
      <p>Of the &#8377;<?php echo number_format($g24_10); ?> per-10g reference for 24K, roughly &#8377;<?php echo number_format($bridge); ?> is import duty, 3% GST and local premium. Making-charge GST (5%) is charged on top when you buy jewellery.</p>
    </div>
    <div class="mp-ins__card">
      <h4>Last 30 days</h4>
      <div class="mp-ins__big <?php echo ($mom !== null && $mom < 0) ? 'dn' : 'up'; ?>">
        <?php echo $mom === null ? '&mdash;' : (($mom >= 0 ? '&#9650; ' : '&#9660; ') . abs($mom) . '%'); ?>
      </div>
      <p><?php
        if ($mom === null)      echo 'Trend data is loading.';
        elseif ($mom >= 3)      echo 'Gold has run up this month. Averaging in (small amounts over time) avoids timing a local top.';
        elseif ($mom <= -3)     echo 'Gold has pulled back this month. Dips have historically suited lump-sum buyers &mdash; but past moves do not predict the next one.';
        else                    echo 'Gold has been broadly flat this month &mdash; a calm window for planned purchases.';
      ?></p>
    </div>
  </div>

  <h3>Making charges move the final bill more than the rate does</h3>
  <table class="mp-ins__tbl">
    <thead><tr><th>Making charge</th><th>10g 22K jewellery, incl. GST</th><th>Extra vs 8%</th></tr></thead>
    <tbody>
    <?php
      $g22_g = $g_per_g * 0.916;
      $base  = $g22_g * 10;
      $ref   = null;
      foreach (array(8, 12, 18, 25) as $mk) {
          $tot = $base * (1 + $mk / 100) * 1.03;
          if ($ref === null) $ref = $tot;
          printf(
              '<tr><td>%d%%</td><td>&#8377;%s</td><td>%s</td></tr>',
              $mk,
              number_format($tot),
              $mk === 8 ? '&mdash;' : ('+&#8377;' . number_format($tot - $ref))
          );
      }
    ?>
    </tbody>
  </table>
  <p class="mp-ins__note">Making charges are negotiable, especially on plain (non-studded) gold &mdash; ask for them itemised. All figures indicative and derived from international prices; they are not a retail quote and not investment advice.</p>
</section>
<style>
.mp-ins{margin:24px 0}
.mp-ins h2{font-size:20px;margin:0 0 12px}
.mp-ins h3{font-size:15px;margin:18px 0 8px}
.mp-ins__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.mp-ins__card{border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:14px 16px;background:var(--mp-surface,#fff)}
.mp-ins__card h4{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--mp-muted,#64748b)}
.mp-ins__big{font-size:26px;font-weight:800;margin-bottom:4px}
.mp-ins__big.up{color:#16a34a}.mp-ins__big.dn{color:#dc2626}
.mp-ins__card p{margin:0;font-size:12.5px;line-height:1.5}
.mp-ins__tbl{width:100%;border-collapse:collapse;font-size:13px}
.mp-ins__tbl th,.mp-ins__tbl td{padding:8px 10px;border-bottom:1px solid var(--mp-border,#eef1f4);text-align:left}
.mp-ins__tbl td:not(:first-child),.mp-ins__tbl th:not(:first-child){text-align:right;font-variant-numeric:tabular-nums}
.mp-ins__note{font-size:11px;color:var(--mp-muted,#64748b);margin-top:10px}
html[data-theme="dark"] .mp-ins__card{background:#111827;border-color:rgba(255,255,255,.08);color:#f1f5f9}
html[data-theme="dark"] .mp-ins__tbl th,html[data-theme="dark"] .mp-ins__tbl td{border-color:rgba(255,255,255,.08)}
</style>
    <?php
    return ob_get_clean();
});


/* ============================================================================
 * COMMODITIES HUB (v1.7.0) — [mp_commodities_page]
 * Real prices (server-side Yahoo v8, cached) grouped Energy / Precious /
 * Base metals / Agriculture. Live auto-refresh + flash on change, indicative
 * INR from the international price, per-row 30-day sparkline, click-to-expand
 * 6-month chart, 52-week position bar, top-mover banner. No MCX contract
 * data (no free feed) — that is stated on the page, not faked.
 * ==========================================================================*/

const MP_MD_COMMOD_KEY   = 'mp_md_commod_v1';
const MP_MD_COMMOD_LOCK  = 'mp_md_commod_lock_v1';
const MP_MD_COMMOD_SPK   = 'mp_md_commod_spark_v1';
const MP_MD_COMMOD_SOFT  = 60;

/** key => [yahoo symbol, label, group, inr-conversion code|null] */
function mp_md_commod_defs() {
    return array(
        'wti'       => array('CL=F',  'Crude Oil (WTI)',   'energy',   'bbl'),
        'brent'     => array('BZ=F',  'Brent Crude',       'energy',   'bbl'),
        'natgas'    => array('NG=F',  'Natural Gas',       'energy',   null),
        'gasoline'  => array('RB=F',  'Gasoline (RBOB)',   'energy',   null),
        'heatoil'   => array('HO=F',  'Heating Oil',       'energy',   null),
        'gold'      => array('GC=F',  'Gold',              'precious', 'oz10g'),
        'silver'    => array('SI=F',  'Silver',            'precious', 'ozkg'),
        'platinum'  => array('PL=F',  'Platinum',          'precious', 'oz10g'),
        'palladium' => array('PA=F',  'Palladium',         'precious', 'oz10g'),
        'copper'    => array('HG=F',  'Copper',            'base',     'lbkg'),
        'aluminium' => array('ALI=F', 'Aluminium',         'base',     'tonnekg'),
        'corn'      => array('ZC=F',  'Corn',              'agri',     null),
        'wheat'     => array('ZW=F',  'Wheat',             'agri',     null),
        'soybean'   => array('ZS=F',  'Soybeans',          'agri',     null),
        'coffee'    => array('KC=F',  'Coffee',            'agri',     null),
        'sugar'     => array('SB=F',  'Sugar',             'agri',     null),
        'cotton'    => array('CT=F',  'Cotton',            'agri',     null),
    );
}

function mp_md_commod_groups_meta() {
    return array(
        'energy'   => 'Energy',
        'precious' => 'Precious metals',
        'base'     => 'Base metals',
        'agri'     => 'Agriculture',
    );
}

/** International price -> indicative INR. Pure FX conversion, no India import duty. */
function mp_md_commod_inr($usd, $code, $usdinr) {
    if (!$usdinr || !$code) return null;
    switch ($code) {
        case 'bbl':     return round($usd * $usdinr);                       // $/bbl  -> INR/bbl
        case 'oz10g':   return round($usd / 31.1035 * $usdinr * 10);        // $/ozt  -> INR/10g
        case 'ozkg':    return round($usd / 31.1035 * $usdinr * 1000);      // $/ozt  -> INR/kg
        case 'lbkg':    return round($usd * 2.20462 * $usdinr);             // $/lb   -> INR/kg
        case 'tonnekg': return round($usd / 1000 * $usdinr, 2);            // $/t    -> INR/kg
    }
    return null;
}
function mp_md_commod_inr_unit($code) {
    return array('bbl' => '/bbl', 'oz10g' => '/10g', 'ozkg' => '/kg', 'lbkg' => '/kg', 'tonnekg' => '/kg')[$code] ?? '';
}

function mp_md_commod_usdinr() {
    $g = get_transient(MP_MD_GRP_KEY);
    if (is_array($g)) {
        foreach (($g['currencies'] ?? array()) as $r) {
            if ($r['sym'] === 'INR=X' && !empty($r['price'])) return (float) $r['price'];
        }
    }
    $q = mp_md_yahoo_one('INR=X');
    return $q ? (float) $q['price'] : 88.0;
}

function mp_md_commod_build() {
    $deadline = microtime(true) + 16;
    $usdinr = mp_md_commod_usdinr();
    $rows = array();
    foreach (mp_md_commod_defs() as $key => $d) {
        if (microtime(true) > $deadline) break;
        list($sym, $label, $group, $code) = $d;
        $q = mp_md_yahoo_one($sym);
        if (!$q || !isset($q['price'])) continue;
        $w52pos = null;
        if ($q['w52_high'] !== null && $q['w52_low'] !== null && $q['w52_high'] > $q['w52_low']) {
            $w52pos = max(0, min(100, round(($q['price'] - $q['w52_low']) / ($q['w52_high'] - $q['w52_low']) * 100)));
        }
        $rows[$key] = array(
            'key'    => $key,
            'label'  => $label,
            'group'  => $group,
            'symbol' => $sym,
            'price'  => $q['price'],
            'change' => $q['change'],
            'chgPct' => $q['chgPct'],
            'currency' => $q['currency'],
            'inr'    => mp_md_commod_inr($q['price'], $code, $usdinr),
            'inrUnit'=> mp_md_commod_inr_unit($code),
            'high'   => $q['high'],
            'low'    => $q['low'],
            'w52_high' => $q['w52_high'],
            'w52_low'  => $q['w52_low'],
            'w52pos' => $w52pos,
            'state'  => $q['state'],
            'asOf'   => $q['asOf'],
        );
    }
    return array('rows' => $rows, 'usdinr' => round($usdinr, 2), '_at' => time(), 'asOf' => gmdate('c'));
}

function mp_md_get_commod() {
    $snap = get_transient(MP_MD_COMMOD_KEY);
    $age  = is_array($snap) && !empty($snap['_at']) ? (time() - $snap['_at']) : PHP_INT_MAX;
    if (is_array($snap) && $age < MP_MD_COMMOD_SOFT) return $snap;

    if (!get_transient(MP_MD_COMMOD_LOCK)) {
        set_transient(MP_MD_COMMOD_LOCK, 1, 20);
        $fresh = mp_md_commod_build();
        delete_transient(MP_MD_COMMOD_LOCK);
        if (!empty($fresh['rows'])) {
            set_transient(MP_MD_COMMOD_KEY, $fresh, MP_MD_HARD_TTL);
            return $fresh;
        }
    }
    return is_array($snap) ? $snap : array('rows' => array(), 'usdinr' => null, 'asOf' => gmdate('c'));
}

/** 30-day sparkline closes per commodity, downsampled to <=26 points. Cached ~2h. */
function mp_md_commod_spark() {
    $c = get_transient(MP_MD_COMMOD_SPK);
    if (is_array($c)) return $c;
    $out = array();
    $deadline = microtime(true) + 18;
    foreach (mp_md_commod_defs() as $key => $d) {
        if (microtime(true) > $deadline) break;
        $ser = mp_md_history_raw($d[0], '1mo');
        if (empty($ser)) continue;
        $vals = array_map(function ($p) { return $p[1]; }, $ser);
        $n = count($vals);
        if ($n > 26) {
            $step = ($n - 1) / 25;
            $s = array();
            for ($i = 0; $i < 26; $i++) $s[] = $vals[(int) round($i * $step)];
            $vals = $s;
        }
        $out[$key] = $vals;
    }
    if ($out) set_transient(MP_MD_COMMOD_SPK, $out, 2 * HOUR_IN_SECONDS);
    return $out;
}

/* crons */
add_action('mp_md_cron_commod', function () {
    delete_transient(MP_MD_COMMOD_LOCK);
    $f = mp_md_commod_build();
    if (!empty($f['rows'])) set_transient(MP_MD_COMMOD_KEY, $f, MP_MD_HARD_TTL);
});
add_action('mp_md_cron_commod_spark', function () {
    delete_transient(MP_MD_COMMOD_SPK);
    mp_md_commod_spark();
});
add_action('init', function () {
    if (!wp_next_scheduled('mp_md_cron_commod'))       wp_schedule_event(time() + 55, 'mp_md_2min',  'mp_md_cron_commod');
    if (!wp_next_scheduled('mp_md_cron_commod_spark')) wp_schedule_event(time() + 90, 'mp_md_10min', 'mp_md_cron_commod_spark');
});
add_filter('cron_schedules', function ($s) {
    if (empty($s['mp_md_10min'])) $s['mp_md_10min'] = array('interval' => 600, 'display' => 'Every 10 minutes');
    return $s;
});

/* REST */
add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/commodities', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $d = mp_md_get_commod();
            $rows = array_values($d['rows']);
            $g = sanitize_key($req->get_param('group'));
            if ($g && isset(mp_md_commod_groups_meta()[$g])) {
                $rows = array_values(array_filter($rows, function ($r) use ($g) { return $r['group'] === $g; }));
            }
            $body = array('rows' => $rows, 'usdinr' => $d['usdinr'], 'asOf' => $d['asOf'], 'note' => 'Prices may be delayed. Not investment advice.');
            if ($req->get_param('spark')) $body['spark'] = mp_md_commod_spark();
            $resp = rest_ensure_response($body);
            $resp->header('Cache-Control', 'public, max-age=15, s-maxage=20, stale-while-revalidate=60');
            return $resp;
        },
    ));
});

/** allow /history to plot an arbitrary whitelisted commodity symbol */
add_filter('mp_history_symbol_whitelist', function ($list) {
    foreach (mp_md_commod_defs() as $d) $list[$d[0]] = $d[1];
    return $list;
});

/* --------------------------- [mp_commodities_page] --------------------------- */
add_shortcode('mp_commodities_page', function () {
    $d      = mp_md_get_commod();
    $rows   = $d['rows'];
    $spark  = mp_md_commod_spark();
    $groups = mp_md_commod_groups_meta();

    // top mover
    $mover = null;
    foreach ($rows as $r) {
        if ($r['chgPct'] === null) continue;
        if ($mover === null || abs($r['chgPct']) > abs($mover['chgPct'])) $mover = $r;
    }

    ob_start(); ?>
<div class="mp-commod" id="mpCommodPage"
     data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/commodities')); ?>"
     data-history="<?php echo esc_url(home_url('/wp-json/mp/v1/history')); ?>">

  <?php if ($mover) : $mu = $mover['chgPct'] >= 0; ?>
  <div class="mp-commod__mover">
    <span class="mp-commod__mover-tag">Biggest move today</span>
    <strong><?php echo esc_html($mover['label']); ?></strong>
    <span class="<?php echo $mu ? 'up' : 'dn'; ?>"><?php echo ($mu ? '▲ ' : '▼ ') . abs($mover['chgPct']); ?>%</span>
  </div>
  <?php endif; ?>

  <div class="mp-commod__tabs" role="tablist">
    <button type="button" class="on" data-g="all">All</button>
    <?php foreach ($groups as $gk => $gl) : ?>
    <button type="button" data-g="<?php echo esc_attr($gk); ?>"><?php echo esc_html($gl); ?></button>
    <?php endforeach; ?>
    <span class="mp-commod__meta" data-role="meta"></span>
  </div>

  <div class="mp-commod__wrap">
    <table class="mp-commod__tbl">
      <thead><tr>
        <th>Commodity</th><th class="num">Price</th><th class="num">≈ &#8377;</th>
        <th class="num">Day</th><th class="w52">52-week range</th><th class="spk">30 days</th>
      </tr></thead>
      <tbody data-role="body">
        <?php foreach ($rows as $r) : mp_md_commod_row_html($r, $spark[$r['key']] ?? array()); endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="mp-commod__note">
    Live international benchmark prices (COMEX / NYMEX / ICE), server-fetched and cached; they refresh here every few seconds and may be delayed by the exchange.
    &#8377; values are the international price converted at USD/INR (<span data-role="fx"><?php echo $d['usdinr'] ? esc_html($d['usdinr']) : '—'; ?></span>) — they exclude Indian import duty, GST and MCX basis, so they are not MCX or retail quotes.
    We don't publish MCX contract prices (Gold Petal, Crude Oil Mini, etc.) because there is no free, redistributable MCX feed. Nothing here is investment advice.
  </p>
</div>

<style>
.mp-commod{margin:18px 0}
.mp-commod__mover{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--mp-bg,#f8fafc);border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:14px}
.mp-commod__mover-tag{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--mp-muted,#64748b);font-weight:700}
.mp-commod__mover .up{color:#16a34a;font-weight:700}.mp-commod__mover .dn{color:#dc2626;font-weight:700}
.mp-commod__tabs{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px}
.mp-commod__tabs button{border:1px solid var(--mp-border,#cbd5e1);background:transparent;color:inherit;padding:6px 12px;border-radius:20px;font-size:12.5px;font-weight:600;cursor:pointer}
.mp-commod__tabs button.on{background:var(--mp-brand,#0057ff);border-color:var(--mp-brand,#0057ff);color:#fff}
.mp-commod__meta{margin-left:auto;font-size:11px;color:var(--mp-muted,#64748b)}
.mp-commod__wrap{overflow-x:auto;border:1px solid var(--mp-border,#e5e7eb);border-radius:12px}
.mp-commod__tbl{width:100%;border-collapse:collapse;font-size:13.5px;min-width:640px}
.mp-commod__tbl th,.mp-commod__tbl td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--mp-border,#eef1f4);white-space:nowrap}
.mp-commod__tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--mp-muted,#64748b);background:var(--mp-bg,#f8fafc)}
.mp-commod__tbl th.num,.mp-commod__tbl td.num{text-align:right;font-variant-numeric:tabular-nums}
.mp-commod__tbl tbody tr{cursor:pointer}
.mp-commod__tbl tbody tr:hover{background:var(--mp-bg,#f8fafc)}
.mp-commod__name{font-weight:600}
.mp-commod__name small{display:block;font-weight:400;color:var(--mp-muted,#64748b);font-size:11px}
.mp-commod__inr{color:var(--mp-muted,#475569)}
.mp-commod__chg.up{color:#16a34a;font-weight:600}.mp-commod__chg.dn{color:#dc2626;font-weight:600}
.mp-commod__w52{display:flex;align-items:center;gap:6px;min-width:150px}
.mp-commod__w52-track{position:relative;flex:1;height:4px;border-radius:3px;background:linear-gradient(90deg,#dc2626,#e5e7eb,#16a34a)}
.mp-commod__w52-dot{position:absolute;top:-3px;width:10px;height:10px;border-radius:50%;background:var(--mp-ink,#0f172a);border:2px solid #fff;transform:translateX(-50%)}
.mp-commod__w52-lbl{font-size:10px;color:var(--mp-muted,#94a3b8)}
.mp-commod__spk svg{display:block}
.mp-commod__flash-up{animation:mpCommFlashU .9s ease-out}
.mp-commod__flash-dn{animation:mpCommFlashD .9s ease-out}
@keyframes mpCommFlashU{0%{background:rgba(22,163,74,.28)}100%{background:transparent}}
@keyframes mpCommFlashD{0%{background:rgba(220,38,38,.28)}100%{background:transparent}}
.mp-commod__exp td{background:var(--mp-bg,#f8fafc);padding:14px}
.mp-commod__exp-tv{width:100%;height:440px;border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;overflow:hidden;background:var(--mp-surface,#fff)}
.mp-commod__exp-stat{font-size:11.5px;color:var(--mp-muted,#64748b);margin-top:6px}
@media(max-width:600px){.mp-commod__exp-tv{height:340px}}
html[data-theme="dark"] .mp-commod__exp-tv{border-color:rgba(255,255,255,.08);background:#111827}
.mp-commod__note{font-size:11px;line-height:1.6;color:var(--mp-muted,#64748b);margin-top:12px}
html[data-theme="dark"] .mp-commod__mover,html[data-theme="dark"] .mp-commod__tbl th{background:#0a0f1e}
html[data-theme="dark"] .mp-commod__wrap{border-color:rgba(255,255,255,.08)}
html[data-theme="dark"] .mp-commod__tbl th,html[data-theme="dark"] .mp-commod__tbl td{border-color:rgba(255,255,255,.08)}
html[data-theme="dark"] .mp-commod__tbl tbody tr:hover,html[data-theme="dark"] .mp-commod__exp td{background:#111827}
html[data-theme="dark"] .mp-commod__w52-dot{background:#f1f5f9;border-color:#111827}
</style>

<script>
(function(){
  var W=document.getElementById('mpCommodPage'); if(!W) return;
  var EP=W.getAttribute('data-endpoint'), HP=W.getAttribute('data-history');
  var body=W.querySelector('[data-role=body]'), metaEl=W.querySelector('[data-role=meta]'), fxEl=W.querySelector('[data-role=fx]');
  var group='all', LAST={}, painted=false, expandedKey=null;

  function fmtP(v){ return v>=1000? v.toLocaleString('en-IN',{maximumFractionDigits:0}) : (v>=10? v.toFixed(2) : v.toFixed(3)); }
  function fmtI(v){ return v==null? '' : '₹'+Math.round(v).toLocaleString('en-IN'); }

  function spark(vals,w,h){
    if(!vals||vals.length<2) return '';
    var mn=Math.min.apply(null,vals), mx=Math.max.apply(null,vals); if(mn===mx){mn-=1;mx+=1;}
    var n=vals.length, d='';
    for(var i=0;i<n;i++){ var x=i/(n-1)*w, y=h-(vals[i]-mn)/(mx-mn)*h; d+=(i?'L':'M')+x.toFixed(1)+' '+y.toFixed(1)+' '; }
    var up=vals[n-1]>=vals[0];
    return '<svg class="mp-commod__spk-svg" width="'+w+'" height="'+h+'" viewBox="0 0 '+w+' '+h+'"><path d="'+d+'" fill="none" stroke="'+(up?'#16a34a':'#dc2626')+'" stroke-width="1.5"/></svg>';
  }

  function rowHtml(r, spk){
    var up=(r.chgPct||0)>=0;
    var pos=r.w52pos;
    var w52 = (pos==null)? '<span class="mp-commod__w52-lbl">—</span>' :
      '<div class="mp-commod__w52"><span class="mp-commod__w52-lbl">'+fmtP(r.w52_low)+'</span>'
      +'<span class="mp-commod__w52-track"><span class="mp-commod__w52-dot" style="left:'+pos+'%"></span></span>'
      +'<span class="mp-commod__w52-lbl">'+fmtP(r.w52_high)+'</span></div>';
    return '<tr data-key="'+r.key+'" data-group="'+r.group+'" data-sym="'+r.symbol+'">'
      +'<td><span class="mp-commod__name">'+r.label+'<small>'+r.symbol.replace('=F',' futures')+'</small></span></td>'
      +'<td class="num" data-role="price">'+fmtP(r.price)+' <span class="mp-commod__inr" style="font-size:11px">'+(r.currency||'USD')+'</span></td>'
      +'<td class="num mp-commod__inr">'+(r.inr!=null? fmtI(r.inr)+'<span style="font-size:10px"> '+(r.inrUnit||'')+'</span>' : '—')+'</td>'
      +'<td class="num mp-commod__chg '+(up?'up':'dn')+'">'+(up?'+':'')+(r.chgPct==null?'—':r.chgPct+'%')+'</td>'
      +'<td>'+w52+'</td>'
      +'<td class="spk">'+spark(spk,90,26)+'</td></tr>';
  }

  function render(rows, sparkMap){
    var html='';
    rows.forEach(function(r){ html+=rowHtml(r, sparkMap&&sparkMap[r.key]); });
    body.innerHTML=html;
    applyFilter();
    if(expandedKey){ var tr=body.querySelector('tr[data-key="'+expandedKey+'"]'); if(tr) expandRow(tr,true); }
  }

  function paint(rows){
    rows.forEach(function(r){
      var tr=body.querySelector('tr[data-key="'+r.key+'"]'); if(!tr) return;
      var cell=tr.querySelector('[data-role=price]');
      var prev=LAST[r.key];
      if(painted && prev!=null && prev!==r.price){
        tr.classList.remove('mp-commod__flash-up','mp-commod__flash-dn');
        void tr.offsetWidth;
        tr.classList.add(r.price>prev?'mp-commod__flash-up':'mp-commod__flash-dn');
      }
      LAST[r.key]=r.price;
      cell.innerHTML=fmtP(r.price)+' <span class="mp-commod__inr" style="font-size:11px">'+(r.currency||'USD')+'</span>';
      var chg=tr.querySelector('.mp-commod__chg');
      var up=(r.chgPct||0)>=0;
      chg.className='num mp-commod__chg '+(up?'up':'dn');
      chg.textContent=(up?'+':'')+(r.chgPct==null?'—':r.chgPct+'%');
    });
    painted=true;
  }

  function applyFilter(){
    [].forEach.call(body.querySelectorAll('tr[data-group]'),function(tr){
      tr.style.display=(group==='all'||tr.getAttribute('data-group')===group)?'':'none';
    });
  }

  function expandRow(tr, keepOpen){
    var key=tr.getAttribute('data-key'), cc=tr.getAttribute('data-cc');
    var next=tr.nextElementSibling;
    if(next && next.classList.contains('mp-commod__exp')){
      if(!keepOpen){ next.remove(); expandedKey=null; return; }
      return;
    }
    var open=body.querySelector('.mp-commod__exp'); if(open) open.remove();
    var nm=tr.querySelector('.mp-commod__name').firstChild.textContent;
    var exp=document.createElement('tr');
    exp.className='mp-commod__exp';
    exp.innerHTML='<td colspan="6"><div class="mp-cc" data-symbol="'+cc+'" data-tf="1D">'
      +'<div class="mp-cc__head"><span class="mp-cc__title" data-role="title">'+nm+'</span>'
      +'<span class="mp-cc__meta" data-role="meta">Loading…</span></div>'
      +'<div class="mp-cc__tf" data-role="tf">'
      +['5m','15m','1h','1D','1W'].map(function(t){return '<button type="button" data-t="'+t+'"'+(t==='1D'?' class="on"':'')+'>'+t.toUpperCase()+'</button>';}).join('')
      +'</div><div class="mp-cc__box" style="height:380px"></div></div></td>';
    tr.parentNode.insertBefore(exp, tr.nextSibling);
    expandedKey=key;
    if(window.__mpLWC) window.__mpLWC.scan(true);
  }

  W.querySelector('.mp-commod__tabs').addEventListener('click',function(e){
    var b=e.target.closest('button[data-g]'); if(!b) return;
    W.querySelectorAll('.mp-commod__tabs button').forEach(function(x){x.classList.remove('on');});
    b.classList.add('on'); group=b.getAttribute('data-g'); applyFilter();
  });
  body.addEventListener('click',function(e){
    var tr=e.target.closest('tr[data-key]'); if(!tr) return;
    expandRow(tr,false);
  });

  function load(){
    fetch(EP+'?spark=1',{credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      if(!d||!d.rows) return;
      if(fxEl && d.usdinr) fxEl.textContent=d.usdinr;
      if(metaEl) metaEl.textContent='updated '+new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
      if(!painted && !body.querySelector('tr[data-key]')) render(d.rows, d.spark);
      else if(!body.querySelector('tr[data-key]')) render(d.rows, d.spark);
      paint(d.rows);
    }).catch(function(){});
  }
  // server-rendered rows already present; seed LAST then start polling
  [].forEach.call(body.querySelectorAll('tr[data-key]'),function(tr){
    var k=tr.getAttribute('data-key'); var t=tr.querySelector('[data-role=price]');
    if(t){ var m=t.textContent.replace(/[^0-9.]/g,''); if(m) LAST[k]=parseFloat(m); }
  });
  painted=true;
  load();
  setInterval(load, 20000);
  document.addEventListener('visibilitychange',function(){ if(!document.hidden) load(); });
}());
</script>
    <?php
    return ob_get_clean();
});

function mp_md_commod_row_html($r, $spk) {
    $up  = ($r['chgPct'] ?? 0) >= 0;
    $pos = $r['w52pos'];
    $fmt = function ($v) {
        if ($v === null) return '—';
        if ($v >= 1000) return number_format($v, 0);
        if ($v >= 10)   return number_format($v, 2);
        return number_format($v, 3);
    };
    $spkSvg = '';
    if (is_array($spk) && count($spk) > 1) {
        $mn = min($spk); $mx = max($spk); if ($mn == $mx) { $mn -= 1; $mx += 1; }
        $n = count($spk); $d = '';
        foreach ($spk as $i => $val) {
            $x = $i / ($n - 1) * 90;
            $y = 26 - ($val - $mn) / ($mx - $mn) * 26;
            $d .= ($i ? 'L' : 'M') . round($x, 1) . ' ' . round($y, 1) . ' ';
        }
        $col = end($spk) >= $spk[0] ? '#16a34a' : '#dc2626';
        $spkSvg = '<svg width="90" height="26" viewBox="0 0 90 26"><path d="' . esc_attr($d) . '" fill="none" stroke="' . $col . '" stroke-width="1.5"/></svg>';
    }
    ?>
<tr data-key="<?php echo esc_attr($r['key']); ?>" data-group="<?php echo esc_attr($r['group']); ?>" data-sym="<?php echo esc_attr($r['symbol']); ?>" data-cc="<?php echo esc_attr(mp_candle_key_for($r['key'])); ?>">
  <td><span class="mp-commod__name"><?php echo esc_html($r['label']); ?><small><?php echo esc_html(str_replace('=F', ' futures', $r['symbol'])); ?></small></span></td>
  <td class="num" data-role="price"><?php echo $fmt($r['price']); ?> <span class="mp-commod__inr" style="font-size:11px"><?php echo esc_html($r['currency'] ?: 'USD'); ?></span></td>
  <td class="num mp-commod__inr"><?php echo $r['inr'] !== null ? '&#8377;' . number_format($r['inr']) . '<span style="font-size:10px"> ' . esc_html($r['inrUnit']) . '</span>' : '—'; ?></td>
  <td class="num mp-commod__chg <?php echo $up ? 'up' : 'dn'; ?>"><?php echo $r['chgPct'] === null ? '—' : (($up ? '+' : '') . $r['chgPct'] . '%'); ?></td>
  <td><?php if ($pos === null) : ?><span class="mp-commod__w52-lbl">—</span><?php else : ?>
    <div class="mp-commod__w52"><span class="mp-commod__w52-lbl"><?php echo $fmt($r['w52_low']); ?></span>
      <span class="mp-commod__w52-track"><span class="mp-commod__w52-dot" style="left:<?php echo (int) $pos; ?>%"></span></span>
      <span class="mp-commod__w52-lbl"><?php echo $fmt($r['w52_high']); ?></span></div>
  <?php endif; ?></td>
  <td class="spk"><?php echo $spkSvg; ?></td>
</tr>
    <?php
}


/* ============================================================================
 * CANDLESTICK CHARTS (v1.9.0) — [mp_candle_chart] / [mp_tv_chart] (alias)
 * TradingView-style candles + volume via the MIT-licensed lightweight-charts
 * library, fed by our own cached market data (Yahoo v8 OHLCV). Works for
 * Indian indices/stocks (.NS / ^NSEI ...), MCX-style INR-converted bullion,
 * global commodities, FX and crypto. Timeframe toggle, symbol switcher,
 * theme-aware, lazy-loaded. No third-party symbol restrictions.
 * ==========================================================================*/

const MP_LWC_VER = '4.1.3';

/** key => [yahoo symbol, label, inr-mode|null]  (inr-mode: 'oz10g' | 'ozkg') */
function mp_candle_symbol_map() {
    return array(
        'gold'      => array('GC=F',        'Gold (INR/10g)', 'oz10g'),
        'silver'    => array('SI=F',        'Silver (INR/kg)', 'ozkg'),
        'crude'     => array('CL=F',        'Crude Oil WTI ($)', null),
        'wti'       => array('CL=F',        'Crude Oil WTI ($)', null),
        'brent'     => array('BZ=F',        'Brent Crude ($)',   null),
        'natgas'    => array('NG=F',        'Natural Gas ($)',   null),
        'gasoline'  => array('RB=F',        'Gasoline ($)',      null),
        'heatoil'   => array('HO=F',        'Heating Oil ($)',   null),
        'platinum'  => array('PL=F',        'Platinum ($)',      null),
        'palladium' => array('PA=F',        'Palladium ($)',     null),
        'copper'    => array('HG=F',        'Copper ($)',        null),
        'aluminium' => array('ALI=F',       'Aluminium ($)',     null),
        'corn'      => array('ZC=F',        'Corn',              null),
        'wheat'     => array('ZW=F',        'Wheat',             null),
        'soybean'   => array('ZS=F',        'Soybeans',          null),
        'coffee'    => array('KC=F',        'Coffee',            null),
        'sugar'     => array('SB=F',        'Sugar',             null),
        'cotton'    => array('CT=F',        'Cotton',            null),
        'nifty'     => array('^NSEI',       'Nifty 50',          null),
        'sensex'    => array('^BSESN',      'Sensex',            null),
        'banknifty' => array('^NSEBANK',    'Bank Nifty',        null),
        'niftyit'   => array('^CNXIT',      'Nifty IT',          null),
        'niftyauto' => array('^CNXAUTO',    'Nifty Auto',        null),
        'niftyfmcg' => array('^CNXFMCG',    'Nifty FMCG',        null),
        'niftypharma' => array('^CNXPHARMA','Nifty Pharma',      null),
        'niftymetal'  => array('^CNXMETAL', 'Nifty Metal',       null),
        'niftyenergy' => array('^CNXENERGY','Nifty Energy',      null),
        'niftyrealty' => array('^CNXREALTY','Nifty Realty',      null),
        'usdinr'    => array('INR=X',       'USD / INR',         null),
        'bitcoin'   => array('BTC-USD',     'Bitcoin ($)',       null),
        'ethereum'  => array('ETH-USD',     'Ethereum ($)',      null),
        'reliance'  => array('RELIANCE.NS', 'Reliance',          null),
        'tcs'       => array('TCS.NS',      'TCS',               null),
        'infy'      => array('INFY.NS',     'Infosys',           null),
        'hdfcbank'  => array('HDFCBANK.NS', 'HDFC Bank',         null),
        'tmpv'      => array('TMPV.NS',     'Tata Motors PV',    null),
        'tmcv'      => array('TMCV.NS',     'Tata Motors CV',    null),
        'ltm'       => array('LTM.NS',      'LTIMindtree (LTM)', null),
        'sp500'     => array('^GSPC',       'S&P 500',           null),
        'nasdaq'    => array('^IXIC',       'Nasdaq',            null),
        'dow'       => array('^DJI',        'Dow Jones',         null),
        'aapl'      => array('AAPL',        'Apple',             null),
        'msft'      => array('MSFT',        'Microsoft',         null),
        'googl'     => array('GOOGL',       'Alphabet',          null),
        'amzn'      => array('AMZN',        'Amazon',            null),
        'nvda'      => array('NVDA',        'Nvidia',            null),
        'meta'      => array('META',        'Meta Platforms',    null),
        'tsla'      => array('TSLA',        'Tesla',             null),
        'jpm'       => array('JPM',         'JPMorgan Chase',    null),
        'v'         => array('V',           'Visa',              null),
        'wmt'       => array('WMT',         'Walmart',           null),
        'xom'       => array('XOM',         'ExxonMobil',        null),
        'unh'       => array('UNH',         'UnitedHealth',      null),
    );
}

/** "gold" -> [GC=F, Gold ..., oz10g]; "RELIANCE.NS"/"^NSEI"/"BTC-USD" passthrough; "NSE:TCS" -> TCS.NS; "tcs" -> TCS.NS */
function mp_candle_resolve($s) {
    $s = trim((string) $s);
    if ($s === '') return array('^NSEI', 'Nifty 50', null);
    $map = mp_candle_symbol_map();
    $k = strtolower($s);
    if (isset($map[$k])) return $map[$k];
    if (stripos($s, 'NSE:') === 0) { $t = substr($s, 4) . '.NS'; return array($t, strtoupper(substr($s, 4)), null); }
    if (stripos($s, 'BSE:') === 0) { $t = substr($s, 4) . '.BO'; return array($t, strtoupper(substr($s, 4)), null); }
    if (preg_match('/[.\^=]|-USD$/', $s)) return array($s, $s, null);
    return array(strtoupper($s) . '.NS', strtoupper($s), null);
}

function mp_candle_tf_map($tf) {
    $m = array(
        '5m'  => array('range' => '1d',  'interval' => '5m',  'ttl' => 60),
        '15m' => array('range' => '5d',  'interval' => '15m', 'ttl' => 120),
        '1h'  => array('range' => '1mo', 'interval' => '60m', 'ttl' => 300),
        '1D'  => array('range' => '1y',  'interval' => '1d',  'ttl' => 1800),
        '1W'  => array('range' => '5y',  'interval' => '1wk', 'ttl' => 21600),
    );
    return isset($m[$tf]) ? $m[$tf] : $m['1D'];
}

/** OHLCV bars for a symbol/key + timeframe. Cached per (symbol,tf). */
function mp_candle_ohlc($symbol_or_key, $tf) {
    list($sym, $label, $inr) = mp_candle_resolve($symbol_or_key);
    $cfg = mp_candle_tf_map($tf);
    $ck  = 'mp_ohlc_' . md5($sym . '|' . $tf);
    $c   = get_transient($ck);
    if (is_array($c)) return $c;

    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($sym)
         . '?range=' . $cfg['range'] . '&interval=' . $cfg['interval'];
    $res = wp_remote_get($url, array('timeout' => 9, 'headers' => array(
        'User-Agent' => 'Mozilla/5.0 (compatible; MoneyPuran/1.0; +https://moneypuran.com)',
        'Accept'     => 'application/json',
    )));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return array('symbol' => $sym, 'label' => $label, 'inr' => (bool) $inr, 'tf' => $tf, 'bars' => array());
    }
    $j = json_decode(wp_remote_retrieve_body($res), true);
    $r = isset($j['chart']['result'][0]) ? $j['chart']['result'][0] : null;
    $ts = $r['timestamp'] ?? array();
    $q  = $r['indicators']['quote'][0] ?? array();
    $O = $q['open'] ?? array(); $H = $q['high'] ?? array(); $L = $q['low'] ?? array();
    $C = $q['close'] ?? array(); $V = $q['volume'] ?? array();

    $factor = 1.0;
    if ($inr) {
        $usdinr = mp_candle_usdinr();
        $factor = ($inr === 'ozkg') ? ($usdinr / 31.1035 * 1000) : ($usdinr / 31.1035 * 10);
        $factor *= (float) apply_filters('mp_gold_india_multiplier', 1.13);
    }
    $bars = array();
    foreach ($ts as $i => $t) {
        if (!isset($O[$i], $H[$i], $L[$i], $C[$i]) || $C[$i] === null || $O[$i] === null) continue;
        $bars[] = array(
            (int) $t,
            round($O[$i] * $factor, $inr ? 0 : 4),
            round($H[$i] * $factor, $inr ? 0 : 4),
            round($L[$i] * $factor, $inr ? 0 : 4),
            round($C[$i] * $factor, $inr ? 0 : 4),
            isset($V[$i]) && $V[$i] !== null ? (int) $V[$i] : 0,
        );
    }
    $out = array('symbol' => $sym, 'label' => $label, 'inr' => (bool) $inr, 'tf' => $tf, 'bars' => $bars);
    if ($bars) set_transient($ck, $out, $cfg['ttl']);
    return $out;
}

function mp_candle_usdinr() {
    $g = get_transient(MP_MD_GRP_KEY);
    if (is_array($g)) {
        foreach (($g['currencies'] ?? array()) as $r) {
            if ($r['sym'] === 'INR=X' && !empty($r['price'])) return (float) $r['price'];
        }
    }
    $q = mp_md_yahoo_one('INR=X');
    return $q ? (float) $q['price'] : 88.0;
}

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/ohlc', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'args' => array('symbol' => array('default' => 'nifty'), 'tf' => array('default' => '1D')),
        'callback' => function (WP_REST_Request $req) {
            $d = mp_candle_ohlc($req->get_param('symbol'), $req->get_param('tf'));
            if (empty($d['bars'])) return new WP_Error('nodata', 'No chart data', array('status' => 502));
            $resp = rest_ensure_response($d);
            $resp->header('Cache-Control', 'public, max-age=30, s-maxage=45, stale-while-revalidate=120');
            return $resp;
        },
    ));
});

function mp_candle_assets_html() {
    static $done = false;
    if ($done) return '';
    $done = true;
    $lib = 'https://cdn.jsdelivr.net/npm/lightweight-charts@' . MP_LWC_VER . '/dist/lightweight-charts.standalone.production.js';
    ob_start(); ?>
<script>
(function(){
  if (window.__mpLWC) return;
  var L = window.__mpLWC = { loaded:false, loading:false, q:[], src:<?php echo wp_json_encode($lib); ?>, charts:[] };

  L.load = function(cb){
    if (L.loaded && window.LightweightCharts) return cb();
    L.q.push(cb);
    if (L.loading) return;
    L.loading = true;
    var s = document.createElement('script');
    s.src = L.src; s.async = true;
    s.onload = function(){ L.loaded = true; L.q.splice(0).forEach(function(f){ try{ f(); }catch(e){} }); };
    s.onerror = function(){ L.loading = false; };
    document.head.appendChild(s);
  };
  L.theme = function(){
    var t = document.documentElement.getAttribute('data-theme');
    if (t === 'dark' || t === 'light') return t;
    return (window.matchMedia && matchMedia('(prefers-color-scheme:dark)').matches) ? 'dark' : 'light';
  };
  L.palette = function(){
    return L.theme() === 'dark'
      ? { bg:'#111827', text:'#94a3b8', grid:'rgba(255,255,255,.06)', border:'rgba(255,255,255,.12)' }
      : { bg:'#ffffff', text:'#64748b', grid:'#eef1f4', border:'#e2e8f0' };
  };
  function retheme(){ L.charts.forEach(function(c){ if (c.apply) c.apply(); }); }
  new MutationObserver(retheme).observe(document.documentElement, { attributes:true, attributeFilter:['data-theme'] });
  if (window.matchMedia) { try { matchMedia('(prefers-color-scheme:dark)').addEventListener('change', retheme); } catch(e){} }

  var SPAN = { '5m':'today', '15m':'5 days', '1h':'1 month', '1D':'1 year', '1W':'5 years' };
  var INTRA = { '5m':1, '15m':1, '1h':1 };

  L.attach = function(root){
    if (!root || root.__mpcc) return;
    root.__mpcc = 1;
    var box = root.querySelector('.mp-cc__box');
    if (!box) return;
    var endpoint = root.getAttribute('data-endpoint') || ((location.origin||'') + '/wp-json/mp/v1/ohlc');
    var rec = { symbol: root.getAttribute('data-symbol') || 'nifty', tf: root.getAttribute('data-tf') || '1D', chart:null, candle:null, vol:null, _go:0 };

    rec.apply = function(){
      if (!rec.chart) return;
      var p = L.palette();
      rec.chart.applyOptions({
        layout:{ background:{ color:p.bg }, textColor:p.text },
        grid:{ vertLines:{ color:p.grid }, horzLines:{ color:p.grid } },
        rightPriceScale:{ borderColor:p.border },
        timeScale:{ borderColor:p.border }
      });
    };
    rec.build = function(){
      if (!window.LightweightCharts || rec.chart) return;
      box.innerHTML = '';
      var p = L.palette();
      rec.chart = LightweightCharts.createChart(box, {
        autoSize:true,
        layout:{ background:{ color:p.bg }, textColor:p.text, fontFamily:'inherit' },
        grid:{ vertLines:{ color:p.grid }, horzLines:{ color:p.grid } },
        rightPriceScale:{ borderColor:p.border, scaleMargins:{ top:0.08, bottom:0.28 } },
        timeScale:{ borderColor:p.border, timeVisible:!!INTRA[rec.tf], secondsVisible:false },
        crosshair:{ mode:1 },
        localization:{ locale:'en-IN' }
      });
      rec.candle = rec.chart.addCandlestickSeries({
        upColor:'#16a34a', downColor:'#dc2626', borderVisible:false,
        wickUpColor:'#16a34a', wickDownColor:'#dc2626'
      });
      rec.vol = rec.chart.addHistogramSeries({ priceFormat:{ type:'volume' }, priceScaleId:'v' });
      rec.chart.priceScale('v').applyOptions({ scaleMargins:{ top:0.82, bottom:0 } });
      L.charts.push(rec);
      rec.fetch();
    };
    rec.fetch = function(){
      if (!rec.candle) return;
      box.style.opacity = '.5';
      fetch(endpoint + '?symbol=' + encodeURIComponent(rec.symbol) + '&tf=' + encodeURIComponent(rec.tf), { credentials:'omit' })
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){
          box.style.opacity = '1';
          if (!d || !d.bars || !d.bars.length) {
            var m0 = root.querySelector('[data-role=meta]'); if (m0) m0.textContent = 'Chart data unavailable right now.';
            return;
          }
          var c = [], v = [];
          d.bars.forEach(function(b){
            c.push({ time:b[0], open:b[1], high:b[2], low:b[3], close:b[4] });
            v.push({ time:b[0], value:b[5], color: b[4] >= b[1] ? 'rgba(22,163,74,.5)' : 'rgba(220,38,38,.5)' });
          });
          rec.candle.setData(c);
          rec.vol.setData(v);
          rec.chart.timeScale().fitContent();
          var meta = root.querySelector('[data-role=meta]');
          if (meta && c.length) {
            var last = c[c.length-1].close;
            var prev = c.length > 1 ? c[c.length-2].close : c[0].open;
            var chg = prev ? ((last-prev)/prev*100) : 0;
            var spanChg = c[0].open ? ((last-c[0].open)/c[0].open*100) : 0;
            var sym = d.inr ? '₹' : '';
            var dp = d.inr ? 0 : (last < 20 ? 4 : 2);
            var lastTxt = last.toLocaleString('en-IN', { minimumFractionDigits:dp, maximumFractionDigits:dp });
            meta.innerHTML = '<b>' + sym + lastTxt + '</b> '
              + '<span style="color:' + (chg>=0?'#16a34a':'#dc2626') + ';font-weight:600">'
              + (chg>=0?'▲ ':'▼ ') + Math.abs(chg).toFixed(2) + '%</span> '
              + '<span style="opacity:.6">· ' + (spanChg>=0?'+':'') + spanChg.toFixed(1) + '% over ' + (SPAN[d.tf]||d.tf) + '</span>';
          }
        })
        .catch(function(){ box.style.opacity = '1'; });
    };
    rec.setSymbol = function(s){ rec.symbol = s; L.load(rec.fetch); };
    rec.setTf = function(t){
      rec.tf = t;
      if (rec.chart) rec.chart.applyOptions({ timeScale:{ timeVisible:!!INTRA[t] } });
      L.load(rec.fetch);
    };

    function near(){
      var r = box.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      return r.width > 0 && r.bottom > -600 && r.top < vh + 600;
    }
    function go(){ if (rec.chart || rec._go) return; rec._go = 1; cleanup(); L.load(rec.build); }
    function onScroll(){ if (near()) go(); }
    function cleanup(){ window.removeEventListener('scroll', onScroll); window.removeEventListener('resize', onScroll); }
    if ('IntersectionObserver' in window){
      var io = new IntersectionObserver(function(es){ es.forEach(function(e){ if (e.isIntersecting){ io.disconnect(); go(); } }); }, { rootMargin:'600px' });
      io.observe(box);
    }
    window.addEventListener('scroll', onScroll, { passive:true });
    window.addEventListener('resize', onScroll);
    setTimeout(function(){ if (near()) go(); }, 200);
    setTimeout(function(){ if (near()) go(); }, 1000);

    // wire controls
    var titleEl = root.querySelector('[data-role=title]');
    var pills = root.querySelector('[data-role=pills]');
    if (pills) pills.addEventListener('click', function(e){
      var b = e.target.closest('button[data-s]'); if (!b) return;
      [].forEach.call(pills.querySelectorAll('button'), function(x){ x.classList.remove('on'); });
      b.classList.add('on');
      if (titleEl) titleEl.textContent = b.textContent;
      go(); rec.setSymbol(b.getAttribute('data-s'));
    });
    var tfWrap = root.querySelector('[data-role=tf]');
    if (tfWrap) tfWrap.addEventListener('click', function(e){
      var b = e.target.closest('button[data-t]'); if (!b) return;
      [].forEach.call(tfWrap.querySelectorAll('button'), function(x){ x.classList.remove('on'); });
      b.classList.add('on');
      go(); rec.setTf(b.getAttribute('data-t'));
    });
    root.__mpccRec = rec;
    root.__mpccGo = go;
  };

  L.scan = function(force){
    var list = document.querySelectorAll('.mp-cc');
    for (var i = 0; i < list.length; i++){
      var el = list[i], fresh = !el.__mpcc;
      L.attach(el);
      if (force && fresh && el.__mpccGo) el.__mpccGo();
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', L.scan);
  else L.scan();
  window.addEventListener('load', L.scan);
  var n = 0, iv = setInterval(function(){ L.scan(); if (++n > 20) clearInterval(iv); }, 700);
}());
</script>
<style>
.mp-cc{margin:22px 0}
.mp-cc__head{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:baseline;margin-bottom:8px}
.mp-cc__title{font-weight:700;font-size:15px}
.mp-cc__meta{font-size:13px;color:var(--mp-muted,#64748b)}
.mp-cc__meta b{font-size:16px;color:var(--mp-ink,#0f172a)}
html[data-theme="dark"] .mp-cc__meta b{color:#f1f5f9}
.mp-cc__pills,.mp-cc__tf{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.mp-cc__pills button,.mp-cc__tf button{border:1px solid var(--mp-border,#cbd5e1);background:transparent;color:inherit;padding:5px 11px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer}
.mp-cc__pills button.on,.mp-cc__tf button.on{background:var(--mp-brand,#0057ff);border-color:var(--mp-brand,#0057ff);color:#fff}
.mp-cc__box{height:clamp(340px,58vh,480px);width:100%;border:1px solid var(--mp-border,#e5e7eb);border-radius:12px;overflow:hidden;background:var(--mp-surface,#fff)}
.mp-cc__note{font-size:11px;color:var(--mp-muted,#64748b);margin:8px 0 0}
@media(max-width:600px){.mp-cc__box{height:340px}}
html[data-theme="dark"] .mp-cc__box{border-color:rgba(255,255,255,.08);background:#111827}
</style>
    <?php
    return ob_get_clean();
}
add_action('wp_footer', function () { echo mp_candle_assets_html(); }, 5);

function mp_candle_chart_shortcode($atts) {
    $a = shortcode_atts(array(
        'symbol'   => 'nifty',
        'symbols'  => '',
        'tf'       => '1D',
        'interval' => '',
        'height'   => '',
    ), $atts, 'mp_candle_chart');

    $tf = in_array($a['tf'], array('5m', '15m', '1h', '1D', '1W'), true) ? $a['tf'] : '1D';
    list($ySym, $label, ) = mp_candle_resolve($a['symbol']);

    $pills = array();
    if ($a['symbols'] !== '') {
        foreach (explode(',', $a['symbols']) as $s) {
            if (trim($s) === '') continue;
            $r = mp_candle_resolve($s);
            $pills[] = array(strtolower(trim($s)), $r[1]);
        }
    }
    $h = $a['height'] !== '' ? (int) preg_replace('/[^0-9]/', '', $a['height']) : 0;

    ob_start(); ?>
<div class="mp-cc" data-symbol="<?php echo esc_attr(strtolower($a['symbol'])); ?>" data-tf="<?php echo esc_attr($tf); ?>" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/ohlc')); ?>">
  <div class="mp-cc__head">
    <span class="mp-cc__title" data-role="title"><?php echo esc_html($label); ?></span>
    <span class="mp-cc__meta" data-role="meta">Loading&hellip;</span>
  </div>
  <?php if ($pills) : ?>
  <div class="mp-cc__pills" data-role="pills">
    <?php foreach ($pills as $i => $p) : ?>
    <button type="button" data-s="<?php echo esc_attr($p[0]); ?>" class="<?php echo $i === 0 ? 'on' : ''; ?>"><?php echo esc_html($p[1]); ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="mp-cc__tf" data-role="tf">
    <?php foreach (array('5m', '15m', '1h', '1D', '1W') as $t) : ?>
    <button type="button" data-t="<?php echo esc_attr($t); ?>" class="<?php echo $t === $tf ? 'on' : ''; ?>"><?php echo esc_html(strtoupper($t)); ?></button>
    <?php endforeach; ?>
  </div>
  <div class="mp-cc__box"<?php echo $h ? ' style="height:' . $h . 'px"' : ''; ?>></div>
  <p class="mp-cc__note">Candles &amp; volume from exchange data (Yahoo Finance), cached and possibly delayed. Bullion is the international price converted to an indicative INR rate. Not investment advice.</p>
</div>
<script>(function(){function s(){ if(window.__mpLWC){ window.__mpLWC.scan(); } else { setTimeout(s,150); } } s();}());</script>
    <?php
    return mp_candle_assets_html() . ob_get_clean();
}
add_shortcode('mp_candle_chart', 'mp_candle_chart_shortcode');
add_shortcode('mp_tv_chart', 'mp_candle_chart_shortcode');

/* commodities-page row expand + stock "Why?" panel look these up */
function mp_candle_key_for($commod_key) {
    $map = array(
        'wti' => 'crude', 'brent' => 'brent', 'natgas' => 'natgas', 'gasoline' => 'gasoline',
        'heatoil' => 'heatoil', 'gold' => 'gold', 'silver' => 'silver', 'platinum' => 'platinum',
        'palladium' => 'palladium', 'copper' => 'copper', 'aluminium' => 'aluminium',
        'corn' => 'corn', 'wheat' => 'wheat', 'soybean' => 'soybean', 'coffee' => 'coffee',
        'sugar' => 'sugar', 'cotton' => 'cotton',
    );
    return isset($map[$commod_key]) ? $map[$commod_key] : $commod_key;
}


/* ============================================================================
 * STOCK SCREENER (v1.10.0) — [mp_stock_screener]
 * All large-cap Indian stocks grouped by sector, each with a rules-based
 * Bullish / Neutral / Bearish trend signal, a "current scenario + global
 * impact" summary, and a click-to-open candlestick chart + plain-language
 * analysis. Signals are derived from observable price data only — never a
 * fabricated buy/sell call.
 * ==========================================================================*/

const MP_SCR_KEY  = 'mp_md_screener_v1';
const MP_SCR_LOCK = 'mp_md_screener_lock_v1';
const MP_SCR_BASE = 'mp_md_screener_base_v1';
const MP_SCR_SOFT = 90;

/** sector => [ index-symbol-for-sector-move | '', [ SYM => Name, ... ] ] */
function mp_md_screener_universe() {
    return array(
        'Banks & Financials' => array('^NSEBANK', array(
            'HDFCBANK' => 'HDFC Bank', 'ICICIBANK' => 'ICICI Bank', 'SBIN' => 'State Bank of India',
            'KOTAKBANK' => 'Kotak Mahindra Bank', 'AXISBANK' => 'Axis Bank', 'INDUSINDBK' => 'IndusInd Bank',
            'BAJFINANCE' => 'Bajaj Finance', 'BAJAJFINSV' => 'Bajaj Finserv',
        )),
        'IT & Tech' => array('^CNXIT', array(
            'TCS' => 'Tata Consultancy Services', 'INFY' => 'Infosys', 'HCLTECH' => 'HCL Technologies',
            'WIPRO' => 'Wipro', 'TECHM' => 'Tech Mahindra', 'LTM' => 'LTIMindtree (LTM)',
        )),
        'Auto' => array('^CNXAUTO', array(
            'MARUTI' => 'Maruti Suzuki', 'TMPV' => 'Tata Motors PV', 'TMCV' => 'Tata Motors CV', 'M&M' => 'Mahindra & Mahindra',
            'BAJAJ-AUTO' => 'Bajaj Auto', 'EICHERMOT' => 'Eicher Motors', 'HEROMOTOCO' => 'Hero MotoCorp',
        )),
        'FMCG & Consumer' => array('^CNXFMCG', array(
            'HINDUNILVR' => 'Hindustan Unilever', 'ITC' => 'ITC', 'NESTLEIND' => 'Nestle India',
            'BRITANNIA' => 'Britannia', 'TATACONSUM' => 'Tata Consumer', 'TITAN' => 'Titan Company',
        )),
        'Pharma & Healthcare' => array('^CNXPHARMA', array(
            'SUNPHARMA' => 'Sun Pharma', 'DRREDDY' => "Dr Reddy's", 'CIPLA' => 'Cipla',
            'DIVISLAB' => "Divi's Labs", 'APOLLOHOSP' => 'Apollo Hospitals', 'TORNTPHARM' => 'Torrent Pharma',
        )),
        'Metals & Mining' => array('^CNXMETAL', array(
            'TATASTEEL' => 'Tata Steel', 'JSWSTEEL' => 'JSW Steel', 'HINDALCO' => 'Hindalco',
            'VEDL' => 'Vedanta', 'JINDALSTEL' => 'Jindal Steel', 'COALINDIA' => 'Coal India',
        )),
        'Energy & Power' => array('^CNXENERGY', array(
            'RELIANCE' => 'Reliance Industries', 'ONGC' => 'ONGC', 'NTPC' => 'NTPC',
            'POWERGRID' => 'Power Grid', 'BPCL' => 'BPCL', 'TATAPOWER' => 'Tata Power',
        )),
        'Infra & Realty' => array('^CNXREALTY', array(
            'LT' => 'Larsen & Toubro', 'ADANIPORTS' => 'Adani Ports', 'ULTRACEMCO' => 'UltraTech Cement',
            'DLF' => 'DLF', 'GRASIM' => 'Grasim', 'ADANIENT' => 'Adani Enterprises',
        )),
        'Telecom & Others' => array('', array(
            'BHARTIARTL' => 'Bharti Airtel', 'ASIANPAINT' => 'Asian Paints', 'TRENT' => 'Trent',
            'DMART' => 'Avenue Supermarts', 'INDIGO' => 'InterGlobe (IndiGo)', 'DABUR' => 'Dabur',
        )),
    );
}

function mp_md_sector_note($sector) {
    $n = array(
        'Banks & Financials'  => 'Banks and financials take their cue from interest-rate expectations, liquidity and foreign fund flows.',
        'IT & Tech'           => 'IT services track US and European tech spending; a weaker rupee supports their margins.',
        'Auto'                => 'Autos are sensitive to crude and metal input costs, financing rates and rural demand.',
        'FMCG & Consumer'     => 'Consumer staples are defensive — rural demand and input costs matter more than global cues.',
        'Pharma & Healthcare' => 'Pharma exporters are driven by US generic pricing and FDA actions; hospitals by domestic demand.',
        'Metals & Mining'     => 'Metals follow global growth, the US dollar and China demand.',
        'Energy & Power'      => 'Oil & gas names move with crude; power utilities are steadier and rate-sensitive.',
        'Infra & Realty'      => 'Infrastructure and real estate are rate-sensitive and benefit when borrowing costs ease.',
        'Telecom & Others'    => 'These are largely domestic-demand stories with limited direct global linkage.',
    );
    return isset($n[$sector]) ? $n[$sector] : '';
}

/** Batch quote via Yahoo v8 spark. Small chunks, alternating hosts, gentle
 *  pacing + one retry so Yahoo's per-IP rate limiting doesn't drop batches. */
function mp_md_yahoo_spark($symbols) {
    $out = array();
    $chunks = array_chunk($symbols, 8);
    foreach ($chunks as $ci => $chunk) {
        $list = implode(',', array_map('rawurlencode', $chunk));
        $host = ($ci % 2) ? 'query2' : 'query1';
        $url  = 'https://' . $host . '.finance.yahoo.com/v8/finance/spark?symbols=' . $list . '&range=1d&interval=1d';
        $j = null;
        for ($try = 0; $try < 2; $try++) {
            $res = wp_remote_get($url, array('timeout' => 6, 'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'Accept'     => 'application/json',
            )));
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $j = json_decode(wp_remote_retrieve_body($res), true);
                if (is_array($j) && $j) break;
            }
            $j = null;
            usleep(900000);
        }
        if ($ci < count($chunks) - 1) usleep(350000);
        if (!is_array($j)) continue;
        // Current shape: { "<SYM>": { symbol, close:[...], chartPreviousClose, previousClose, ... }, ... }
        $rows = isset($j['spark']['result']) ? $j['spark']['result'] : $j;
        foreach ($rows as $key => $r) {
            $sym = isset($r['symbol']) ? $r['symbol'] : $key;
            $closeArr = null;
            if (isset($r['close']) && is_array($r['close'])) {
                $closeArr = $r['close'];
            } elseif (isset($r['response'][0]['indicators']['quote'][0]['close'])) {
                $closeArr = $r['response'][0]['indicators']['quote'][0]['close'];
            }
            $prev = null;
            foreach (array('previousClose', 'chartPreviousClose') as $pk) {
                if (isset($r[$pk]) && $r[$pk] !== null) { $prev = (float) $r[$pk]; break; }
                if (isset($r['response'][0]['meta'][$pk]) && $r['response'][0]['meta'][$pk] !== null) { $prev = (float) $r['response'][0]['meta'][$pk]; break; }
            }
            $meta = $r['response'][0]['meta'] ?? array();
            $price = null;
            if (isset($meta['regularMarketPrice'])) {
                $price = (float) $meta['regularMarketPrice'];
            } elseif (is_array($closeArr)) {
                for ($i = count($closeArr) - 1; $i >= 0; $i--) {
                    if ($closeArr[$i] !== null) { $price = (float) $closeArr[$i]; break; }
                }
            }
            if ($price === null) continue;
            $out[$sym] = array(
                'price'  => round($price, 2),
                'prev'   => $prev,
                'chgPct' => ($prev && $prev != 0) ? round(($price - $prev) / $prev * 100, 2) : null,
                'w52pos' => null,
            );
        }
    }
    return $out;
}

function mp_md_screener_signal($chgPct, $niftyChg, $sectorChg, $w52pos) {
    $s = 0;
    if ($chgPct !== null) $s += ($chgPct >= 1 ? 1 : 0) - ($chgPct <= -1 ? 1 : 0);
    if ($chgPct !== null && $niftyChg !== null) {
        $rel = $chgPct - $niftyChg;
        $s += ($rel >= 0.75 ? 1 : 0) - ($rel <= -0.75 ? 1 : 0);
    }
    if ($sectorChg !== null) $s += ($sectorChg >= 0.6 ? 1 : 0) - ($sectorChg <= -0.6 ? 1 : 0);
    if ($w52pos !== null)    $s += ($w52pos >= 80 ? 1 : 0) - ($w52pos <= 20 ? 1 : 0);
    return array($s >= 2 ? 'Bullish' : ($s <= -2 ? 'Bearish' : 'Neutral'), $s);
}

function mp_md_screener_scenario() {
    $idx = mp_md_get_indices();
    $grp = mp_md_get_groups();
    $I = array();
    foreach (($idx['indices'] ?? array()) as $k => $v) $I[$v['sym']] = $v;
    $pick = function ($arr, $sym) {
        foreach ($arr as $r) if (($r['sym'] ?? '') === $sym) return $r;
        return null;
    };
    $world = $grp['world'] ?? array();
    $curr  = $grp['currencies'] ?? array();
    $comm  = $grp['commodities'] ?? array();

    $nifty = $I['^NSEI']    ?? null;
    $sensex= $I['^BSESN']   ?? null;
    $bank  = $I['^NSEBANK'] ?? null;
    $dji   = $pick($world, '^DJI');
    $ixic  = $pick($world, '^IXIC');
    $gspc  = $pick($world, '^GSPC');
    $brent = $pick($comm, 'BZ=F');
    $wti   = $pick($comm, 'CL=F');
    $inr   = $pick($curr, 'INR=X');

    $usAvg = array();
    foreach (array($dji, $ixic, $gspc) as $x) if ($x && $x['chgPct'] !== null) $usAvg[] = $x['chgPct'];
    $usMean = $usAvg ? array_sum($usAvg) / count($usAvg) : null;
    $usLine = $usMean === null ? 'US markets data is loading'
        : ($usMean >= 0.3 ? 'Wall Street closed higher overnight'
        : ($usMean <= -0.3 ? 'Wall Street closed lower overnight'
        : 'Wall Street ended little changed overnight'));

    $crude = $brent ?: $wti;
    $crudeLine = $crude ? ('is near $' . number_format($crude['price'], 1)
        . ($crude['chgPct'] !== null ? ' (' . ($crude['chgPct'] >= 0 ? '+' : '') . $crude['chgPct'] . '%)' : '')) : 'data loading';
    $inrLine = $inr ? ('is at ' . "\xE2\x82\xB9" . number_format($inr['price'], 2)
        . ($inr['chgPct'] !== null ? ' (' . ($inr['chgPct'] >= 0 ? '+' : '') . $inr['chgPct'] . '%)' : '')) : 'data loading';

    $gold = $pick($comm, 'GC=F');
    $vixq = mp_md_yahoo_one('^INDIAVIX');
    $one  = function ($x) { return $x ? array('level' => $x['price'], 'chg' => $x['chgPct']) : null; };

    return array(
        'nifty'     => $one($nifty),
        'sensex'    => $one($sensex),
        'banknifty' => $one($bank),
        'niftyChg'  => $nifty['chgPct'] ?? null,
        'vix'       => $vixq ? array('level' => round($vixq['price'], 2), 'chg' => $vixq['chgPct']) : null,
        'usLine'    => $usLine,
        'crudeLine' => $crudeLine,
        'inrLine'   => $inrLine,
        'global'    => array(
            'dow'    => $one($dji),
            'nasdaq' => $one($ixic),
            'sp500'  => $one($gspc),
            'crude'  => $crude ? array('level' => $crude['price'], 'chg' => $crude['chgPct'], 'name' => ($brent ? 'Brent' : 'WTI')) : null,
            'gold'   => $one($gold),
            'usdinr' => $one($inr),
        ),
        'asOf'      => gmdate('c'),
    );
}

function mp_md_screener_build() {
    $deadline = microtime(true) + 20;
    $universe = mp_md_screener_universe();

    $all = array();
    foreach ($universe as $sec => $def) foreach ($def[1] as $sym => $name) $all[$sym] = $name;
    $nsSyms = array_map(function ($s) { return $s . '.NS'; }, array_keys($all));
    $spark  = mp_md_yahoo_spark($nsSyms);
    $w52map = function_exists('mp_md_scr_52w') ? mp_md_scr_52w($nsSyms) : array();

    $grp = mp_md_get_groups();
    $secBy = array();
    foreach (($grp['sectors'] ?? array()) as $r) $secBy[$r['sym']] = $r['chgPct'];
    $idx = mp_md_get_indices();
    $niftyChg = null;
    foreach (($idx['indices'] ?? array()) as $v) if ($v['sym'] === '^NSEI') $niftyChg = $v['chgPct'];

    // Start-of-day baseline of signals, so "changed today" is stable (not 3-min noise).
    $istDate  = gmdate('Y-m-d', time() + 19800);
    $baseline = get_transient(MP_SCR_BASE);
    $baseSigs = (is_array($baseline) && ($baseline['date'] ?? '') === $istDate) ? $baseline['sigs'] : null;

    $out = array();
    $curSigs = array();
    $movers  = array();
    foreach ($universe as $sec => $def) {
        list($secIdxSym, $stocks) = $def;
        $secChg = ($secIdxSym !== '' && isset($secBy[$secIdxSym])) ? $secBy[$secIdxSym] : null;
        $rows = array();
        foreach ($stocks as $sym => $name) {
            $q = $spark[$sym . '.NS'] ?? null;
            if (!$q) continue;
            $w = $w52map[$sym . '.NS'] ?? null;
            if ($w && $w['max'] > $w['min']) {
                $q['w52pos'] = max(0, min(100, (int) round(($q['price'] - $w['min']) / ($w['max'] - $w['min']) * 100)));
            }
            list($sig, $score) = mp_md_screener_signal($q['chgPct'], $niftyChg, $secChg, $q['w52pos']);
            $curSigs[$sym] = $sig;
            $prevSig = $baseSigs[$sym] ?? null;
            $row = array(
                'sym' => $sym, 'name' => $name, 'price' => $q['price'], 'chgPct' => $q['chgPct'],
                'sector' => $sec, 'w52pos' => $q['w52pos'], 'signal' => $sig, 'score' => $score,
                'prevSignal' => $prevSig, 'changed' => ($prevSig !== null && $prevSig !== $sig),
            );
            $rows[] = $row;
            if ($q['chgPct'] !== null) $movers[] = $row;
        }
        usort($rows, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $bull = count(array_filter($rows, function ($r) { return $r['signal'] === 'Bullish'; }));
        $bear = count(array_filter($rows, function ($r) { return $r['signal'] === 'Bearish'; }));
        $out[$sec] = array(
            'index'  => $secIdxSym, 'chg' => $secChg, 'note' => mp_md_sector_note($sec),
            'bull'   => $bull, 'bear' => $bear, 'total' => count($rows), 'stocks' => $rows,
        );
        if (microtime(true) > $deadline) break;
    }

    if (!$baseSigs && $curSigs) {
        set_transient(MP_SCR_BASE, array('date' => $istDate, 'sigs' => $curSigs), 2 * DAY_IN_SECONDS);
    }

    usort($movers, function ($a, $b) { return ($b['chgPct'] ?? 0) <=> ($a['chgPct'] ?? 0); });
    $changes = array_values(array_filter($movers, function ($r) { return !empty($r['changed']); }));

    // sector leaderboard
    $lead = array();
    foreach ($out as $sec => $info) if ($info['chg'] !== null) $lead[$sec] = $info['chg'];
    arsort($lead);

    return array(
        'sectors'  => $out,
        'scenario' => mp_md_screener_scenario(),
        'movers'   => array(
            'up'   => array_slice($movers, 0, 5),
            'down' => array_slice(array_reverse($movers), 0, 5),
        ),
        'changes'  => array_slice($changes, 0, 10),
        'leaders'  => $lead,
        '_at'      => time(),
        'asOf'     => gmdate('c'),
    );
}

function mp_md_get_screener() {
    $snap = get_transient(MP_SCR_KEY);
    $age  = is_array($snap) && !empty($snap['_at']) ? (time() - $snap['_at']) : PHP_INT_MAX;
    if (is_array($snap) && $age < MP_SCR_SOFT) return $snap;
    if (!get_transient(MP_SCR_LOCK)) {
        set_transient(MP_SCR_LOCK, 1, 25);
        $fresh = mp_md_screener_build();
        delete_transient(MP_SCR_LOCK);
        if (!empty($fresh['sectors'])) { set_transient(MP_SCR_KEY, $fresh, MP_MD_HARD_TTL); return $fresh; }
    }
    return is_array($snap) ? $snap : array('sectors' => array(), 'scenario' => null, 'asOf' => gmdate('c'));
}

add_action('mp_md_cron_screener', function () {
    delete_transient(MP_SCR_LOCK);
    $f = mp_md_screener_build();
    if (!empty($f['sectors'])) set_transient(MP_SCR_KEY, $f, MP_MD_HARD_TTL);
});
add_action('init', function () {
    if (!wp_next_scheduled('mp_md_cron_screener')) wp_schedule_event(time() + 70, 'mp_md_3min', 'mp_md_cron_screener');
});
add_filter('cron_schedules', function ($s) {
    if (empty($s['mp_md_3min'])) $s['mp_md_3min'] = array('interval' => 180, 'display' => 'Every 3 minutes');
    return $s;
});

/* 301s for stock pages whose ticker changed (renamed / demerged listings). */
function mp_md_stock_redirects() {
    return apply_filters('mp_md_stock_redirects', array(
        'tatamotors' => 'tmpv',   // Tata Motors demerger (2025): old listing renamed Tata Motors PV
        'ltim'       => 'ltm',     // LTIMindtree renamed LTM Limited (2026), ticker LTIM -> LTM
    ));
}
add_action('template_redirect', function () {
    if (is_admin() || !is_404()) return;
    $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if (strpos($path, 'stocks/') !== 0) return;
    $slug = substr($path, strlen('stocks/'));
    $map  = mp_md_stock_redirects();
    if (isset($map[$slug])) {
        wp_safe_redirect(home_url('/stocks/' . $map[$slug] . '/'), 301);
        exit;
    }
}, 5);

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/screener', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $d = mp_md_get_screener();
            $body = array(
                'sectors'  => $d['sectors'],
                'scenario' => $d['scenario'],
                'movers'   => $d['movers'] ?? null,
                'changes'  => $d['changes'] ?? array(),
                'leaders'  => $d['leaders'] ?? array(),
                'asOf'     => $d['asOf'],
                'note'     => 'Signals are a rules-based reading of delayed price data. Not investment advice.',
            );
            $resp = rest_ensure_response($body);
            $resp->header('Cache-Control', 'public, max-age=30, s-maxage=45, stale-while-revalidate=120');
            return $resp;
        },
    ));
});

/* --------------------------- [mp_stock_screener] --------------------------- */
add_shortcode('mp_stock_screener', function () {
    $d = mp_md_get_screener();
    $sectors = $d['sectors'];
    $scn = $d['scenario'];
    ob_start(); ?>
<div class="mp-scr" id="mpScreener" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/screener')); ?>" data-ohlc="<?php echo esc_url(home_url('/wp-json/mp/v1/ohlc')); ?>">

  <div class="mp-scr__scenario" data-role="scenario">
    <?php if ($scn) : ?>
      <div class="mp-scr__scn-idx">
        <?php foreach (array('nifty' => 'Nifty 50', 'sensex' => 'Sensex', 'banknifty' => 'Bank Nifty') as $k => $lbl) :
          if (empty($scn[$k])) continue; $x = $scn[$k]; $up = ($x['chg'] ?? 0) >= 0; ?>
          <span><b><?php echo esc_html($lbl); ?></b> <?php echo number_format($x['level'], 2); ?>
            <i class="<?php echo $up ? 'up' : 'dn'; ?>"><?php echo ($up ? '+' : '') . ($x['chg'] ?? 0); ?>%</i></span>
        <?php endforeach; ?>
      </div>
      <p class="mp-scr__scn-line" data-role="scnline">
        <?php
          $nc = $scn['niftyChg'];
          echo 'The Nifty is ' . ($nc === null ? 'flat' : (($nc >= 0 ? 'up ' : 'down ') . abs($nc) . '%')) . ' today. '
             . esc_html($scn['usLine']) . ', Brent crude ' . esc_html($scn['crudeLine'])
             . ', and the rupee ' . esc_html($scn['inrLine']) . '.';
        ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="mp-scr__controls">
    <span class="mp-scr__seg" data-role="sig">
      <button type="button" data-s="all" class="on">All</button>
      <button type="button" data-s="Bullish">Bullish</button>
      <button type="button" data-s="Neutral">Neutral</button>
      <button type="button" data-s="Bearish">Bearish</button>
    </span>
    <span class="mp-scr__meta" data-role="meta"></span>
  </div>

  <div class="mp-scr__body" data-role="body">
    <?php foreach ($sectors as $sec => $info) : mp_md_screener_sector_html($sec, $info); endforeach; ?>
  </div>

  <p class="mp-scr__note">
    <b>How the signal works:</b> each stock scores +1 / &minus;1 for a positive or negative day move, for out- or under-performing the Nifty, and for its sector index direction. A net score of +2 or more = <b>Bullish</b>, &minus;2 or lower = <b>Bearish</b>, in between = <b>Neutral</b>.
    It is a mechanical reading of <em>delayed</em> price data — not a buy/sell recommendation, research or a forecast. Consult a SEBI-registered investment adviser before acting.
  </p>
</div>

<style>
.mp-scr{margin:18px 0}
.mp-scr__scenario{border:1px solid var(--mp-border,#e5e7eb);border-radius:12px;padding:14px 16px;margin-bottom:14px;background:var(--mp-bg,#f8fafc)}
.mp-scr__scn-idx{display:flex;flex-wrap:wrap;gap:16px;font-size:13px;font-variant-numeric:tabular-nums;margin-bottom:8px}
.mp-scr__scn-idx i{font-style:normal;font-weight:700}
.mp-scr__scn-idx .up,.mp-scr__chg.up,.mp-scr__sig.Bullish{color:#16a34a}
.mp-scr__scn-idx .dn,.mp-scr__chg.dn,.mp-scr__sig.Bearish{color:#dc2626}
.mp-scr__scn-line{margin:0;font-size:13.5px;line-height:1.55}
.mp-scr__controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px}
.mp-scr__seg{display:inline-flex;border:1px solid var(--mp-border,#cbd5e1);border-radius:8px;overflow:hidden}
.mp-scr__seg button{border:0;background:transparent;color:inherit;padding:7px 13px;font-size:12.5px;font-weight:600;cursor:pointer}
.mp-scr__seg button.on{background:var(--mp-brand,#0057ff);color:#fff}
.mp-scr__meta{font-size:11px;color:var(--mp-muted,#64748b);margin-left:auto}
.mp-scr__sector{border:1px solid var(--mp-border,#e5e7eb);border-radius:12px;margin-bottom:12px;overflow:hidden}
.mp-scr__sec-head{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 14px;background:var(--mp-bg,#f8fafc);cursor:pointer}
.mp-scr__sec-head h3{margin:0;font-size:15px;flex:1 1 auto}
.mp-scr__sec-chg{font-size:12px;font-weight:700}
.mp-scr__pill{font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;background:rgba(22,163,74,.12);color:#16a34a}
.mp-scr__pill.bear{background:rgba(220,38,38,.12);color:#dc2626}
.mp-scr__tbl{width:100%;border-collapse:collapse;font-size:13.5px;table-layout:fixed}
.mp-scr__tbl td{padding:10px 12px;border-top:1px solid var(--mp-border,#eef1f4);overflow-wrap:anywhere}
.mp-scr__tbl td:nth-child(1){width:44%}
.mp-scr__tbl td:nth-child(2){width:22%}
.mp-scr__tbl td:nth-child(3){width:16%}
.mp-scr__tbl td:nth-child(4){width:18%}
.mp-scr__tbl td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;overflow-wrap:normal}
.mp-scr__exp td{width:auto !important}
@media(max-width:560px){.mp-scr__tbl td{padding:9px 7px;font-size:12px}.mp-scr__name small{display:none}}
.mp-scr__row{cursor:pointer}
.mp-scr__row:hover{background:var(--mp-bg,#f8fafc)}
.mp-scr__name{font-weight:600}.mp-scr__name small{display:block;font-weight:400;color:var(--mp-muted,#64748b);font-size:11px}
.mp-scr__sig{font-weight:700;font-size:12px}.mp-scr__sig.Neutral{color:var(--mp-muted,#64748b)}
.mp-scr__exp td{background:var(--mp-bg,#f8fafc);padding:14px}
.mp-scr__ana h4{margin:12px 0 4px;font-size:13px;text-transform:uppercase;letter-spacing:.03em;color:var(--mp-muted,#64748b)}
.mp-scr__ana p{margin:0;font-size:13.5px;line-height:1.55}
.mp-scr__ana .disc{font-size:11.5px;color:var(--mp-muted,#64748b);margin-top:10px}
html[data-theme="dark"] .mp-scr__scenario,html[data-theme="dark"] .mp-scr__sec-head,html[data-theme="dark"] .mp-scr__row:hover,html[data-theme="dark"] .mp-scr__exp td{background:#111827}
html[data-theme="dark"] .mp-scr__sector,html[data-theme="dark"] .mp-scr__scenario,html[data-theme="dark"] .mp-scr__tbl td{border-color:rgba(255,255,255,.08)}
</style>

<script>
(function(){
  var W=document.getElementById('mpScreener'); if(!W) return;
  var EP=W.getAttribute('data-endpoint'), OHLC=W.getAttribute('data-ohlc');
  var body=W.querySelector('[data-role=body]'), metaEl=W.querySelector('[data-role=meta]');
  var sig='all', SCN=null, LAST={};

  var SECNOTE={};
  <?php foreach (mp_md_screener_universe() as $sec => $def) : ?>SECNOTE[<?php echo wp_json_encode($sec); ?>]=<?php echo wp_json_encode(mp_md_sector_note($sec)); ?>;<?php endforeach; ?>

  var SHORT={Bullish:'Bull',Bearish:'Bear',Neutral:'Flat'};
  function chip(sg){ return '<span class="mp-scr__sig '+sg+'" title="'+sg+'">'+(sg==='Bullish'?'▲ ':sg==='Bearish'?'▼ ':'● ')+(SHORT[sg]||sg)+'</span>'; }

  function rowHtml(r, sector){
    var up=(r.chgPct||0)>=0;
    return '<tr class="mp-scr__row" data-sym="'+r.sym+'" data-sector="'+sector+'" data-sig="'+r.signal+'" data-r=\''+JSON.stringify(r).replace(/'/g,'&#39;')+'\'>'
      +'<td><span class="mp-scr__name">'+r.name+'<small>NSE: '+r.sym+'</small></span></td>'
      +'<td class="num">₹'+Number(r.price).toLocaleString('en-IN')+'</td>'
      +'<td class="num mp-scr__chg '+(up?'up':'dn')+'">'+(up?'+':'')+(r.chgPct==null?'—':r.chgPct+'%')+'</td>'
      +'<td class="num">'+chip(r.signal)+'</td></tr>';
  }

  function applyFilter(){
    body.querySelectorAll('.mp-scr__sector').forEach(function(sec){
      var shown=0;
      sec.querySelectorAll('.mp-scr__row').forEach(function(tr){
        var ok = sig==='all' || tr.getAttribute('data-sig')===sig;
        tr.style.display = ok?'':'none';
        var ex=tr.nextElementSibling;
        if(ex && ex.classList.contains('mp-scr__exp')) ex.style.display = ok?'':'none';
        if(ok) shown++;
      });
      sec.style.display = shown?'':'none';
    });
  }

  function sectorHtml(name, info){
    var rows=(info.stocks||[]).filter(function(r){ return sig==='all'||r.signal===sig; });
    if(!rows.length) return '';
    var sc=info.chg, scTxt = sc==null? '' : '<span class="mp-scr__sec-chg '+(sc>=0?'up':'dn')+'">'+(sc>=0?'+':'')+sc+'%</span>';
    var h='<div class="mp-scr__sector" data-sector="'+name+'"><div class="mp-scr__sec-head">'
      +'<h3>'+name+'</h3>'+scTxt
      +'<span class="mp-scr__pill">'+info.bull+' bullish</span>'
      +'<span class="mp-scr__pill bear">'+info.bear+' bearish</span></div>'
      +'<table class="mp-scr__tbl"><tbody>';
    rows.forEach(function(r){ h+=rowHtml(r,name); });
    return h+'</tbody></table></div>';
  }

  function render(sectors){
    var h='';
    Object.keys(sectors).forEach(function(k){ h+=sectorHtml(k, sectors[k]); });
    body.innerHTML = h || '<p style="padding:16px;opacity:.6">Loading stocks…</p>';
    applyFilter();
  }

  function analysis(r, sector){
    var parts=[];
    var up=(r.chgPct||0)>=0;
    var snap=r.name+' is trading around ₹'+Number(r.price).toLocaleString('en-IN')+', '
      +(r.chgPct==null?'flat':((up?'up ':'down ')+Math.abs(r.chgPct)+'% today'))+'.';
    if(r.w52pos!=null) snap+=' It is sitting '+(r.w52pos>=75?'near the top':r.w52pos<=25?'near the bottom':'mid-way')+' of its 52-week range.';
    parts.push('<h4>Snapshot</h4><p>'+snap+'</p>');

    var read = r.signal==='Bullish'
      ? 'The stock is outperforming both the broader market and its peers, which usually reflects active buyer interest.'
      : r.signal==='Bearish'
      ? 'The stock is lagging both the market and its sector, a sign that sellers currently have the upper hand.'
      : 'The stock is broadly tracking the market with no strong directional bias at the moment.';
    if(SCN && SCN.niftyChg!=null && r.chgPct!=null){
      var rel=r.chgPct-SCN.niftyChg;
      read+= Math.abs(rel)<0.5 ? ' Today’s move is roughly in line with the index.'
           : rel>0 ? ' It is doing noticeably better than the Nifty today.' : ' It is underperforming the Nifty today.';
    }
    parts.push('<h4>Near-term read</h4><p>'+read+'</p>');

    var gl = SCN ? ('Global backdrop: '+SCN.usLine+'; Brent crude '+SCN.crudeLine+'; the rupee '+SCN.inrLine+'. ') : '';
    gl += SECNOTE[sector]||'';
    parts.push('<h4>Global &amp; sector impact</h4><p>'+gl+'</p>');

    parts.push('<p class="disc">This is a rules-based reading of delayed price data — not a buy or sell recommendation, research, or a forecast. Consider your own goals and consult a SEBI-registered investment adviser before acting.</p>');
    return '<div class="mp-scr__ana">'+parts.join('')+'</div>';
  }

  body.addEventListener('click', function(e){
    var head=e.target.closest('.mp-scr__sec-head');
    if(head){ var tbl=head.nextElementSibling; if(tbl) tbl.style.display = tbl.style.display==='none'?'':'none'; return; }
    var tr=e.target.closest('.mp-scr__row'); if(!tr) return;
    var nx=tr.nextElementSibling;
    if(nx && nx.classList.contains('mp-scr__exp')){ nx.remove(); return; }
    var open=body.querySelector('.mp-scr__exp'); if(open) open.remove();
    var sym=tr.getAttribute('data-sym'), sector=tr.getAttribute('data-sector');
    var r=null;
    try { r=JSON.parse(tr.getAttribute('data-r')||'null'); } catch(e){}
    if(!r && SCN && SCN._sectors) (SCN._sectors[sector]?.stocks||[]).forEach(function(x){ if(x.sym===sym) r=x; });
    var exp=document.createElement('tr'); exp.className='mp-scr__exp';
    exp.innerHTML='<td colspan="4"><div class="mp-cc" data-symbol="'+sym.toLowerCase()+'" data-tf="1D">'
      +'<div class="mp-cc__head"><span class="mp-cc__title" data-role="title">'+sym+'</span>'
      +'<span class="mp-cc__meta" data-role="meta">Loading…</span></div>'
      +'<div class="mp-cc__tf" data-role="tf">'
      +['5m','15m','1h','1D','1W'].map(function(t){return '<button type="button" data-t="'+t+'"'+(t==='1D'?' class="on"':'')+'>'+t.toUpperCase()+'</button>';}).join('')
      +'</div><div class="mp-cc__box" style="height:360px"></div></div>'
      +(r? analysis(r, sector) : '')+'</td>';
    tr.parentNode.insertBefore(exp, tr.nextSibling);
    if(window.__mpLWC) window.__mpLWC.scan(true);
  });

  W.querySelector('[data-role=sig]').addEventListener('click', function(e){
    var b=e.target.closest('button[data-s]'); if(!b) return;
    W.querySelectorAll('[data-role=sig] button').forEach(function(x){x.classList.remove('on');});
    b.classList.add('on'); sig=b.getAttribute('data-s');
    applyFilter();
  });

  function paintScenario(scn){
    if(!scn) return;
    var el=W.querySelector('[data-role=scnline]');
    if(el){
      var nc=scn.niftyChg;
      el.textContent='The Nifty is '+(nc==null?'flat':((nc>=0?'up ':'down ')+Math.abs(nc)+'%'))+' today. '
        +scn.usLine+', Brent crude '+scn.crudeLine+', and the rupee '+scn.inrLine+'.';
    }
    var box=W.querySelector('[data-role=scenario] .mp-scr__scn-idx');
    if(box){
      var map={nifty:'Nifty 50',sensex:'Sensex',banknifty:'Bank Nifty'};
      box.innerHTML=Object.keys(map).filter(function(k){return scn[k];}).map(function(k){
        var x=scn[k], up=(x.chg||0)>=0;
        return '<span><b>'+map[k]+'</b> '+Number(x.level).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})
          +' <i class="'+(up?'up':'dn')+'">'+(up?'+':'')+(x.chg==null?'—':x.chg+'%')+'</i></span>';
      }).join('');
    }
  }

  function load(){
    fetch(EP,{credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      if(!d||!d.sectors) return;
      SCN=d.scenario||{}; SCN._sectors=d.sectors;
      if(metaEl) metaEl.textContent='updated '+new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
      paintScenario(d.scenario);
      if(!body.querySelector('.mp-scr__row')) render(d.sectors);
      else {
        // in-place price/chg/signal refresh without collapsing open rows
        Object.keys(d.sectors).forEach(function(sk){
          (d.sectors[sk].stocks||[]).forEach(function(r){
            var tr=body.querySelector('.mp-scr__row[data-sym="'+CSS.escape(r.sym)+'"]'); if(!tr) return;
            var tds=tr.querySelectorAll('td'); var up=(r.chgPct||0)>=0;
            var prev=LAST[r.sym];
            if(prev!=null && prev!==r.price){ tr.style.transition='background .1s'; tr.style.background = r.price>prev?'rgba(22,163,74,.16)':'rgba(220,38,38,.16)'; setTimeout(function(){ tr.style.background=''; }, 700); }
            LAST[r.sym]=r.price;
            tds[1].textContent='₹'+Number(r.price).toLocaleString('en-IN');
            tds[2].className='num mp-scr__chg '+(up?'up':'dn'); tds[2].textContent=(up?'+':'')+(r.chgPct==null?'—':r.chgPct+'%');
            tds[3].innerHTML=chip(r.signal); tr.setAttribute('data-sig',r.signal);
            tr.setAttribute('data-r', JSON.stringify(r).replace(/'/g,'&#39;'));
          });
        });
        applyFilter();
      }
    }).catch(function(){});
  }
  load();
  setInterval(load, 30000);
  document.addEventListener('visibilitychange', function(){ if(!document.hidden) load(); });
}());
</script>
    <?php
    return ob_get_clean();
});

function mp_md_screener_sector_html($name, $info) {
    $sc = $info['chg'];
    ?>
<div class="mp-scr__sector" data-sector="<?php echo esc_attr($name); ?>">
  <div class="mp-scr__sec-head">
    <h3><?php echo esc_html($name); ?></h3>
    <?php if ($sc !== null) : ?><span class="mp-scr__sec-chg <?php echo $sc >= 0 ? 'up' : 'dn'; ?>"><?php echo ($sc >= 0 ? '+' : '') . $sc; ?>%</span><?php endif; ?>
    <span class="mp-scr__pill"><?php echo (int) $info['bull']; ?> bullish</span>
    <span class="mp-scr__pill bear"><?php echo (int) $info['bear']; ?> bearish</span>
  </div>
  <table class="mp-scr__tbl"><tbody>
  <?php foreach ($info['stocks'] as $r) : $up = ($r['chgPct'] ?? 0) >= 0; ?>
    <tr class="mp-scr__row" data-sym="<?php echo esc_attr($r['sym']); ?>" data-sector="<?php echo esc_attr($name); ?>" data-sig="<?php echo esc_attr($r['signal']); ?>" data-r="<?php echo esc_attr(wp_json_encode($r)); ?>">
      <td><span class="mp-scr__name"><?php echo esc_html($r['name']); ?><small>NSE: <?php echo esc_html($r['sym']); ?></small></span></td>
      <td class="num">&#8377;<?php echo number_format($r['price'], 2); ?></td>
      <td class="num mp-scr__chg <?php echo $up ? 'up' : 'dn'; ?>"><?php echo $r['chgPct'] === null ? '&mdash;' : (($up ? '+' : '') . $r['chgPct'] . '%'); ?></td>
      <td class="num"><span class="mp-scr__sig <?php echo esc_attr($r['signal']); ?>" title="<?php echo esc_attr($r['signal']); ?>"><?php
        echo $r['signal'] === 'Bullish' ? '&#9650; Bull' : ($r['signal'] === 'Bearish' ? '&#9660; Bear' : '&#9679; Flat'); ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
    <?php
}


/* ============================================================================
 * "WHY THE MARKET MOVED" + MARKET PULSE (v1.11.0)
 *  [mp_why_market]  — always-fresh plain-English "why is the market up/down
 *                     today" explainer (sector movers + global cues + top
 *                     movers + latest news + FAQ + FAQPage schema)
 *  [mp_market_pulse] — sector leaderboard, top gainers/losers, signal changes
 *                     today, and a search-any-NSE-stock lookup
 * ==========================================================================*/

function mp_md_dir_word($chg) {
    if ($chg === null) return 'little changed';
    if ($chg >= 0.75) return 'firmly higher';
    if ($chg >= 0.15) return 'higher';
    if ($chg <= -0.75) return 'sharply lower';
    if ($chg <= -0.15) return 'lower';
    return 'flat';
}

function mp_md_market_news($n = 5) {
    $ids = array();
    foreach (array('indian-markets', 'us-markets', 'global-markets', 'markets', 'economy', 'stocks') as $slug) {
        $t = get_term_by('slug', $slug, 'category');
        if ($t) $ids[] = (int) $t->term_id;
    }
    $args = array('numberposts' => $n, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC');
    if ($ids) $args['category__in'] = $ids;
    $q = get_posts($args);
    if (count($q) < $n) {
        $q = get_posts(array('numberposts' => $n, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'));
    }
    return $q;
}

/* --------------------------- [mp_why_market] --------------------------- */
add_shortcode('mp_why_market', function () {
    $d   = mp_md_get_screener();
    $scn = $d['scenario'];
    if (!$scn) return '<p>Market data is loading — please refresh in a moment.</p>';

    $nc     = $scn['niftyChg'];
    $dir    = mp_md_dir_word($nc);
    $upDown = ($nc === null) ? 'moving' : ($nc >= 0 ? 'up' : 'down');
    $leaders = $d['leaders'] ?? array();
    $up3    = array_slice($leaders, 0, 3, true);
    $dn3    = array_slice(array_reverse($leaders, true), 0, 3, true);
    $movers = $d['movers'] ?? array('up' => array(), 'down' => array());
    $g      = $scn['global'] ?? array();
    $ist    = gmdate('H:i', time() + 19800);
    $istDate= gmdate('j F Y', time() + 19800);

    // build the driver paragraph
    $glParts = array();
    if (!empty($g['dow']) || !empty($g['nasdaq'])) $glParts[] = $scn['usLine'];
    if (!empty($g['crude'])) $glParts[] = 'crude oil ' . $scn['crudeLine'];
    if (!empty($g['usdinr'])) $glParts[] = 'the rupee ' . $scn['inrLine'];
    if (!empty($g['gold'])) $glParts[] = 'gold at $' . number_format($g['gold']['level'], 0)
        . ($g['gold']['chg'] !== null ? ' (' . ($g['gold']['chg'] >= 0 ? '+' : '') . $g['gold']['chg'] . '%)' : '');

    ob_start(); ?>
<div class="mp-why">
  <p class="mp-why__lead"><strong>The Nifty 50 is <?php echo esc_html($dir); ?> today (<?php echo $istDate; ?>).</strong>
    <?php
      $bits = array();
      if (!empty($scn['nifty'])) $bits[] = 'Nifty ' . number_format($scn['nifty']['level'], 0) . ' (' . ($scn['nifty']['chg'] >= 0 ? '+' : '') . $scn['nifty']['chg'] . '%)';
      if (!empty($scn['sensex'])) $bits[] = 'Sensex ' . number_format($scn['sensex']['level'], 0) . ' (' . ($scn['sensex']['chg'] >= 0 ? '+' : '') . $scn['sensex']['chg'] . '%)';
      if (!empty($scn['banknifty'])) $bits[] = 'Bank Nifty ' . number_format($scn['banknifty']['level'], 0) . ' (' . ($scn['banknifty']['chg'] >= 0 ? '+' : '') . $scn['banknifty']['chg'] . '%)';
      echo esc_html(implode(' · ', $bits)) . '.';
      if (!empty($scn['vix'])) {
          $vc = $scn['vix']['chg'];
          echo ' India VIX, the market\'s "fear gauge", is at ' . $scn['vix']['level']
             . ($vc !== null ? ' (' . ($vc >= 0 ? 'up ' : 'down ') . abs($vc) . '% &mdash; ' . ($vc >= 0 ? 'nerves rising' : 'calmer') . ')' : '') . '.';
      }
    ?>
  </p>

  <h2>What&rsquo;s driving the move</h2>
  <p>
    <?php if ($up3 || $dn3) : ?>
      Among sectors, <strong><?php echo esc_html(implode(', ', array_keys($up3))); ?></strong>
      <?php echo count($up3) > 1 ? 'are' : 'is'; ?> holding up best today, while
      <strong><?php echo esc_html(implode(', ', array_keys($dn3))); ?></strong>
      <?php echo count($dn3) > 1 ? 'are' : 'is'; ?> under the most pressure.
    <?php endif; ?>
    <?php if ($glParts) : ?>
      On the global side, <?php echo esc_html(implode(', ', $glParts)); ?>.
    <?php endif; ?>
  </p>
  <ul class="mp-why__cues">
    <?php foreach ($leaders as $sec => $chg) : ?>
      <li><span><?php echo esc_html($sec); ?></span>
        <b class="<?php echo $chg >= 0 ? 'up' : 'dn'; ?>"><?php echo ($chg >= 0 ? '+' : '') . $chg; ?>%</b></li>
    <?php endforeach; ?>
  </ul>

  <h2>Biggest movers today</h2>
  <div class="mp-why__movers">
    <div><h4>Top gainers</h4><ul>
      <?php foreach (($movers['up'] ?? array()) as $m) : if (($m['chgPct'] ?? 0) <= 0) continue; ?>
        <li><?php echo esc_html($m['name']); ?> <b class="up">+<?php echo $m['chgPct']; ?>%</b></li>
      <?php endforeach; ?>
    </ul></div>
    <div><h4>Top losers</h4><ul>
      <?php foreach (($movers['down'] ?? array()) as $m) : if (($m['chgPct'] ?? 0) >= 0) continue; ?>
        <li><?php echo esc_html($m['name']); ?> <b class="dn"><?php echo $m['chgPct']; ?>%</b></li>
      <?php endforeach; ?>
    </ul></div>
  </div>

  <h2>Latest market news</h2>
  <ul class="mp-why__news">
    <?php foreach (mp_md_market_news(5) as $p) : ?>
      <li><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
        <span><?php echo esc_html(human_time_diff(get_post_time('U', true, $p)) . ' ago'); ?></span></li>
    <?php endforeach; ?>
  </ul>

  <h2>Frequently asked</h2>
  <div class="mp-why__faq">
    <details open><summary>Why is the stock market <?php echo esc_html($upDown); ?> today?</summary>
      <p>Today the Nifty is <?php echo esc_html($dir); ?>. The main factors are the sector moves and global cues above &mdash;
      <?php echo $glParts ? esc_html(strtolower($scn['usLine'])) . ', and ' : ''; ?>
      shifts in crude oil and the rupee. Day-to-day moves are usually a mix of global sentiment, fund flows and stock-specific news rather than one single reason.</p>
    </details>
    <details><summary>Will the market go up tomorrow / recover?</summary>
      <p>No one can reliably predict the next session. What tends to matter for the next open: how US markets close tonight, moves in crude and the dollar, any domestic data or results, and foreign investor flows. This page updates through the day so you can see the picture as it changes.</p>
    </details>
    <details><summary>Is this a good time to invest?</summary>
      <p>That depends on your goals, time horizon and risk appetite &mdash; not on a single day&rsquo;s move. Long-term investors generally focus on staying invested and adding regularly rather than timing daily swings. This is information only, not advice; consult a SEBI-registered investment adviser.</p>
    </details>
    <details><summary>Where can I see which stocks are moving?</summary>
      <p>The <a href="<?php echo esc_url(home_url('/stock-analysis/')); ?>">Stock Analysis by Sector</a> page lists every large-cap with a Bullish / Neutral / Bearish signal, and the <a href="<?php echo esc_url(home_url('/charts/')); ?>">Live Charts</a> page has full candlestick charts.</p>
    </details>
  </div>

  <p class="mp-why__stamp">Updated <?php echo esc_html($ist); ?> IST &middot; figures may be delayed &middot; nothing here is investment advice.</p>
</div>

<script type="application/ld+json"><?php echo wp_json_encode(array(
  '@context' => 'https://schema.org', '@type' => 'FAQPage',
  'mainEntity' => array(
    array('@type' => 'Question', 'name' => 'Why is the stock market ' . $upDown . ' today?',
      'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'Today the Nifty 50 is ' . $dir . '. The move reflects a combination of sector performance, global cues (' . implode(', ', $glParts) . ') and fund flows rather than any single cause.')),
    array('@type' => 'Question', 'name' => 'Will the stock market recover tomorrow?',
      'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'Short-term market direction cannot be reliably predicted. The next session usually hinges on how US markets close overnight, crude oil and the rupee, domestic data or results, and foreign investor flows.')),
    array('@type' => 'Question', 'name' => 'Is it a good time to invest in the stock market?',
      'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'That depends on your personal goals, time horizon and risk appetite, not on a single day\'s move. This page is information only and not investment advice; consult a SEBI-registered investment adviser.')),
  ),
), JSON_UNESCAPED_SLASHES); ?></script>

<style>
.mp-why{margin:12px 0;font-size:15px;line-height:1.65}
.mp-why h2{font-size:19px;margin:22px 0 8px}
.mp-why h4{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--mp-muted,#64748b);margin:0 0 6px}
.mp-why__lead{background:var(--mp-bg,#f8fafc);border:1px solid var(--mp-border,#e5e7eb);border-radius:12px;padding:14px 16px}
.mp-why__cues{list-style:none;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px 14px;font-size:13px}
.mp-why__cues li{display:flex;justify-content:space-between;border-bottom:1px solid var(--mp-border,#eef1f4);padding:5px 0}
.mp-why__cues b.up,.mp-why__movers b.up{color:#16a34a}.mp-why__cues b.dn,.mp-why__movers b.dn{color:#dc2626}
.mp-why__movers{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.mp-why__movers ul,.mp-why__news{list-style:none;padding:0;margin:0}
.mp-why__movers li{padding:5px 0;border-bottom:1px solid var(--mp-border,#eef1f4);font-size:14px;display:flex;justify-content:space-between;gap:8px}
.mp-why__news li{padding:7px 0;border-bottom:1px solid var(--mp-border,#eef1f4)}
.mp-why__news a{font-weight:600}
.mp-why__news span{display:block;font-size:11.5px;color:var(--mp-muted,#64748b)}
.mp-why__faq details{border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:10px 14px;margin-bottom:8px}
.mp-why__faq summary{font-weight:700;cursor:pointer;font-size:14px}
.mp-why__faq p{margin:8px 0 0;font-size:14px}
.mp-why__stamp{font-size:11.5px;color:var(--mp-muted,#64748b);margin-top:14px}
@media(max-width:560px){.mp-why__movers{grid-template-columns:1fr}}
html[data-theme="dark"] .mp-why__lead,html[data-theme="dark"] .mp-why__faq details{background:#111827;border-color:rgba(255,255,255,.08)}
</style>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_market_pulse] --------------------------- */
add_shortcode('mp_market_pulse', function ($atts) {
    $atts = shortcode_atts(array('lookup' => '1'), $atts);
    $lookup = $atts['lookup'] !== '0';
    $ep = esc_url(home_url('/wp-json/mp/v1/screener'));
    $oh = esc_url(home_url('/wp-json/mp/v1/ohlc'));
    ob_start(); ?>
<div class="mp-pulse" id="mpPulse" data-ep="<?php echo $ep; ?>" data-ohlc="<?php echo $oh; ?>">
  <div class="mp-pulse__row" data-role="leaders"><span class="mp-pulse__k">Sectors</span> <span data-role="lead">loading&hellip;</span></div>
  <div class="mp-pulse__grid">
    <div><h4>Top gainers</h4><ol data-role="gain"><li>&mdash;</li></ol></div>
    <div><h4>Top losers</h4><ol data-role="lose"><li>&mdash;</li></ol></div>
    <div><h4>Signal changes today</h4><div data-role="chg" class="mp-pulse__chg">&mdash;</div></div>
  </div>
  <?php if ($lookup) : ?>
  <form class="mp-pulse__lookup" data-role="lookup">
    <input type="text" placeholder="Search any NSE stock (e.g. RELIANCE, DMART)" aria-label="Search a stock" autocomplete="off">
    <button type="submit">Analyse</button>
  </form>
  <div data-role="card"></div>
  <?php endif; ?>
  <p class="mp-pulse__note">Movers and signal changes are across the ~54 large-caps we track. Not investment advice.</p>
</div>
<style>
.mp-pulse{margin:16px 0;border:1px solid var(--mp-border,#e5e7eb);border-radius:12px;padding:14px 16px;background:var(--mp-surface,#fff)}
.mp-pulse__row{font-size:13px;margin-bottom:10px}
.mp-pulse__k{font-weight:700;color:var(--mp-muted,#64748b);text-transform:uppercase;font-size:11px;letter-spacing:.03em}
.mp-pulse__row .up,.mp-pulse__grid .up{color:#16a34a}.mp-pulse__row .dn,.mp-pulse__grid .dn{color:#dc2626}
.mp-pulse__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:12px}
.mp-pulse__grid h4{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--mp-muted,#64748b);margin:0 0 6px}
.mp-pulse__grid ol{margin:0;padding-left:18px;font-size:13px;line-height:1.7}
.mp-pulse__chg{font-size:12.5px;line-height:1.6}
.mp-pulse__lookup{display:flex;gap:8px;margin-bottom:10px}
.mp-pulse__lookup input{flex:1;min-width:0;padding:9px 12px;border:1px solid var(--mp-border,#cbd5e1);border-radius:8px;background:var(--mp-bg,#f8fafc);color:inherit;font-size:13px}
.mp-pulse__lookup button{padding:9px 16px;border:0;border-radius:8px;background:var(--mp-brand,#0057ff);color:#fff;font-weight:600;font-size:13px;cursor:pointer}
.mp-pulse__note{font-size:11px;color:var(--mp-muted,#64748b);margin:6px 0 0}
html[data-theme="dark"] .mp-pulse{background:#111827;border-color:rgba(255,255,255,.08)}
html[data-theme="dark"] .mp-pulse__lookup input{background:#0a0f1e;border-color:rgba(255,255,255,.12)}
</style>
<script>
(function(){
  var W=document.getElementById('mpPulse'); if(!W) return;
  var EP=W.getAttribute('data-ep'), OH=W.getAttribute('data-ohlc'), SCN=null;
  function q(s){ return W.querySelector('[data-role='+s+']'); }
  function pct(v){ return (v>=0?'+':'')+v+'%'; }

  function paint(d){
    SCN=d.scenario||{};
    var L=d.leaders||{};
    var keys=Object.keys(L);
    q('lead').innerHTML = keys.map(function(k){ var c=L[k]; return k+' <b class="'+(c>=0?'up':'dn')+'">'+pct(c)+'</b>'; }).join(' &nbsp;·&nbsp; ');
    var mu=(d.movers&&d.movers.up)||[], ml=(d.movers&&d.movers.down)||[];
    q('gain').innerHTML = mu.filter(function(m){return m.chgPct>0;}).slice(0,5).map(function(m){ return '<li>'+m.name+' <b class="up">'+pct(m.chgPct)+'</b></li>'; }).join('')||'<li>—</li>';
    q('lose').innerHTML = ml.filter(function(m){return m.chgPct<0;}).slice(0,5).map(function(m){ return '<li>'+m.name+' <b class="dn">'+pct(m.chgPct)+'</b></li>'; }).join('')||'<li>—</li>';
    var ch=d.changes||[];
    if(!ch.length){ q('chg').textContent='No signals have flipped since the open.'; }
    else {
      var b=ch.filter(function(c){return c.signal==='Bullish';}).map(function(c){return c.name;});
      var r=ch.filter(function(c){return c.signal==='Bearish';}).map(function(c){return c.name;});
      var n=ch.filter(function(c){return c.signal==='Neutral';}).map(function(c){return c.name;});
      var out=[];
      if(b.length) out.push('<span class="up">▲ Turned bullish:</span> '+b.join(', '));
      if(r.length) out.push('<span class="dn">▼ Turned bearish:</span> '+r.join(', '));
      if(n.length) out.push('● Back to neutral: '+n.join(', '));
      q('chg').innerHTML=out.join('<br>');
    }
  }

  fetch(EP,{credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(d){ if(d) paint(d); }).catch(function(){});
  setInterval(function(){ fetch(EP,{credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(d){ if(d) paint(d); }).catch(function(){}); }, 45000);

  var LK=q('lookup');
  if(LK) LK.addEventListener('submit', function(e){
    e.preventDefault();
    var v=(this.querySelector('input').value||'').trim().toUpperCase().replace(/[^A-Z0-9&.-]/g,'');
    if(!v) return;
    var card=q('card');
    card.innerHTML='<div style="padding:12px;font-size:13px;opacity:.6">Loading '+v+'…</div>';
    fetch(OH+'?symbol='+encodeURIComponent(v.toLowerCase())+'&tf=1D',{credentials:'omit'})
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){
        if(!d||!d.bars||d.bars.length<2){ card.innerHTML='<div style="padding:12px;font-size:13px">Couldn’t find <b>'+v+'</b> on NSE. Try the exact NSE symbol.</div>'; return; }
        var b=d.bars, last=b[b.length-1][4], prev=b[b.length-2][4];
        var chg=prev? (last-prev)/prev*100 : 0;
        var niftyChg=(SCN&&SCN.niftyChg!=null)?SCN.niftyChg:0;
        var s=0; s+=(chg>=1?1:0)-(chg<=-1?1:0); s+=((chg-niftyChg)>=0.75?1:0)-((chg-niftyChg)<=-0.75?1:0);
        var sig=s>=2?'Bullish':(s<=-2?'Bearish':'Neutral');
        var col=sig==='Bullish'?'#16a34a':sig==='Bearish'?'#dc2626':'#64748b';
        card.innerHTML='<div class="mp-pulse__found">'
          +'<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:baseline;margin:6px 0 8px">'
          +'<b style="font-size:16px">'+v+'</b> <span style="font-variant-numeric:tabular-nums">₹'+Number(last).toLocaleString("en-IN")+'</span>'
          +'<span style="color:'+(chg>=0?"#16a34a":"#dc2626")+';font-weight:600">'+pct(chg.toFixed(2))+'</span>'
          +'<span style="color:'+col+';font-weight:700">'+(sig==="Bullish"?"▲":sig==="Bearish"?"▼":"●")+' '+sig+'</span></div>'
          +'<div class="mp-cc" data-symbol="'+v.toLowerCase()+'" data-tf="1D"><div class="mp-cc__head"><span class="mp-cc__title" data-role="title">'+v+'</span><span class="mp-cc__meta" data-role="meta">Loading…</span></div>'
          +'<div class="mp-cc__tf" data-role="tf">'+["5m","15m","1h","1D","1W"].map(function(t){return "<button type=\"button\" data-t=\""+t+"\""+(t==="1D"?" class=\"on\"":"")+">"+t.toUpperCase()+"</button>";}).join("")+'</div>'
          +'<div class="mp-cc__box" style="height:340px"></div></div>'
          +'<p style="font-size:11.5px;color:var(--mp-muted,#64748b);margin:8px 0 0">Signal is a quick day-move read versus the Nifty. Delayed data. Not investment advice.</p></div>';
        if(window.__mpLWC) window.__mpLWC.scan(true);
        var tf=card.querySelector('[data-role=tf]');
        if(tf) tf.addEventListener('click',function(e){ var x=e.target.closest('button[data-t]'); if(!x) return; [].forEach.call(tf.querySelectorAll('button'),function(y){y.classList.remove('on');}); x.classList.add('on'); var rec=card.querySelector('.mp-cc').__mpccRec; if(rec) rec.setTf(x.getAttribute('data-t')); });
      }).catch(function(){ card.innerHTML='<div style="padding:12px;font-size:13px">Lookup failed, try again.</div>'; });
  });
}());
</script>
    <?php
    return ob_get_clean();
});


/* ============================================================================
 * STOCK / SECTOR / INDEX / LIST / FII-DII PAGES  (v1.12.0)
 *  [mp_stock_page symbol=]  — full per-stock page (price, chart, MAs, 52-wk,
 *      volume, our signal + read, returns, news, FAQ + schema, related)
 *  [mp_stock_directory]     — index of every tracked stock by sector
 *  [mp_sector_page sector=] — sector overview + index chart + its stocks
 *  [mp_index_page index=]   — Nifty 50 / Sensex / Bank Nifty page
 *  [mp_stock_list type=]    — top gainers / losers / 52-week high / low
 *  [mp_fii_dii]             — FII/DII net activity (NSE, with manual fallback)
 * ==========================================================================*/

/** 52-week high/low per NSE symbol from 1y weekly closes. Cached 6h (barely moves intraday). */
function mp_md_scr_52w($nsSymbols) {
    $ck = 'mp_scr_52w_v1';
    $c  = get_transient($ck);
    if (is_array($c) && count($c) >= count($nsSymbols) * 0.6) return $c;

    $out = is_array($c) ? $c : array();
    $chunks = array_chunk($nsSymbols, 8);
    foreach ($chunks as $ci => $chunk) {
        $list = implode(',', array_map('rawurlencode', $chunk));
        $host = ($ci % 2) ? 'query2' : 'query1';
        $url  = 'https://' . $host . '.finance.yahoo.com/v8/finance/spark?symbols=' . $list . '&range=1y&interval=1wk';
        $j = null;
        for ($try = 0; $try < 2; $try++) {
            $res = wp_remote_get($url, array('timeout' => 6, 'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'Accept'     => 'application/json',
            )));
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $j = json_decode(wp_remote_retrieve_body($res), true);
                if (is_array($j) && $j) break;
            }
            $j = null;
            usleep(900000);
        }
        if ($ci < count($chunks) - 1) usleep(350000);
        if (!is_array($j)) continue;
        $rows = isset($j['spark']['result']) ? $j['spark']['result'] : $j;
        foreach ($rows as $key => $r) {
            $sym = isset($r['symbol']) ? $r['symbol'] : $key;
            $cl  = null;
            if (isset($r['close']) && is_array($r['close'])) $cl = $r['close'];
            elseif (isset($r['response'][0]['indicators']['quote'][0]['close'])) $cl = $r['response'][0]['indicators']['quote'][0]['close'];
            if (!is_array($cl)) continue;
            $vals = array_values(array_filter($cl, function ($x) { return $x !== null; }));
            if (count($vals) < 10) continue;
            $out[$sym] = array('min' => round(min($vals), 2), 'max' => round(max($vals), 2));
        }
    }
    if ($out) set_transient($ck, $out, 6 * HOUR_IN_SECONDS);
    return $out;
}

function mp_md_stock_universe_flat() {
    static $f = null;
    if ($f !== null) return $f;
    $f = array();
    foreach (mp_md_screener_universe() as $sec => $def) {
        foreach ($def[1] as $sym => $name) {
            $f[$sym] = array('name' => $name, 'sector' => $sec, 'secIndex' => $def[0]);
        }
    }
    return $f;
}
function mp_md_stock_slug($sym) {
    $s = strtolower($sym);
    $s = str_replace('&', '-and-', $s);
    $s = str_replace(array(' ', '_'), '-', $s);
    return preg_replace('/[^a-z0-9-]/', '', $s);
}
function mp_md_stock_from_slug($slug) {
    $slug = strtolower(trim($slug));
    foreach (mp_md_stock_universe_flat() as $sym => $m) {
        if (mp_md_stock_slug($sym) === $slug) return $sym;
    }
    return strtoupper(str_replace('-and-', '&', $slug));
}
function mp_md_sector_slug($sector) {
    return preg_replace('/[^a-z0-9]+/', '-', strtolower($sector));
}
function mp_md_sector_candle_key($secIndexSym) {
    $m = array(
        '^NSEBANK' => 'banknifty', '^CNXIT' => 'niftyit', '^CNXAUTO' => 'niftyauto',
        '^CNXFMCG' => 'niftyfmcg', '^CNXPHARMA' => 'niftypharma', '^CNXMETAL' => 'niftymetal',
        '^CNXENERGY' => 'niftyenergy', '^CNXREALTY' => 'niftyrealty',
    );
    return $m[$secIndexSym] ?? 'nifty';
}

/** Trend / MA / 52-week analytics for one NSE stock, from the cached 1-year daily bars. */
function mp_md_stock_analytics($sym) {
    $ck = 'mp_stkan_' . md5($sym);
    $c  = get_transient($ck);
    if (is_array($c)) return $c;

    $d = mp_candle_ohlc($sym, '1D');
    $bars = $d['bars'] ?? array();
    if (count($bars) < 20) return array('ok' => false);

    $closes = array_column($bars, 4);
    $highs  = array_column($bars, 2);
    $lows   = array_column($bars, 3);
    $vols   = array_column($bars, 5);
    $n = count($closes);
    $last = $closes[$n - 1];
    $prev = $closes[$n - 2];

    $sma = function ($arr, $p) {
        if (count($arr) < $p) return null;
        return array_sum(array_slice($arr, -$p)) / $p;
    };
    $sma20 = $sma($closes, 20); $sma50 = $sma($closes, 50); $sma200 = $sma($closes, 200);
    $wHigh = max($highs); $wLow = min($lows);
    $wPos  = ($wHigh > $wLow) ? (int) round(($last - $wLow) / ($wHigh - $wLow) * 100) : null;
    $vol20 = $sma($vols, 20);
    $volX  = ($vol20 && $vol20 > 0) ? round($vols[$n - 1] / $vol20, 1) : null;

    $trend = null;
    if ($sma20 && $sma50 && $sma200) {
        if ($last > $sma20 && $last > $sma50 && $last > $sma200) $trend = 'up';
        elseif ($last < $sma20 && $last < $sma50 && $last < $sma200) $trend = 'down';
        else $trend = 'mixed';
    }

    $out = array(
        'ok' => true,
        'last' => round($last, 2), 'prev' => round($prev, 2),
        'chg1d' => $prev ? round(($last - $prev) / $prev * 100, 2) : null,
        'chg1m' => ($n > 22) ? round(($last - $closes[$n - 22]) / $closes[$n - 22] * 100, 1) : null,
        'chg1y' => round(($last - $closes[0]) / $closes[0] * 100, 1),
        'sma20' => $sma20 ? round($sma20) : null,
        'sma50' => $sma50 ? round($sma50) : null,
        'sma200' => $sma200 ? round($sma200) : null,
        'wHigh' => round($wHigh, 2), 'wLow' => round($wLow, 2), 'wPos' => $wPos,
        'volX' => $volX, 'trend' => $trend,
        'asOf' => gmdate('c'),
    );
    set_transient($ck, $out, 15 * MINUTE_IN_SECONDS);
    return $out;
}

function mp_md_stock_context($sym) {
    $flat = mp_md_stock_universe_flat();
    $meta = $flat[$sym] ?? array('name' => $sym, 'sector' => '', 'secIndex' => '');
    $scr  = mp_md_get_screener();
    $scn  = $scr['scenario'] ?? array();
    $secChg = null;
    if (!empty($meta['sector']) && isset($scr['sectors'][$meta['sector']])) {
        $secChg = $scr['sectors'][$meta['sector']]['chg'];
    }
    return array('meta' => $meta, 'scn' => $scn, 'niftyChg' => $scn['niftyChg'] ?? null, 'secChg' => $secChg);
}

/* --------------------------- [mp_stock_page] --------------------------- */
add_shortcode('mp_stock_page', function ($atts) {
    $sym = isset($atts['symbol']) ? strtoupper(trim($atts['symbol'])) : '';
    if ($sym === '') return '';
    $an  = mp_md_stock_analytics($sym);
    $ctx = mp_md_stock_context($sym);
    $meta = $ctx['meta']; $scn = $ctx['scn'];
    $name = $meta['name'];
    $slug = mp_md_stock_slug($sym);

    if (empty($an['ok'])) {
        return '<p>Live data for ' . esc_html($name) . ' is loading — please refresh in a moment.</p>';
    }

    list($sig, $score) = mp_md_screener_signal($an['chg1d'], $ctx['niftyChg'], $ctx['secChg'], $an['wPos']);
    $up = ($an['chg1d'] ?? 0) >= 0;
    $fromHigh = $an['wHigh'] ? round(($an['wHigh'] - $an['last']) / $an['wHigh'] * 100, 1) : null;

    // trend sentence
    $trendTxt = '';
    if ($an['trend'] === 'up')    $trendTxt = $name . ' is trading above its 20-, 50- and 200-day moving averages — the classic picture of an uptrend.';
    elseif ($an['trend'] === 'down') $trendTxt = $name . ' is trading below its 20-, 50- and 200-day moving averages — a downtrend on the charts.';
    elseif ($an['trend'] === 'mixed') $trendTxt = $name . ' is trading between its short- and long-term moving averages — a mixed, rangebound picture.';
    $volTxt = '';
    if ($an['volX'] !== null) {
        $volTxt = 'Today&rsquo;s volume is about ' . $an['volX'] . '&times; its 20-day average';
        $volTxt .= $an['volX'] >= 1.6 ? ' &mdash; heavier than usual, which points to conviction behind the move.'
            : ($an['volX'] <= 0.6 ? ' &mdash; lighter than usual.' : '.');
    }

    // near-term read (reuses the screener logic)
    $read = $sig === 'Bullish'
        ? $name . ' is outperforming both the broader market and its ' . ($meta['sector'] ?: 'sector') . ' peers, which usually reflects buyer interest.'
        : ($sig === 'Bearish'
        ? $name . ' is lagging both the market and its ' . ($meta['sector'] ?: 'sector') . ' — a sign sellers are in control.'
        : $name . ' is broadly tracking the market with no strong directional bias right now.');
    if ($ctx['niftyChg'] !== null && $an['chg1d'] !== null) {
        $rel = $an['chg1d'] - $ctx['niftyChg'];
        $read .= abs($rel) < 0.5 ? ' Today\'s move is roughly in line with the Nifty.'
            : ($rel > 0 ? ' It is doing better than the Nifty today.' : ' It is underperforming the Nifty today.');
    }
    $global = '';
    if ($scn) {
        $global = 'Global backdrop: ' . ($scn['usLine'] ?? '') . '; crude oil ' . ($scn['crudeLine'] ?? '') . '; the rupee ' . ($scn['inrLine'] ?? '') . '. ';
    }
    $global .= mp_md_sector_note($meta['sector']);

    $sigColor = $sig === 'Bullish' ? '#16a34a' : ($sig === 'Bearish' ? '#dc2626' : '#64748b');
    $ist = gmdate('H:i', time() + 19800);

    ob_start(); ?>
<div class="mp-stk">
  <div class="mp-stk__hd">
    <div>
      <span class="mp-stk__price">&#8377;<?php echo number_format($an['last'], 2); ?></span>
      <span class="mp-stk__chg <?php echo $up ? 'up' : 'dn'; ?>"><?php echo ($up ? '+' : '') . $an['chg1d']; ?>% today</span>
    </div>
    <span class="mp-stk__sig" style="color:<?php echo $sigColor; ?>">
      <?php echo $sig === 'Bullish' ? '&#9650;' : ($sig === 'Bearish' ? '&#9660;' : '&#9679;'); ?> <?php echo esc_html($sig); ?>
    </span>
  </div>
  <p class="mp-stk__stamp">NSE: <?php echo esc_html($sym); ?> &middot; <?php echo esc_html($meta['sector']); ?> &middot; updated <?php echo esc_html($ist); ?> IST, may be delayed</p>

  <h2>Chart</h2>
  <?php echo do_shortcode('[mp_candle_chart symbol="' . esc_attr($sym) . '"]'); ?>

  <h2><?php echo esc_html($name); ?> share price &mdash; the numbers</h2>
  <table class="mp-stk__tbl">
    <tr><td>Last price</td><td>&#8377;<?php echo number_format($an['last'], 2); ?></td></tr>
    <tr><td>Previous close</td><td>&#8377;<?php echo number_format($an['prev'], 2); ?></td></tr>
    <tr><td>52-week high / low</td><td>&#8377;<?php echo number_format($an['wHigh'], 0); ?> / &#8377;<?php echo number_format($an['wLow'], 0); ?></td></tr>
    <tr><td>Position in 52-wk range</td><td><?php echo $an['wPos'] !== null ? $an['wPos'] . '%' : '&mdash;'; ?><?php echo $fromHigh !== null ? ' (' . $fromHigh . '% below the high)' : ''; ?></td></tr>
    <tr><td>20 / 50 / 200-day average</td><td>&#8377;<?php echo number_format($an['sma20']); ?> / &#8377;<?php echo number_format($an['sma50']); ?> / &#8377;<?php echo $an['sma200'] ? number_format($an['sma200']) : '&mdash;'; ?></td></tr>
    <tr><td>Return &mdash; 1 month / 1 year</td><td><?php echo ($an['chg1m'] !== null ? ($an['chg1m'] >= 0 ? '+' : '') . $an['chg1m'] . '%' : '&mdash;'); ?> / <?php echo ($an['chg1y'] >= 0 ? '+' : '') . $an['chg1y']; ?>%</td></tr>
  </table>

  <h2>What the data says</h2>
  <p><?php echo esc_html($trendTxt); ?> <?php echo wp_kses_post($volTxt); ?></p>
  <p><strong>Signal: <?php echo esc_html($sig); ?>.</strong> <?php echo esc_html($read); ?></p>
  <p><?php echo esc_html($global); ?></p>
  <p class="mp-stk__disc">This is a rules-based reading of delayed price data &mdash; not a buy or sell recommendation, research, or a price forecast. Consult a SEBI-registered investment adviser before acting.</p>

  <h2>MoneyPuran coverage</h2>
  <div id="mpStkNews-<?php echo esc_attr($slug); ?>" class="mp-stk__news" data-name="<?php echo esc_attr($name); ?>" data-sym="<?php echo esc_attr($sym); ?>">Loading&hellip;</div>

  <?php
    $peers = array();
    foreach (mp_md_stock_universe_flat() as $ps => $pm) {
        if ($pm['sector'] === $meta['sector'] && $ps !== $sym) $peers[$ps] = $pm['name'];
    }
    if ($peers) : ?>
  <h2>Other <?php echo esc_html($meta['sector']); ?> stocks</h2>
  <p class="mp-stk__peers">
    <?php foreach ($peers as $ps => $pn) : ?>
      <a href="<?php echo esc_url(home_url('/stocks/' . mp_md_stock_slug($ps) . '/')); ?>"><?php echo esc_html($pn); ?></a>
    <?php endforeach; ?>
  </p>
  <?php endif; ?>

  <h2>FAQ</h2>
  <div class="mp-why__faq">
    <details open><summary>What is the <?php echo esc_html($name); ?> share price today?</summary>
      <p><?php echo esc_html($name); ?> (NSE: <?php echo esc_html($sym); ?>) is trading at &#8377;<?php echo number_format($an['last'], 2); ?>, <?php echo $up ? 'up' : 'down'; ?> <?php echo abs($an['chg1d']); ?>% on the day. Figures update through the session and may be delayed.</p></details>
    <details><summary>What is the <?php echo esc_html($name); ?> share price target?</summary>
      <p>MoneyPuran does not publish price targets. Brokerages issue their own and they often disagree. What this page shows is the live price and its trend: the stock is currently <?php echo $an['trend'] === 'up' ? 'above' : ($an['trend'] === 'down' ? 'below' : 'around'); ?> its key moving averages and <?php echo $an['wPos'] !== null && $an['wPos'] >= 70 ? 'near the top of' : ($an['wPos'] !== null && $an['wPos'] <= 30 ? 'near the bottom of' : 'in the middle of'); ?> its 52-week range.</p></details>
    <details><summary>Is <?php echo esc_html($name); ?> a good stock to buy?</summary>
      <p>That depends on your goals, time horizon and risk appetite &mdash; and on the company's fundamentals and valuation, which this page does not cover. Our signal is <?php echo esc_html($sig); ?> today, but that is momentum, not a recommendation. Consult a SEBI-registered investment adviser.</p></details>
    <details><summary>Why is <?php echo esc_html($name); ?> up or down today?</summary>
      <p><?php echo esc_html(trim($read . ' ' . mp_md_sector_note($meta['sector']))); ?> See <a href="<?php echo esc_url(home_url('/why-market-moved-today/')); ?>">why the market moved today</a> for the full picture.</p></details>
  </div>
</div>

<script>
(function(){
  var el=document.getElementById('mpStkNews-<?php echo esc_js($slug); ?>'); if(!el) return;
  var name=el.getAttribute('data-name'), sym=el.getAttribute('data-sym');
  fetch((location.origin||'')+'/wp-json/wp/v2/posts?per_page=4&search='+encodeURIComponent(name)+'&_fields=title,link,date')
    .then(function(r){return r.ok?r.json():[];})
    .then(function(p){
      if(p&&p.length){
        el.innerHTML='<ul class="mp-why__news">'+p.map(function(x){return '<li><a href="'+x.link+'">'+x.title.rendered+'</a></li>';}).join('')+'</ul>';
      } else {
        el.innerHTML='<p style="font-size:13px">No MoneyPuran articles on '+name+' yet. '
          +'<a target="_blank" rel="noopener" href="https://news.google.com/search?q='+encodeURIComponent(name+' share NSE')+'">Latest on Google News</a> · '
          +'<a href="'+(location.origin||'')+'/category/stocks/">Our Stocks section</a></p>';
      }
    }).catch(function(){ el.innerHTML=''; });
}());
</script>
<script type="application/ld+json"><?php echo wp_json_encode(array(
  '@context' => 'https://schema.org', '@type' => 'FAQPage',
  'mainEntity' => array(
    array('@type' => 'Question', 'name' => 'What is the ' . $name . ' share price today?',
      'acceptedAnswer' => array('@type' => 'Answer', 'text' => $name . ' (NSE: ' . $sym . ') is trading at Rs ' . number_format($an['last'], 2) . ', ' . ($up ? 'up' : 'down') . ' ' . abs($an['chg1d']) . '% on the day. Figures may be delayed.')),
    array('@type' => 'Question', 'name' => 'Is ' . $name . ' a good stock to buy?',
      'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'That depends on your goals, time horizon, risk appetite and the company fundamentals, which this page does not cover. MoneyPuran shows a rules-based momentum signal (' . $sig . ' today), not a recommendation. Consult a SEBI-registered investment adviser.')),
    array('@type' => 'Question', 'name' => 'What is the ' . $name . ' share price target?',
      'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'MoneyPuran does not publish price targets. Brokerages issue their own and often disagree. This page shows the live price and its trend versus key moving averages and the 52-week range.')),
  ),
), JSON_UNESCAPED_SLASHES); ?></script>

<style>
.mp-stk{margin:10px 0}
.mp-stk__hd{display:flex;flex-wrap:wrap;gap:12px;align-items:baseline}
.mp-stk__price{font-size:26px;font-weight:800}
.mp-stk__chg{font-weight:700}.mp-stk__chg.up{color:#16a34a}.mp-stk__chg.dn{color:#dc2626}
.mp-stk__sig{font-weight:800;font-size:15px;margin-left:auto}
.mp-stk__stamp{font-size:11.5px;color:var(--mp-muted,#64748b);margin:4px 0 0}
.mp-stk h2{font-size:19px;margin:22px 0 8px}
.mp-stk__tbl{width:100%;border-collapse:collapse;font-size:14px}
.mp-stk__tbl td{padding:9px 12px;border-bottom:1px solid var(--mp-border,#eef1f4)}
.mp-stk__tbl td:last-child{text-align:right;font-weight:600;font-variant-numeric:tabular-nums}
.mp-stk p{font-size:14.5px;line-height:1.6}
.mp-stk__disc{font-size:11.5px;color:var(--mp-muted,#64748b)}
.mp-stk__peers a,.mp-stk__dir a{display:inline-block;margin:0 8px 6px 0;font-size:13px;font-weight:600;color:var(--mp-brand,#0057ff)}
html[data-theme="dark"] .mp-stk__tbl td{border-color:rgba(255,255,255,.08)}
</style>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_stock_directory] --------------------------- */
add_shortcode('mp_stock_directory', function () {
    ob_start(); ?>
<div class="mp-stk__dir">
  <?php foreach (mp_md_screener_universe() as $sec => $def) : ?>
    <h3><a href="<?php echo esc_url(home_url('/stocks/sector/' . mp_md_sector_slug($sec) . '/')); ?>"><?php echo esc_html($sec); ?></a></h3>
    <p>
    <?php foreach ($def[1] as $sym => $name) : ?>
      <a href="<?php echo esc_url(home_url('/stocks/' . mp_md_stock_slug($sym) . '/')); ?>"><?php echo esc_html($name); ?></a>
    <?php endforeach; ?>
    </p>
  <?php endforeach; ?>
</div>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_sector_page] --------------------------- */
add_shortcode('mp_sector_page', function ($atts) {
    $want = isset($atts['sector']) ? trim($atts['sector']) : '';
    $u = mp_md_screener_universe();
    $sec = null;
    foreach ($u as $name => $def) {
        if (strcasecmp($name, $want) === 0 || mp_md_sector_slug($name) === mp_md_sector_slug($want)) { $sec = $name; break; }
    }
    if (!$sec) return '';
    $def = $u[$sec];
    $scr = mp_md_get_screener();
    $info = $scr['sectors'][$sec] ?? null;
    $ckey = mp_md_sector_candle_key($def[0]);

    ob_start(); ?>
<div class="mp-sec">
  <?php if ($info) : $sc = $info['chg']; ?>
  <p class="mp-sec__lead">The <?php echo esc_html($sec); ?> basket is
    <strong class="<?php echo ($sc ?? 0) >= 0 ? 'up' : 'dn'; ?>"><?php echo $sc === null ? 'flat' : (($sc >= 0 ? 'up ' : 'down ') . abs($sc) . '%'); ?></strong> today
    &mdash; <?php echo (int) $info['bull']; ?> of <?php echo (int) $info['total']; ?> stocks we track are showing a bullish signal, <?php echo (int) $info['bear']; ?> bearish.</p>
  <?php endif; ?>
  <p><?php echo esc_html(mp_md_sector_note($sec)); ?></p>

  <h2><?php echo esc_html($sec); ?> index chart</h2>
  <?php echo do_shortcode('[mp_candle_chart symbol="' . esc_attr($ckey) . '"]'); ?>

  <h2><?php echo esc_html($sec); ?> stocks</h2>
  <table class="mp-scr__tbl" style="table-layout:auto">
    <?php
      $rows = $info ? $info['stocks'] : array();
      if (!$rows) { foreach ($def[1] as $s => $nm) $rows[] = array('sym' => $s, 'name' => $nm, 'price' => null, 'chgPct' => null, 'signal' => 'Neutral'); }
      foreach ($rows as $r) : $up = ($r['chgPct'] ?? 0) >= 0; ?>
    <tr>
      <td><a href="<?php echo esc_url(home_url('/stocks/' . mp_md_stock_slug($r['sym']) . '/')); ?>"><?php echo esc_html($r['name']); ?></a></td>
      <td class="num"><?php echo $r['price'] !== null ? '&#8377;' . number_format($r['price'], 2) : '&mdash;'; ?></td>
      <td class="num mp-scr__chg <?php echo $up ? 'up' : 'dn'; ?>"><?php echo $r['chgPct'] === null ? '&mdash;' : (($up ? '+' : '') . $r['chgPct'] . '%'); ?></td>
      <td class="num"><span class="mp-scr__sig <?php echo esc_attr($r['signal']); ?>"><?php echo $r['signal'] === 'Bullish' ? '&#9650; Bull' : ($r['signal'] === 'Bearish' ? '&#9660; Bear' : '&#9679; Flat'); ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="mp-stk__disc">Signals are a rules-based reading of delayed price data. Not investment advice.</p>
  <p><a href="<?php echo esc_url(home_url('/stock-analysis/')); ?>">See all sectors &rarr;</a></p>
</div>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_index_page] --------------------------- */
add_shortcode('mp_index_page', function ($atts) {
    $key = isset($atts['index']) ? strtolower(trim($atts['index'])) : 'nifty';
    $map = array(
        'nifty'     => array('^NSEI', 'Nifty 50', 'nifty', 'India\'s benchmark index of the 50 largest NSE-listed companies'),
        'sensex'    => array('^BSESN', 'Sensex', 'sensex', 'the BSE\'s 30-stock benchmark, India\'s oldest equity index'),
        'banknifty' => array('^NSEBANK', 'Bank Nifty', 'banknifty', 'the index of India\'s most-traded banking stocks'),
    );
    if (!isset($map[$key])) $key = 'nifty';
    list($ysym, $label, $ckey, $desc) = $map[$key];

    $idx = mp_md_get_indices();
    $me  = null;
    foreach (($idx['indices'] ?? array()) as $v) if ($v['sym'] === $ysym) $me = $v;
    $scr = mp_md_get_screener();
    $scn = $scr['scenario'] ?? array();
    $leaders = $scr['leaders'] ?? array();

    ob_start(); ?>
<div class="mp-idx">
  <?php if ($me) : $up = ($me['chgPct'] ?? 0) >= 0; ?>
  <p class="mp-sec__lead"><strong><?php echo esc_html($label); ?> is at <?php echo number_format($me['price'], 2); ?></strong>,
    <span class="<?php echo $up ? 'up' : 'dn'; ?>"><?php echo ($up ? 'up ' : 'down ') . abs($me['chgPct']) . '%'; ?></span> today.
    <?php echo esc_html(ucfirst($desc)) . '.'; ?></p>
  <?php endif; ?>

  <h2><?php echo esc_html($label); ?> chart</h2>
  <?php echo do_shortcode('[mp_candle_chart symbol="' . esc_attr($ckey) . '"]'); ?>

  <h2>What&rsquo;s moving the <?php echo esc_html($label); ?> today</h2>
  <p>
    <?php
      if ($leaders) {
          $u3 = array_slice($leaders, 0, 3, true);
          $d3 = array_slice(array_reverse($leaders, true), 0, 3, true);
          echo 'Among sectors, <strong>' . esc_html(implode(', ', array_keys($u3))) . '</strong> are supporting the index while <strong>'
             . esc_html(implode(', ', array_keys($d3))) . '</strong> are the drag. ';
      }
      if ($scn) echo esc_html(($scn['usLine'] ?? '') . ', crude oil ' . ($scn['crudeLine'] ?? '') . ', and the rupee ' . ($scn['inrLine'] ?? '') . '.');
    ?>
  </p>
  <p><a href="<?php echo esc_url(home_url('/why-market-moved-today/')); ?>">Full "why the market moved" breakdown &rarr;</a></p>

  <h2>FAQ</h2>
  <div class="mp-why__faq">
    <details open><summary>What is the <?php echo esc_html($label); ?> at today?</summary>
      <p><?php echo $me ? esc_html($label . ' is at ' . number_format($me['price'], 2) . ', ' . (($me['chgPct'] ?? 0) >= 0 ? 'up ' : 'down ') . abs($me['chgPct'] ?? 0) . '% on the day.') : 'Live level is loading.'; ?> Figures may be delayed.</p></details>
    <details><summary>Why is the <?php echo esc_html($label); ?> up or down today?</summary>
      <p>Index moves are a blend of global sentiment, sector performance, fund flows and domestic data. The section above summarises today's drivers; the <a href="<?php echo esc_url(home_url('/why-market-moved-today/')); ?>">why the market moved</a> page has the full picture.</p></details>
    <details><summary>Where can I see the <?php echo esc_html($label); ?> live chart?</summary>
      <p>The chart on this page updates through the session (5-minute to weekly candles). <a href="<?php echo esc_url(home_url('/charts/')); ?>">Live Charts</a> has more instruments.</p></details>
  </div>
  <p class="mp-stk__disc">Information only. Not investment advice.</p>
</div>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_stock_list] --------------------------- */
add_shortcode('mp_stock_list', function ($atts) {
    $type = isset($atts['type']) ? strtolower(trim($atts['type'])) : 'gainers';
    $scr  = mp_md_get_screener();
    $all  = array();
    foreach (($scr['sectors'] ?? array()) as $sec => $info) {
        foreach ($info['stocks'] as $r) { $r['sector'] = $sec; $all[] = $r; }
    }
    if (!$all) return '<p>Data is loading &mdash; please refresh in a moment.</p>';

    if ($type === 'losers') {
        usort($all, function ($a, $b) { return ($a['chgPct'] ?? 0) <=> ($b['chgPct'] ?? 0); });
        $title = 'Top losers today';
    } elseif ($type === '52whigh' || $type === 'near-high') {
        usort($all, function ($a, $b) { return ($b['w52pos'] ?? -1) <=> ($a['w52pos'] ?? -1); });
        $title = 'Stocks near their 52-week high';
    } elseif ($type === '52wlow' || $type === 'near-low') {
        usort($all, function ($a, $b) { return ($a['w52pos'] ?? 101) <=> ($b['w52pos'] ?? 101); });
        $title = 'Stocks near their 52-week low';
    } else {
        usort($all, function ($a, $b) { return ($b['chgPct'] ?? 0) <=> ($a['chgPct'] ?? 0); });
        $title = 'Top gainers today';
    }
    $rows = array_slice($all, 0, 15);
    $ist  = gmdate('H:i', time() + 19800);

    ob_start(); ?>
<div class="mp-list">
  <p class="mp-stk__stamp"><?php echo esc_html($title); ?> &middot; among the ~54 large-caps MoneyPuran tracks &middot; updated <?php echo esc_html($ist); ?> IST</p>
  <table class="mp-scr__tbl" style="table-layout:auto">
    <?php foreach ($rows as $r) : $up = ($r['chgPct'] ?? 0) >= 0; ?>
    <tr>
      <td><a href="<?php echo esc_url(home_url('/stocks/' . mp_md_stock_slug($r['sym']) . '/')); ?>"><?php echo esc_html($r['name']); ?></a>
        <small style="display:block;color:var(--mp-muted,#64748b);font-size:11px"><?php echo esc_html($r['sector']); ?></small></td>
      <td class="num"><?php echo $r['price'] !== null ? '&#8377;' . number_format($r['price'], 2) : '&mdash;'; ?></td>
      <td class="num mp-scr__chg <?php echo $up ? 'up' : 'dn'; ?>"><?php echo $r['chgPct'] === null ? '&mdash;' : (($up ? '+' : '') . $r['chgPct'] . '%'); ?></td>
      <?php if (in_array($type, array('52whigh', '52wlow', 'near-high', 'near-low'), true)) : ?>
      <td class="num"><?php echo $r['w52pos'] !== null ? $r['w52pos'] . '% of range' : '&mdash;'; ?></td>
      <?php else : ?>
      <td class="num"><span class="mp-scr__sig <?php echo esc_attr($r['signal']); ?>"><?php echo $r['signal'] === 'Bullish' ? '&#9650; Bull' : ($r['signal'] === 'Bearish' ? '&#9660; Bear' : '&#9679; Flat'); ?></span></td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="mp-stk__disc">A list, not a recommendation. Figures may be delayed. Not investment advice.</p>
  <p><a href="<?php echo esc_url(home_url('/stock-analysis/')); ?>">Full sector screener &rarr;</a></p>
</div>
    <?php
    return ob_get_clean();
});

/* --------------------------- FII / DII --------------------------- */
function mp_md_fii_dii() {
    $ck = 'mp_fiidii_v1';
    $c  = get_transient($ck);
    if (is_array($c)) return $c;

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $home = wp_remote_get('https://www.nseindia.com/reports/fii-dii', array('timeout' => 8, 'headers' => array(
        'User-Agent' => $ua, 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'en-US,en;q=0.9',
    )));
    $cookies = is_wp_error($home) ? array() : wp_remote_retrieve_cookies($home);
    if ($cookies) {
        $res = wp_remote_get('https://www.nseindia.com/api/fiidiiTradeReact', array('timeout' => 8, 'cookies' => $cookies, 'headers' => array(
            'User-Agent' => $ua, 'Accept' => 'application/json', 'Referer' => 'https://www.nseindia.com/reports/fii-dii',
            'X-Requested-With' => 'XMLHttpRequest',
        )));
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $j = json_decode(wp_remote_retrieve_body($res), true);
            if (is_array($j) && !empty($j)) {
                $out = array('source' => 'NSE', 'rows' => $j, 'asOf' => gmdate('c'));
                set_transient($ck, $out, HOUR_IN_SECONDS);
                return $out;
            }
        }
    }
    $raw = (string) get_option('mp_fii_dii_json', '');
    $m   = $raw !== '' ? json_decode($raw, true) : null;
    $out = is_array($m) && !empty($m) ? array('source' => 'manual', 'rows' => $m, 'asOf' => null)
        : array('source' => 'none', 'rows' => null, 'asOf' => null);
    set_transient($ck, $out, 15 * MINUTE_IN_SECONDS);
    return $out;
}

add_shortcode('mp_fii_dii', function () {
    $d = mp_md_fii_dii();
    ob_start(); ?>
<div class="mp-fii">
  <?php if (!empty($d['rows'])) :
    $rows = $d['rows'];
    // normalise NSE shape: [{category, date, buyValue, sellValue, netValue}, ...]
    ?>
  <table class="mp-scr__tbl" style="table-layout:auto">
    <thead><tr><th>Category</th><th class="num">Buy (&#8377; cr)</th><th class="num">Sell (&#8377; cr)</th><th class="num">Net (&#8377; cr)</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) :
      $cat = $r['category'] ?? ($r['cat'] ?? '');
      $buy = $r['buyValue'] ?? ($r['buy'] ?? null);
      $sell= $r['sellValue'] ?? ($r['sell'] ?? null);
      $net = $r['netValue'] ?? ($r['net'] ?? (($buy !== null && $sell !== null) ? $buy - $sell : null));
      $np  = is_numeric($net) ? (float) $net : null; ?>
      <tr><td><?php echo esc_html($cat); ?></td>
        <td class="num"><?php echo is_numeric($buy) ? number_format((float) $buy, 1) : '&mdash;'; ?></td>
        <td class="num"><?php echo is_numeric($sell) ? number_format((float) $sell, 1) : '&mdash;'; ?></td>
        <td class="num <?php echo $np === null ? '' : ($np >= 0 ? 'up' : 'dn'); ?>" style="font-weight:700"><?php echo $np === null ? '&mdash;' : (($np >= 0 ? '+' : '') . number_format($np, 1)); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="mp-stk__stamp">Source: <?php echo esc_html($d['source'] === 'NSE' ? 'NSE' : 'compiled from official data'); ?><?php echo !empty($rows[0]['date']) ? ' &middot; for ' . esc_html($rows[0]['date']) : ''; ?>. A positive net means net buying.</p>
  <?php else : ?>
  <p>Today&rsquo;s FII/DII figures aren&rsquo;t available here right now. The National Stock Exchange publishes them each evening at
    <a href="https://www.nseindia.com/reports/fii-dii" target="_blank" rel="noopener nofollow">nseindia.com/reports/fii-dii</a>.</p>
  <?php endif; ?>

  <h2>What FII and DII activity means</h2>
  <p><strong>FIIs (Foreign Institutional Investors)</strong> are overseas funds &mdash; pension funds, sovereign funds, hedge funds &mdash; that invest in Indian shares. <strong>DIIs (Domestic Institutional Investors)</strong> are Indian mutual funds, insurers and banks. The exchange reports how much each group bought and sold in the cash market each day.</p>
  <p>Traders watch it because these two groups move large amounts and often lean opposite ways: when FIIs sell heavily, DIIs frequently absorb it, and vice versa. Sustained FII selling can pressure the index and the rupee; sustained buying tends to support both. It is one input among many &mdash; not a timing signal on its own.</p>
  <div class="mp-why__faq">
    <details><summary>Where does this data come from?</summary><p>The daily cash-market provisional figures are published by the NSE (and BSE) after the close. Monthly and detailed data also come from the exchanges and SEBI.</p></details>
    <details><summary>Does FII buying mean the market will go up?</summary><p>Not reliably. Heavy FII buying is generally supportive, but the market also reacts to global cues, earnings, rates and domestic flows. Treat FII/DII data as context, not a forecast.</p></details>
  </div>
  <p class="mp-stk__disc">Information only. Not investment advice.</p>
</div>
    <?php
    return ob_get_clean();
});

/* Manual FII/DII fallback — Settings -> Reading -> "FII / DII data (JSON)". */
add_action('admin_init', function () {
    register_setting('reading', 'mp_fii_dii_json', array('type' => 'string', 'sanitize_callback' => function ($v) {
        delete_transient('mp_fiidii_v1');
        return is_string($v) ? $v : '';
    }));
    add_settings_field('mp_fii_dii_json', 'FII / DII data (JSON)', function () {
        echo '<textarea name="mp_fii_dii_json" rows="4" class="large-text code" placeholder=\'[{"category":"FII/FPI","date":"03-Sep-2026","buyValue":12000,"sellValue":13500,"netValue":-1500},{"category":"DII","buyValue":11000,"sellValue":9800,"netValue":1200}]\'>'
           . esc_textarea((string) get_option('mp_fii_dii_json', '')) . '</textarea>'
           . '<p class="description">Used only when the live NSE feed is unavailable. Paste the array from nseindia.com/reports/fii-dii.</p>';
    }, 'reading');
});

/* ════════════════════════════════════════════════════════════════════════════
 * AI STOCK ANALYZER — Phase 1 : deterministic quant + levels + patterns +
 * market context + weighted scoring + snapshot store + REST.
 * Layer 3 (the AI explanation) is added separately; this layer never guesses.
 * ════════════════════════════════════════════════════════════════════════════ */

const MP_AN_SNAP_VER = 1;

/* ---- helpers on numeric series ---- */
function mp_an_ema_series($vals, $p) {
    $vals = array_values(array_filter($vals, function ($v) { return $v !== null; }));
    $n = count($vals);
    $out = array_fill(0, $n, null);
    if ($n < $p) return $out;
    $k = 2 / ($p + 1);
    $seed = array_sum(array_slice($vals, 0, $p)) / $p;
    $out[$p - 1] = $seed;
    for ($i = $p; $i < $n; $i++) $out[$i] = ($vals[$i] - $out[$i - 1]) * $k + $out[$i - 1];
    return $out;
}
function mp_an_ema($vals, $p) { $s = mp_an_ema_series($vals, $p); $v = end($s); return ($v === false || $v === null) ? null : $v; }
function mp_an_last($arr) { $v = end($arr); return ($v === false || $v === null) ? null : $v; }
function mp_an_sma($vals, $p) {
    $vals = array_values($vals);
    if (count($vals) < $p) return null;
    return array_sum(array_slice($vals, -$p)) / $p;
}
function mp_an_stddev($vals) {
    $n = count($vals); if ($n < 2) return 0;
    $m = array_sum($vals) / $n;
    $s = 0; foreach ($vals as $v) $s += ($v - $m) * ($v - $m);
    return sqrt($s / $n);
}
function mp_an_slope($vals, $lookback = 10) {
    $vals = array_values(array_filter($vals, function ($v) { return $v !== null; }));
    $n = count($vals); if ($n < 3) return 0;
    $lb = min($lookback, $n - 1);
    $a = $vals[$n - 1 - $lb]; $b = $vals[$n - 1];
    if ($a == 0) return 0;
    return ($b - $a) / abs($a) * 100;
}

/* ---- indicators ---- */
function mp_an_rsi($closes, $p = 14) {
    $c = array_values($closes); $n = count($c);
    if ($n <= $p) return null;
    $gain = 0; $loss = 0;
    for ($i = 1; $i <= $p; $i++) { $d = $c[$i] - $c[$i - 1]; if ($d >= 0) { $gain += $d; } else { $loss -= $d; } }
    $ag = $gain / $p; $al = $loss / $p;
    for ($i = $p + 1; $i < $n; $i++) {
        $d = $c[$i] - $c[$i - 1];
        $g = $d > 0 ? $d : 0; $l = $d < 0 ? -$d : 0;
        $ag = ($ag * ($p - 1) + $g) / $p;
        $al = ($al * ($p - 1) + $l) / $p;
    }
    if ($al == 0) return 100;
    $rs = $ag / $al;
    return round(100 - 100 / (1 + $rs), 1);
}
function mp_an_macd($closes, $fast = 12, $slow = 26, $sig = 9) {
    $c = array_values(array_filter($closes, function ($v) { return $v !== null; }));
    if (count($c) < $slow + $sig) return null;
    $ef = mp_an_ema_series($c, $fast);
    $es = mp_an_ema_series($c, $slow);
    $line = array();
    foreach ($c as $i => $_) $line[$i] = ($ef[$i] !== null && $es[$i] !== null) ? $ef[$i] - $es[$i] : null;
    $sigS = mp_an_ema_series(array_values(array_filter($line, function ($v) { return $v !== null; })), $sig);
    $macd = mp_an_last($line);
    $signal = mp_an_last($sigS);
    $prevLine = $line[count($line) - 2] ?? null;
    return array(
        'macd'   => $macd !== null ? round($macd, 3) : null,
        'signal' => $signal !== null ? round($signal, 3) : null,
        'hist'   => ($macd !== null && $signal !== null) ? round($macd - $signal, 3) : null,
        'rising' => ($macd !== null && $prevLine !== null) ? ($macd > $prevLine) : null,
        'state'  => ($macd !== null && $signal !== null) ? ($macd > $signal ? 'bullish' : 'bearish') : null,
    );
}
function mp_an_adx($h, $l, $c, $p = 14) {
    $h = array_values($h); $l = array_values($l); $c = array_values($c);
    $n = count($c);
    if ($n <= 2 * $p) return null;
    $trs = array(); $pdm = array(); $ndm = array();
    for ($i = 1; $i < $n; $i++) {
        $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
        $up = $h[$i] - $h[$i - 1]; $dn = $l[$i - 1] - $l[$i];
        $trs[] = $tr;
        $pdm[] = ($up > $dn && $up > 0) ? $up : 0;
        $ndm[] = ($dn > $up && $dn > 0) ? $dn : 0;
    }
    $wilder = function ($arr, $p) {
        $sm = array_sum(array_slice($arr, 0, $p));
        $out = array($sm);
        for ($i = $p; $i < count($arr); $i++) { $sm = $sm - $sm / $p + $arr[$i]; $out[] = $sm; }
        return $out;
    };
    $tr14 = $wilder($trs, $p); $pdm14 = $wilder($pdm, $p); $ndm14 = $wilder($ndm, $p);
    $dx = array();
    for ($i = 0; $i < count($tr14); $i++) {
        if ($tr14[$i] == 0) { $dx[] = 0; continue; }
        $pdi = 100 * $pdm14[$i] / $tr14[$i];
        $ndi = 100 * $ndm14[$i] / $tr14[$i];
        $sum = $pdi + $ndi;
        $dx[] = $sum == 0 ? 0 : 100 * abs($pdi - $ndi) / $sum;
    }
    if (count($dx) < $p) return null;
    $adx = array_sum(array_slice($dx, 0, $p)) / $p;
    for ($i = $p; $i < count($dx); $i++) $adx = ($adx * ($p - 1) + $dx[$i]) / $p;
    $tr = end($tr14); $pdmL = end($pdm14); $ndmL = end($ndm14);
    return array(
        'adx'     => round($adx, 1),
        'plusDI'  => $tr ? round(100 * $pdmL / $tr, 1) : null,
        'minusDI' => $tr ? round(100 * $ndmL / $tr, 1) : null,
    );
}
function mp_an_stoch_rsi($closes, $p = 14) {
    $c = array_values($closes); $n = count($c);
    if ($n < 3 * $p) return null;
    $rsis = array();
    for ($i = $p; $i < $n; $i++) $rsis[] = mp_an_rsi(array_slice($c, 0, $i + 1), $p);
    $rsis = array_values(array_filter($rsis, function ($v) { return $v !== null; }));
    if (count($rsis) < $p) return null;
    $win = array_slice($rsis, -$p);
    $mn = min($win); $mx = max($win);
    $k = ($mx > $mn) ? round(($rsis[count($rsis) - 1] - $mn) / ($mx - $mn) * 100, 1) : 50;
    return array('k' => $k);
}
function mp_an_cci($h, $l, $c, $p = 20) {
    $h = array_values($h); $l = array_values($l); $c = array_values($c);
    $n = count($c); if ($n < $p) return null;
    $tp = array();
    for ($i = 0; $i < $n; $i++) $tp[] = ($h[$i] + $l[$i] + $c[$i]) / 3;
    $win = array_slice($tp, -$p);
    $sma = array_sum($win) / $p;
    $md = 0; foreach ($win as $v) $md += abs($v - $sma);
    $md /= $p;
    if ($md == 0) return 0;
    return round(($tp[$n - 1] - $sma) / (0.015 * $md), 1);
}
function mp_an_obv($closes, $vols) {
    $c = array_values($closes); $v = array_values($vols); $n = count($c);
    if ($n < 10) return null;
    $obv = 0; $series = array(0);
    for ($i = 1; $i < $n; $i++) {
        if ($c[$i] > $c[$i - 1]) $obv += $v[$i];
        elseif ($c[$i] < $c[$i - 1]) $obv -= $v[$i];
        $series[] = $obv;
    }
    return array('last' => $obv, 'slopePct' => round(mp_an_slope($series, 10), 1));
}
function mp_an_atr($h, $l, $c, $p = 14) {
    $h = array_values($h); $l = array_values($l); $c = array_values($c);
    $n = count($c); if ($n <= $p) return null;
    $trs = array();
    for ($i = 1; $i < $n; $i++) $trs[] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
    $atr = array_sum(array_slice($trs, 0, $p)) / $p;
    for ($i = $p; $i < count($trs); $i++) $atr = ($atr * ($p - 1) + $trs[$i]) / $p;
    return round($atr, 2);
}
function mp_an_vwap($h, $l, $c, $vols) {
    $h = array_values($h); $l = array_values($l); $c = array_values($c); $v = array_values($vols);
    $pv = 0; $vv = 0;
    for ($i = 0; $i < count($c); $i++) {
        $tp = ($h[$i] + $l[$i] + $c[$i]) / 3;
        $pv += $tp * $v[$i]; $vv += $v[$i];
    }
    return $vv > 0 ? round($pv / $vv, 2) : null;
}

/* ---- support / resistance ---- */
function mp_an_levels($bars, $price) {
    $n = count($bars);
    if ($n < 20) return null;
    $H = array_column($bars, 2); $L = array_column($bars, 3); $C = array_column($bars, 4);

    // fractal swing points (2 bars each side)
    $sw_hi = array(); $sw_lo = array();
    for ($i = 2; $i < $n - 2; $i++) {
        if ($H[$i] >= $H[$i - 1] && $H[$i] >= $H[$i - 2] && $H[$i] >= $H[$i + 1] && $H[$i] >= $H[$i + 2]) $sw_hi[] = $H[$i];
        if ($L[$i] <= $L[$i - 1] && $L[$i] <= $L[$i - 2] && $L[$i] <= $L[$i + 1] && $L[$i] <= $L[$i + 2]) $sw_lo[] = $L[$i];
    }
    $recentHi = array_slice($sw_hi, -12);
    $recentLo = array_slice($sw_lo, -12);

    // classic pivots from the last completed bar (n-2; n-1 may still be forming)
    $lb = $bars[$n - 2];
    $p = ($lb[2] + $lb[3] + $lb[4]) / 3;
    $piv = array(
        'p'  => round($p, 2),
        'r1' => round(2 * $p - $lb[3], 2), 'r2' => round($p + ($lb[2] - $lb[3]), 2),
        's1' => round(2 * $p - $lb[2], 2), 's2' => round($p - ($lb[2] - $lb[3]), 2),
    );

    // fib retracement over the last major swing (highest high vs lowest low of last ~60 bars)
    $win = array_slice($bars, -60);
    $wh = max(array_column($win, 2)); $wl = min(array_column($win, 3));
    $rng = $wh - $wl;
    $fib = $rng > 0 ? array(
        'high' => round($wh, 2), 'low' => round($wl, 2),
        'f382' => round($wh - $rng * 0.382, 2),
        'f5'   => round($wh - $rng * 0.5, 2),
        'f618' => round($wh - $rng * 0.618, 2),
    ) : null;

    // assemble candidate levels, split by side of current price
    $cand = array_merge($recentHi, $recentLo, array($piv['r1'], $piv['r2'], $piv['s1'], $piv['s2'], $lb[2], $lb[3]));
    if ($fib) $cand = array_merge($cand, array($fib['f382'], $fib['f5'], $fib['f618']));
    $res = array(); $sup = array();
    foreach ($cand as $lvl) {
        $lvl = round($lvl, 2);
        if ($lvl > $price * 1.001) $res[] = $lvl;
        elseif ($lvl < $price * 0.999) $sup[] = $lvl;
    }
    // dedupe within 0.4%
    $dedupe = function ($arr, $asc) {
        sort($arr);
        if (!$asc) $arr = array_reverse($arr);
        $out = array();
        foreach ($arr as $v) {
            $ok = true;
            foreach ($out as $o) if (abs($v - $o) / max($o, 1) < 0.004) { $ok = false; break; }
            if ($ok) $out[] = $v;
        }
        return $out;
    };
    $res = array_slice($dedupe($res, true), 0, 3);
    $sup = array_slice($dedupe($sup, false), 0, 3);

    return array(
        'resistance' => $res,
        'support'    => $sup,
        'pivots'     => $piv,
        'fib'        => $fib,
        'nearestResistance' => $res ? $res[0] : null,
        'nearestSupport'    => $sup ? $sup[0] : null,
        'distResistancePct' => ($res && $price) ? round(($res[0] - $price) / $price * 100, 2) : null,
        'distSupportPct'    => ($sup && $price) ? round(($price - $sup[0]) / $price * 100, 2) : null,
    );
}

/* ---- candlestick patterns (last 1-3 bars, confluence only) ---- */
function mp_an_patterns($bars) {
    $n = count($bars); if ($n < 3) return array();
    $b2 = $bars[$n - 3]; $b1 = $bars[$n - 2]; $b0 = $bars[$n - 1];
    $o2 = $b2[1]; $h2 = $b2[2]; $l2 = $b2[3]; $c2 = $b2[4];
    $o1 = $b1[1]; $h1 = $b1[2]; $l1 = $b1[3]; $c1 = $b1[4];
    $o0 = $b0[1]; $h0 = $b0[2]; $l0 = $b0[3]; $c0 = $b0[4];
    $body0 = abs($c0 - $o0); $rng0 = max($h0 - $l0, 0.0001);
    $upper0 = $h0 - max($c0, $o0); $lower0 = min($c0, $o0) - $l0;
    $out = array();
    // Hammer / Shooting star
    if ($body0 / $rng0 < 0.35 && $lower0 > 2 * $body0 && $upper0 < $body0) $out[] = array('name' => 'Hammer', 'dir' => 'bullish');
    if ($body0 / $rng0 < 0.35 && $upper0 > 2 * $body0 && $lower0 < $body0) $out[] = array('name' => 'Shooting Star', 'dir' => 'bearish');
    // Doji
    if ($body0 / $rng0 < 0.1) $out[] = array('name' => 'Doji', 'dir' => 'neutral');
    // Engulfing
    if ($c1 < $o1 && $c0 > $o0 && $c0 >= $o1 && $o0 <= $c1) $out[] = array('name' => 'Bullish Engulfing', 'dir' => 'bullish');
    if ($c1 > $o1 && $c0 < $o0 && $o0 >= $c1 && $c0 <= $o1) $out[] = array('name' => 'Bearish Engulfing', 'dir' => 'bearish');
    // Piercing / Dark cloud
    $mid1 = ($o1 + $c1) / 2;
    if ($c1 < $o1 && $o0 < $l1 && $c0 > $mid1 && $c0 < $o1) $out[] = array('name' => 'Piercing Line', 'dir' => 'bullish');
    if ($c1 > $o1 && $o0 > $h1 && $c0 < $mid1 && $c0 > $o1) $out[] = array('name' => 'Dark Cloud Cover', 'dir' => 'bearish');
    // Morning / Evening star
    if ($o2 !== null && $c2 !== null) {
        $sm1 = abs($c1 - $o1) < abs($c2 - $o2) * 0.5;
        if ($c2 < $o2 && $sm1 && $c0 > $o0 && $c0 > ($o2 + $c2) / 2) $out[] = array('name' => 'Morning Star', 'dir' => 'bullish');
        if ($c2 > $o2 && $sm1 && $c0 < $o0 && $c0 < ($o2 + $c2) / 2) $out[] = array('name' => 'Evening Star', 'dir' => 'bearish');
    }
    return $out;
}

/* ---- market context + regime + sector relative strength ---- */
function mp_an_context($sym) {
    $scn = function_exists('mp_md_screener_scenario') ? mp_md_screener_scenario() : array();
    $niftyChg = $scn['niftyChg'] ?? null;
    $vix = isset($scn['vix']['level']) ? $scn['vix']['level'] : null;
    $usMean = null;
    $g = $scn['global'] ?? array();
    $tmp = array();
    foreach (array('dow', 'nasdaq', 'sp500') as $k) if (isset($g[$k]['chg']) && $g[$k]['chg'] !== null) $tmp[] = $g[$k]['chg'];
    if ($tmp) $usMean = array_sum($tmp) / count($tmp);

    $regime = 'neutral';
    $pts = 0;
    if ($niftyChg !== null) $pts += ($niftyChg > 0.3 ? 1 : ($niftyChg < -0.3 ? -1 : 0));
    if ($usMean !== null)   $pts += ($usMean > 0.3 ? 1 : ($usMean < -0.3 ? -1 : 0));
    if ($vix !== null)       $pts += ($vix < 14 ? 1 : ($vix > 20 ? -1 : 0));
    if ($pts >= 2) $regime = 'risk-on';
    elseif ($pts <= -2) $regime = 'risk-off';

    // sector RS: stock 1m return vs sector index 1m return vs nifty 1m
    $rs = null; $secName = null; $secChg1m = null; $stkChg1m = null;
    $flat = function_exists('mp_md_stock_universe_flat') ? mp_md_stock_universe_flat() : array();
    $bareSym = preg_replace('/\.(NS|BO)$/', '', $sym);
    if (isset($flat[$bareSym])) {
        $secName = $flat[$bareSym]['sector'];
        $secKey = function_exists('mp_md_sector_candle_key') ? mp_md_sector_candle_key($flat[$bareSym]['secIndex']) : 'nifty';
        $sb = mp_candle_ohlc($secKey, '1D')['bars'] ?? array();
        $kb = mp_candle_ohlc($sym, '1D')['bars'] ?? array();
        $r1m = function ($b) {
            $c = array_column($b, 4); $n = count($c);
            return ($n > 22 && $c[$n - 22] != 0) ? ($c[$n - 1] - $c[$n - 22]) / $c[$n - 22] * 100 : null;
        };
        $secChg1m = $sb ? $r1m($sb) : null;
        $stkChg1m = $kb ? $r1m($kb) : null;
        if ($stkChg1m !== null && $secChg1m !== null) $rs = round($stkChg1m - $secChg1m, 1);
    }

    return array(
        'niftyChg'  => $niftyChg,
        'spxChg'    => $scn['global']['sp500']['chg'] ?? null,
        'vix'       => $vix,
        'regime'    => $regime,
        'usLine'    => $scn['usLine'] ?? null,
        'crudeLine' => $scn['crudeLine'] ?? null,
        'inrLine'   => $scn['inrLine'] ?? null,
        'sector'    => $secName,
        'sectorRS1m' => $rs,
        'stockChg1m' => $stkChg1m !== null ? round($stkChg1m, 1) : null,
        'sectorChg1m' => $secChg1m !== null ? round($secChg1m, 1) : null,
    );
}

/* ---- scoring config (weights + bands) ---- */
function mp_an_scoring_config() {
    return apply_filters('mp_an_scoring_config', array(
        'weights' => array(
            'trend' => 20, 'momentum' => 15, 'volume' => 10, 'levels' => 10,
            'fo' => 20, 'market' => 10, 'sector' => 5, 'news' => 5, 'fundamentals' => 5,
        ),
        'modeWeights' => array(
            'intraday'   => array('trend' => 15, 'momentum' => 20, 'volume' => 15, 'levels' => 15, 'fo' => 15, 'market' => 15, 'sector' => 3, 'news' => 2, 'fundamentals' => 0),
            'positional' => array('trend' => 22, 'momentum' => 10, 'volume' => 6,  'levels' => 8,  'fo' => 10, 'market' => 12, 'sector' => 8, 'news' => 6, 'fundamentals' => 18),
        ),
        'bands' => array(
            array(80, 'BULLISH'), array(65, 'MODERATELY_BULLISH'),
            array(45, 'NEUTRAL'), array(30, 'MODERATELY_BEARISH'), array(0, 'BEARISH'),
        ),
    ));
}
function mp_an_view_for_score($score) {
    foreach (mp_an_scoring_config()['bands'] as $b) if ($score >= $b[0]) return $b[1];
    return 'NEUTRAL';
}

/* ---- factor scores from computed data (0-100, 50 = neutral) ---- */
function mp_an_factor_scores($tech, $levels, $ctx, $quote, $bars) {
    $clamp = function ($v) { return (int) round(max(0, min(100, $v))); };
    $lastBar = end($bars);
    $price = $quote['price'] ?? (is_array($lastBar) ? ($lastBar[4] ?? null) : null);
    $C = array_column($bars, 4);
    $chg = $quote['chgPct'] ?? null;
    $f = array();

    // trend
    $t = 50;
    if ($price !== null) {
        foreach (array('ema20' => 12, 'ema50' => 10, 'ema200' => 10) as $k => $w) {
            if (!empty($tech[$k])) $t += ($price > $tech[$k] ? $w : -$w);
        }
    }
    if (!empty($tech['ema20']) && !empty($tech['ema50']) && !empty($tech['ema200'])) {
        if ($tech['ema20'] > $tech['ema50'] && $tech['ema50'] > $tech['ema200']) $t += 8;
        elseif ($tech['ema20'] < $tech['ema50'] && $tech['ema50'] < $tech['ema200']) $t -= 8;
    }
    $f['trend'] = $clamp($t);

    // momentum
    $m = 50; $has = false;
    if (isset($tech['rsi'])) {
        $has = true; $r = $tech['rsi'];
        if ($r >= 70) $m += 12; elseif ($r >= 55) $m += 16; elseif ($r <= 30) $m -= 20; elseif ($r <= 45) $m -= 12;
    }
    if (!empty($tech['macd']['state'])) { $has = true; $m += ($tech['macd']['state'] === 'bullish' ? 10 : -10); if (!empty($tech['macd']['rising'])) $m += 5; }
    if (isset($tech['adx']['adx']) && $tech['adx']['adx'] >= 25 && isset($tech['adx']['plusDI'], $tech['adx']['minusDI'])) {
        $has = true; $m += ($tech['adx']['plusDI'] > $tech['adx']['minusDI'] ? 10 : -10);
    }
    $f['momentum'] = $has ? $clamp($m) : null;

    // volume
    $v = 50; $hv = false;
    if (isset($tech['relVol']) && $chg !== null) {
        $hv = true;
        if ($tech['relVol'] >= 1.5) $v += ($chg >= 0 ? 15 : -15);
        elseif ($tech['relVol'] < 0.7) $v -= 4;
    }
    if (isset($tech['obv']['slopePct'])) { $hv = true; $v += max(-12, min(12, $tech['obv']['slopePct'] * 1.5)); }
    $f['volume'] = $hv ? $clamp($v) : null;

    // levels — room to run vs hugging resistance
    $lv = 50;
    if ($levels && $levels['distResistancePct'] !== null && $levels['distSupportPct'] !== null) {
        $dr = $levels['distResistancePct']; $ds = $levels['distSupportPct'];
        if ($dr + $ds > 0) $lv = 50 + (($dr - $ds) / ($dr + $ds)) * 22;
        // fresh breakout: price just above a former resistance (last bar low < nearestSupport-ish)
    }
    $f['levels'] = $clamp($lv);

    // market
    $mk = array('risk-on' => 64, 'neutral' => 50, 'risk-off' => 36)[$ctx['regime']] ?? 50;
    if ($ctx['niftyChg'] !== null) $mk += max(-8, min(8, $ctx['niftyChg'] * 4));
    $f['market'] = $clamp($mk);

    // sector
    $f['sector'] = ($ctx['sectorRS1m'] === null) ? null : $clamp(50 + max(-25, min(25, $ctx['sectorRS1m'] * 2.5)));

    // news / fundamentals — Phase 1 stubs (real in later layers)
    $f['news'] = null;
    $f['fundamentals'] = null;
    $f['fo'] = null; // Phase 2

    return $f;
}

/* ---- weighted score + confidence ---- */
function mp_an_score($factors, $mode, $vix) {
    $cfg = mp_an_scoring_config();
    $w = $cfg['weights'];
    if ($mode !== 'swing' && isset($cfg['modeWeights'][$mode])) $w = $cfg['modeWeights'][$mode];

    $num = 0; $den = 0; $present = array();
    foreach ($w as $k => $weight) {
        if (!isset($factors[$k]) || $factors[$k] === null || $weight <= 0) continue;
        $num += $factors[$k] * $weight;
        $den += $weight;
        $present[] = $factors[$k];
    }
    $score = $den > 0 ? round($num / $den) : 50;
    $view  = mp_an_view_for_score($score);

    // confidence
    $scored = 0; $total = 0;
    foreach ($w as $k => $weight) { if ($weight > 0) { $total++; if (isset($factors[$k]) && $factors[$k] !== null) $scored++; } }
    $completeness = $total ? $scored / $total : 0;
    $agreement = count($present) >= 2 ? max(0, 1 - mp_an_stddev($present) / 32) : 0.4;
    $conf = (0.4 * $agreement + 0.45 * $completeness + 0.15) * 100;
    if ($vix !== null) { if ($vix > 25) $conf -= 18; elseif ($vix > 20) $conf -= 10; }
    $conf = (int) round(max(25, min(92, $conf)));

    return array('score' => (int) $score, 'view' => $view, 'confidence' => $conf, 'weightsUsed' => $w);
}

/* ---- scenarios from computed levels ---- */
function mp_an_scenarios($price, $levels, $atr) {
    if (!$levels) return null;
    $nr = $levels['nearestResistance']; $ns = $levels['nearestSupport'];
    $r2 = $levels['resistance'][1] ?? ($nr !== null ? round($nr * 1.02, 2) : null);
    $s2 = $levels['support'][1] ?? ($ns !== null ? round($ns * 0.98, 2) : null);
    $fmt = function ($a, $b) { return $a !== null && $b !== null ? number_format($a, 2) . '-' . number_format($b, 2) : ($a !== null ? number_format($a, 2) : 'n/a'); };
    return array(
        'bullish' => array(
            'condition'  => $nr !== null ? ('Sustained close above ' . number_format($nr, 2) . ' with above-average volume') : 'A decisive breakout above nearby resistance',
            'targetZone' => $fmt($nr !== null ? round($nr * 1.005, 2) : null, $r2),
        ),
        'neutral' => array(
            'condition' => ($ns !== null && $nr !== null) ? ('Price holds the ' . number_format($ns, 2) . '-' . number_format($nr, 2) . ' range') : 'Price consolidates around current levels',
        ),
        'bearish' => array(
            'condition'  => $ns !== null ? ('Break below ' . number_format($ns, 2) . ' with follow-through selling') : 'A breakdown below nearby support',
            'targetZone' => $fmt($s2, $ns !== null ? round($ns * 0.995, 2) : null),
        ),
    );
}

/* ════ orchestrator ════ */
function mp_an_mode_tf($mode) {
    return array('intraday' => '15m', 'swing' => '1D', 'positional' => '1W')[$mode] ?? '1D';
}
/**
 * Turn a free-text query (company name, index name, ticker with spaces) into a
 * symbol mp_candle_resolve() understands. Idempotent for clean tickers.
 */
function mp_an_resolve_query($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') return $raw;
    $u = strtoupper($raw);
    $alias = array(
        'NIFTY' => 'NIFTY', 'NIFTY50' => 'NIFTY', 'NIFTY 50' => 'NIFTY', 'NIFTY-50' => 'NIFTY', 'NSEI' => 'NIFTY',
        'BANKNIFTY' => 'BANKNIFTY', 'NIFTYBANK' => 'BANKNIFTY', 'BANK NIFTY' => 'BANKNIFTY', 'NIFTY BANK' => 'BANKNIFTY',
        'SENSEX' => 'SENSEX', 'BSE SENSEX' => 'SENSEX', 'BSESN' => 'SENSEX',
        'NIFTY IT' => 'NIFTYIT', 'NIFTY AUTO' => 'NIFTYAUTO', 'NIFTY PHARMA' => 'NIFTYPHARMA',
        'NIFTY FMCG' => 'NIFTYFMCG', 'NIFTY METAL' => 'NIFTYMETAL', 'NIFTY ENERGY' => 'NIFTYENERGY',
        'DOW' => 'DOW', 'DOW JONES' => 'DOW', 'DOWJONES' => 'DOW', 'DJI' => 'DOW',
        'NASDAQ' => 'NASDAQ', 'NASDAQ COMPOSITE' => 'NASDAQ', 'IXIC' => 'NASDAQ',
        'SP500' => 'SP500', 'S&P 500' => 'SP500', 'S&P500' => 'SP500', 'SPX' => 'SP500', 'SANDP500' => 'SP500', 'GSPC' => 'SP500',
    );
    if (isset($alias[$u])) return $alias[$u];

    // US mega-cap company names -> ticker (no dynamic "universe" for these, unlike NSE).
    $usAlias = array(
        'APPLE' => 'AAPL', 'APPLEINC' => 'AAPL',
        'MICROSOFT' => 'MSFT', 'MICROSOFTCORP' => 'MSFT',
        'ALPHABET' => 'GOOGL', 'GOOGLE' => 'GOOGL',
        'AMAZON' => 'AMZN', 'AMAZONCOM' => 'AMZN',
        'NVIDIA' => 'NVDA',
        'META' => 'META', 'FACEBOOK' => 'META', 'METAPLATFORMS' => 'META',
        'TESLA' => 'TSLA', 'TESLAINC' => 'TSLA',
        'JPMORGAN' => 'JPM', 'JPMORGANCHASE' => 'JPM', 'JPMORGANCHASECO' => 'JPM',
        'VISA' => 'V', 'VISAINC' => 'V',
        'WALMART' => 'WMT',
        'EXXON' => 'XOM', 'EXXONMOBIL' => 'XOM',
        'UNITEDHEALTH' => 'UNH', 'UNITEDHEALTHGROUP' => 'UNH',
    );
    $un = preg_replace('/[^A-Z0-9]/', '', $u);
    if (isset($usAlias[$un])) return $usAlias[$un];

    $flat = function_exists('mp_md_stock_universe_flat') ? mp_md_stock_universe_flat() : array();
    $tick = $un;
    if ($tick !== '' && isset($flat[$tick])) return $tick;

    $norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s)); };
    $qn = $norm($raw);
    if (strlen($qn) >= 3) {
        $pref = null; $sub = null;
        foreach ($flat as $sym => $m) {
            $nn = $norm(isset($m['name']) ? $m['name'] : '');
            if ($nn === '') continue;
            if ($nn === $qn) return $sym;
            if ($pref === null && (strpos($nn, $qn) === 0 || strpos($qn, $nn) === 0)) $pref = $sym;
            if ($sub === null && strpos($nn, $qn) !== false) $sub = $sym;
        }
        if ($pref !== null) return $pref;
        if ($sub !== null) return $sub;
    }
    return $tick !== '' ? $tick : $raw;
}

function mp_an_analyze($symbol_or_key, $mode = 'swing') {
    $mode = in_array($mode, array('intraday', 'swing', 'positional'), true) ? $mode : 'swing';
    $symbol_or_key = mp_an_resolve_query($symbol_or_key);
    list($ysym, $label) = mp_candle_resolve($symbol_or_key);
    $ck = 'mp_an_' . md5($ysym . '|' . $mode . '|' . MP_AN_SNAP_VER);
    $cached = get_transient($ck);
    if (is_array($cached)) return $cached;

    $tf = mp_an_mode_tf($mode);
    $d  = mp_candle_ohlc($symbol_or_key, $tf);
    $bars = $d['bars'] ?? array();
    if (count($bars) < 30) {
        return array('ok' => false, 'symbol' => $ysym, 'label' => $label, 'reason' => 'Not enough price history for ' . $label . '.');
    }
    $isIndex = (strpos($ysym, '^') === 0);
    $isNseStock = (substr($ysym, -3) === '.NS');
    $isUsStock = (!$isIndex && !$isNseStock && preg_match('/^[A-Z]{1,5}$/', $ysym) === 1);

    $C = array_column($bars, 4); $H = array_column($bars, 2); $L = array_column($bars, 3); $V = array_column($bars, 5);
    $quote = mp_md_yahoo_one($ysym);
    $price = $quote['price'] ?? end($C);
    $prev  = $quote['prev'] ?? ($C[count($C) - 2] ?? null);

    $vol20 = mp_an_sma($V, 20);
    $tech = array(
        'ema20'  => mp_an_ema($C, 20)  ? round(mp_an_ema($C, 20), 2)  : null,
        'ema50'  => mp_an_ema($C, 50)  ? round(mp_an_ema($C, 50), 2)  : null,
        'ema200' => mp_an_ema($C, 200) ? round(mp_an_ema($C, 200), 2) : null,
        'sma20'  => mp_an_sma($C, 20)  ? round(mp_an_sma($C, 20), 2)  : null,
        'sma50'  => mp_an_sma($C, 50)  ? round(mp_an_sma($C, 50), 2)  : null,
        'sma200' => mp_an_sma($C, 200) ? round(mp_an_sma($C, 200), 2) : null,
        'rsi'    => mp_an_rsi($C, 14),
        'macd'   => mp_an_macd($C),
        'adx'    => mp_an_adx($H, $L, $C, 14),
        'stochRsi' => mp_an_stoch_rsi($C, 14),
        'cci'    => mp_an_cci($H, $L, $C, 20),
        'obv'    => mp_an_obv($C, $V),
        'atr'    => mp_an_atr($H, $L, $C, 14),
        'relVol' => ($vol20 && $vol20 > 0) ? round($V[count($V) - 1] / $vol20, 2) : null,
        'vwap'   => ($mode === 'intraday') ? mp_an_vwap($H, $L, $C, $V) : null,
    );
    // golden / death cross (50 vs 200 SMA over last ~5 bars)
    $tech['cross'] = null;
    if (count($C) > 205) {
        $s50a = mp_an_sma(array_slice($C, 0, -5), 50); $s200a = mp_an_sma(array_slice($C, 0, -5), 200);
        if ($s50a && $s200a && $tech['sma50'] && $tech['sma200']) {
            if ($s50a <= $s200a && $tech['sma50'] > $tech['sma200']) $tech['cross'] = 'golden';
            elseif ($s50a >= $s200a && $tech['sma50'] < $tech['sma200']) $tech['cross'] = 'death';
        }
    }

    $levels = mp_an_levels($bars, $price);
    $patterns = mp_an_patterns($bars);
    $ctx = mp_an_context($ysym);
    $factors = mp_an_factor_scores($tech, $levels, $ctx, $quote ?: array('price' => $price, 'chgPct' => null), $bars);
    if (!$isNseStock) { $factors['sector'] = null; }
    $scored = mp_an_score($factors, $mode, $ctx['vix']);
    $scenarios = mp_an_scenarios($price, $levels, $tech['atr']);

    $chg1y = ($C[0] != 0) ? round(($price - $C[0]) / $C[0] * 100, 1) : null;
    $chg1m = (count($C) > 22 && $C[count($C) - 22] != 0) ? round(($price - $C[count($C) - 22]) / $C[count($C) - 22] * 100, 1) : null;

    $out = array(
        'ok'        => true,
        'symbol'    => $ysym,
        'label'     => $label,
        'exchange'  => $isNseStock ? 'NSE' : ($isIndex ? 'Index' : ($isUsStock ? 'US' : '')),
        'mode'      => $mode,
        'price'     => $price !== null ? round($price, 2) : null,
        'change'    => ($price !== null && $prev !== null) ? round($price - $prev, 2) : null,
        'changePct' => ($price !== null && $prev) ? round(($price - $prev) / $prev * 100, 2) : null,
        'chg1m'     => $chg1m,
        'chg1y'     => $chg1y,
        'view'      => $scored['view'],
        'confidence' => $scored['confidence'],
        'score'     => $scored['score'],
        'factorScores' => $factors,
        'weightsUsed'  => $scored['weightsUsed'],
        'technical' => $tech,
        'levels'    => $levels,
        'patterns'  => $patterns,
        'context'   => $ctx,
        'scenarios' => $scenarios,
        'fo'        => null,   // Phase 2
        'fundamentals' => null, // Phase 1.5
        'ai'        => null,   // Layer 3 attaches here
        'dataMeta'  => array('source' => 'Yahoo v8', 'asOf' => gmdate('c'), 'bars' => count($bars), 'tf' => $tf, 'quoteLive' => (bool) $quote),
        'disclaimer' => 'Analysis and educational information based on delayed market data. Not personalised investment advice. Consult a SEBI-registered adviser before investing.',
    );

    $out['ai'] = mp_an_ai_explain($out);
    mp_an_snapshot($out);
    set_transient($ck, $out, ($mode === 'intraday' ? 3 : 12) * MINUTE_IN_SECONDS);
    return $out;
}

/* ---- snapshot store (accuracy tracking, §30) ---- */
function mp_an_table_name() { global $wpdb; return $wpdb->prefix . 'mp_analysis_snapshots'; }
function mp_an_install_table() {
    if ((int) get_option('mp_an_snap_installed') === MP_AN_SNAP_VER) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = mp_an_table_name();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        symbol varchar(32) NOT NULL,
        mode varchar(16) NOT NULL,
        ts datetime NOT NULL,
        price decimal(14,2) DEFAULT NULL,
        av_view varchar(24) DEFAULT NULL,
        confidence tinyint(3) unsigned DEFAULT NULL,
        score tinyint(3) unsigned DEFAULT NULL,
        factor_scores longtext,
        support varchar(255) DEFAULT NULL,
        resistance varchar(255) DEFAULT NULL,
        regime varchar(16) DEFAULT NULL,
        ai_json longtext,
        data_meta longtext,
        PRIMARY KEY  (id),
        KEY symbol_ts (symbol,ts)
    ) $charset;");
    update_option('mp_an_snap_installed', MP_AN_SNAP_VER);
}
add_action('init', 'mp_an_install_table', 1);

function mp_an_snapshot($a) {
    if (empty($a['ok'])) return;
    global $wpdb;
    // one row per (symbol, mode) per hour — don't flood
    $t = mp_an_table_name();
    $since = gmdate('Y-m-d H:i:s', time() - 3300);
    $recent = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE symbol=%s AND mode=%s AND ts>%s LIMIT 1", $a['symbol'], $a['mode'], $since));
    if ($recent) return;
    $wpdb->insert($t, array(
        'symbol' => $a['symbol'], 'mode' => $a['mode'], 'ts' => gmdate('Y-m-d H:i:s'),
        'price' => $a['price'], 'av_view' => $a['view'], 'confidence' => $a['confidence'], 'score' => $a['score'],
        'factor_scores' => wp_json_encode($a['factorScores']),
        'support' => implode(',', $a['levels']['support'] ?? array()),
        'resistance' => implode(',', $a['levels']['resistance'] ?? array()),
        'regime' => $a['context']['regime'] ?? null,
        'ai_json' => $a['ai'] ? wp_json_encode($a['ai']) : null,
        'data_meta' => wp_json_encode($a['dataMeta']),
    ));
}


/* ════════════════════════════════════════════════════════════════════════════
 * AI STOCK ANALYZER — Phase 1 : Layer 3 (AI reasoning) + [mp_analyzer] UI
 * The AI explains the pre-computed numbers. It cannot change the score or view,
 * and falls back to a rules-based plain-English read when no LLM key is set.
 * ════════════════════════════════════════════════════════════════════════════ */

function mp_an_ai_key() {
    if (defined('MP_AI_ANALYSIS_KEY') && MP_AI_ANALYSIS_KEY) return (string) MP_AI_ANALYSIS_KEY;
    $o = trim((string) get_option('mp_ai_analysis_key', ''));
    return $o !== '' ? $o : '';
}
function mp_an_ai_model() {
    if (defined('MP_AI_ANALYSIS_MODEL')) return (string) MP_AI_ANALYSIS_MODEL;
    $o = trim((string) get_option('mp_ai_analysis_model', ''));
    return $o !== '' ? $o : 'gemini-2.0-flash';
}
add_action('admin_init', function () {
    register_setting('reading', 'mp_ai_analysis_key', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'));
    add_settings_field('mp_ai_analysis_key', 'AI Analyzer LLM key', function () {
        printf('<input type="text" name="mp_ai_analysis_key" value="%s" class="regular-text" placeholder="Google AI Studio API key" />'
            . '<p class="description">Optional. Powers the plain-English &ldquo;Why?&rdquo; on the AI Analyzer (Gemini). Blank = a rules-based explanation is used instead. Never changes the computed score/view.</p>',
            esc_attr(get_option('mp_ai_analysis_key', '')));
    }, 'reading');
});

/* compact facts for the model — never raw candles */
function mp_an_ai_compact($a) {
    $t = $a['technical']; $l = $a['levels']; $c = $a['context']; $p = $a['price'];
    $side = function ($v) use ($p) { return ($v && $p) ? ($p > $v ? 'above' : 'below') : null; };
    return array(
        'symbol' => $a['label'], 'exchange' => $a['exchange'], 'mode' => $a['mode'],
        'price' => $p, 'changePct' => $a['changePct'],
        'computedView' => $a['view'], 'computedScore' => $a['score'], 'computedConfidence' => $a['confidence'],
        'factorScores' => $a['factorScores'],
        'trend' => array('vsEma20' => $side($t['ema20']), 'vsEma50' => $side($t['ema50']), 'vsEma200' => $side($t['ema200']), 'cross' => $t['cross']),
        'momentum' => array('rsi' => $t['rsi'], 'macd' => $t['macd']['state'] ?? null, 'macdRising' => $t['macd']['rising'] ?? null, 'adx' => $t['adx']['adx'] ?? null),
        'volume' => array('relativeVolume' => $t['relVol'], 'obvSlopePct' => $t['obv']['slopePct'] ?? null),
        'levels' => array('support' => $l['support'] ?? array(), 'resistance' => $l['resistance'] ?? array(),
                          'nearestSupport' => $l['nearestSupport'] ?? null, 'nearestResistance' => $l['nearestResistance'] ?? null),
        'patterns' => array_map(function ($x) { return $x['name'] . ' (' . $x['dir'] . ')'; }, $a['patterns']),
        'market' => array('regime' => $c['regime'], 'niftyChangePct' => $c['niftyChg'], 'sp500ChangePct' => $c['spxChg'] ?? null, 'indiaVix' => $c['vix'],
                          'sector' => $c['sector'], 'sectorRelStrength1m' => $c['sectorRS1m']),
        'returns' => array('oneMonth' => $a['chg1m'], 'oneYear' => $a['chg1y']),
        'computedScenarios' => $a['scenarios'],
    );
}
function mp_an_ai_prompt() {
    return 'You explain PRE-COMPUTED market data for MoneyPuran. Explain WHY the computed view is what it is, in plain English. '
        . 'Rules: (1) never output a number not in the input; (2) never say buy or sell; '
        . '(3) overallView and confidence MUST equal computedView and computedConfidence exactly; '
        . '(4) every keyReason must trace to an input field; (5) targetZone values must come only from the input support/resistance. '
        . 'Return ONLY minified JSON: {"overallView":"","confidence":0,"keyReasons":["3-5"],"riskFactors":["2-4"],'
        . '"bullishScenario":{"condition":"","targetZone":""},"neutralScenario":{"condition":""},"bearishScenario":{"condition":"","targetZone":""}}';
}
function mp_an_ai_call($compact) {
    $key = mp_an_ai_key();
    if ($key === '') return null;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(mp_an_ai_model()) . ':generateContent?key=' . rawurlencode($key);
    $body = array(
        'systemInstruction' => array('parts' => array(array('text' => mp_an_ai_prompt()))),
        'contents' => array(array('parts' => array(array('text' => 'DATA: ' . wp_json_encode($compact))))),
        'generationConfig' => array('temperature' => 0.3, 'maxOutputTokens' => 800, 'responseMimeType' => 'application/json'),
    );
    $res = wp_remote_post($url, array('timeout' => 12, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode($body)));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
    $j = json_decode(wp_remote_retrieve_body($res), true);
    $txt = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $parsed = json_decode($txt, true);
    if (!is_array($parsed) || empty($parsed['keyReasons'])) return null;
    return $parsed;
}
/* rules-based plain-English read — the default, and the fallback */
function mp_an_ai_template($a) {
    $t = $a['technical']; $l = $a['levels']; $c = $a['context']; $p = $a['price'];
    $R = array(); $K = array();
    $above = array();
    foreach (array('20' => $t['ema20'], '50' => $t['ema50'], '200' => $t['ema200']) as $k => $v) if ($v && $p && $p > $v) $above[] = $k;
    if (count($above) >= 2) $R[] = 'Price is trading above its ' . implode('- and ', $above) . '-day moving averages.';
    elseif (count($above) === 0 && $t['ema20']) $R[] = 'Price is below all of its key moving averages &mdash; a weak trend structure.';
    if ($t['cross'] === 'golden') $R[] = 'A golden cross (50-day average above the 200-day) formed recently.';
    if ($t['cross'] === 'death') $K[] = 'A death cross (50-day average below the 200-day) formed recently.';
    if ($t['rsi'] !== null) {
        if ($t['rsi'] >= 70) { $R[] = 'RSI at ' . $t['rsi'] . ' shows strong momentum, though it is near overbought.'; $K[] = 'RSI near overbought can precede a pullback.'; }
        elseif ($t['rsi'] >= 55) $R[] = 'RSI at ' . $t['rsi'] . ' points to positive momentum without being stretched.';
        elseif ($t['rsi'] <= 30) $R[] = 'RSI at ' . $t['rsi'] . ' is in oversold territory.';
        elseif ($t['rsi'] <= 45) $R[] = 'RSI at ' . $t['rsi'] . ' reflects soft momentum.';
    }
    if (!empty($t['macd']['state'])) $R[] = 'MACD is ' . $t['macd']['state'] . (!empty($t['macd']['rising']) ? ' and rising' : '') . '.';
    if (isset($t['adx']['adx'])) {
        if ($t['adx']['adx'] >= 25) $R[] = 'ADX at ' . $t['adx']['adx'] . ' confirms a trending market.';
        else $K[] = 'ADX at ' . $t['adx']['adx'] . ' points to a weak or rangebound trend.';
    }
    if ($t['relVol'] !== null) {
        if ($t['relVol'] >= 1.5) $R[] = 'Volume is running at ' . $t['relVol'] . '&times; the 20-day average, ' . ((($a['changePct'] ?? 0) >= 0) ? 'supporting the up-move' : 'behind the decline') . '.';
        elseif ($t['relVol'] < 0.7) $K[] = 'Volume is below average, so the current move lacks conviction.';
    }
    if ($l && $l['nearestSupport'] !== null && $l['nearestResistance'] !== null) {
        $R[] = 'Nearby support sits near ' . number_format($l['nearestSupport'], 2) . ' and resistance near ' . number_format($l['nearestResistance'], 2) . '.';
        if ($l['distResistancePct'] !== null && $l['distResistancePct'] < 1) $K[] = 'Price is within ' . $l['distResistancePct'] . '% of resistance, capping near-term upside.';
    }
    foreach ($a['patterns'] as $pt) {
        if ($pt['dir'] === 'bullish') $R[] = 'A ' . $pt['name'] . ' candlestick formed on the latest bar.';
        elseif ($pt['dir'] === 'bearish') $K[] = 'A ' . $pt['name'] . ' candlestick formed on the latest bar.';
    }
    $isUs = (isset($a['exchange']) && $a['exchange'] === 'US');
    $reg = array('risk-on' => 'The broad market is in a risk-on mood', 'risk-off' => 'The broad market is risk-off', 'neutral' => 'The broad market is neutral');
    if ($isUs && isset($c['spxChg']) && $c['spxChg'] !== null) {
        $R[] = ($reg[$c['regime']] ?? 'The market backdrop is mixed') . ' (S&amp;P 500 ' . ($c['spxChg'] >= 0 ? '+' : '') . $c['spxChg'] . '%).';
    } else {
        $R[] = ($reg[$c['regime']] ?? 'The market backdrop is mixed')
            . ($c['niftyChg'] !== null ? ' (Nifty ' . ($c['niftyChg'] >= 0 ? '+' : '') . $c['niftyChg'] . '%)' : '') . '.';
    }
    if (!$isUs && $c['vix'] !== null && $c['vix'] > 18) $K[] = 'India VIX at ' . $c['vix'] . ' signals elevated volatility.';
    if ($c['sectorRS1m'] !== null && $c['sector']) {
        if ($c['sectorRS1m'] > 1) $R[] = 'It is outperforming the ' . $c['sector'] . ' sector by ' . $c['sectorRS1m'] . ' pts over the past month.';
        elseif ($c['sectorRS1m'] < -1) $K[] = 'It is lagging the ' . $c['sector'] . ' sector by ' . abs($c['sectorRS1m']) . ' pts over the past month.';
    }
    if (!$R) $R[] = 'The computed factors are mixed, which is why the view is ' . strtolower(str_replace('_', ' ', $a['view'])) . '.';
    if (!$K) $K[] = 'Broad-market volatility can override any single-stock setup.';
    return array(
        'overallView' => $a['view'], 'confidence' => $a['confidence'],
        'keyReasons'  => array_slice(array_values(array_unique($R)), 0, 5),
        'riskFactors' => array_slice(array_values(array_unique($K)), 0, 4),
        'bullishScenario' => $a['scenarios']['bullish'] ?? null,
        'neutralScenario' => $a['scenarios']['neutral'] ?? null,
        'bearishScenario' => $a['scenarios']['bearish'] ?? null,
        'source' => 'rules',
    );
}
function mp_an_ai_explain($a) {
    if (empty($a['ok'])) return null;
    $ck = 'mp_anai_' . md5($a['symbol'] . '|' . $a['mode'] . '|' . $a['view'] . '|' . $a['score']);
    $c = get_transient($ck);
    if (is_array($c)) return $c;
    $out = null;
    if (mp_an_ai_key() !== '') {
        $llm = mp_an_ai_call(mp_an_ai_compact($a));
        if (is_array($llm)) {
            $llm['overallView'] = $a['view'];
            $llm['confidence']  = $a['confidence'];
            foreach (array('bullishScenario', 'neutralScenario', 'bearishScenario') as $sk) {
                $native = str_replace('Scenario', '', $sk);
                if (empty($llm[$sk]) && !empty($a['scenarios'][$native])) $llm[$sk] = $a['scenarios'][$native];
            }
            $llm['source'] = 'ai';
            $out = $llm;
        }
    }
    if ($out === null) $out = mp_an_ai_template($a);
    set_transient($ck, $out, 30 * MINUTE_IN_SECONDS);
    return $out;
}

/* ════════════════════════════════════════════════════════════════════════════
 * [mp_analyzer]  —  the dashboard UI (spec §46). Server-rendered; the mode
 * switcher reloads with ?mode=. [mp_analyzer search="1"] renders the search hero.
 * ════════════════════════════════════════════════════════════════════════════ */
function mp_an_bar($label, $score) {
    if ($score === null) return '<div class="mp-az-fac"><span>' . esc_html($label) . '</span><span class="mp-az-fac__na">no data</span></div>';
    $cls = $score >= 60 ? 'g' : ($score <= 40 ? 'r' : 'n');
    return '<div class="mp-az-fac"><span>' . esc_html($label) . '</span>'
        . '<span class="mp-az-fac__bar"><i class="' . $cls . '" style="width:' . (int) $score . '%"></i></span>'
        . '<b>' . (int) $score . '</b></div>';
}
function mp_an_view_label($v) {
    return ucwords(strtolower(str_replace('_', ' ', $v)));
}
function mp_an_view_class($v) {
    if (strpos($v, 'BULLISH') !== false) return strpos($v, 'MODERATELY') !== false ? 'mbull' : 'bull';
    if (strpos($v, 'BEARISH') !== false) return strpos($v, 'MODERATELY') !== false ? 'mbear' : 'bear';
    return 'neut';
}

add_shortcode('mp_analyzer', function ($atts) {
    $atts = shortcode_atts(array('symbol' => '', 'mode' => '', 'search' => '', 'related' => '', 'chips' => '', 'placeholder' => ''), $atts);
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    $symbol = $atts['symbol'] !== '' ? $atts['symbol'] : $q;
    if ($symbol !== '') $symbol = mp_an_resolve_query($symbol);
    $mode = isset($_GET['mode']) ? sanitize_key($_GET['mode']) : ($atts['mode'] ?: 'swing');
    if (!in_array($mode, array('intraday', 'swing', 'positional'), true)) $mode = 'swing';

    ob_start();
    echo mp_an_ui_css();

    if ($symbol === '') {
        echo mp_an_search_hero($atts['chips'], $atts['placeholder']);
        return ob_get_clean();
    }

    $a = mp_an_analyze($symbol, $mode);

    if (empty($a['ok'])) {
        echo '<div class="mp-az mp-az--err">' . mp_an_search_hero($atts['chips'], $atts['placeholder'])
            . '<p class="mp-az__err">' . esc_html($a['reason'] ?? 'Could not analyse that symbol. Try an exact NSE symbol like RELIANCE or an index like NIFTY.') . '</p></div>';
        return ob_get_clean();
    }

    $ai   = $a['ai'] ?: array();
    $vc   = mp_an_view_class($a['view']);
    $up   = ($a['changePct'] ?? 0) >= 0;
    $base = get_permalink();
    $modeUrl = function ($m) use ($base, $symbol, $q) {
        $args = array('mode' => $m);
        if ($q !== '') $args['q'] = $q;
        return esc_url(add_query_arg($args, $base));
    };
    ?>
<div class="mp-az" data-symbol="<?php echo esc_attr($a['symbol']); ?>">

  <div class="mp-az__top">
    <div class="mp-az__id">
      <h2 class="mp-az__name"><?php echo esc_html($a['label']); ?><?php if ($a['exchange']) : ?> <span><?php echo esc_html($a['exchange']); ?></span><?php endif; ?></h2>
      <div class="mp-az__px">
        <b><?php echo $a['exchange'] === 'NSE' ? '&#8377;' : ($a['exchange'] === 'US' ? '$' : ''); ?><?php echo number_format($a['price'], 2); ?></b>
        <?php if ($a['changePct'] !== null) : ?>
        <span class="mp-az__chg <?php echo $up ? 'up' : 'dn'; ?>"><?php echo $up ? '&#9650;' : '&#9660;'; ?> <?php echo ($up ? '+' : '') . number_format($a['change'], 2); ?> (<?php echo ($up ? '+' : '') . $a['changePct']; ?>%)</span>
        <?php endif; ?>
      </div>
      <div class="mp-az__meta">Data may be delayed &middot; updated <?php echo esc_html(wp_date('H:i')); ?> IST &middot; source: <?php echo esc_html($a['dataMeta']['source']); ?></div>
    </div>
    <div class="mp-az__modes" role="tablist">
      <?php foreach (array('intraday' => 'Intraday', 'swing' => 'Swing', 'positional' => 'Positional') as $mk => $ml) : ?>
      <a href="<?php echo $modeUrl($mk); ?>" class="<?php echo $mode === $mk ? 'is-on' : ''; ?>"><?php echo esc_html($ml); ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="mp-az__view mp-az__view--<?php echo $vc; ?>">
    <div class="mp-az__view-main">
      <span class="mp-az__view-eyebrow">AI Market View &middot; <?php echo esc_html(ucfirst($mode)); ?></span>
      <strong class="mp-az__view-big"><?php echo esc_html(mp_an_view_label($a['view'])); ?></strong>
      <span class="mp-az__conf"><?php echo (int) $a['confidence']; ?><small>/100 model confidence</small></span>
    </div>
    <div class="mp-az__facs">
      <?php
      $order = array('trend' => 'Price trend', 'momentum' => 'Momentum', 'volume' => 'Volume', 'levels' => 'Support / resistance', 'market' => 'Market trend', 'sector' => 'Sector strength', 'fo' => 'Options data', 'news' => 'News', 'fundamentals' => 'Fundamentals');
      foreach ($order as $fk => $fl) {
        if (!array_key_exists($fk, $a['factorScores'])) continue;
        echo mp_an_bar($fl, $a['factorScores'][$fk]);
      }
      ?>
    </div>
  </div>

  <?php if (!empty($ai['keyReasons'])) : ?>
  <div class="mp-az__why">
    <h3>Why <?php echo esc_html(strtolower(mp_an_view_label($a['view']))); ?>?</h3>
    <ul class="mp-az__pro">
      <?php foreach ($ai['keyReasons'] as $r) echo '<li>' . wp_kses_post($r) . '</li>'; ?>
    </ul>
    <?php if (!empty($ai['riskFactors'])) : ?>
    <ul class="mp-az__con">
      <?php foreach ($ai['riskFactors'] as $r) echo '<li>' . wp_kses_post($r) . '</li>'; ?>
    </ul>
    <?php endif; ?>
    <p class="mp-az__ai-src"><?php echo $ai['source'] === 'ai' ? 'Explanation generated by AI from the computed data.' : 'Rules-based explanation from the computed indicators.'; ?></p>
  </div>
  <?php endif; ?>

  <div class="mp-az__grid">

    <?php if (!empty($a['levels']['support']) || !empty($a['levels']['resistance'])) : ?>
    <div class="mp-az__card">
      <h4>Key levels</h4>
      <table class="mp-az__lv">
        <?php foreach (array_reverse($a['levels']['resistance']) as $r) : ?>
        <tr class="r"><td>Resistance</td><td><?php echo number_format($r, 2); ?></td><td><?php echo $a['price'] ? '+' . round(($r - $a['price']) / $a['price'] * 100, 1) . '%' : ''; ?></td></tr>
        <?php endforeach; ?>
        <tr class="now"><td>Current</td><td><?php echo number_format($a['price'], 2); ?></td><td>&mdash;</td></tr>
        <?php foreach ($a['levels']['support'] as $s) : ?>
        <tr class="s"><td>Support</td><td><?php echo number_format($s, 2); ?></td><td><?php echo $a['price'] ? '&minus;' . round(($a['price'] - $s) / $a['price'] * 100, 1) . '%' : ''; ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($a['scenarios'])) : ?>
    <div class="mp-az__card">
      <h4>Scenarios</h4>
      <div class="mp-az__scn">
        <div class="b"><b>Bullish</b><span><?php echo esc_html($a['scenarios']['bullish']['condition']); ?><?php if (!empty($a['scenarios']['bullish']['targetZone']) && $a['scenarios']['bullish']['targetZone'] !== 'n/a') : ?> &rarr; <em><?php echo esc_html($a['scenarios']['bullish']['targetZone']); ?></em><?php endif; ?></span></div>
        <div class="n"><b>Neutral</b><span><?php echo esc_html($a['scenarios']['neutral']['condition']); ?></span></div>
        <div class="r"><b>Bearish</b><span><?php echo esc_html($a['scenarios']['bearish']['condition']); ?><?php if (!empty($a['scenarios']['bearish']['targetZone']) && $a['scenarios']['bearish']['targetZone'] !== 'n/a') : ?> &rarr; <em><?php echo esc_html($a['scenarios']['bearish']['targetZone']); ?></em><?php endif; ?></span></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="mp-az__card">
      <h4>Technical</h4>
      <?php
      $t = $a['technical'];
      $rows = array();
      if ($t['rsi'] !== null) $rows['RSI (14)'] = $t['rsi'] . ($t['rsi'] >= 70 ? ' overbought' : ($t['rsi'] <= 30 ? ' oversold' : ''));
      if (!empty($t['macd']['state'])) $rows['MACD'] = ucfirst($t['macd']['state']) . (!empty($t['macd']['rising']) ? ', rising' : '');
      if (isset($t['adx']['adx'])) $rows['ADX'] = $t['adx']['adx'] . ($t['adx']['adx'] >= 25 ? ' (trending)' : ' (weak)');
      if (isset($t['stochRsi']['k'])) $rows['Stoch RSI'] = $t['stochRsi']['k'];
      if ($t['cci'] !== null) $rows['CCI (20)'] = $t['cci'];
      if ($t['ema20'] !== null) $rows['EMA 20'] = number_format($t['ema20'], 2) . ($a['price'] > $t['ema20'] ? ' &#9650;' : ' &#9660;');
      if ($t['ema50'] !== null) $rows['EMA 50'] = number_format($t['ema50'], 2) . ($a['price'] > $t['ema50'] ? ' &#9650;' : ' &#9660;');
      if ($t['sma200'] !== null) $rows['SMA 200'] = number_format($t['sma200'], 2) . ($a['price'] > $t['sma200'] ? ' &#9650;' : ' &#9660;');
      if ($t['relVol'] !== null) $rows['Rel. volume'] = $t['relVol'] . '&times;';
      if ($t['atr'] !== null) $rows['ATR (14)'] = number_format($t['atr'], 2);
      if ($t['cross']) $rows['MA cross'] = ucfirst($t['cross']) . ' cross';
      echo '<table class="mp-az__kv">';
      foreach ($rows as $k => $v) echo '<tr><td>' . esc_html($k) . '</td><td>' . wp_kses_post($v) . '</td></tr>';
      echo '</table>';
      ?>
    </div>

    <div class="mp-az__card">
      <h4>Market context</h4>
      <table class="mp-az__kv">
        <tr><td>Regime</td><td><?php echo esc_html(ucwords(str_replace('-', ' ', $a['context']['regime']))); ?></td></tr>
        <?php if ($a['context']['niftyChg'] !== null) : ?><tr><td>Nifty today</td><td><?php echo ($a['context']['niftyChg'] >= 0 ? '+' : '') . $a['context']['niftyChg']; ?>%</td></tr><?php endif; ?>
        <?php if ($a['context']['vix'] !== null) : ?><tr><td>India VIX</td><td><?php echo $a['context']['vix']; ?><?php echo $a['context']['vix'] > 20 ? ' (elevated)' : ($a['context']['vix'] < 13 ? ' (calm)' : ' (moderate)'); ?></td></tr><?php endif; ?>
        <?php if ($a['context']['sector']) : ?><tr><td>Sector</td><td><?php echo esc_html($a['context']['sector']); ?><?php echo $a['context']['sectorRS1m'] !== null ? ' &middot; RS ' . ($a['context']['sectorRS1m'] >= 0 ? '+' : '') . $a['context']['sectorRS1m'] . ' pts (1m)' : ''; ?></td></tr><?php endif; ?>
        <?php if ($a['context']['usLine']) : ?><tr><td>Global</td><td><?php echo esc_html($a['context']['usLine']); ?></td></tr><?php endif; ?>
        <?php if ($a['chg1m'] !== null) : ?><tr><td>1-month</td><td><?php echo ($a['chg1m'] >= 0 ? '+' : '') . $a['chg1m']; ?>%</td></tr><?php endif; ?>
        <?php if ($a['chg1y'] !== null) : ?><tr><td>1-year</td><td><?php echo ($a['chg1y'] >= 0 ? '+' : '') . $a['chg1y']; ?>%</td></tr><?php endif; ?>
      </table>
    </div>
  </div>

  <div class="mp-az__chart">
    <h4>Price chart</h4>
    <?php echo do_shortcode('[mp_candle_chart symbol="' . esc_attr($symbol) . '" tf="' . ($mode === 'intraday' ? '15m' : ($mode === 'positional' ? '1W' : '1D')) . '"]'); ?>
  </div>

  <?php if (!empty($a['patterns'])) : ?>
  <p class="mp-az__pat"><b>Candlestick:</b> <?php echo esc_html(implode(', ', array_map(function ($x) { return $x['name']; }, $a['patterns']))); ?> &mdash; treated as confluence, not a standalone signal.</p>
  <?php endif; ?>

  <p class="mp-az__disc"><?php echo esc_html($a['disclaimer']); ?> Futures and options carry substantial risk; scenarios are analytical possibilities, not guaranteed outcomes.</p>
  <?php if (!empty($atts['related'])) echo mp_an_related_html($a); ?>
</div>
    <?php
    return ob_get_clean();
});

function mp_an_search_hero($chips = null, $placeholder = null) {
    $ex = $chips ? array_filter(array_map('trim', explode(',', $chips))) : array('RELIANCE', 'TCS', 'HDFCBANK', 'INFY', 'NIFTY', 'BANKNIFTY');
    $ph = $placeholder ? $placeholder : 'Enter a stock or index — e.g. RELIANCE, NIFTY';
    ob_start(); ?>
<div class="mp-az-hero">
  <form class="mp-az-search" method="get" action="">
    <input type="text" name="q" placeholder="<?php echo esc_attr($ph); ?>" autocomplete="off" required>
    <button type="submit">Analyze</button>
  </form>
  <div class="mp-az-ex">
    <?php foreach ($ex as $e) : ?><a href="<?php echo esc_url(add_query_arg('q', $e, get_permalink())); ?>"><?php echo esc_html($e); ?></a><?php endforeach; ?>
  </div>
  <p class="mp-az-hero__note">Technical, momentum, volume, support/resistance and market-context analysis with a transparent confidence score. Educational analysis, not investment advice.</p>
</div>
    <?php
    return ob_get_clean();
}

function mp_an_ui_css() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style id="mp-az-css">
.mp-az{--g:#16a34a;--r:#dc2626;--n:#6b7280;color:var(--mp-ink,#0f172a);margin:8px 0 28px}
.mp-az__top{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:16px}
.mp-az__name{font-size:22px;font-weight:800;margin:0 0 4px}
.mp-az__name span{font-size:11px;font-weight:700;letter-spacing:.05em;color:var(--mp-muted,#64748b);border:1px solid var(--mp-border,#e2e8f0);padding:2px 6px;border-radius:5px;vertical-align:middle}
.mp-az__px b{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums}
.mp-az__chg{font-size:14px;font-weight:600;margin-left:8px}
.mp-az__chg.up{color:var(--g)}.mp-az__chg.dn{color:var(--r)}
.mp-az__meta{font-size:11px;color:var(--mp-muted,#64748b);margin-top:4px}
.mp-az__modes{display:flex;gap:4px;background:var(--mp-surface2,#f1f5f9);padding:3px;border-radius:9px}
.mp-az__modes a{padding:6px 12px;font-size:12.5px;font-weight:600;border-radius:7px;color:var(--mp-muted,#64748b);text-decoration:none}
.mp-az__modes a.is-on{background:var(--mp-surface,#fff);color:var(--mp-ink,#0f172a);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.mp-az__view{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:20px;border:1px solid var(--mp-border,#e2e8f0);border-radius:14px;padding:20px 22px;background:var(--mp-surface,#fff)}
.mp-az__view--bull{border-left:4px solid var(--g)}.mp-az__view--mbull{border-left:4px solid #4ea86e}
.mp-az__view--bear{border-left:4px solid var(--r)}.mp-az__view--mbear{border-left:4px solid #d9737a}
.mp-az__view--neut{border-left:4px solid var(--n)}
.mp-az__view-eyebrow{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--mp-muted,#64748b)}
.mp-az__view-big{display:block;font-size:26px;font-weight:800;line-height:1.15;margin:6px 0 8px}
.mp-az__view--bull .mp-az__view-big,.mp-az__view--mbull .mp-az__view-big{color:var(--g)}
.mp-az__view--bear .mp-az__view-big,.mp-az__view--mbear .mp-az__view-big{color:var(--r)}
.mp-az__conf{font-size:22px;font-weight:800;font-variant-numeric:tabular-nums}
.mp-az__conf small{font-size:12px;font-weight:500;color:var(--mp-muted,#64748b);margin-left:4px}
.mp-az__facs{display:grid;gap:7px}
.mp-az-fac{display:grid;grid-template-columns:120px 1fr 26px;gap:10px;align-items:center;font-size:12.5px}
.mp-az-fac__bar{height:7px;border-radius:4px;background:var(--mp-surface2,#eef1f5);overflow:hidden}
.mp-az-fac__bar i{display:block;height:100%;border-radius:4px}
.mp-az-fac__bar i.g{background:var(--g)}.mp-az-fac__bar i.r{background:var(--r)}.mp-az-fac__bar i.n{background:#94a3b8}
.mp-az-fac b{font-variant-numeric:tabular-nums;text-align:right;font-size:12px}
.mp-az-fac__na{font-size:11px;color:var(--mp-muted,#94a3b8);grid-column:2/4}
.mp-az__why{margin:18px 0;border:1px solid var(--mp-border,#e2e8f0);border-radius:14px;padding:18px 22px;background:var(--mp-surface,#fff)}
.mp-az__why h3{font-size:16px;font-weight:700;margin:0 0 10px}
.mp-az__why ul{margin:0 0 8px;padding:0;list-style:none;display:grid;gap:6px;font-size:13.5px;line-height:1.5}
.mp-az__pro li::before{content:"\2713 ";color:var(--g);font-weight:700}
.mp-az__con li::before{content:"\26A0 ";color:#b45309}
.mp-az__ai-src{font-size:11px;color:var(--mp-muted,#94a3b8);margin:6px 0 0}
.mp-az__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin:18px 0}
.mp-az__card{border:1px solid var(--mp-border,#e2e8f0);border-radius:12px;padding:14px 16px;background:var(--mp-surface,#fff)}
.mp-az__card h4{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mp-muted,#64748b);margin:0 0 10px}
.mp-az__kv,.mp-az__lv{width:100%;border-collapse:collapse;font-size:13px}
.mp-az__kv td,.mp-az__lv td{padding:5px 0;border-top:1px solid var(--mp-border,#eef1f4)}
.mp-az__kv tr:first-child td,.mp-az__lv tr:first-child td{border-top:0}
.mp-az__kv td:first-child{color:var(--mp-muted,#64748b)}
.mp-az__kv td:last-child,.mp-az__lv td:last-child{text-align:right;font-variant-numeric:tabular-nums}
.mp-az__lv td:nth-child(2){text-align:right;font-weight:600;font-variant-numeric:tabular-nums}
.mp-az__lv td:first-child{color:var(--mp-muted,#64748b)}
.mp-az__lv tr.r td:first-child{color:var(--r)}.mp-az__lv tr.s td:first-child{color:var(--g)}
.mp-az__lv tr.now{font-weight:700;background:var(--mp-surface2,#f8fafc)}
.mp-az__scn{display:grid;gap:9px;font-size:13px}
.mp-az__scn div{display:grid;gap:2px;padding-left:10px;border-left:3px solid var(--n)}
.mp-az__scn div.b{border-color:var(--g)}.mp-az__scn div.r{border-color:var(--r)}
.mp-az__scn b{font-size:12px}
.mp-az__scn span{color:var(--mp-ink2,#475569)}
.mp-az__scn em{font-style:normal;font-weight:600}
.mp-az__chart{margin:18px 0}
.mp-az__chart h4{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mp-muted,#64748b);margin:0 0 8px}
.mp-az__pat{font-size:12.5px;color:var(--mp-ink2,#475569);margin:10px 0}
.mp-az__disc{font-size:11px;color:var(--mp-muted,#94a3b8);line-height:1.5;border-top:1px solid var(--mp-border,#e2e8f0);padding-top:12px;margin-top:16px}
.mp-az__err{color:var(--r);font-size:13px;margin-top:12px}
.mp-az-hero{text-align:center;padding:26px 16px 20px;border:1px solid var(--mp-border,#e2e8f0);border-radius:16px;background:var(--mp-surface,#fff)}
.mp-az-search{display:flex;gap:8px;max-width:460px;margin:0 auto}
.mp-az-search input{flex:1;padding:12px 14px;font-size:14px;border:1px solid var(--mp-border,#cbd5e1);border-radius:10px;background:var(--mp-bg,#fff);color:var(--mp-ink,#0f172a)}
.mp-az-search button{padding:12px 20px;font-size:14px;font-weight:700;border:0;border-radius:10px;background:var(--mp-brand,#0057ff);color:#fff;cursor:pointer}
.mp-az-ex{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin:12px 0 0}
.mp-az-ex a{font-size:12px;font-weight:600;padding:5px 11px;border-radius:20px;background:var(--mp-surface2,#f1f5f9);color:var(--mp-ink2,#475569);text-decoration:none}
.mp-az-ex a:hover{background:var(--mp-brand,#0057ff);color:#fff}
.mp-az-hero__note{font-size:12px;color:var(--mp-muted,#64748b);max-width:44ch;margin:14px auto 0}
@media(max-width:640px){.mp-az__view{grid-template-columns:1fr}.mp-az-fac{grid-template-columns:100px 1fr 24px}.mp-az__name{font-size:19px}.mp-az__px b{font-size:22px}}
html[data-theme="dark"] .mp-az__modes a.is-on{box-shadow:0 1px 3px rgba(0,0,0,.4)}
</style>';
}

/* ---- [mp_analyzer related="1"] — coverage + peer links + FAQ for NSE stock pages ---- */
function mp_an_related_html($a) {
    if (empty($a['ok']) || $a['exchange'] !== 'NSE') return '';
    $bare = preg_replace('/\.(NS|BO)$/', '', $a['symbol']);
    $flat = mp_md_stock_universe_flat();
    $meta = isset($flat[$bare]) ? $flat[$bare] : null;
    $name = $a['label'];
    $sector = $a['context']['sector'] ? $a['context']['sector'] : ($meta ? $meta['sector'] : '');
    $slug = mp_md_stock_slug($bare);
    $price = number_format($a['price'], 2);
    $up = ($a['changePct'] !== null && $a['changePct'] >= 0);
    $chgAbs = $a['changePct'] !== null ? abs($a['changePct']) : 0;
    $viewLabel = mp_an_view_label($a['view']);
    $reason = !empty($a['ai']['keyReasons'][0]) ? $a['ai']['keyReasons'][0] : '';
    $nr = isset($a['levels']['nearestResistance']) ? $a['levels']['nearestResistance'] : null;
    $ns = isset($a['levels']['nearestSupport']) ? $a['levels']['nearestSupport'] : null;
    $sectorNote = function_exists('mp_md_sector_note') ? mp_md_sector_note($sector) : '';

    $faq = array(
        array(
            'q' => 'What is the ' . $name . ' share price today?',
            'a' => $name . ' (NSE: ' . $bare . ') is trading at Rs ' . $price . ', ' . ($up ? 'up' : 'down') . ' ' . $chgAbs . '% on the day. Prices update through the session and may be delayed.',
        ),
        array(
            'q' => 'What does MoneyPuran\'s analysis say about ' . $name . '?',
            'a' => 'The rules-based engine reads ' . $name . ' as ' . strtolower($viewLabel) . ' on the ' . $a['mode'] . ' timeframe, at a model confidence of ' . (int) $a['confidence'] . '/100. It is an analytical read of price trend, momentum, volume, key levels and market context &mdash; not a recommendation.',
        ),
        array(
            'q' => 'Does MoneyPuran publish a ' . $name . ' share price target?',
            'a' => 'No. MoneyPuran does not issue price targets. The analysis shows computed support and resistance'
                . (($ns !== null && $nr !== null) ? ' (nearby support around Rs ' . number_format($ns, 2) . ', resistance around Rs ' . number_format($nr, 2) . ')' : '')
                . ' plus scenario conditions, so you can see the levels that would confirm or invalidate the current view.',
        ),
        array(
            'q' => 'Why is ' . $name . ' up or down today?',
            'a' => trim(($reason ? $reason . ' ' : '') . $sectorNote),
        ),
    );

    ob_start(); ?>
<div class="mp-az-rel">
  <h3>MoneyPuran coverage</h3>
  <div id="mpAzNews-<?php echo esc_attr($slug); ?>" class="mp-az-rel__news" data-name="<?php echo esc_attr($name); ?>">Loading&hellip;</div>
  <?php
  $peers = array();
  if ($sector) {
      foreach ($flat as $ps => $pm) {
          if ($pm['sector'] === $sector && $ps !== $bare) $peers[$ps] = $pm['name'];
      }
  }
  if ($peers) : ?>
  <h3>Other <?php echo esc_html($sector); ?> stocks</h3>
  <p class="mp-az-rel__peers">
    <?php foreach ($peers as $ps => $pn) : ?><a href="<?php echo esc_url(home_url('/stocks/' . mp_md_stock_slug($ps) . '/')); ?>"><?php echo esc_html($pn); ?></a><?php endforeach; ?>
  </p>
  <?php endif; ?>
  <h3>FAQ</h3>
  <div class="mp-az-rel__faq">
    <?php foreach ($faq as $i => $f) : ?>
    <details<?php echo $i === 0 ? ' open' : ''; ?>><summary><?php echo esc_html($f['q']); ?></summary><p><?php echo wp_kses_post($f['a']); ?></p></details>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function(){
  var el=document.getElementById('mpAzNews-<?php echo esc_js($slug); ?>'); if(!el) return;
  var name=el.getAttribute('data-name');
  fetch((location.origin||'')+'/wp-json/wp/v2/posts?per_page=4&search='+encodeURIComponent(name)+'&_fields=title,link')
    .then(function(r){return r.ok?r.json():[];})
    .then(function(p){
      if(p&&p.length){ el.innerHTML='<ul>'+p.map(function(x){return '<li><a href="'+x.link+'">'+x.title.rendered+'</a></li>';}).join('')+'</ul>'; }
      else { el.innerHTML='<p style="font-size:13px">No MoneyPuran articles on '+name+' yet. <a href="'+(location.origin||'')+'/category/stocks/">Browse our Stocks section</a>.</p>'; }
    }).catch(function(){ el.innerHTML=''; });
}());
</script>
<script type="application/ld+json"><?php echo wp_json_encode(array(
  '@context' => 'https://schema.org', '@type' => 'FAQPage',
  'mainEntity' => array_map(function ($f) {
      return array('@type' => 'Question', 'name' => wp_strip_all_tags($f['q']),
          'acceptedAnswer' => array('@type' => 'Answer', 'text' => wp_strip_all_tags($f['a'])));
  }, $faq),
), JSON_UNESCAPED_SLASHES); ?></script>
<style>
.mp-az-rel{margin:24px 0 0}
.mp-az-rel h3{font-size:16px;font-weight:700;margin:20px 0 8px}
.mp-az-rel__news ul{margin:0;padding-left:18px;font-size:13.5px;line-height:1.7}
.mp-az-rel__peers a{display:inline-block;margin:0 8px 6px 0;font-size:13px;font-weight:600;color:var(--mp-brand,#0057ff);text-decoration:none}
.mp-az-rel__faq details{border-top:1px solid var(--mp-border,#e2e8f0);padding:9px 0}
.mp-az-rel__faq summary{font-weight:600;font-size:14px;cursor:pointer}
.mp-az-rel__faq p{font-size:13.5px;line-height:1.6;margin:6px 0 0;color:var(--mp-ink2,#475569)}
</style>
<?php
    return ob_get_clean();
}

/* ---- analysis warm-up cron: precompute tracked symbols off the request path ---- */
function mp_an_warm_symbols() {
    $syms = array_keys(mp_md_stock_universe_flat());
    array_unshift($syms, 'NIFTY', 'BANKNIFTY');
    return apply_filters('mp_an_warm_symbols', array_values(array_unique($syms)));
}
add_action('mp_an_warm_cron', function () {
    $syms = mp_an_warm_symbols();
    $n = count($syms);
    if (!$n) return;
    $per = 5;
    $off = ((int) get_option('mp_an_warm_off', 0)) % $n;
    $done = 0;
    $deadline = time() + 45;
    for ($i = 0; $i < $per && time() < $deadline; $i++) {
        mp_an_analyze($syms[($off + $i) % $n], 'swing');
        $done++;
    }
    update_option('mp_an_warm_off', ($off + $done) % $n, false);
});
add_action('init', function () {
    if (!wp_next_scheduled('mp_an_warm_cron')) wp_schedule_event(time() + 150, 'mp_md_2min', 'mp_an_warm_cron');
});

/* ---- REST ---- */
add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/analysis', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'args' => array('symbol' => array('required' => true), 'mode' => array('default' => 'swing')),
        'callback' => function (WP_REST_Request $req) {
            $sym = sanitize_text_field($req->get_param('symbol'));
            $mode = sanitize_key($req->get_param('mode'));
            if ($sym === '') return new WP_Error('mp_no_symbol', 'symbol is required', array('status' => 400));
            $res = rest_ensure_response(mp_an_analyze($sym, $mode));
            $res->header('Cache-Control', 'public, max-age=30, s-maxage=60, stale-while-revalidate=120');
            return $res;
        },
    ));
});




/* ============================================================================
 * US MARKETS (v1.24.0)
 *  [mp_us_markets]      - S&P 500 / Dow / Nasdaq cards + a 12-stock mega-cap
 *                         table with a Bullish/Neutral/Bearish signal, each
 *                         row linking into [mp_analyzer] on the same page.
 *  [mp_us_markets_faq]  - accordion FAQ + FAQPage schema for the US page.
 * ==========================================================================*/

add_shortcode('mp_us_markets', function ($atts) {
    $idxRows = mp_md_tb_us_index_rows(); // [S&P 500, Dow, Nasdaq, Bitcoin] shaped {sym,price,chgPct,change}
    $nmW = array('^GSPC' => 'S&P 500', '^DJI' => 'Dow Jones', '^IXIC' => 'Nasdaq');
    $spxChg = null;
    $cards = array();
    foreach ($idxRows as $r) {
        if (!isset($nmW[$r['sym']])) continue; // skip bitcoin here, the ticker already covers crypto
        $cards[] = array('label' => $nmW[$r['sym']], 'price' => $r['price'], 'chg' => $r['chgPct']);
        if ($r['sym'] === '^GSPC') $spxChg = $r['chgPct'];
    }

    $stocks = mp_md_us_stocks('trending');
    foreach ($stocks as &$s) {
        list($sig, ) = mp_md_screener_signal(isset($s['change_pct']) ? $s['change_pct'] : null, $spxChg, null, null);
        $s['sig'] = $sig;
    }
    unset($s);

    $base = get_permalink();
    ob_start(); ?>
<div class="mp-usm" id="mpUsMarkets" data-endpoint="<?php echo esc_url(home_url('/wp-json/mp/v1/markets?filter=trending&session=us')); ?>">
  <div class="mp-usm__idx" data-role="idx">
    <?php if ($cards) : foreach ($cards as $c) : $up = ($c['chg'] ?? 0) >= 0; ?>
    <div class="mp-usm__card">
      <h4><?php echo esc_html($c['label']); ?></h4>
      <div class="v"><?php echo $c['price'] !== null ? number_format($c['price'], 2) : '&mdash;'; ?></div>
      <?php if ($c['chg'] !== null) : ?><div class="mp-usm__chg <?php echo $up ? 'up' : 'dn'; ?>"><?php echo $up ? '&#9650;' : '&#9660;'; ?> <?php echo ($up ? '+' : '') . number_format($c['chg'], 2); ?>%</div><?php endif; ?>
    </div>
    <?php endforeach; else : ?><div class="mp-usm__card">Loading&hellip;</div><?php endif; ?>
  </div>

  <table class="mp-usm__tbl" data-role="tbl">
    <thead><tr><th>Stock</th><th>Price</th><th>Change</th><th>Signal</th><th></th></tr></thead>
    <tbody data-role="tbody">
      <?php if ($stocks) : foreach ($stocks as $s) :
        $up = (isset($s['change_pct']) ? $s['change_pct'] : 0) >= 0;
        $sigCls = $s['sig'] === 'Bullish' ? 'bull' : ($s['sig'] === 'Bearish' ? 'bear' : 'neut');
        $qurl = esc_url(add_query_arg('q', $s['symbol'], $base));
      ?>
      <tr>
        <td><a href="<?php echo $qurl; ?>"><b><?php echo esc_html($s['symbol']); ?></b> <span class="mp-usm__nm"><?php echo esc_html($s['name']); ?></span></a></td>
        <td class="num">$<?php echo number_format($s['price'], 2); ?></td>
        <td class="num <?php echo $up ? 'up' : 'dn'; ?>"><?php echo $up ? '&#9650;' : '&#9660;'; ?> <?php echo ($up ? '+' : '') . number_format(isset($s['change_pct']) ? $s['change_pct'] : 0, 2); ?>%</td>
        <td><span class="mp-usm__sig <?php echo $sigCls; ?>"><?php echo esc_html($s['sig']); ?></span></td>
        <td><a class="mp-usm__go" href="<?php echo $qurl; ?>">Analyse &rarr;</a></td>
      </tr>
      <?php endforeach; else : ?><tr><td colspan="5">Loading&hellip;</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="mp-usm__note">Prices in USD, may be delayed. Signal is a mechanical read of the day's move versus the S&amp;P 500 &mdash; not a buy/sell recommendation. Click any stock for the full trend, momentum and scenario analysis. Not investment advice.</p>
</div>
<style id="mp-usm-css">
.mp-usm{margin:18px 0}
.mp-usm__idx{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.mp-usm__card{border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:14px 16px;background:var(--mp-surface,#fff)}
.mp-usm__card h4{margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--mp-muted,#64748b)}
.mp-usm__card .v{font-size:22px;font-weight:700;font-variant-numeric:tabular-nums}
.mp-usm__chg{font-size:13px;font-weight:700;margin-top:4px}
.mp-usm__chg.up{color:#16a34a}.mp-usm__chg.dn{color:#dc2626}
.mp-usm__tbl{width:100%;border-collapse:collapse;font-size:13.5px}
.mp-usm__tbl th,.mp-usm__tbl td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--mp-border,#eef1f4)}
.mp-usm__tbl th.num,.mp-usm__tbl td.num{text-align:right;font-variant-numeric:tabular-nums}
.mp-usm__tbl td.num.up{color:#16a34a}.mp-usm__tbl td.num.dn{color:#dc2626}
.mp-usm__tbl a{color:inherit;text-decoration:none}
.mp-usm__tbl a:hover b{color:var(--mp-brand,#0057ff)}
.mp-usm__nm{display:block;font-size:11.5px;color:var(--mp-muted,#64748b);font-weight:400}
.mp-usm__sig{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
.mp-usm__sig.bull{background:rgba(22,163,74,.12);color:#16a34a}
.mp-usm__sig.bear{background:rgba(220,38,38,.12);color:#dc2626}
.mp-usm__sig.neut{background:rgba(100,116,139,.12);color:#64748b}
.mp-usm__go{font-size:12px;font-weight:600;color:var(--mp-brand,#0057ff);white-space:nowrap}
.mp-usm__note{font-size:11px;color:var(--mp-muted,#64748b);margin-top:10px}
html[data-theme="dark"] .mp-usm__card{background:#111827;border-color:rgba(255,255,255,.08)}
@media(max-width:640px){.mp-usm__nm{display:none}.mp-usm__tbl th:nth-child(2),.mp-usm__tbl td:nth-child(2){display:none}}
</style>
<script>
(function(){
  var W=document.getElementById('mpUsMarkets'); if(!W) return;
  function num(n,d){ return Number(n).toLocaleString('en-US',{minimumFractionDigits:d,maximumFractionDigits:d}); }
  function paint(d){
    var idx=W.querySelector('[data-role=idx]'), body=W.querySelector('[data-role=tbody]');
    var NMW={'^GSPC':'S&P 500','^DJI':'Dow Jones','^IXIC':'Nasdaq'};
    var spx=null;
    var ih='';
    (d.indices||[]).forEach(function(r){
      if(!NMW[r.sym]) return;
      if(r.sym==='^GSPC') spx=r.chgPct;
      var up=(r.chgPct||0)>=0;
      ih+='<div class="mp-usm__card"><h4>'+NMW[r.sym]+'</h4><div class="v">'+(r.price!=null?num(r.price,2):'—')+'</div>'
        +(r.chgPct!=null?'<div class="mp-usm__chg '+(up?'up':'dn')+'">'+(up?'▲':'▼')+' '+(up?'+':'')+num(r.chgPct,2)+'%</div>':'')+'</div>';
    });
    if(ih && idx) idx.innerHTML=ih;
    var bh='';
    (d.stocks||[]).forEach(function(s){
      var chg=s.change_pct!=null?s.change_pct:s.chgPct;
      var up=(chg||0)>=0;
      var sc=0;
      if(chg!=null) sc+=(chg>=1?1:0)-(chg<=-1?1:0);
      if(chg!=null && spx!=null){ var rel=chg-spx; sc+=(rel>=0.75?1:0)-(rel<=-0.75?1:0); }
      var sig=sc>=2?'Bullish':(sc<=-2?'Bearish':'Neutral');
      var sigCls=sig==='Bullish'?'bull':(sig==='Bearish'?'bear':'neut');
      var q=<?php echo wp_json_encode(esc_url_raw($base)); ?>+'?q='+encodeURIComponent(s.symbol||s.sym);
      bh+='<tr><td><a href="'+q+'"><b>'+(s.symbol||s.sym)+'</b> <span class="mp-usm__nm">'+(s.name||'')+'</span></a></td>'
        +'<td class="num">$'+num(s.price,2)+'</td>'
        +'<td class="num '+(up?'up':'dn')+'">'+(up?'▲':'▼')+' '+(up?'+':'')+num(chg||0,2)+'%</td>'
        +'<td><span class="mp-usm__sig '+sigCls+'">'+sig+'</span></td>'
        +'<td><a class="mp-usm__go" href="'+q+'">Analyse →</a></td></tr>';
    });
    if(bh && body) body.innerHTML=bh;
  }
  function load(){
    fetch(W.getAttribute('data-endpoint'),{credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(d){ if(d) paint(d); }).catch(function(){});
  }
  setTimeout(load,1500);
  setInterval(load,45000);
}());
</script>
    <?php
    return ob_get_clean();
});

/* --------------------------- [mp_us_markets_faq] --------------------------- */
add_shortcode('mp_us_markets_faq', function () {
    $idxRows = mp_md_tb_us_index_rows();
    $spx = null;
    foreach ($idxRows as $r) if ($r['sym'] === '^GSPC') $spx = $r;
    $today = wp_date('j M Y');
    $live = $spx && isset($spx['price'])
        ? 'As of ' . $today . ', the S&amp;P 500 is around ' . number_format($spx['price'], 2) . (isset($spx['chgPct']) && $spx['chgPct'] !== null ? ' (' . ($spx['chgPct'] >= 0 ? '+' : '') . number_format($spx['chgPct'], 2) . '% today)' : '') . '. It updates live during the US trading session.'
        : 'The US market page updates live during the US trading session; see the figures above.';

    $faq = array(
        array('Is the US stock market open today?', 'US markets (NYSE and Nasdaq) trade Monday to Friday, 9:30am&ndash;4:00pm US Eastern Time &mdash; roughly 7:00pm&ndash;1:30am India Standard Time (adjusts with US daylight saving). Outside those hours the page shows the last close.'),
        array('Where does the S&P 500 stand today?', $live),
        array('What is the difference between the S&P 500, Dow Jones and Nasdaq?', 'The S&amp;P 500 tracks 500 large US companies and is the broadest read of the US market. The Dow Jones Industrial Average tracks 30 large "blue-chip" companies. The Nasdaq Composite is weighted toward technology and growth stocks, so it tends to move more than the other two.'),
        array('Which US stocks does this page track?', 'A curated set of US mega-caps &mdash; Apple, Microsoft, Alphabet (Google), Amazon, Nvidia, Meta, Tesla, JPMorgan Chase, Visa, Walmart, ExxonMobil and UnitedHealth &mdash; covering technology, financials, retail, energy and healthcare.'),
        array('What does the Bullish / Neutral / Bearish signal mean?', 'It is a mechanical read of the day\'s price move: a stock scores +1 for a move of 1% or more, and another +1 for beating the S&amp;P 500\'s move by 0.75 points or more (the reverse for a fall). A combined score of +2 or higher shows as Bullish, &minus;2 or lower as Bearish, in between as Neutral. It is not a buy or sell recommendation.'),
        array('Can I get a full trend and scenario analysis for a US stock?', 'Yes &mdash; click any stock (or search it at the top of this page) for the full MoneyPuran Analyzer: trend, momentum, volume, key levels, and Bullish/Neutral/Bearish scenarios with a confidence score, the same engine used for Indian stocks.'),
    );
    ob_start(); ?>
<div class="mp-rates-faq">
  <h2>US stock market today &mdash; FAQ</h2>
  <?php foreach ($faq as $i => $f) : ?>
  <details<?php echo $i === 0 ? ' open' : ''; ?>><summary><?php echo esc_html($f[0]); ?></summary><div><?php echo wp_kses_post(wpautop($f[1])); ?></div></details>
  <?php endforeach; ?>
</div>
<script type="application/ld+json"><?php echo wp_json_encode(array(
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(function ($f) {
        return array('@type' => 'Question', 'name' => wp_strip_all_tags($f[0]),
            'acceptedAnswer' => array('@type' => 'Answer', 'text' => wp_strip_all_tags($f[1])));
    }, $faq),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
    <?php
    return ob_get_clean();
});

/* SEO title / description for the US markets page. */
add_filter('rank_math/frontend/title', function ($title) {
    if (is_page('us-markets')) return 'US Stock Market Today &mdash; S&P 500, Dow, Nasdaq Live + Top Stocks';
    return $title;
}, 21);
add_filter('rank_math/frontend/description', function ($desc) {
    if (is_page('us-markets')) return 'US stock market today: live S&P 500, Dow Jones and Nasdaq levels, top US mega-cap stocks (Apple, Microsoft, Tesla, Nvidia and more) with a Bullish/Neutral/Bearish signal, and a full trend and scenario analysis for any US stock.';
    return $desc;
}, 21);
