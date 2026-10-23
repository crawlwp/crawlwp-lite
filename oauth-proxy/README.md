# CrawlWP OAuth proxy

A single PHP file that lets CrawlWP sites "Connect with Google" and "Connect with Bing" without their own API projects. It holds CrawlWP's OAuth client secrets, swaps authorization codes for tokens, and refreshes tokens. It is not shipped in the plugin zip (`plugin-build.php` removes this folder).

## Setup

1. Host `index.php` on its own HTTPS domain (default the plugin uses: `https://auth.crawlwp.com/`; override with the `CRAWLWP_OAUTH_PROXY_URL` constant or the `crawlwp_oauth_proxy_url` filter). PHP 8.0+ with curl.
2. Copy `config.sample.php` to `config.php` and fill it in. `storage_dir` must be writable and outside the web root.
3. Google Cloud: create an OAuth client (Web application), enable the **Google Search Console API**, add the proxy URL as an authorised redirect URI. Scopes requested: `openid email https://www.googleapis.com/auth/webmasters.readonly`. The `webmasters.readonly` scope is sensitive, so the consent screen needs Google verification before public release.
4. Bing Webmaster Tools > Settings > API Access > OAuth client: register the same proxy URL as the redirect URI.

5. Route every path to `index.php` (the included `.htaccess` does this on Apache; on Nginx use `try_files $uri /index.php?$query_string;`). Bing documents its error redirect with an extra path segment.

## Monitoring

Every OAuth-connected site depends on this app: if it is down for more than an hour, their access tokens expire and Google/Bing data stops until it is back (the plugin keeps using a valid token and retries every 5 minutes).

- Point an uptime monitor (UptimeRobot, Better Stack, etc.) at `https://auth.crawlwp.com/?action=health`. It returns 200 with `{"ok":true,...}` when the Google and Bing credentials, signing secret, storage folder and curl are all in place, and 503 naming what is missing.
- Requests are limited per IP address per minute (`rate_limit` in `config.php`, default 120; extra requests get 429). Behind Cloudflare or a load balancer, set `client_ip_header` so the limit applies to visitors, not to the CDN.

## Limits to know

- Google keeps at most 100 refresh tokens per Google account for one OAuth client. An agency connecting more than 100 sites with the same Google account silently loses the oldest connections (those sites must reconnect or use a service account key).
- Disconnecting in WordPress only deletes the site's tokens. It does not revoke them at Google/Bing, because a revoke cancels the account's grant for the whole CrawlWP app and would disconnect every other site using that account.

## Flow

1. WordPress (`admin-post.php?action=crawlwp_oauth_connect`) creates a random state and sends the admin to `?action=authorize`.
2. The proxy stores the flow in a signed cookie (Bing does not document returning `state`) and redirects to Google/Bing.
3. The provider redirects back here with `code`. The proxy exchanges it, saves the tokens in a one-time hand-off file (deleted on first read, expires after 5 minutes) and redirects to the site with only a random handle.
4. The site POSTs the handle and its state to `?action=fetch` (server to server) and stores the tokens.
5. Later, the site POSTs its refresh token to `?action=refresh` for a new access token.

Tokens never appear in a URL, and the proxy keeps nothing after the hand-off.

## Why Google indexing still needs a service account

Indexing API quota (200 publish requests a day by default) belongs to the Google Cloud project. If submissions went through CrawlWP's OAuth app, every CrawlWP site would share one quota. So the OAuth connection is used for Search Console data, and indexing submissions keep using each site's own service account JSON key. Bing has no such limit: its OAuth token is used for URL submission too.
