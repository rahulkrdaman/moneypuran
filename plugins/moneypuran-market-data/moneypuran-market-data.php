<?php
/**
 * Plugin Name: MoneyPuran Market Data
 * Description: Real market data (server-side, cached) - index bar, Live Markets widget, Markets Dashboard, session-aware news ticker, and city Gold/Silver + Fuel rate tools. Safe to deactivate.
 * Version: 1.10.3
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
    if ($usdinr && $gold) {
        $g10 = $gold['price'] / 31.1035 * $usdinr * 10; // $/oz -> INR/10g (24K)
        $out['bullion_inr'] = array(
            'usdinr'      => round($usdinr, 2),
            'gold_24k_10g' => round($g10),
            'gold_22k_10g' => round($g10 * 0.916),
            'gold_18k_10g' => round($g10 * 0.75),
            'silver_kg'   => $silver ? round($silver['price'] / 31.1035 * $usdinr * 1000) : null,
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
            'only'   => array('default' => 'all'),
            'filter' => array('default' => 'trending'),
        ),
        'callback' => function (WP_REST_Request $req) {
            $only = $req->get_param('only');
            $body = array('asOf' => gmdate('c'), 'source' => 'Market data', 'note' => 'Prices may be delayed. Not investment advice.');

            if ($only === 'dashboard') {
                $body = array_merge($body, mp_md_get_groups());
                unset($body['_at']);
            } elseif ($only === 'stocks') {
                $body['stocks'] = mp_md_sorted_stocks($req->get_param('filter'));
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
    echo mp_md_card_html('Currency Rates', $g['currencies']  ?? array());
    echo mp_md_card_html('Commodities',    $g['commodities'] ?? array());
    echo mp_md_card_html('Sector Indices (India)', $g['sectors'] ?? array());
    ?>
  </div>
  <?php if (!empty($g['bullion_inr'])) : $b = $g['bullion_inr']; ?>
  <div class="mp-md-bullion">
    <h3 class="mp-md-card-title">Gold &amp; Silver &mdash; indicative (INR)</h3>
    <div class="mp-md-bullion-row">
      <span><strong>24K</strong> &#8377;<?php echo mp_md_fmt($b['gold_24k_10g'], 0); ?>/10g</span>
      <span><strong>22K</strong> &#8377;<?php echo mp_md_fmt($b['gold_22k_10g'], 0); ?>/10g</span>
      <span><strong>18K</strong> &#8377;<?php echo mp_md_fmt($b['gold_18k_10g'], 0); ?>/10g</span>
      <?php if (!empty($b['silver_kg'])) : ?><span><strong>Silver</strong> &#8377;<?php echo mp_md_fmt($b['silver_kg'], 0); ?>/kg</span><?php endif; ?>
    </div>
    <p class="mp-md-note"><?php echo esc_html($b['note']); ?></p>
  </div>
  <?php endif; ?>
  <p class="mp-md-disclaimer">Market data is provided for information only and may be delayed. Nothing here is investment advice.</p>
</section>
<style>
/* Theme-aware: uses the moneypuran-theme tokens (--mp-surface/--mp-ink/--mp-muted/--mp-border)
   with light fallbacks, plus an explicit dark-mode block for safety. */
.mp-md-dashboard{margin:28px 0;color:var(--mp-ink,#0f172a)}
.mp-md-head{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.mp-md-asof{font-size:11px;color:var(--mp-muted,#64748b)}
.mp-md-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
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
  function paint(d){
    var html = '';
    ['world','currencies','commodities','sectors'].forEach(function(k){
      var rows = d[k]||[]; if(!rows.length) return;
      html += '<div class="mp-md-card"><h3 class="mp-md-card-title">'+TITLES[k]+'</h3><div class="mp-md-card-body">'+rows.map(row).join('')+'</div></div>';
    });
    if(html) GRID.innerHTML = html;
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

    return array(
        'city'      => $city,
        'gold_24k'  => array('g' => round($g24_10 / 10), 'ten_g' => round($g24_10)),
        'gold_22k'  => array('g' => round($g24_10 * 0.916 / 10), 'ten_g' => round($g24_10 * 0.916)),
        'gold_18k'  => array('g' => round($g24_10 * 0.75 / 10), 'ten_g' => round($g24_10 * 0.75)),
        'silver'    => $s_kg ? array('g' => round($s_kg / 1000, 2), 'kg' => round($s_kg)) : null,
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
        <div class="u">₹<?php echo number_format($row['ten_g']); ?> / 10g</div></div>
    <?php endforeach; if (!empty($g['silver'])): ?>
      <div class="mp-rate-card"><h4>Silver (999)</h4>
        <div class="v">₹<?php echo number_format($g['silver']['g'], 2); ?><span class="u">/g</span></div>
        <div class="u">₹<?php echo number_format($g['silver']['kg']); ?> / kg</div></div>
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
      h += '<div class="mp-rate-card"><h4>'+x[1]+' Gold</h4><div class="v">₹'+r.g.toLocaleString('en-IN')+'<span class="u">/g</span></div><div class="u">₹'+r.ten_g.toLocaleString('en-IN')+' / 10g</div></div>';
    });
    if(G.silver) h += '<div class="mp-rate-card"><h4>Silver (999)</h4><div class="v">₹'+G.silver.g.toLocaleString('en-IN')+'<span class="u">/g</span></div><div class="u">₹'+G.silver.kg.toLocaleString('en-IN')+' / kg</div></div>';
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
        'usdinr'    => array('INR=X',       'USD / INR',         null),
        'bitcoin'   => array('BTC-USD',     'Bitcoin ($)',       null),
        'ethereum'  => array('ETH-USD',     'Ethereum ($)',      null),
        'reliance'  => array('RELIANCE.NS', 'Reliance',          null),
        'tcs'       => array('TCS.NS',      'TCS',               null),
        'infy'      => array('INFY.NS',     'Infosys',           null),
        'hdfcbank'  => array('HDFCBANK.NS', 'HDFC Bank',         null),
        'sp500'     => array('^GSPC',       'S&P 500',           null),
        'nasdaq'    => array('^IXIC',       'Nasdaq',            null),
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
            'WIPRO' => 'Wipro', 'TECHM' => 'Tech Mahindra', 'LTIM' => 'LTIMindtree',
        )),
        'Auto' => array('^CNXAUTO', array(
            'MARUTI' => 'Maruti Suzuki', 'TATAMOTORS' => 'Tata Motors', 'M&M' => 'Mahindra & Mahindra',
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
    $inrLine = $inr ? ('is at ' . chr(0xE2) . chr(0x82) . chr(0xB9) . number_format($inr['price'], 2)
        . ($inr['chgPct'] !== null ? ' (' . ($inr['chgPct'] >= 0 ? '+' : '') . $inr['chgPct'] . '%)' : '')) : 'data loading';

    return array(
        'nifty'     => $nifty ? array('level' => $nifty['price'], 'chg' => $nifty['chgPct']) : null,
        'sensex'    => $sensex ? array('level' => $sensex['price'], 'chg' => $sensex['chgPct']) : null,
        'banknifty' => $bank ? array('level' => $bank['price'], 'chg' => $bank['chgPct']) : null,
        'niftyChg'  => $nifty['chgPct'] ?? null,
        'usLine'    => $usLine,
        'crudeLine' => $crudeLine,
        'inrLine'   => $inrLine,
        'asOf'      => gmdate('c'),
    );
}

function mp_md_screener_build() {
    $deadline = microtime(true) + 20;
    $universe = mp_md_screener_universe();

    $all = array();
    foreach ($universe as $sec => $def) foreach ($def[1] as $sym => $name) $all[$sym] = $name;
    $spark = mp_md_yahoo_spark(array_map(function ($s) { return $s . '.NS'; }, array_keys($all)));

    $grp = mp_md_get_groups();
    $secBy = array();
    foreach (($grp['sectors'] ?? array()) as $r) $secBy[$r['sym']] = $r['chgPct'];
    $idx = mp_md_get_indices();
    $niftyChg = null;
    foreach (($idx['indices'] ?? array()) as $v) if ($v['sym'] === '^NSEI') $niftyChg = $v['chgPct'];

    $out = array();
    foreach ($universe as $sec => $def) {
        list($secIdxSym, $stocks) = $def;
        $secChg = ($secIdxSym !== '' && isset($secBy[$secIdxSym])) ? $secBy[$secIdxSym] : null;
        $rows = array();
        foreach ($stocks as $sym => $name) {
            $q = $spark[$sym . '.NS'] ?? null;
            if (!$q) continue;
            list($sig, $score) = mp_md_screener_signal($q['chgPct'], $niftyChg, $secChg, $q['w52pos']);
            $rows[] = array(
                'sym' => $sym, 'name' => $name, 'price' => $q['price'], 'chgPct' => $q['chgPct'],
                'w52pos' => $q['w52pos'], 'signal' => $sig, 'score' => $score,
            );
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
    return array('sectors' => $out, 'scenario' => mp_md_screener_scenario(), '_at' => time(), 'asOf' => gmdate('c'));
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

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/screener', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $d = mp_md_get_screener();
            $body = array(
                'sectors'  => $d['sectors'],
                'scenario' => $d['scenario'],
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
