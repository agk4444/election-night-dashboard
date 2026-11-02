<?php
/**
 * Election Night Dashboard — free-source prototype
 * =================================================
 * Single-file PHP page. Drop it as election-night/index.php under the
 * WordPress docroot on socialnews.xyz (a real directory, so WP never
 * intercepts it) — same pattern as the early-vote tracker. No database,
 * no build step, no paid feed.
 *
 * What it does
 * ------------
 * - Pulls live returns from FREE official sources only (state / county
 *   Election Night Reporting feeds), with a server-side cache so a
 *   traffic spike never hammers the source.
 * - Applies a CONSERVATIVE call rule and shows its working. This is NOT
 *   an AP / Decision Desk call: it calls a race only when the leader's
 *   margin is larger than every vote that could plausibly remain, or
 *   when reporting is complete. It will be late. That is the point.
 * - Falls back to a manual live.json (see data/live.example.json) when a
 *   feed format breaks mid-night: paste official numbers in, the page
 *   calls from those instead of inventing anything.
 *
 * Modes
 * -----
 * - Live (default): ?mode=live — fetches sources, caches 2 minutes.
 * - Test: ?mode=test — built-in sample mid-evening numbers, clearly
 *   labelled TEST DATA. Use this before Tuesday to check the layout
 *   and the call logic. Test data is NEVER real results.
 *
 * Adding a race for a future election
 * ------------------------------------
 * 1. Add one entry to $RACES below (label, candidates, source).
 * 2. For a Clarity (ENR) source, set 'source' => 'clarity',
 *    'url' => the sum.json URL, 'match' => a string in the contest
 *    title, and candidate last names for matching.
 * 3. For a state JSON source, set 'source' => 'state_json' and a
 *    'match' string; the parser walks the JSON looking for that
 *    contest, so exact field names do not have to be known in advance.
 * 4. If you know an expected total (2022 turnout is a decent proxy),
 *    set 'expected_total' — it makes the "votes remaining" estimate
 *    far better than inferring it from % reporting alone.
 * 5. Georgia-style races (winner needs >50%) set 'runoff_50' => true.
 *
 * Sources are unofficial until certified. Results can move as mail
 * and provisional ballots are counted after election night.
 */

$MODE = (isset($_GET['mode']) && $_GET['mode'] === 'test') ? 'test' : 'live';
$CACHE_TTL = 120; // seconds, election night
$CACHE_DIR = is_writable(__DIR__) ? __DIR__ : sys_get_temp_dir();
$CACHE_FILE = $CACHE_DIR . '/election-cache.json';
$UA = 'socialnews.xyz election-night dashboard';

// ---------------------------------------------------------------- races
// 'dem'/'rep' are candidate display names. 'match' is matched against the
// contest title in the source feed (case-insensitive, substring).
// 'url' is left empty where the state has no single statewide free JSON
// feed wired yet — those races then run on data/live.json (manual) until
// a feed is added. The page never hides that: source column says Manual.
$RACES = [
  // Senate — candidates from the Psepho race list, Nov 1 2026.
  ['id' => 'ga-sen',  'label' => 'Georgia Senate',      'chamber' => 'Senate',   'dem' => 'Jon Ossoff (D)',   'rep' => 'Mike Collins (R)',  'match' => 'United States Senator', 'runoff_50' => true,  'poll_close' => '7:00 PM ET', 'source' => 'manual', 'url' => ''],
  ['id' => 'nc-sen',  'label' => 'North Carolina Senate', 'chamber' => 'Senate', 'dem' => 'Roy Cooper (D)',   'rep' => 'Michael Whatley (R)', 'match' => 'U.S. Senate', 'poll_close' => '7:30 PM ET', 'source' => 'manual', 'url' => ''],
  ['id' => 'mi-sen',  'label' => 'Michigan Senate',     'chamber' => 'Senate',   'dem' => 'Abdul El-Sayed (D)', 'rep' => 'Mike Rogers (R)', 'match' => 'U.S. Senator', 'source' => 'manual', 'url' => ''],
  ['id' => 'tx-sen',  'label' => 'Texas Senate',        'chamber' => 'Senate',   'dem' => 'James Talarico (D)', 'rep' => 'Ken Paxton (R)', 'match' => 'United States Senator', 'source' => 'manual', 'url' => ''],
  ['id' => 'me-sen',  'label' => 'Maine Senate',        'chamber' => 'Senate',   'dem' => 'Troy Jackson (D)', 'rep' => 'Susan Collins (R)', 'match' => 'United States Senator', 'source' => 'manual', 'url' => ''],
  ['id' => 'oh-sen',  'label' => 'Ohio Senate',         'chamber' => 'Senate',   'dem' => 'Sherrod Brown (D)', 'rep' => 'Jon Husted (R)', 'match' => 'U.S. Senator', 'poll_close' => '7:30 PM ET', 'source' => 'manual', 'url' => ''],
  ['id' => 'fl-sen',  'label' => 'Florida Senate',      'chamber' => 'Senate',   'dem' => 'Angie Nixon (D)',  'rep' => 'Ashley Moody (R)', 'match' => 'United States Senator', 'source' => 'manual', 'url' => ''],
  ['id' => 'ia-sen',  'label' => 'Iowa Senate',         'chamber' => 'Senate',   'dem' => 'Josh Turek (D)',   'rep' => 'Ashley Hinson (R)', 'match' => 'United States Senator', 'source' => 'manual', 'url' => ''],
  ['id' => 'ak-sen',  'label' => 'Alaska Senate',       'chamber' => 'Senate',   'dem' => 'Mary Peltola (D)', 'rep' => 'Dan Sullivan (R)', 'match' => 'United States Senator', 'source' => 'manual', 'url' => ''],
  // Governor
  ['id' => 'az-gov',  'label' => 'Arizona Governor',    'chamber' => 'Governor', 'dem' => 'Katie Hobbs (D)',  'rep' => 'Andy Biggs (R)',   'match' => 'Governor', 'source' => 'state_json',
   'url' => 'https://app.enhancedvoting.com/cdn/results/Arizona/export-110326GeneralElection.json',
   'source_label' => 'AZ Secretary of State, Enhanced Results (from 8 PM ET Tue)'],
  ['id' => 'ga-gov',  'label' => 'Georgia Governor',    'chamber' => 'Governor', 'dem' => 'Keisha Lance Bottoms (D)', 'rep' => 'Rick Jackson (R)', 'match' => 'Governor', 'runoff_50' => true, 'poll_close' => '7:00 PM ET', 'source' => 'manual', 'url' => ''],
  ['id' => 'ks-gov',  'label' => 'Kansas Governor',     'chamber' => 'Governor', 'dem' => 'Cindy Holscher (D)', 'rep' => 'Ty Masterson (R)', 'match' => 'Governor', 'source' => 'manual', 'url' => ''],
  ['id' => 'me-gov',  'label' => 'Maine Governor',      'chamber' => 'Governor', 'dem' => 'Hannah Pingree (D)', 'rep' => 'Bobby Charles (R)', 'match' => 'Governor', 'source' => 'manual', 'url' => ''],
  ['id' => 'mi-gov',  'label' => 'Michigan Governor',   'chamber' => 'Governor', 'dem' => 'Jocelyn Benson (D)', 'rep' => 'John James (R)', 'match' => 'Governor', 'source' => 'manual', 'url' => ''],
  ['id' => 'nv-gov',  'label' => 'Nevada Governor',     'chamber' => 'Governor', 'dem' => 'Aaron Ford (D)',   'rep' => 'Joe Lombardo (R)', 'match' => 'Governor', 'source' => 'manual', 'url' => ''],
  ['id' => 'nm-gov',  'label' => 'New Mexico Governor', 'chamber' => 'Governor', 'dem' => 'Deb Haaland (D)',  'rep' => 'Gregg Hull (R)',  'match' => 'Governor', 'source' => 'manual', 'url' => ''],
  ['id' => 'oh-gov',  'label' => 'Ohio Governor',       'chamber' => 'Governor', 'dem' => 'Amy Acton (D)',    'rep' => 'Vivek Ramaswamy (R)', 'match' => 'Governor', 'poll_close' => '7:30 PM ET', 'source' => 'manual', 'url' => ''],
  ['id' => 'wi-gov',  'label' => 'Wisconsin Governor',  'chamber' => 'Governor', 'dem' => 'David Crowley (D)', 'rep' => 'Tom Tiffany (R)', 'match' => 'Governor', 'source' => 'manual', 'url' => ''],
  ['id' => 'fl-gov',  'label' => 'Florida Governor',    'chamber' => 'Governor', 'dem' => 'David Jolly (D)',  'rep' => 'Byron Donalds (R)', 'match' => 'Governor', 'source' => 'manual', 'url' => ''],
  // Added Nov 1 2026: Texas Governor was not in the Psepho list, but late
  // polls had it within a few points (TSU Oct 29: Abbott 50%, Hinojosa 47%),
  // so it is tracked here just in case it is the night's surprise.
  ['id' => 'tx-gov',  'label' => 'Texas Governor',      'chamber' => 'Governor', 'dem' => 'Gina Hinojosa (D)', 'rep' => 'Greg Abbott (R)', 'match' => 'Governor', 'source' => 'manual', 'url' => ''],
];

// Test data: a plausible mid-evening snapshot. NOT real. Covers every
// call outcome once: called D, called R, leading, too close, too early,
// and a Georgia runoff case (leader under 50% at full reporting).
$TEST_DATA = [
  'ga-sen' => ['dem' => 1823400, 'rep' => 1798100, 'other' => 141000, 'reported_pct' => 100],
  'nc-sen' => ['dem' => 1210000, 'rep' => 1090000, 'other' => 30000,  'reported_pct' => 78],
  'mi-sen' => ['dem' => 940000,  'rep' => 935000,  'other' => 20000,  'reported_pct' => 84],
  'tx-sen' => ['dem' => 0,       'rep' => 0,       'other' => 0,      'reported_pct' => 0],
  'me-sen' => ['dem' => 310000,  'rep' => 295000,  'other' => 12000,  'reported_pct' => 91],
  'oh-sen' => ['dem' => 1510000, 'rep' => 1390000, 'other' => 45000,  'reported_pct' => 96],
  'fl-sen' => ['dem' => 2900000, 'rep' => 3300000, 'other' => 90000,  'reported_pct' => 88],
  'ia-sen' => ['dem' => 410000,  'rep' => 415000,  'other' => 9000,   'reported_pct' => 67],
  'ak-sen' => ['dem' => 95000,   'rep' => 88000,   'other' => 7000,   'reported_pct' => 54],
  'az-gov' => ['dem' => 760000,  'rep' => 690000,  'other' => 25000,  'reported_pct' => 61],
  'ga-gov' => ['dem' => 1740000, 'rep' => 1710000, 'other' => 210000, 'reported_pct' => 93],
  'ks-gov' => ['dem' => 420000,  'rep' => 445000,  'other' => 15000,  'reported_pct' => 82],
  'me-gov' => ['dem' => 340000,  'rep' => 280000,  'other' => 10000,  'reported_pct' => 97],
  'mi-gov' => ['dem' => 1100000, 'rep' => 980000,  'other' => 40000,  'reported_pct' => 79],
  'nv-gov' => ['dem' => 380000,  'rep' => 395000,  'other' => 12000,  'reported_pct' => 71],
  'nm-gov' => ['dem' => 290000,  'rep' => 240000,  'other' => 8000,   'reported_pct' => 89],
  'oh-gov' => ['dem' => 1450000, 'rep' => 1380000, 'other' => 50000,  'reported_pct' => 94],
  'wi-gov' => ['dem' => 1250000, 'rep' => 1180000, 'other' => 30000,  'reported_pct' => 86],
  'fl-gov' => ['dem' => 3100000, 'rep' => 3500000, 'other' => 110000, 'reported_pct' => 90],
  'tx-gov' => ['dem' => 2920000, 'rep' => 2940000, 'other' => 60000,  'reported_pct' => 73],
];

// ---------------------------------------------------------------- helpers
function fetch_url($url, $timeout = 25) {
    global $UA;
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => $UA, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : $raw;
}

function last_name($display) {
    $parts = preg_split('/\s+/', trim($display));
    // "Keisha Lance Bottoms (D)" -> Bottoms ; "Mary Peltola (D)" -> Peltola
    $parts = array_values(array_filter($parts, fn($p) => strpos($p, '(') !== 0));
    return end($parts);
}

// Walk any decoded JSON tree looking for a contest whose title contains
// $match and that carries candidate-like children with votes. Tolerant
// by design: state feeds rename fields between elections.
function find_contest($node, $match, &$depth = 0) {
    if ($depth > 8 || !is_array($node)) return null;
    $depth++;
    $title = '';
    foreach (['title', 'contest', 'contestName', 'ContestName', 'name', 'office', 'race'] as $k) {
        if (isset($node[$k]) && is_string($node[$k])) { $title = $node[$k]; break; }
    }
    if ($title !== '' && stripos($title, $match) !== false) {
        $cands = null;
        foreach (['candidates', 'choices', 'Candidates', 'Choices', 'results', 'candidateVotes'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) { $cands = $node[$k]; break; }
        }
        if ($cands) return ['title' => $title, 'candidates' => $cands, 'node' => $node];
    }
    foreach ($node as $v) {
        if (is_array($v)) {
            $found = find_contest($v, $match, $depth);
            if ($found) return $found;
        }
    }
    $depth--;
    return null;
}

function votes_from_candidate($c) {
    foreach (['votes', 'Votes', 'totalVotes', 'voteCount', 'ballots'] as $k) {
        if (isset($c[$k]) && is_numeric($c[$k])) return (int)$c[$k];
    }
    return null;
}

function name_from_candidate($c) {
    foreach (['name', 'candidate', 'candidateName', 'ChoiceName', 'choice', 'fullName'] as $k) {
        if (isset($c[$k]) && is_string($c[$k])) return $c[$k];
    }
    return '';
}

// Conservative call. Returns [status, reason].
// status: called_dem | called_rep | leading_dem | leading_rep |
//         too_close | too_early | no_data | runoff
// Rule: call only if margin > estimated votes remaining, or reporting
// is complete (100%). "Remaining" comes from expected_total when set,
// else from % reporting. A race under 5% reporting is never called,
// however large the early margin — early vote is not representative.
function call_race($dem, $rep, $other, $reported_pct, $expected_total, $runoff_50) {
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
    if ($reported_pct < 5) {
        return ['too_early', 'Under 5% reporting — early returns are not representative.'];
    }
    if ($expected_total && $expected_total > $total) {
        $remaining = $expected_total - $total;
    } elseif ($reported_pct > 0) {
        $remaining = (int)round($total * (100 - $reported_pct) / $reported_pct);
    } else {
        $remaining = PHP_INT_MAX;
    }
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

// ---------------------------------------------------------------- data
$live = []; $cache_ts = 0; $stale = false;
if ($MODE === 'test') {
    foreach ($TEST_DATA as $id => $d) $live[$id] = $d + ['source' => 'TEST DATA', 'fetched_ok' => true];
} else {
    if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
        $live = json_decode(file_get_contents($CACHE_FILE), true) ?: [];
        $cache_ts = filemtime($CACHE_FILE);
    } else {
        // Manual live.json overrides feeds: simplest reliable Tuesday fallback.
        $manual = [];
        foreach ([__DIR__ . '/data/live.json', $CACHE_DIR . '/data-live.json'] as $p) {
            if (file_exists($p)) { $manual = json_decode(file_get_contents($p), true) ?: []; break; }
        }
        foreach ($RACES as $r) {
            $row = ['dem' => 0, 'rep' => 0, 'other' => 0, 'reported_pct' => 0, 'fetched_ok' => false, 'source' => 'No data yet'];
            if (isset($manual[$r['id']])) {
                $m = $manual[$r['id']];
                $row = ['dem' => (int)($m['dem'] ?? 0), 'rep' => (int)($m['rep'] ?? 0), 'other' => (int)($m['other'] ?? 0),
                        'reported_pct' => (float)($m['reported_pct'] ?? 0), 'fetched_ok' => true, 'source' => 'Manual (official numbers pasted in)'];
            } elseif ($r['source'] === 'state_json' && !empty($r['url'])) {
                $raw = fetch_url($r['url'], 25);
                $json = $raw ? json_decode($raw, true) : null;
                if ($json) {
                    $d = 0; $found = find_contest($json, $r['match']);
                    if ($found) {
                        $dn = last_name($r['dem']); $rn = last_name($r['rep']);
                        foreach ($found['candidates'] as $c) {
                            $nm = name_from_candidate($c); $v = votes_from_candidate($c);
                            if ($v === null) continue;
                            if (stripos($nm, $dn) !== false) $row['dem'] += $v;
                            elseif (stripos($nm, $rn) !== false) $row['rep'] += $v;
                            else $row['other'] += $v;
                        }
                        $row['fetched_ok'] = true;
                        $row['source'] = $r['source_label'] ?? 'State feed';
                        foreach (['precinctsReportingPct', 'reportingPct', 'percentReporting', 'pctReporting'] as $k) {
                            if (isset($found['node'][$k]) && is_numeric($found['node'][$k])) $row['reported_pct'] = (float)$found['node'][$k];
                        }
                    }
                }
            } elseif ($r['source'] === 'clarity' && !empty($r['url'])) {
                // Clarity sum.json: Contests[] -> ContestName, Choices[] -> Votes.
                // Kept as a documented path; wire a URL in $RACES to use it.
                $raw = fetch_url($r['url'], 25);
                $json = $raw ? json_decode($raw, true) : null;
                if ($json && isset($json['Contests'])) {
                    $dn = last_name($r['dem']); $rn = last_name($r['rep']);
                    foreach ($json['Contests'] as $c) {
                        if (!isset($c['ContestName']) || stripos($c['ContestName'], $r['match']) === false) continue;
                        foreach ($c['Choices'] ?? [] as $ch) {
                            $nm = $ch['ChoiceName'] ?? ''; $v = (int)($ch['Votes'] ?? 0);
                            if (stripos($nm, $dn) !== false) $row['dem'] += $v;
                            elseif (stripos($nm, $rn) !== false) $row['rep'] += $v;
                            else $row['other'] += $v;
                        }
                        $row['fetched_ok'] = true; $row['source'] = 'Clarity ENR (official)';
                        break;
                    }
                }
            }
            $live[$r['id']] = $row;
        }
        @file_put_contents($CACHE_FILE, json_encode($live), LOCK_EX);
        $cache_ts = time();
        // If every feed failed and no manual file exists, keep showing the
        // previous cache (if any) instead of a blank page.
        $any_ok = false;
        foreach ($live as $row) if ($row['fetched_ok']) $any_ok = true;
        if (!$any_ok && file_exists($CACHE_FILE)) { $stale = true; }
    }
    if (!$cache_ts && file_exists($CACHE_FILE)) $cache_ts = filemtime($CACHE_FILE);
}

// ---------------------------------------------------------------- tally
$results = [];
foreach ($RACES as $r) {
    $d = $live[$r['id']] ?? ['dem' => 0, 'rep' => 0, 'other' => 0, 'reported_pct' => 0, 'fetched_ok' => false, 'source' => 'No data yet'];
    [$status, $reason] = call_race((int)$d['dem'], (int)$d['rep'], (int)$d['other'],
        (float)$d['reported_pct'], (int)($r['expected_total'] ?? 0), !empty($r['runoff_50']));
    $total = (int)$d['dem'] + (int)$d['rep'] + (int)$d['other'];
    // NOTE: $d must come FIRST in the union. $RACES entries carry dem/rep
    // as candidate NAMES ("Jon Ossoff (D)"), while $d carries dem/rep as
    // vote COUNTS. With PHP's + union, the left side wins on shared keys,
    // so $r + $d made $results['dem'] a name string — number_format()
    // on it fatally stopped the page mid-table on PHP 8.5, after row 1.
    // $d + $r keeps the counts and fills in race metadata (label, etc.)
    // from $r, and the data source label from $d.
    $results[] = $d + $r + ['status' => $status, 'reason' => $reason, 'total' => $total,
        'dem_pct' => $total ? round(100 * $d['dem'] / $total, 1) : 0,
        'rep_pct' => $total ? round(100 * $d['rep'] / $total, 1) : 0];
}
$called_dem = count(array_filter($results, fn($x) => $x['status'] === 'called_dem'));
$called_rep = count(array_filter($results, fn($x) => $x['status'] === 'called_rep'));
$uncalled = count($results) - $called_dem - $called_rep;
$fmt = fn($n) => number_format((int)$n); // defensive: never feed number_format a name string again
$status_label = [
  'called_dem' => 'CALLED — Dem', 'called_rep' => 'CALLED — Rep',
  'leading_dem' => 'Leading Dem', 'leading_rep' => 'Leading Rep',
  'too_close' => 'Too close to call', 'too_early' => 'Too early to call',
  'no_data' => 'No votes yet', 'runoff' => 'Runoff likely'];
$status_class = [
  'called_dem' => 'cd', 'called_rep' => 'cr', 'leading_dem' => 'ld', 'leading_rep' => 'lr',
  'too_close' => 'tc', 'too_early' => 'te', 'no_data' => 'nd', 'runoff' => 'ro'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($MODE === 'live'): ?><meta http-equiv="refresh" content="120"><?php endif; ?>
<title>Election Night Dashboard — Nov 3, 2026 — SocialNews.XYZ</title>
<style>
  :root { --brand:#005a8c; --ink:#1f1e1e; --bg:#f4f4f4; --card:#fff; --line:#e2e2e2; --mut:#777;
          --dem:#2f7fe0; --rep:#d04343; }
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
  .cd { background:var(--dem); } .cr { background:var(--rep); }
  .ld { background:#7fb0ee; } .lr { background:#e88a8a; }
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
  <h1>Election Night Dashboard</h1>
  <div style="color:var(--mut);font-size:13px">Key Senate &amp; Governor races · free official sources only · conservative calls, with the working shown</div>
</div></div>
<div class="wrap" style="padding-top:18px">

<div class="cards">
  <div class="card"><div class="big" style="color:var(--dem)"><?= $called_dem ?></div><div class="lbl">Races called — Dem (tracked races only)</div></div>
  <div class="card"><div class="big" style="color:var(--rep)"><?= $called_rep ?></div><div class="lbl">Races called — Rep (tracked races only)</div></div>
  <div class="card"><div class="big"><?= $uncalled ?></div><div class="lbl">Still uncalled / no votes yet</div></div>
  <div class="card"><div class="big"><?= count($results) ?></div><div class="lbl">Key races tracked</div></div>
</div>

<div class="disclaimer">
  <strong>How to read this page:</strong> a race is <strong>called</strong> only when the leader's margin is
  bigger than every vote that could plausibly remain, or reporting is complete. That is deliberately
  stricter — and later — than AP / network calls. "Leading" is not a call. All results are
  <strong>unofficial</strong> until certified, and can move as mail and provisional ballots are counted.
  This page tracks key races only — it is not a full House / Senate control map.
</div>

<div class="panel" style="padding:0;overflow:hidden">
<div style="padding:18px 18px 0"><h2 style="margin-bottom:0">Key races</h2>
<p style="color:var(--mut);font-size:13px;margin:6px 0 12px">
  Data as of <?= $MODE === 'test' ? 'test snapshot' : ($cache_ts ? date('M j, g:i A T', $cache_ts) : '—') ?>
  <?= $stale ? '· <strong style="color:#b35400">showing cached data (sources unreachable)</strong>' : '' ?>
  · page refreshes every 2 minutes in live mode ·
  <?= $MODE === 'test' ? '<a href="?mode=live">Switch to live</a>' : '<a href="?mode=test">Preview with test data</a>' ?>
</p></div>
<table>
<thead><tr><th>Race</th><th>Status</th><th class="num">Dem</th><th class="num">Rep</th><th class="num hide-m">Other</th><th class="num hide-m">Reporting</th><th class="hide-m">Source</th></tr></thead>
<tbody>
<?php foreach ($results as $r): ?>
<tr>
  <td><strong><?= htmlspecialchars($r['label']) ?></strong><br><span style="color:var(--mut);font-size:11px"><?= htmlspecialchars($r['chamber']) ?><?= !empty($r['poll_close']) ? ' · polls close ' . htmlspecialchars($r['poll_close']) : '' ?></span>
    <div class="bar"><div style="width:<?= $r['dem_pct'] ?>%;background:var(--dem)"></div><div style="width:<?= $r['rep_pct'] ?>%;background:var(--rep)"></div></div>
    <div class="reason"><?= htmlspecialchars($r['reason']) ?></div>
  </td>
  <td><span class="badge <?= $status_class[$r['status']] ?>"><?= $status_label[$r['status']] ?></span></td>
  <td class="num"><?= $fmt($r['dem']) ?><br><span style="color:var(--mut)"><?= $r['dem_pct'] ?>%</span></td>
  <td class="num"><?= $fmt($r['rep']) ?><br><span style="color:var(--mut)"><?= $r['rep_pct'] ?>%</span></td>
  <td class="num hide-m"><?= $fmt($r['other']) ?></td>
  <td class="num hide-m"><?= $r['reported_pct'] ? $r['reported_pct'] . '%' : '—' ?></td>
  <td class="hide-m" style="color:var(--mut);font-size:12px"><?= htmlspecialchars($r['source']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<footer>
  Sources: state / county official Election Night Reporting feeds only — no paid AP / DDHQ feed.
  Arizona: AZ Secretary of State Enhanced Results export (live from 8 PM ET Tue, Nov 3).
  Other states: add their Clarity sum.json URL or state JSON URL in index.php, or paste official
  numbers into data/live.json (see data/live.example.json) — the page will say "Manual" for those races.
  Calls on this page are conservative, transparent calls — not AP calls.
</footer>

</div>
</body>
</html>
