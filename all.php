<?php
/**
 * Election Night — All Races Available (separate page, does NOT replace index.php)
 * =================================================================================
 * Built Nov 3, 2026 at Gopi's "build whatever is available" call.
 *
 * What is available, verified live in the browser on Nov 3, 2026:
 * - civicAPI (free, no key): https://civicapi.org/api/v2/race/search
 *   and https://civicapi.org/api/v2/race/{id}
 *   - Governor races: national overview race 87535, region_results =
 *     per-state Governor races (the search endpoint 503'd all evening
 *     Nov 3, so search pagination was dropped).
 *   - Senate races: national overview race 87528, region_results =
 *     per-state Senate races (same search problem, same fix).
 *   - House overview: race ID 87524, "United States House of
 *     Representatives", type "House of Representatives" — a NATIONAL
 *     overview (province=US, district=null, candidates by party).
 * - The 9 key Senate races from index.php, via data/live.json manual
 *   numbers (same file the main page reads in production).
 *
 * What is NOT available in civicAPI (verified, not assumed):
 * - Individual US House district races (CA-01, TX-02, ...). Every House
 *   result found was a statewide/national aggregate with district=null.
 *   Queries for Senate / Representative timed out (503 database
 *   timeout) or returned 0. So this page does NOT claim to be a
 *   435-district board. It says so on the page.
 *
 * Cache: server-side JSON, 120s, so a traffic spike never hammers
 * civicAPI. If every fetch fails and a cache exists, show the cache
 * labelled stale instead of a blank page.
 *
 * Modes: live (default) and ?mode=test (labelled sample data).
 */

$MODE = (isset($_GET['mode']) && $_GET['mode'] === 'test') ? 'test' : 'live';
$CACHE_TTL = 120;
$CACHE_DIR = is_writable(__DIR__) ? __DIR__ : sys_get_temp_dir();
$CACHE_FILE = $CACHE_DIR . '/election-all-cache.json';
$UA = 'socialnews.xyz election-night dashboard';
$CIVIC = 'https://civicapi.org/api/v2';
$HOUSE_RACE_ID = 87524;
$SENATE_RACE_ID = 87528; // "United States Senate" national overview, region_results = per-state Senate races
$GOV_RACE_ID = 87535;    // "United States Governors" national overview, region_results = per-state Governor races
$fmt = fn($n) => number_format((int)$n);

function fetch_url($url, $timeout = 45) {
    // 45s, not 15s: civicAPI's House race JSON is ~310 KB and slow
    // under election-night load.
    // Prefer curl: on Nov 3 evening, shell curl got 200 for the House
    // race while file_get_contents from web PHP failed in 0.1s on the
    // same box minutes apart (test/test2 succeeded, test3 failed) —
    // civicAPI is flaky with the stream wrapper tonight, curl is not.
    global $UA;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => $UA, CURLOPT_FOLLOWLOCATION => true]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw !== false && $code >= 200 && $code < 300) return $raw;
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => $UA, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : $raw;
}
function civic_json($url) {
    $raw = fetch_url($url);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? $j : null;
}
// Conservative call, same rule as index.php: call only if margin >
// estimated remaining votes, or reporting is 100%. Under 5% = too early.
function call_race($dem, $rep, $other, $reported_pct, $runoff_50 = false) {
    $total = $dem + $rep + $other;
    if ($total <= 0) return ['no_data', 'No votes reported yet.'];
    $leader = $dem >= $rep ? 'dem' : 'rep';
    $margin = abs($dem - $rep);
    $lead_pct = $total ? round(100 * $margin / $total, 2) : 0;
    if ($reported_pct >= 100) {
        if ($runoff_50) {
            $top_share = $total ? 100 * max($dem, $rep) / $total : 0;
            if ($top_share <= 50) return ['runoff', 'No candidate over 50% at full reporting — runoff likely (Georgia rule).'];
        }
        $who = $leader === 'dem' ? 'Democrat' : 'Republican';
        return [$leader === 'dem' ? 'called_dem' : 'called_rep',
            "Reporting complete. $who leads by " . number_format($margin) . " votes ($lead_pct% of votes counted)."];
    }
    if ($reported_pct < 5) return ['too_early', 'Under 5% reporting — early returns are not representative.'];
    $remaining = $reported_pct > 0 ? (int)round($total * (100 - $reported_pct) / $reported_pct) : PHP_INT_MAX;
    if ($margin > $remaining) {
        $who = $leader === 'dem' ? 'Democrat' : 'Republican';
        return [$leader === 'dem' ? 'called_dem' : 'called_rep',
            "$who leads by " . number_format($margin) . " votes; est. " . number_format($remaining) . " votes remain — margin exceeds all remaining votes."];
    }
    if ($lead_pct < 0.5 && $remaining > $margin) {
        return ['too_close', 'Margin ' . number_format($margin) . " votes ($lead_pct%) is smaller than est. remaining votes (" . number_format($remaining) . ').'];
    }
    $who = $leader === 'dem' ? 'Democrat' : 'Republican';
    return [$leader === 'dem' ? 'leading_dem' : 'leading_rep',
        "$who leads by " . number_format($margin) . " votes ($lead_pct%), but est. " . number_format($remaining) . ' votes remain.'];
}
function party_votes($candidates, $needle) {
    $v = 0; $name = '';
    foreach ($candidates as $c) {
        if (stripos((string)($c['party'] ?? ''), $needle) !== false) { $v += (int)($c['votes'] ?? 0); $name = (string)($c['name'] ?? ''); }
    }
    return [$v, $name];
}
function leader_of($candidates) {
    $top = null;
    foreach ($candidates as $c) { if ($top === null || (int)$c['votes'] > (int)$top['votes']) $top = $c; }
    return $top;
}

// Key Senate races (same 9 as index.php; numbers come from data/live.json
// in production, exactly as the main page reads them).
$SENATE = [
  ['id' => 'ga-sen', 'label' => 'Georgia Senate', 'dem' => 'Jon Ossoff (D)', 'rep' => 'Mike Collins (R)', 'runoff_50' => true],
  ['id' => 'nc-sen', 'label' => 'North Carolina Senate', 'dem' => 'Roy Cooper (D)', 'rep' => 'Michael Whatley (R)'],
  ['id' => 'mi-sen', 'label' => 'Michigan Senate', 'dem' => 'Abdul El-Sayed (D)', 'rep' => 'Mike Rogers (R)'],
  ['id' => 'tx-sen', 'label' => 'Texas Senate', 'dem' => 'James Talarico (D)', 'rep' => 'Ken Paxton (R)'],
  ['id' => 'me-sen', 'label' => 'Maine Senate', 'dem' => 'Troy Jackson (D)', 'rep' => 'Susan Collins (R)'],
  ['id' => 'oh-sen', 'label' => 'Ohio Senate', 'dem' => 'Sherrod Brown (D)', 'rep' => 'Jon Husted (R)'],
  ['id' => 'fl-sen', 'label' => 'Florida Senate', 'dem' => 'Angie Nixon (D)', 'rep' => 'Ashley Moody (R)'],
  ['id' => 'ia-sen', 'label' => 'Iowa Senate', 'dem' => 'Josh Turek (D)', 'rep' => 'Ashley Hinson (R)'],
  ['id' => 'ak-sen', 'label' => 'Alaska Senate', 'dem' => 'Mary Peltola (D)', 'rep' => 'Dan Sullivan (R)'],
];

$stale = false; $cache_ts = 0; $fetch_ok = false;
$house = null; $governors = []; $senate_live = [];

if ($MODE === 'test') {
    $house = ['dem' => 12000000, 'rep' => 11500000, 'other' => 800000, 'reported_pct' => 61,
              'dem_seats' => 0, 'rep_seats' => 0, 'source' => 'TEST DATA'];
    $governors = [
      ['label' => 'Arizona Governor', 'state' => 'AZ', 'dem' => 760000, 'dem_name' => 'Katie Hobbs', 'rep' => 690000, 'rep_name' => 'Andy Biggs', 'other' => 25000, 'reported_pct' => 61, 'leader' => 'Katie Hobbs (D)', 'source' => 'TEST DATA'],
      ['label' => 'Georgia Governor', 'state' => 'GA', 'dem' => 1740000, 'dem_name' => 'Keisha Lance Bottoms', 'rep' => 1710000, 'rep_name' => 'Rick Jackson', 'other' => 210000, 'reported_pct' => 93, 'leader' => 'Keisha Lance Bottoms (D)', 'source' => 'TEST DATA'],
      ['label' => 'Texas Governor', 'state' => 'TX', 'dem' => 2920000, 'dem_name' => 'Gina Hinojosa', 'rep' => 2940000, 'rep_name' => 'Greg Abbott', 'other' => 60000, 'reported_pct' => 73, 'leader' => 'Greg Abbott (R)', 'source' => 'TEST DATA'],
    ];
    foreach ($SENATE as $s) $senate_live[$s['id']] = ['dem' => 1000000, 'rep' => 980000, 'other' => 20000, 'reported_pct' => 80, 'source' => 'TEST DATA'];
    $senate_display = $SENATE;
    $fetch_ok = true;
} else {
    if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
        $cached = json_decode(file_get_contents($CACHE_FILE), true) ?: [];
        $house = $cached['house'] ?? null; $governors = $cached['governors'] ?? []; $senate_live = $cached['senate'] ?? [];
        $cache_ts = filemtime($CACHE_FILE); $fetch_ok = !empty($cached);
    } else {
        // House national overview (single race, civicAPI).
        $hj = civic_json("$CIVIC/race/$HOUSE_RACE_ID");
        if ($hj && isset($hj['candidates'])) {
            [$dv, ] = party_votes($hj['candidates'], 'Democrat');
            [$rv, ] = party_votes($hj['candidates'], 'Republican');
            $total = 0; $dseats = 0; $rseats = 0;
            foreach ($hj['candidates'] as $c) {
                $total += (int)($c['votes'] ?? 0);
                if (stripos((string)($c['party'] ?? ''), 'Democrat') !== false) $dseats += (int)($c['seats'] ?? 0);
                if (stripos((string)($c['party'] ?? ''), 'Republican') !== false) $rseats += (int)($c['seats'] ?? 0);
            }
            $house = ['dem' => $dv, 'rep' => $rv, 'other' => max(0, $total - $dv - $rv),
                      'reported_pct' => (float)($hj['percent_reporting'] ?? 0),
                      'dem_seats' => $dseats, 'rep_seats' => $rseats, 'source' => 'civicAPI — House overview race 87524'];
            $fetch_ok = true;
        }
        // All Governor races: national overview race, NOT the search
        // endpoint. The search endpoint 503'd with DATABASE_TIMEOUT all
        // evening Nov 3, leaving the table empty; the overview race
        // (same single-race pattern as House 87524, via curl) is live
        // and its region_results carry every Governor state.
        $gj = civic_json("$CIVIC/race/$GOV_RACE_ID");
        foreach (($gj['region_results'] ?? []) as $abbr => $r) {
            $cands = $r['candidates'] ?? [];
            if (!$cands) continue;
            [$dv, $dn] = party_votes($cands, 'Democrat');
            [$rv, $rn] = party_votes($cands, 'Republican');
            $total = 0; foreach ($cands as $c) $total += (int)($c['votes'] ?? 0);
            $top = leader_of($cands);
            $governors[] = ['label' => (string)($r['name'] ?? strtoupper($abbr)) . ' Governor',
                'state' => strtoupper((string)$abbr), 'dem' => $dv, 'dem_name' => $dn,
                'rep' => $rv, 'rep_name' => $rn, 'other' => max(0, $total - $dv - $rv),
                'reported_pct' => (float)($r['percent_reporting'] ?? 0),
                'leader' => $top ? (string)($top['name'] ?? '') . ' (' . (string)($top['party'] ?? '') . ')' : '',
                'source' => 'civicAPI — Governors overview race 87535'];
        }
        if ($governors) $fetch_ok = true;
        usort($governors, fn($a, $b) => strcmp($a['state'], $b['state']));
        // All Senate races: national overview race, same reason as
        // Governors — its region_results carry every Senate state, so
        // the 9-race manual list below is now only a fallback.
        $senate_display = [];
        $sjj = civic_json("$CIVIC/race/$SENATE_RACE_ID");
        foreach (($sjj['region_results'] ?? []) as $abbr => $r) {
            $cands = $r['candidates'] ?? [];
            if (!$cands) continue;
            [$dv, $dn] = party_votes($cands, 'Democrat');
            [$rv, $rn] = party_votes($cands, 'Republican');
            $total = 0; foreach ($cands as $c) $total += (int)($c['votes'] ?? 0);
            $id = 'civic-' . strtolower((string)$abbr);
            $senate_display[] = ['id' => $id,
                'label' => (string)($r['name'] ?? strtoupper($abbr)) . ' Senate',
                'dem' => $dn, 'rep' => $rn, 'runoff_50' => strtolower((string)$abbr) === 'ga'];
            $senate_live[$id] = ['dem' => $dv, 'rep' => $rv, 'other' => max(0, $total - $dv - $rv),
                'reported_pct' => (float)($r['percent_reporting'] ?? 0),
                'source' => 'civicAPI — Senate overview race 87528'];
        }
        if ($senate_display) { $fetch_ok = true; usort($senate_display, fn($a, $b) => strcmp($a['label'], $b['label'])); }
        // Manual fallback: the production manual file, if present, only
        // when civicAPI gave no Senate states at all.
        if (!$senate_display) {
            $manual = [];
            foreach ([__DIR__ . '/data/live.json'] as $p) {
                if (file_exists($p)) { $manual = json_decode(file_get_contents($p), true) ?: []; break; }
            }
            foreach ($SENATE as $s) {
                if (isset($manual[$s['id']])) {
                    $m = $manual[$s['id']];
                    $senate_live[$s['id']] = ['dem' => (int)($m['dem'] ?? 0), 'rep' => (int)($m['rep'] ?? 0),
                        'other' => (int)($m['other'] ?? 0), 'reported_pct' => (float)($m['reported_pct'] ?? 0),
                        'source' => 'Manual (official numbers pasted in)'];
                }
            }
            $senate_display = $SENATE;
        }
        // Never cache an empty fetch: an empty cache would make the
        // next 120s of loads show "No Governor races" even after
        // civicAPI recovers. Only write when something was fetched.
        if ($fetch_ok) {
            @file_put_contents($CACHE_FILE, json_encode(['house' => $house, 'governors' => $governors, 'senate' => $senate_live]), LOCK_EX);
            $cache_ts = time();
        } elseif (file_exists($CACHE_FILE)) {
            $stale = true;
        }
    }
    if (!$cache_ts && file_exists($CACHE_FILE)) $cache_ts = filemtime($CACHE_FILE);
}

// Build display rows.
$gov_rows = [];
foreach ($governors as $g) {
    [$status, $reason] = call_race((int)$g['dem'], (int)$g['rep'], (int)$g['other'], (float)$g['reported_pct']);
    $total = (int)$g['dem'] + (int)$g['rep'] + (int)$g['other'];
    $gov_rows[] = $g + ['status' => $status, 'reason' => $reason, 'total' => $total,
        'dem_pct' => $total ? round(100 * $g['dem'] / $total, 1) : 0,
        'rep_pct' => $total ? round(100 * $g['rep'] / $total, 1) : 0];
}
$gov_called_dem = count(array_filter($gov_rows, fn($x) => $x['status'] === 'called_dem'));
$gov_called_rep = count(array_filter($gov_rows, fn($x) => $x['status'] === 'called_rep'));
$sen_rows = [];
foreach ($senate_display as $s) {
    $d = $senate_live[$s['id']] ?? ['dem' => 0, 'rep' => 0, 'other' => 0, 'reported_pct' => 0, 'source' => 'No data yet'];
    [$status, $reason] = call_race((int)$d['dem'], (int)$d['rep'], (int)$d['other'], (float)$d['reported_pct'], !empty($s['runoff_50']));
    $total = (int)$d['dem'] + (int)$d['rep'] + (int)$d['other'];
    $sen_rows[] = $d + $s + ['status' => $status, 'reason' => $reason, 'total' => $total,
        'dem_pct' => $total ? round(100 * $d['dem'] / $total, 1) : 0,
        'rep_pct' => $total ? round(100 * $d['rep'] / $total, 1) : 0];
}
$house_total = $house ? (int)$house['dem'] + (int)$house['rep'] + (int)$house['other'] : 0;
$status_label = ['called_dem' => 'CALLED — Dem', 'called_rep' => 'CALLED — Rep',
  'leading_dem' => 'Leading Dem', 'leading_rep' => 'Leading Rep',
  'too_close' => 'Too close to call', 'too_early' => 'Too early to call',
  'no_data' => 'No votes yet', 'runoff' => 'Runoff likely'];
$status_class = ['called_dem' => 'cd', 'called_rep' => 'cr', 'leading_dem' => 'ld', 'leading_rep' => 'lr',
  'too_close' => 'tc', 'too_early' => 'te', 'no_data' => 'nd', 'runoff' => 'ro'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($MODE === 'live'): ?><meta http-equiv="refresh" content="120"><?php endif; ?>
<title>All Races Available — Election Night — Nov 3, 2026 — SocialNews.XYZ</title>
<style>
  :root { --brand:#005a8c; --ink:#1f1e1e; --bg:#f4f4f4; --card:#fff; --line:#e2e2e2; --mut:#777; --dem:#2f7fe0; --rep:#d04343; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { background:var(--bg); color:#222; font-family:"Open Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  .sitebar { background:var(--ink); padding:10px 16px; }
  .sitebar .wrap { display:flex; align-items:center; gap:12px; max-width:1080px; margin:0 auto; }
  .sitebar img { height:40px; width:40px; border-radius:6px; }
  .sitebar a.home { color:#fff; text-decoration:none; font-weight:700; font-size:17px; }
  .sitebar a.home span { color:#8ed1fc; font-weight:400; }
  .wrap { max-width:1080px; margin:0 auto; padding:0 16px; }
  .pagehead { background:var(--card); border-bottom:3px solid var(--brand); padding:22px 0 16px; margin-bottom:20px; }
  .kicker { color:var(--brand); font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; }
  h1 { font-size:30px; margin:6px 0 4px; }
  .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin:4px 0 18px; }
  .card { background:var(--card); border:1px solid var(--line); border-top:4px solid var(--brand); border-radius:4px; padding:16px; }
  .card .big { font-size:28px; font-weight:800; }
  .card .lbl { color:var(--mut); font-size:12px; margin-top:5px; line-height:1.5; }
  .panel { background:var(--card); border:1px solid var(--line); border-radius:4px; padding:18px; margin-bottom:20px; }
  .panel h2 { font-size:18px; border-bottom:2px solid var(--brand); padding-bottom:8px; margin-bottom:12px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { text-align:left; padding:10px; border-bottom:1px solid var(--line); vertical-align:top; }
  thead th { background:var(--ink); color:#fff; font-size:12px; text-transform:uppercase; letter-spacing:.5px; }
  .num { font-variant-numeric:tabular-nums; text-align:right; }
  .bar { display:flex; height:12px; border-radius:6px; overflow:hidden; background:#e6e6e6; margin-top:6px; min-width:120px; }
  .badge { display:inline-block; font-size:11px; font-weight:700; padding:3px 8px; border-radius:12px; color:#fff; white-space:nowrap; }
  .cd { background:var(--dem); } .cr { background:var(--rep); } .ld { background:#7fb0ee; } .lr { background:#e88a8a; }
  .tc { background:#b58900; } .te { background:#777; } .nd { background:#aaa; } .ro { background:#7b5cd6; }
  .reason { color:var(--mut); font-size:12px; line-height:1.5; margin-top:4px; }
  .testbanner { background:#7b1d1d; color:#fff; padding:12px 16px; font-weight:700; text-align:center; }
  .disclaimer { background:#fff8e6; border:1px solid #e8c96a; border-left:4px solid #d4a017; color:#6b5310; border-radius:4px; padding:12px 14px; font-size:13px; margin:0 0 20px; line-height:1.6; }
  footer { color:var(--mut); font-size:12px; margin:8px 0 40px; line-height:1.7; }
  footer a { color:var(--brand); }
  @media (max-width:640px){ h1{font-size:24px;} th, td{padding:8px 6px;font-size:13px;} .hide-m{display:none;} }
</style>
</head>
<body>
<?php if ($MODE === 'test'): ?><div class="testbanner">TEST DATA — NOT REAL RESULTS. Add ?mode=live for election night.</div><?php endif; ?>
<div class="sitebar"><div class="wrap" style="padding:0">
  <img src="https://www.socialnews.xyz/wp-content/uploads/2016/07/12/Social-News-XYZ.jpg" alt="Social News XYZ">
  <a class="home" href="https://www.socialnews.xyz/">Social News <span>XYZ</span></a>
</div></div>
<div class="pagehead"><div class="wrap">
  <div class="kicker">Midterm 2026 · Election Night · Tue, Nov 3</div>
  <h1>All Races Available</h1>
  <div style="color:var(--mut);font-size:13px">What civicAPI + the key-race manual file can actually show tonight · <a href="key.php">Back to the 20 key races</a></div>
</div></div>
<div class="wrap" style="padding-top:18px">

<div class="cards">
  <div class="card"><div class="big"><?= count($gov_rows) ?></div><div class="lbl">Governor races (civicAPI, type = Governor)</div></div>
  <div class="card"><div class="big" style="color:var(--dem)"><?= $gov_called_dem ?></div><div class="lbl">Governor called — Dem (conservative rule)</div></div>
  <div class="card"><div class="big" style="color:var(--rep)"><?= $gov_called_rep ?></div><div class="lbl">Governor called — Rep (conservative rule)</div></div>
  <div class="card"><div class="big"><?= count($sen_rows) ?></div><div class="lbl">Senate races (civicAPI overview)</div></div>
</div>

<div class="disclaimer">
  <strong>Honest scope — read this first:</strong> this page shows <strong>whatever is actually
  available</strong>, not all 506 federal races. civicAPI (free, no key) carries a national House
  overview, a Senate overview, and a Governors overview, but <strong>no individual US House district races</strong>
  were found in it on Nov 3. So House districts are
  <strong>not</strong> listed here — listing them with no votes would be worse than saying so.
  Calls here use the same conservative rule as the main page: called only when the margin
  exceeds every vote that could plausibly remain, or reporting is complete. All results are
  unofficial until certified.
</div>

<div class="panel">
  <h2>House — national overview (civicAPI race 87524)</h2>
  <?php if ($house): ?>
  <p style="font-size:14px;line-height:1.7">
    Dem: <strong><?= $fmt($house['dem']) ?></strong> (<?= $house_total ? round(100 * $house['dem'] / $house_total, 1) : 0 ?>%) ·
    Rep: <strong><?= $fmt($house['rep']) ?></strong> (<?= $house_total ? round(100 * $house['rep'] / $house_total, 1) : 0 ?>%) ·
    Other: <?= $fmt($house['other']) ?> · Reporting: <?= $house['reported_pct'] ? $house['reported_pct'] . '%' : '—' ?>
    <?php if ((int)$house['dem_seats'] + (int)$house['rep_seats'] > 0): ?>
    · Seats as reported by civicAPI: Dem <?= (int)$house['dem_seats'] ?>, Rep <?= (int)$house['rep_seats'] ?> (218 needed for control)
    <?php else: ?> · 218 seats needed for House control <?php endif; ?>
  </p>
  <p style="color:var(--mut);font-size:12px;margin-top:6px">Source: <?= htmlspecialchars($house['source']) ?> · This is the national party-vote overview, not 435 district results.</p>
  <?php else: ?><p style="font-size:14px">House overview not available right now. <?= $stale ? 'Showing cached data.' : '' ?></p><?php endif; ?>
</div>

<div class="panel" style="padding:0;overflow:hidden">
<div style="padding:18px 18px 0"><h2 style="margin-bottom:0">Governor — all available (civicAPI)</h2>
<p style="color:var(--mut);font-size:13px;margin:6px 0 12px">
  Data as of <?= $MODE === 'test' ? 'test snapshot' : ($cache_ts ? date('M j, g:i A T', $cache_ts) : '—') ?>
  <?= $stale ? '· <strong style="color:#b35400">showing cached data (sources unreachable)</strong>' : '' ?>
  · page refreshes every 2 minutes in live mode ·
  <?= $MODE === 'test' ? '<a href="?mode=live">Switch to live</a>' : '<a href="?mode=test">Preview with test data</a>' ?>
</p></div>
<table>
<thead><tr><th>Race</th><th>Status</th><th class="num">Dem</th><th class="num">Rep</th><th class="num hide-m">Other</th><th class="num hide-m">Reporting</th></tr></thead>
<tbody>
<?php if (!$gov_rows): ?><tr><td colspan="6" style="color:var(--mut)">No Governor races returned yet.</td></tr><?php endif; ?>
<?php foreach ($gov_rows as $r): ?>
<tr>
  <td><strong><?= htmlspecialchars($r['label']) ?></strong><br><span style="color:var(--mut);font-size:11px"><?= htmlspecialchars($r['state']) ?> · leader: <?= htmlspecialchars($r['leader']) ?></span>
    <div class="bar"><div style="width:<?= $r['dem_pct'] ?>%;background:var(--dem)"></div><div style="width:<?= $r['rep_pct'] ?>%;background:var(--rep)"></div></div>
    <div class="reason"><?= htmlspecialchars($r['reason']) ?></div>
  </td>
  <td><span class="badge <?= $status_class[$r['status']] ?>"><?= $status_label[$r['status']] ?></span></td>
  <td class="num"><?= $fmt($r['dem']) ?><br><span style="color:var(--mut)"><?= $r['dem_pct'] ?>%</span><br><span style="color:var(--mut);font-size:11px"><?= htmlspecialchars($r['dem_name']) ?></span></td>
  <td class="num"><?= $fmt($r['rep']) ?><br><span style="color:var(--mut)"><?= $r['rep_pct'] ?>%</span><br><span style="color:var(--mut);font-size:11px"><?= htmlspecialchars($r['rep_name']) ?></span></td>
  <td class="num hide-m"><?= $fmt($r['other']) ?></td>
  <td class="num hide-m"><?= $r['reported_pct'] ? $r['reported_pct'] . '%' : '—' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="panel" style="padding:0;overflow:hidden">
<div style="padding:18px 18px 0"><h2 style="margin-bottom:0">Senate — all states (civicAPI overview race 87528)</h2>
<p style="color:var(--mut);font-size:13px;margin:6px 0 12px">Every Senate state from the national overview race; the 9-race manual file is only a fallback if that race is unreachable.</p></div>
<table>
<thead><tr><th>Race</th><th>Status</th><th class="num">Dem</th><th class="num">Rep</th><th class="num hide-m">Other</th><th class="num hide-m">Reporting</th></tr></thead>
<tbody>
<?php foreach ($sen_rows as $r): ?>
<tr>
  <td><strong><?= htmlspecialchars($r['label']) ?></strong>
    <div class="bar"><div style="width:<?= $r['dem_pct'] ?>%;background:var(--dem)"></div><div style="width:<?= $r['rep_pct'] ?>%;background:var(--rep)"></div></div>
    <div class="reason"><?= htmlspecialchars($r['reason']) ?></div>
  </td>
  <td><span class="badge <?= $status_class[$r['status']] ?>"><?= $status_label[$r['status']] ?></span></td>
  <td class="num"><?= $fmt($r['dem']) ?><br><span style="color:var(--mut)"><?= $r['dem_pct'] ?>%</span></td>
  <td class="num"><?= $fmt($r['rep']) ?><br><span style="color:var(--mut)"><?= $r['rep_pct'] ?>%</span></td>
  <td class="num hide-m"><?= $fmt($r['other']) ?></td>
  <td class="num hide-m"><?= $r['reported_pct'] ? $r['reported_pct'] . '%' : '—' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<footer>
  Sources: civicAPI (free, no key) — House overview race 87524, Senate overview race 87528,
  Governors overview race 87535; the main page's manual file is only a Senate fallback;
  Arizona Governor also appears in the main page
  from the AZ Secretary of State feed. Calls on this page are conservative, transparent
  calls — not AP calls. House district-level results are not in civicAPI as verified
  Nov 3, 2026, so they are not shown here.
</footer>

</div>
</body>
</html>
