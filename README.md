# Election Night Dashboard — free-source prototype

Single-file PHP dashboard for election night, built for
**Tuesday, November 3, 2026** and reusable for future elections.
No database, no build step, no paid AP / Decision Desk HQ feed.

## What it is

- `index.php` — the whole dashboard. Drop it as
  `election-night/index.php` under the WordPress docroot on
  socialnews.xyz (a real directory, so WordPress never intercepts it —
  same pattern as the early-vote tracker).
- Key Senate and Governor races only in v1. It is **not** a full
  House / Senate control map — that needs all 435 House races and
  every Senate seat, which is a bigger ingest job.
- Calls are **conservative and transparent**: a race is called only
  when the leader's margin exceeds every vote that could plausibly
  remain, or reporting is complete. The page shows the reasoning
  for every race. It will be later than AP / network calls, on purpose.
  "Leading" is never presented as a call.

## Run it

Live (default):

```
https://www.socialnews.xyz/election-night/
```

Preview with test data (before Tuesday, to check layout and calls):

```
https://www.socialnews.xyz/election-night/?mode=test
```

Test data is built into `index.php` and is labelled
**TEST DATA — NOT REAL RESULTS** on the page. Never use test mode
on election night.

Local preview (any PHP 8+ machine):

```sh
php -S 127.0.0.1:8080 -t .
# open http://127.0.0.1:8080/?mode=test
php tests/test-call-logic.php
```

## Data sources (free, official only)

| Source | How it is used | Status in v1 |
|---|---|---|
| Arizona Secretary of State, Enhanced Results export (`export-110326GeneralElection.json`) | Wired in `index.php` for Arizona Governor; live from 8 PM ET Tue Nov 3 per the AZ SOS announcement | Parser is defensive (walks the JSON for the contest) because the export was still empty / placeholder before election night — verify its field names on the first live pull |
| Clarity Elections (ENR) `sum.json` | Parser in `index.php` (`source => 'clarity'`); used by several states and 800+ counties, public endpoints, no auth | No statewide Clarity URL wired yet — add one per race (see below) |
| State / county SOS feeds | Parser in `index.php` (`source => 'state_json'`) | Same as above |
| Manual `data/live.json` | Paste official numbers in; the page calls from those | **The Tuesday fallback.** If any feed format breaks mid-night, this keeps the dashboard honest |

Cache: server-side, 2 minutes in live mode, in the page directory
(or `sys_get_temp_dir()` if that is not writable). If all sources
fail, the page keeps showing the last cache with a stale banner —
it never invents numbers.

## Adding a race / a feed (future elections)

1. Add one entry to `$RACES` in `index.php`: label, candidates,
   `match` string (substring of the contest title in the feed).
2. Clarity: set `'source' => 'clarity'`, `'url'` to the
   `…/json/sum.json` URL, candidate last names for matching.
   Get the URL from the public results page URL — the state,
   county and election ID are in its path.
3. State JSON: set `'source' => 'state_json'` and a `match` string.
   The parser walks the whole JSON tree, so exact field names do
   not have to be known in advance.
4. If you know an expected total (2022 turnout is a decent proxy),
   set `'expected_total'` — it makes the "votes remaining"
   estimate far better than inferring it from % reporting.
5. Georgia-style races (winner needs >50%): `'runoff_50' => true`.
   At full reporting with no one over 50%, the page says
   "Runoff likely" instead of calling the leader.
6. Update the test data in `$TEST_DATA` to cover the new race's
   call outcome, and add a case to `tests/test-call-logic.php`.

## Manual live.json (Tuesday fallback)

Copy `data/live.example.json` to `data/live.json` and fill in
official numbers as they are published:

```json
{
  "ga-sen": {"dem": 1823400, "rep": 1798100, "other": 141000, "reported_pct": 100}
}
```

`data/live.json` is gitignored — it is operator data, not code.
The page labels those races "Manual (official numbers pasted in)".

## Call rule, precisely

`call_race($dem, $rep, $other, $reported_pct, $expected_total, $runoff_50)`:

- No votes → "No votes yet".
- 100% reporting → call the leader, unless `runoff_50` and no one
  is over 50% → "Runoff likely".
- Under 5% reporting → "Too early to call", however large the margin.
- Otherwise, estimate remaining votes (from `expected_total` if set,
  else from % reporting). Call only if `margin > remaining`.
- Margin under 0.5% and smaller than remaining → "Too close to call".
- Otherwise → "Leading Dem / Rep" (uncalled).

This is not AP's model. AP also uses voter surveys, historical
precinct patterns and analyst judgment to call races early. This
dashboard deliberately does not — it trades earliness for a call
rule anyone can audit from the numbers on the page.

## What is NOT in v1

- Full House control (435 races) and full Senate control tallies.
- Precinct / county breakdowns per race.
- Exit polls, voter surveys, or any predictive model.
- AP / DDHQ data (paid, licensed — not free).

All of those are addable by extending `$RACES` and the parsers,
which is why this lives in a repo instead of as a one-off file.

## Deploy checklist for Nov 3

- [ ] Upload the whole folder to `socialnews.xyz/election-night/` (`index.php`
      plus `data/` — `data/live.json` is created on the server when needed)
- [ ] Check `?mode=test` renders, then switch to live mode
- [ ] At 8 PM ET Tue, check Arizona Governor pulls from the AZ SOS export
- [ ] Keep `data/live.json` ready as the fallback for any state whose
      feed format breaks; label those races Manual on the page
- [ ] After the night, note which feeds worked / broke in the repo —
      that is the prep for the next election
