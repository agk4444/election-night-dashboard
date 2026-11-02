<?php
// CLI test for the call rule in index.php. Run: php tests/test-call-logic.php
// The call function is extracted from index.php without executing the page:
// index.php has no top-level side effects that prevent inclusion in CLI
// EXCEPT it renders HTML, so instead we copy the rule's contract here by
// including the file's function via a stub: we parse it out.
$src = file_get_contents(__DIR__ . '/../index.php');
if (!preg_match('/function call_race\(.*?^}/ms', $src, $m)) {
    fwrite(STDERR, "FAIL: call_race not found in index.php\n"); exit(1);
}
eval($m[0]);

$cases = [
    // [dem, rep, other, reported_pct, expected_total, runoff_50, expected_status]
    [0, 0, 0, 0, 0, false, 'no_data'],
    [1000, 500, 0, 3, 0, false, 'too_early'],          // under 5% never called
    [1823400, 1798100, 141000, 100, 0, true, 'runoff'], // GA: leader under 50% at 100%
    [1823400, 1798100, 141000, 100, 0, false, 'called_dem'],
    [1510000, 1390000, 45000, 96, 3000000, false, 'called_dem'], // margin 120k > remaining ~45k
    [940000, 935000, 20000, 84, 0, false, 'too_close'], // margin 5k, est remaining ~181k, <0.5%
    [1210000, 1090000, 30000, 78, 0, false, 'leading_dem'], // margin 120k < est remaining ~348k, >0.5%
    [2900000, 3300000, 90000, 88, 0, false, 'leading_rep'],
];
$fail = 0;
foreach ($cases as $i => $c) {
    [$status] = call_race($c[0], $c[1], $c[2], $c[3], $c[4], $c[5]);
    $ok = $status === $c[6];
    if (!$ok) $fail++;
    echo ($ok ? 'PASS' : 'FAIL') . " case $i: got $status, want {$c[6]}\n";
}
echo $fail ? "FAILURES: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
