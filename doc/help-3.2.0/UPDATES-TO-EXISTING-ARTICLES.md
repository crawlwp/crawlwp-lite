# Live help articles that this release makes out of date

The writers found these by reading the release code. Nobody has read the live articles to compare yet, because crawlwp.com blocked the request from here. Treat each line as "check and update", not as a confirmed error. Each article is listed once, with every change it needs.

## Indexing

**setting-up-search-engine-indexing-for-wordpress**
- Automatic submissions now go out in the background, shortly after saving. A post saved in the block editor waits for the SEO box, or up to 45 seconds, so a noindex choice is respected.
- A post set to noindex in CrawlWP, or in Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework or Slim SEO, is not submitted.
- Trashing or deleting a post sends Google a removal request, up to 50 a day. None is sent if the address now belongs to another published page.
- The Bing, Google and Yandex section descriptions have new wording.
- There is a new warning when Google is connected but no key is set.
- Link to the new Submit URLs article (submitting-urls-to-search-engines).
- Premium: SEO Indexing now shows a "Why … pages aren't indexed by Google" summary with reason filters. Each **Not Indexed** badge opens a details panel instead of linking to Search Console. Link to why-google-has-not-indexed-a-page.
- Premium: access follows the Role Manager **SEO Indexing** permission.

**reading-the-indexing-submission-log**
- New kinds of entry:
  - "(removed)" items;
  - reasons a submission was not sent ("another published page now uses this address", the daily removal limit);
  - "queue full" and "gave up after repeated failures" messages;
  - Submit URLs results.
- Check whether the **Outgoing requests** switch is already described.

**integrating-wordpress-with-google-search**
- **API Settings » Google API** now starts with a **Google account** row with **Connect with Google**. Link to connect-google-search-console-and-bing.
- The **Service Account JSON Key** is now described as needed only for the Google Indexing API, and for agencies over the 100-connection limit. When it is set, it is used for all Google requests.
- Premium adds a **Google Analytics** block. A service account used for Analytics must be added as a Viewer on the GA4 property.
- The not-indexed reasons and Site Audit's **Not indexed by Google** check rely on the Search Console connection.
- If the article says **Not Indexed** badges link to Search Console, update it: they now open a panel.

**integrating-wordpress-with-bing**
- New **Bing account** row with **Connect with Bing**.
- **API Key** is now "Optional if you connected your Bing account above".

**verify-website-google-search-console**
- Optional: the one-click connection needs a verified property, so link to connect-google-search-console-and-bing.

**fix-cron-events-missing-schedules**
- New scheduled tasks: `crawlwp_background_submission`, `crawlwp_background_submission_recover`, `crawlwp_site_audit_weekly`, `crawlwp_site_audit_step`.
- Automatic submissions and Site Audit now depend on WP-Cron running.

## Search performance

**seo-performance-email-reports**
- Alert emails go to the same **Recipient Email Address** and use the same **Brand Color**.
- The Settings tab now has an **Alerts** section next to **Email Reports**, with its own **Send Test Alert** button. Link to index-and-ranking-alerts.

## Sitemaps

**understanding-crawlwp-sitemaps**
- New **Image sitemap** switch, on by default, and new `wp-sitemap-crawlwpimages-*.xml` files in the sitemap index. Link to image-sitemap.
- The SEO box now warns when a post isn't in the sitemap, or sends mixed signals. Link to post-not-in-sitemap-warning.

## Search appearance and schema

**search-appearance-settings-for-post-types**
- Turning on **Hide from search results** for a post type now shows "All [type] are set to noindex in Title & Meta…" in that type's SEO box.
- Premium: the **Locations** post type probably appears here once multiple locations are on (not confirmed on screen).

**setting-up-organization-vs-person**
- The local business fields moved out of **Site Information** into their own **Local SEO** section. Old values are copied over automatically. Link to setting-up-local-seo.
- The Organization / Person choice also decides whether authors can pick the site's organisation under **Works for**.
- Authors are now full Person entries, separate from the site's own entry. Link to author-profiles-and-author-box.

**setting-up-open-graph-for-facebook**
- Premium's **Automatic Share Images** sits under the social image settings.
- The fallback image is only used when automatic share images are off or can't be made. Link to automatic-share-images.

## Tools

**using-the-bulk-seo-editor**
- New **Image alt text** view with a missing count.
- AI buttons on each field, and **Write with AI** for many rows (Premium).
- New Bulk Editor permission for roles other than administrators. Link to writing-seo-in-bulk-with-ai.

**generating-seo-titles-and-descriptions-with-ai**
- New limits:
  - 20 per user every 5 minutes, shared with the Bulk Editor;
  - a daily site cap of 2,000, or 10,000 with Premium;
  - no user can use more than half the daily cap;
  - daily limits reset at midnight UTC.

**image-seo-and-automatic-alt-text**
- Existing images can now get AI alt text in bulk from the Bulk Editor's **Image alt text** view. JPEG, PNG, GIF and WebP only, up to 4 MB.

## Redirects

**creating-redirect-rule-wordpress**
- Role Manager (Premium) can give the **Redirects and 404s** permission to other roles. Only administrators can save a redirect to another site.
- Premium Site Audit flags redirect chains.

**using-the-404-monitor**
- Same permission note as creating-redirect-rule-wordpress.
- Premium Site Audit reports **404 errors with no redirect**: addresses with 2 or more hits in 30 days.
- Free 3.2.0 no longer logs Site Audit's own checks as 404 visits.

**editing-robots-txt**
- Premium Site Audit checks the robots.txt at the root of the domain for a site-wide "Disallow: /".

## Migrating

**what-gets-imported-and-what-doesnt**
- Add rows for SureRank and Redirection. Link to the two new importer articles.

## Getting started

**uninstalling-crawlwp-and-what-gets-removed**
- With **Remove Data on Uninstall** on, the free plugin's uninstall now also removes:
  - the `crawlwp_analytics`, `crawlwp_alerts` and `crawlwp_content_refresh` options;
  - author profile fields (user meta), "Reviewed by" and rich result data;
  - once Premium is no longer installed, the `uploads/crawlwp-social/` folder and location posts.
- While Premium stays installed, its Role Manager choices, share images and location details are kept.

## Also worth doing after launch

- **Fixed on 18 Nov, before release:**
  - "Learn more" links for Local SEO, Authors, Alerts, Role Manager and Submit URLs (#93).
  - The Site Audit "Site Identity" wording (#13).
  - The importer's untranslatable labels (#99).
  - The rich result currency and length notes (#94).
  - The Bulk Editor **All post types** list for administrators and editors (#97).
  - The share-image help text (#27).
