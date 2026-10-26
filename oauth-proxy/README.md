# CrawlWP OAuth proxy

A single PHP file that lets CrawlWP sites "Connect with Google" and "Connect with Bing" without their own API projects. It holds CrawlWP's OAuth client secrets, swaps authorization codes for tokens, and refreshes tokens. It is not shipped in the plugin zip (`plugin-build.php` removes this folder).

## Setup

1. Host `index.php` on its own HTTPS domain (default the plugin uses: `https://auth.crawlwp.com/`; override with the `CRAWLWP_OAUTH_PROXY_URL` constant or the `crawlwp_oauth_proxy_url` filter). PHP 8.0+ with curl.
2. Copy `config.sample.php` to `config.php` and fill it in. The proxy refuses to run (503) until `secret` is at least 32 characters. `storage_dir` must be writable and outside the web root.
3. Google Cloud: create an OAuth client (Web application), enable the **Google Search Console API**, add the proxy URL as an authorised redirect URI. Scopes requested: `openid email https://www.googleapis.com/auth/webmasters.readonly`, plus `https://www.googleapis.com/auth/analytics.readonly` only when the site turns on Google Analytics (CrawlWP Premium). Add both to the consent screen and enable the Google Analytics Data API and Google Analytics Admin API in the same Cloud project. Both scopes are sensitive, so the consent screen needs Google verification before public release.
4. Bing Webmaster Tools > Settings > API Access > OAuth client: register the same proxy URL as the redirect URI.

5. Route every path to `index.php` (the included `.htaccess` does this on Apache; on Nginx use `try_files $uri /index.php?$query_string;`). Bing documents its error redirect with an extra path segment.

## Monitoring

Every OAuth-connected site depends on this app: if it is down for more than an hour, their access tokens expire and Google/Bing data stops until it is back (the plugin keeps using a valid token and retries every 5 minutes).

- Point an uptime monitor (UptimeRobot, Better Stack, etc.) at `https://auth.crawlwp.com/?action=health`. It returns 200 with `{"ok":true,...}` when the Google and Bing credentials, signing secret, storage folder and curl are all in place, and 503 naming what is missing.
- Requests are limited per IP address per minute (`rate_limit` in `config.php`, default 120; extra requests get 429). Behind Cloudflare or a load balancer, set `client_ip_header` so the limit applies to visitors, not to the CDN.

## Security notes

- **HTTPS only.** The proxy sends `Strict-Transport-Security`. Add `auth.crawlwp.com` to the HSTS preload list once it's live, and never serve it over plain HTTP. Its flow cookies use the `__Host-` prefix, so other `*.crawlwp.com` sites and plain-HTTP responses can't set them. Hosting it on its own domain is better still.
- **`state` never travels in clear.** Sites send `sha256(state)` in the authorize link and the state itself only in the server-to-server fetch, so seeing the URLs isn't enough to collect tokens.
- **Tokens only go to sites the account manages.** After Google/Bing sign-in, the proxy reads the account's site list (Search Console `sites.list`, Bing `GetUserSites`) and only hands the tokens over when the WordPress site's host is there: a domain property (`sc-domain:example.com`, which also covers subdomains) or a URL property on the same host, ignoring `www.`. Otherwise the tokens are dropped and the site shows "This Google account has no Search Console property for example.com". This stops a phishing link (an authorize link pointing at the attacker's domain) collecting a victim's tokens through CrawlWP's verified app. If the site list can't be read, the connection fails with a "try again" message. So **users must add and verify their site in Search Console / Bing Webmaster Tools before connecting**. `verify_site_ownership => false` in `config.php` turns the check off (emergencies only).
- **Disconnect revokes at the proxy.** Sites call `?action=revoke` with their refresh token; the proxy stores a keyed hash of it (`revoked-*.txt` in `storage_dir`, keep these files) and refuses to refresh it again. Only tokens the provider still accepts are recorded (the proxy checks with one refresh first), so made-up tokens can't fill the disk. Tokens aren't revoked at Google/Bing, because that would disconnect every site using the same account.
- Files in `storage_dir` are created private to the proxy's account (`umask 0077`). Keep `storage_dir` outside the web root.

## Limits to know

- Google keeps at most 100 refresh tokens per Google account for one OAuth client, and every connect (including a reconnect) issues a new one. An agency connecting more than 100 sites with the same Google account silently loses the oldest connections. Those sites show "your Google connection has stopped working", which names this limit as a possible cause; they must reconnect or use a service account key. Service accounts are exempt from the limit, so the plugin points agencies to them on the API Settings screen and in the "stopped working" notice. Disconnecting can't free a slot: revoking a token at Google cancels the user's whole grant for the CrawlWP app, which would disconnect every other site connected with that account, so the plugin deliberately doesn't revoke. Unused tokens expire after six months.
- While the Google consent screen is in **Testing** mode, Google expires refresh tokens after 7 days: test sites will see the "connection stopped working" notice weekly until the app is published and verified.
- Disconnecting in WordPress only deletes the site's tokens. It does not revoke them at Google/Bing, because a revoke cancels the account's grant for the whole CrawlWP app and would disconnect every other site using that account.

## Things to confirm during live testing

- **Bing's "cancel" redirect.** Microsoft documents it as `?error=access_denied` without `state`; the proxy then sends the user back using the Bing flow cookie in that browser. Check that cancelling on Bing returns to WordPress with "Bing connection was cancelled".
- **The site check works for both providers** with a domain property, a URL-prefix property and a `www.` site, and refuses a site the account doesn't have.
- **Bing returns `state` on its callback.** The proxy requires it (it stops someone slipping their own sign-in into a user's connect flow). Microsoft's docs don't mention `state`; if Bing drops it, connecting Bing fails with "connection request has expired", and you can set `bing_state_optional` in `config.php` (this re-opens that risk).
- **Bing's token response includes the granted scope**, or none at all. The plugin rejects a connection whose scope lacks `webmaster.manage` (Google: `webmasters.readonly`), so a user who unticks Search Console access gets a clear error instead of a connection that silently fails.
- **Bing's refresh request** works with `grant_type=refresh_token` (Microsoft's docs contradict themselves).

## Flow

1. WordPress (`admin-post.php?action=crawlwp_oauth_connect`) creates a random state and sends the admin to `?action=authorize`.
2. The proxy shows a confirmation page naming the WordPress site that will get access. Anyone can build an authorize link pointing at their own site, so this stops a phishing link from silently sending a victim's tokens elsewhere. The form only works with a same-site cookie, so it can't be submitted from another site, and the page can't be framed.
3. On confirm, the proxy stores the flow in a signed cookie (Bing does not document returning `state`) and redirects to Google/Bing.
4. The provider redirects back here with `code`. The proxy exchanges it, saves the tokens in a one-time hand-off file (deleted on first read, expires after 5 minutes) and redirects to the site with only a random handle.
5. The site POSTs the handle and its state to `?action=fetch` (server to server) and stores the tokens.
6. Later, the site POSTs its refresh token to `?action=refresh` for a new access token, and to `?action=revoke` when the admin clicks Disconnect.

Tokens never appear in a URL, and the proxy keeps nothing after the hand-off.

## Why Google indexing still needs a service account

Indexing API quota (200 publish requests a day by default) belongs to the Google Cloud project. If submissions went through CrawlWP's OAuth app, every CrawlWP site would share one quota. So the OAuth connection is used for Search Console data, and indexing submissions keep using each site's own service account JSON key. Bing has no such limit: its OAuth token is used for URL submission too.
