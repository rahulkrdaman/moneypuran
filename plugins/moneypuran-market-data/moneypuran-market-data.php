<?php
/**
 * Plugin Name: MoneyPuran Market Data
 * Description: Real market data (Yahoo Finance, server-side, cached) - theme index bar, "Live Markets" widget, and the homepage Markets Dashboard (world indices, currencies, commodities, sector indices, indicative gold/silver). Replaces the simulated fallback and neutralises the fabricated "STRONG BUY" trade ideas. Safe to deactivate.
 * Version: 1.2.0
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
    if ($filter === 'gainers') {
        usort($stocks, fn($a, $b) => ($b['change_pct'] ?? -99) <=> ($a['change_pct'] ?? -99));
    } elseif ($filter === 'losers') {
        usort($stocks, fn($a, $b) => ($a['change_pct'] ?? 99) <=> ($b['change_pct'] ?? 99));
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
.mp-md-dashboard{margin:28px 0}
.mp-md-head{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.mp-md-asof{font-size:11px;color:var(--mp-muted,#6b7280)}
.mp-md-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
.mp-md-card{border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:14px 16px;background:var(--mp-card,#fff)}
.mp-md-card-title{font-size:13px;font-weight:700;margin:0 0 10px;letter-spacing:.02em;text-transform:uppercase;color:var(--mp-muted,#6b7280)}
.mp-md-row{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:6px 0;border-top:1px solid var(--mp-border,#f1f1f1);font-size:13px}
.mp-md-row:first-child{border-top:0}
.mp-md-label{color:var(--mp-fg,#111827)}
.mp-md-price{font-variant-numeric:tabular-nums;font-weight:600}
.mp-md-chg{font-variant-numeric:tabular-nums;font-weight:600;min-width:62px;text-align:right}
.mp-md-up{color:#16a34a}.mp-md-dn{color:#dc2626}
.mp-md-bullion{margin-top:16px;border:1px solid var(--mp-border,#e5e7eb);border-radius:10px;padding:14px 16px;background:var(--mp-card,#fff)}
.mp-md-bullion-row{display:flex;flex-wrap:wrap;gap:16px;font-size:14px}
.mp-md-bullion-row strong{color:var(--mp-brand,#1d4ed8)}
.mp-md-note{font-size:11px;color:var(--mp-muted,#6b7280);margin:8px 0 0}
.mp-md-disclaimer{font-size:11px;color:var(--mp-muted,#6b7280);margin:12px 0 0}
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
