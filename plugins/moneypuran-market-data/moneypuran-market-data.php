<?php
/**
 * Plugin Name: MoneyPuran Market Data
 * Description: Real market data (Yahoo Finance, server-side, cached) - theme index bar, "Live Markets" widget, and the homepage Markets Dashboard (world indices, currencies, commodities, sector indices, indicative gold/silver). Replaces the simulated fallback and neutralises the fabricated "STRONG BUY" trade ideas. Safe to deactivate.
 * Version: 1.3.1
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

/* Dashboard groups: label map per Yahoo symbol. */
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

/* --------------------------- Yahoo fetch --------------------------- */

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
function mp_md_sorted_stocks($filter) {
    $stocks = array_values(mp_md_get_stocks()['stocks']);
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
            $body = array('asOf' => gmdate('c'), 'source' => 'Yahoo Finance', 'note' => 'Prices may be delayed. Not investment advice.');

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
    <span class="mp-md-asof" id="mpMdAsOf">Yahoo Finance &middot; prices may be delayed</span>
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
    if(a) a.textContent = 'Yahoo Finance - updated ' + new Date().toLocaleTimeString() + ' - may be delayed';
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
   implementation that fills the markup with REAL Yahoo data (no score/target/momentum
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

  function rowHtml(s, i){
    var up = (s.change_pct||0) >= 0, sg = sigOf(s.change_pct||0);
    return '<tr>'
      + '<td class="td-rank">'+(i+1)+'</td>'
      + '<td class="td-stock"><strong>'+s.symbol+'</strong><span style="display:block;font-size:11px;opacity:.7">'+(s.name||'')+' &middot; '+(s.exchange||'NSE')+'</span></td>'
      + '<td class="td-price">&#8377;'+inr(s.price)+'</td>'
      + '<td class="td-change"><span class="chg-val '+(up?'chg-up':'chg-dn')+'">'+(up?'+':'')+Number(s.change_pct||0).toFixed(2)+'%</span></td>'
      + '<td class="td-vol">'+vol(s.volume)+'</td>'
      + '<td class="td-signal"><span class="sig-pill sig-'+sg.c+'">'+sg.l+'</span></td>'
      + '<td class="td-score">&ndash;</td>'
      + '<td class="td-action"><a href="'+(location.origin)+'/?s='+encodeURIComponent(s.symbol)+'" style="font-size:12px;font-weight:600">News &rarr;</a></td>'
      + '</tr>';
  }
  function cardHtml(s, i){
    var up = (s.change_pct||0) >= 0, sg = sigOf(s.change_pct||0);
    return '<div class="mpst-card">'
      + '<div class="mpst-card-top"><span class="mpst-card-rank">#'+(i+1)+'</span><strong>'+s.symbol+'</strong>'
      + '<span class="chg-val '+(up?'chg-up':'chg-dn')+'">'+(up?'+':'')+Number(s.change_pct||0).toFixed(2)+'%</span></div>'
      + '<div class="mpst-card-row"><span>&#8377;'+inr(s.price)+'</span>'
      + '<span class="sig-pill sig-'+sg.c+'">'+sg.l+'</span>'
      + '<span style="opacity:.7">Vol '+vol(s.volume)+'</span></div>'
      + '<div class="mpst-card-reason" style="font-size:11px;opacity:.7">'+(s.name||'')+' &middot; via Yahoo Finance, delayed</div></div>';
  }

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
        var tb = $('mpstTableBody'); if (tb) tb.innerHTML = list.map(rowHtml).join('');
        var cc = $('mpstCards'); if (cc) cc.innerHTML = list.map(cardHtml).join('');
        summary(list);
        // relabel columns we repurposed
        var vh = W.querySelector('.th-vol'); if (vh) vh.textContent = 'Volume';
        show('mpstTable');
        if (src) src.textContent = 'NSE - Yahoo Finance';
        var t=$('mpstTime'); if(t) t.textContent = ' - ' + new Date().toLocaleTimeString();
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
    // fresh). Never trigger a blocking Yahoo fetch from the ticker request.
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
    $mkt   = isset($data['market'][$session]) ? $data['market'][$session] : '';
    $tab   = $dup ? ' tabindex="-1"' : '';
    $h = '';
    if ($mkt !== '') {
        $h .= '<span class="mp-ticker__mkt">' . $mkt . '</span><span class="mp-ticker__sep">&#9679;</span>';
    }
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
    var items=(d.sessions&&d.sessions[session])||[], mkt=(d.market&&d.market[session])||'', h='';
    if(mkt) h+='<span class="mp-ticker__mkt">'+mkt+'</span><span class="mp-ticker__sep">●</span>';
    items.forEach(function(it){
      h+='<a class="mp-ticker__item" href="'+it.url+'"'+(dup?' tabindex="-1"':'')+'>'+esc(it.title)+'</a>'
        +'<span class="mp-ticker__sep">●</span>';
    });
    return h||'<span class="mp-ticker__item">Markets are quiet right now.</span>';
  }
  function setDuration(){
    requestAnimationFrame(function(){
      var w=(TRACK.firstElementChild&&TRACK.firstElementChild.getBoundingClientRect().width)||1200;
      TRACK.style.setProperty('--mp-tick-duration',Math.max(18,Math.round(w/SPEED))+'s');
    });
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
}());
</script>
    <?php
}
add_action('mp_news_ticker', 'mp_md_render_ticker');
add_shortcode('mp_news_ticker', function () { ob_start(); mp_md_render_ticker(); return ob_get_clean(); });
