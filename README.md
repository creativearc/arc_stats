# Arc Stats

Site analytics on the ExpressionEngine Control Panel dashboard, for one site.
Visitor numbers come from **Fathom Analytics**, **Google Analytics 4**, or both;
search queries come from **Google Search Console**, which works alongside
either (or on its own).

## Requirements

- ExpressionEngine 7 with the Pro dashboard (widgets are a Pro feature)
- PHP 8.0+ with `curl`, `openssl` and `simplexml`
- No Composer dependencies

## Install

1. Copy `arc_stats` to `system/user/addons/`.
2. **Add-Ons → Arc Stats → Install.** This creates `exp_arc_stats_settings` and
   registers nine dashboard widgets (EE scans `widgets/*.php` on install and update).
3. **Add-Ons → Arc Stats → Settings** — switch on Fathom and/or Google Analytics
   and fill in their fields. Saving runs a live connection test and reports the
   result for each service.

Non-superadmin roles only see the widgets if they are granted access to the
Arc Stats add-on (Roles → CP access → Add-ons).

## Widgets

| Widget | Shows | Needs |
|---|---|---|
| Site Traffic · Fathom / · Google Analytics | Visitors, pageviews, sessions (GA4 only), duration, bounce rate (change in points) — last 30 days vs. the 30 before | that provider switched on |
| Top Pages · Fathom / · Google Analytics | Top 10 paths | that provider switched on |
| Top Sources · Fathom / · Google Analytics | Top 10 referrers / session sources | that provider switched on |
| Google Search Console · Search Totals | Clicks, impressions, avg. position vs. the previous 30 days | a Search Console property |
| Google Search Console · Search Queries | Rising and falling queries: the 5 biggest click gains and losses (impressions break ties) | a Search Console property |
| Google Search Console · Index Status | Indexed / not indexed counts for sitemap pages, grouped by Google's reason, plus rich-result issues | a Search Console property **and** the `arc_stats:index` cron job |

Widgets for a service that is switched off are **hidden** from the dashboard, so a
Fathom-only site shows no empty Google boxes. In **Edit Layout** they still appear,
with a note saying why they are empty. Until anything at all is configured, the
two Site Traffic widgets show a "not set up" message instead of hiding.

With both providers on, each gets its own set of widgets. Their numbers are never
merged or put side by side in one widget: GA4 "Users" and Fathom "Visitors" are
counted differently (and GA4 misses visitors who block it or decline consent),
so the labels follow each provider's own vocabulary.

## Fathom

1. app.usefathom.com → Settings → API → create a **read-only** key (scoped to the site is fine).
2. Paste the key and the site ID (shown in the site's settings) into Arc Stats.
3. Optional: **Editor Fathom dashboard link.** The Fathom widgets' "Fathom ↗" button
   opens this link, for everyone, and is hidden when it is blank. Create a shared
   dashboard (the site's settings in Fathom → Sharing) and paste its
   `https://app.usefathom.com/share/…` link. The app link is never used: it only
   works for someone logged in to the owning Fathom account, and no EE role says
   who that is (on a client install the client is a Super Admin without access).

## Google (Analytics 4 and Search Console)

One **service account** covers both. No OAuth consent screen or redirect URI is involved.

1. **console.cloud.google.com** → create (or pick) a project.
2. **APIs & Services → Library** → enable **Google Analytics Data API** and/or
   **Google Search Console API**.
3. **IAM & Admin → Service Accounts → Create**. No roles needed. Open it →
   **Keys → Add key → JSON**. A `.json` file downloads.
4. Copy the service account's email (`…@….iam.gserviceaccount.com`) and grant it read access:
   - **GA4**: Admin → Property access management → **+** → the email, role **Viewer**.
   - **Search Console**: Settings → Users and permissions → Add user → the email, **Restricted**.
5. Paste the JSON file's contents into Arc Stats, plus:
   - the **GA4 property ID**: numeric, from Admin → Property details. *Not* the `G-…` measurement ID.
   - the **Search Console property**: `example.com` for a domain property,
     `https://example.com/` for a URL-prefix property.

The settings page then shows the service account email, as a reminder of which address needs access.

## Index Status

Search Console's API has no endpoint for the Page indexing or Issues reports. The
**URL Inspection API** gives the same verdict one URL at a time, limited to about
2,000 calls a day per property, so it cannot run while the dashboard loads.
Instead a daily command checks pages in batches and the widget reads the results.

```bash
php system/ee/eecli.php arc_stats:index              # read sitemaps, inspect the 200 least-recently-checked pages
php system/ee/eecli.php arc_stats:index --limit=500
php system/ee/eecli.php arc_stats:index --dry-run    # sitemap discovery only; writes nothing
php system/ee/eecli.php arc_stats:index --url=https://example.com/page   # raw Google response for one URL
```

Example cron (daily, 4:10am):

```cron
10 4 * * * cd /path/to/site && php system/ee/eecli.php arc_stats:index >> arc_stats_index.log 2>&1
```

- **Pages checked** are the URLs in the site's sitemaps: those submitted in Search
  Console, any `Sitemap:` line in `robots.txt`, else `/sitemap.xml`. Sitemap indexes
  and `.xml.gz` are followed. URLs outside the Search Console property are skipped.
- New sitemap URLs are checked first; then the oldest check is refreshed. At 200 a
  day, a 1,000-page site is fully re-checked every 5 days.
- A run stops early on a quota error rather than wasting the rest of the batch.
- Only one writing run per site at a time (a MySQL named lock, released even if the
  process dies). A second run, such as a manual one while the cron is going, exits
  with code 4 and does nothing. `--dry-run` and `--url` don't take the lock.
- Exit codes: 0 ok, 1 no property or every inspection failed, 3 stopped on quota,
  4 another run in progress.
- Example pages in the widget link to Google's own inspection page for that URL.
- Results live in `exp_arc_stats_index`. It is rebuilt by the next run if lost.

## Behaviour worth knowing

- **Caching.** Each widget's data is cached for an hour (per site, per settings),
  and failures for 5 minutes, so the dashboard does not call the APIs on every CP
  page view. Saving settings clears the cache.
- **Periods.** Analytics covers the 30 days ending **yesterday** in the site timezone
  (GA4 uses the property's own timezone). Search Console ends **3 days ago**,
  because Google's most recent days are incomplete.
- **Errors are shown, not swallowed.** EE's dashboard silently hides a widget that
  throws, so every API error is caught and displayed in the widget with a link
  to Settings.
- **Secrets** (Fathom key, Google JSON) are stored encrypted with EE's encryption
  key. Changing `encryption_key` makes them unreadable; re-enter them.
- **Who sees what.** Each source (Fathom, Google Analytics, Search Console) has a
  "Who can see the … widgets" role list in Settings. Nothing ticked means no
  restriction; Super Admins always see everything. This only narrows Pro's own
  rule: a non-Super-Admin sees no Arc Stats widget at all unless their role has
  Arc Stats under Add-on access, which also opens this Settings page to them.
  A hidden widget makes no API call.
