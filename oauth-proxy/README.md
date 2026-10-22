# CrawlWP OAuth proxy

A single PHP file that lets CrawlWP sites "Connect with Google" and "Connect with Bing" without their own API projects. It holds CrawlWP's OAuth client secrets, swaps authorization codes for tokens, and refreshes tokens. It is not shipped in the plugin zip (`plugin-build.php` removes this folder).

## Setup

1. Host `index.php` on its own HTTPS domain (default the plugin uses: `https://auth.crawlwp.com/`; override with the `CRAWLWP_OAUTH_PROXY_URL` constant or the `crawlwp_oauth_proxy_url` filter). PHP 8.0+ with curl.
2. Copy `config.sample.php` to `config.php` and fill it in. `storage_dir` must be writable and outside the web root.
3. Google Cloud: create an OAuth client (Web application), enable the **Google Search Console API**, add the proxy URL as an authorised redirect URI. Scopes requested: `openid email https://www.googleapis.com/auth/webmasters.readonly`. The `webmasters.readonly` scope is sensitive, so the consent screen needs Google verification before public release.
4. Bing Webmaster Tools > Settings > API Access > OAuth client: register the same proxy URL as the redirect URI.

## Flow

1. WordPress (`admin-post.php?action=crawlwp_oauth_connect`) creates a random state and sends the admin to `?action=authorize`.
2. The proxy stores the flow in a signed cookie (Bing does not document returning `state`) and redirects to Google/Bing.
3. The provider redirects back here with `code`. The proxy exchanges it, saves the tokens in a one-time hand-off file (deleted on first read, expires after 5 minutes) and redirects to the site with only a random handle.
4. The site POSTs the handle and its state to `?action=fetch` (server to server) and stores the tokens.
5. Later, the site POSTs its refresh token to `?action=refresh` for a new access token.

Tokens never appear in a URL, and the proxy keeps nothing after the hand-off.

## Why Google indexing still needs a service account

Indexing API quota (200 publish requests a day by default) belongs to the Google Cloud project. If submissions went through CrawlWP's OAuth app, every CrawlWP site would share one quota. So the OAuth connection is used for Search Console data, and indexing submissions keep using each site's own service account JSON key. Bing has no such limit: its OAuth token is used for URL submission too.
