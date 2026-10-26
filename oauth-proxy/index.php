<?php
/**
 * CrawlWP OAuth proxy.
 *
 * Holds CrawlWP's Google and Bing OAuth client secrets so WordPress sites can connect
 * without creating their own API projects. It never stores tokens beyond a one-time,
 * five-minute hand-off.
 *
 * Routes (all on this one file):
 *   GET  ?action=authorize&provider=google|bing&return_url=…&state=…  Show "Connect <site>?" confirmation.
 *   POST ?action=authorize  (the confirmation form)                       Start the consent flow at Google/Bing.
 *   GET  ?code=…  /  ?error=…                                            Provider callback (the registered redirect URI).
 *   POST ?action=fetch    handle, state                                  Site collects its tokens (server to server).
 *   POST ?action=refresh  provider, refresh_token                        Exchange a refresh token for a new access token.
 *   POST ?action=revoke   provider, refresh_token                        Disconnect: refuse this refresh token from now on.
 *   GET  ?action=health                                                  For uptime monitors: 200 when ready, 503 otherwise.
 */

declare(strict_types=1);

const HANDOFF_TTL = 300;
const FLOW_TTL    = 900;
// __Host- cookies can only be set over HTTPS with Path=/ and no Domain, so neither a sibling
// *.crawlwp.com site nor an injected plain-HTTP response can plant a flow or confirm cookie.
const COOKIE      = '__Host-cwp_oauth_flow';
const CONFIRM     = '__Host-cwp_oauth_confirm';

$providers = [
	'google' => [
		'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
		'token'     => 'https://oauth2.googleapis.com/token',
		'scope'     => 'openid email https://www.googleapis.com/auth/webmasters.readonly',
		'extra'     => ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'],
		// Optional permissions a site can ask for on top of the base scope.
		'features'  => ['analytics' => 'https://www.googleapis.com/auth/analytics.readonly'],
		'sites'     => 'https://www.googleapis.com/webmasters/v3/sites',
		'required'  => 'https://www.googleapis.com/auth/webmasters.readonly',
	],
	'bing'   => [
		'authorize' => 'https://www.bing.com/webmasters/oauth/authorize',
		'token'     => 'https://www.bing.com/webmasters/oauth/token',
		'scope'     => 'webmaster.manage',
		'extra'     => [],
		'sites'     => 'https://www.bing.com/webmaster/api.svc/json/GetUserSites',
		'required'  => 'webmaster.manage',
	],
];

// Tests define CRAWLWP_OAUTH_PROXY_LIB_ONLY to load the functions below without handling a request.
if ( ! defined('CRAWLWP_OAUTH_PROXY_LIB_ONLY')) {
	$config = require __DIR__ . '/config.php';

	// Hand-off files hold tokens: create every file private to this account.
	umask(0077);

	// HTTPS only from the first visit on (also stops cookies being planted over plain HTTP).
	header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

	gc_handoffs($config['storage_dir']);

	$action = $_GET['action'] ?? '';

	if ($action === 'health') {
		health($config);
	}

	// Fail closed: without a strong secret the flow cookies could be forged.
	if (strlen((string)($config['secret'] ?? '')) < 32) {
		fail(503, 'The CrawlWP connection service is not configured.');
	}

	rate_limit($config);

	if ($action === 'authorize') {
		authorize($providers, $config);
	} elseif ($action === 'fetch') {
		fetch_handoff($config);
	} elseif ($action === 'refresh') {
		refresh($providers, $config);
	} elseif ($action === 'revoke') {
		revoke($providers, $config);
	} elseif (isset($_GET['code']) || isset($_GET['error'])) {
		callback($providers, $config);
	} else {
		http_response_code(404);
		exit('Not found');
	}
}

function authorize(array $providers, array $config): void
{
	$post       = $_SERVER['REQUEST_METHOD'] === 'POST';
	$input      = $post ? $_POST : $_GET;
	$provider   = (string)($input['provider'] ?? '');
	$return_url = (string)($input['return_url'] ?? '');
	$site_state = (string)($input['state'] ?? '');
	$features   = requested_features($providers, $provider, (string)($input['features'] ?? ''));

	if ( ! isset($providers[$provider]) || empty($config[$provider]['client_id'])) {
		fail(400, 'Unknown provider.');
	}

	if ( ! is_valid_return_url($return_url)) {
		fail(400, 'Invalid return URL.');
	}

	// The site sends sha256(state) here and the state itself only in the server-to-server fetch,
	// so seeing this URL (or the handle on the way back) isn't enough to collect the tokens.
	if ( ! preg_match('/^[a-f0-9]{64}$/', $site_state)) {
		fail(400, 'Invalid state.');
	}

	// Anyone can build an authorize link pointing at their own site, so the user must confirm which
	// site gets access before we send them to Google/Bing. The confirm cookie is SameSite=Lax, so a
	// cross-site POST can't carry it and the form can't be submitted on the user's behalf.
	if ( ! $post) {
		confirm_page($provider, $return_url, $site_state, $features);
	}

	if ( ! hash_equals((string)($_COOKIE[CONFIRM] ?? ''), (string)($_POST['confirm'] ?? '')) || empty($_POST['confirm'])) {
		fail(400, 'This confirmation has expired. Go back to your WordPress dashboard and click Connect again.');
	}

	$nonce = bin2hex(random_bytes(16));

	setcookie(CONFIRM, '', cookie_options(1));
	setcookie(COOKIE . '_' . $provider, sign(['p' => $provider, 'r' => $return_url, 's' => $site_state, 'n' => $nonce, 't' => time()], $config), cookie_options(time() + FLOW_TTL));

	$p = $providers[$provider];

	redirect($p['authorize'] . '?' . http_build_query([
			'response_type' => 'code',
			'client_id'     => $config[$provider]['client_id'],
			'redirect_uri'  => $config['redirect_uri'],
			'scope'         => scope_for($providers, $provider, $features),
			'state'         => $nonce,
		] + $p['extra']));
}

/**
 * Optional features (e.g. "analytics") the provider knows about, from a comma-separated list. Unknown ones are dropped.
 *
 * @return string[]
 */
function requested_features(array $providers, string $provider, string $features): array
{
	$known = array_keys($providers[$provider]['features'] ?? []);

	return array_values(array_intersect($known, array_map('trim', explode(',', $features))));
}

/**
 * The base scope plus the scopes of the requested features.
 */
function scope_for(array $providers, string $provider, array $features): string
{
	$scopes = [$providers[$provider]['scope']];

	foreach ($features as $feature) {
		$scopes[] = $providers[$provider]['features'][$feature];
	}

	return implode(' ', $scopes);
}

/**
 * "Connect <site> to Google Search Console?" page. Exits.
 */
function confirm_page(string $provider, string $return_url, string $site_state, array $features = []): void
{
	$token = bin2hex(random_bytes(16));
	setcookie(CONFIRM, $token, cookie_options(time() + FLOW_TTL));

	$site    = site_label($return_url);
	$service = $provider === 'google' ? 'Google Search Console' : 'Bing Webmaster Tools';

	if (in_array('analytics', $features, true)) {
		$service .= ' and Google Analytics';
	}
	$e       = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

	http_response_code(200);
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Frame-Options: DENY');
	header("Content-Security-Policy: frame-ancestors 'none'");
	header('Referrer-Policy: no-referrer');

	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>Connect ' . $e($service) . ' – CrawlWP</title><style>'
		. 'body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:48px 16px}'
		. '.box{max-width:480px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:28px}'
		. 'h1{font-size:20px;margin:0 0 12px}.host{display:block;font-size:22px;word-break:break-all}.site{font-weight:600;word-break:break-all;background:#f6f7f7;border-radius:4px;padding:8px 10px;display:block;margin:12px 0}'
		. 'p{margin:0 0 12px}.warn{font-size:14px;color:#50575e}button{font:inherit;background:#2271b1;color:#fff;border:0;border-radius:4px;padding:10px 18px;cursor:pointer}'
		. 'a{color:#2271b1;margin-left:14px}</style></head><body><div class="box">'
		. '<h1>Connect ' . $e($service) . ' to CrawlWP</h1>'
		. '<p>This gives the following WordPress site access to your ' . $e($service) . ' data:</p>'
		. '<span class="site"><span class="host">' . $e(strtolower((string)parse_url($return_url, PHP_URL_HOST))) . '</span>' . $e($site) . '</span>'
		. '<p class="warn">Only continue if this is your site and you just clicked “Connect” in its dashboard. If someone sent you this link, close this page.</p>'
		. '<form method="post" action="?action=authorize">'
		. '<input type="hidden" name="provider" value="' . $e($provider) . '">'
		. '<input type="hidden" name="return_url" value="' . $e($return_url) . '">'
		. '<input type="hidden" name="state" value="' . $e($site_state) . '">'
		. '<input type="hidden" name="features" value="' . $e(implode(',', $features)) . '">'
		. '<input type="hidden" name="confirm" value="' . $e($token) . '">'
		. '<button type="submit">Continue to ' . $e($provider === 'google' ? 'Google' : 'Microsoft') . '</button>'
		. '<a href="' . $e(substr($return_url, 0, (int)strpos($return_url, '/wp-admin/')) . '/wp-admin/') . '">Cancel</a>'
		. '</form></div></body></html>';
	exit;
}

/**
 * The site's address as the user would recognise it: scheme + host (+ port) + any sub-folder WordPress lives in.
 */
function site_label(string $return_url): string
{
	$parts = parse_url($return_url);
	$path  = substr((string)($parts['path'] ?? ''), 0, -strlen('/wp-admin/admin-post.php'));

	return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
}

function cookie_options(int $expires): array
{
	return ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
}

function callback(array $providers, array $config): void
{
	// One cookie per provider so Google and Bing can be connected in parallel tabs. `state` must match
	// the cookie's nonce, so a code from someone else's sign-in can't be slipped into this browser's flow.
	// Microsoft's docs don't mention `state`; if live testing shows Bing drops it, set
	// `bing_state_optional` in config.php (that re-opens the login-CSRF risk, see README).
	$flow  = null;
	$state = isset($_GET['state']) ? (string)$_GET['state'] : null;

	foreach (array_keys($providers) as $name) {
		$candidate = verify((string)($_COOKIE[COOKIE . '_' . $name] ?? ''), $config);

		if ( ! $candidate) {
			continue;
		}

		if ($state !== null ? hash_equals((string)$candidate['n'], $state) : ($name === 'bing' && ! empty($config['bing_state_optional']))) {
			$flow = $candidate;
			break;
		}
	}

	// Microsoft documents its "cancel" redirect as ?error=access_denied without `state`. No tokens are
	// involved, so it is safe to send the user back to the Bing flow in this browser with the error.
	if ( ! $flow && $state === null && isset($_GET['error'])) {
		$flow = verify((string)($_COOKIE[COOKIE . '_bing'] ?? ''), $config);
	}

	if ( ! $flow || time() - (int)$flow['t'] > FLOW_TTL) {
		fail(400, 'This connection request has expired. Go back to your WordPress dashboard and click Connect again.');
	}

	setcookie(COOKIE . '_' . $flow['p'], '', cookie_options(1));

	$back = static function (array $args) use ($flow) {
		redirect($flow['r'] . (str_contains($flow['r'], '?') ? '&' : '?') . http_build_query($args + ['provider' => $flow['p']]));
	};

	if (isset($_GET['error'])) {
		$back(['crawlwp_oauth_error' => preg_replace('/[^a-z_]/', '', strtolower((string)$_GET['error'])) ?: 'unknown']);
	}

	$provider = $flow['p'];
	$tokens   = post_form($providers[$provider]['token'], [
		'grant_type'    => 'authorization_code',
		'code'          => (string)$_GET['code'],
		'client_id'     => $config[$provider]['client_id'],
		'client_secret' => $config[$provider]['client_secret'],
		'redirect_uri'  => $config['redirect_uri'],
	]);

	if (empty($tokens['access_token'])) {
		$back(['crawlwp_oauth_error' => 'token_exchange_failed']);
	}

	// Only hand tokens to a site this account manages in Search Console / Bing Webmaster Tools. Anyone can
	// build an authorize link to their own domain, so without this a phishing link could collect a victim's
	// tokens through CrawlWP's verified app. The tokens are dropped, never written, when the check fails.
	$owned = site_check($providers[$provider], $tokens, (string)parse_url($flow['r'], PHP_URL_HOST), $config);

	if ($owned !== 'ok') {
		$back(['crawlwp_oauth_error' => $owned]);
	}

	$handle = bin2hex(random_bytes(24));

	$written = file_put_contents(handoff_path($config['storage_dir'], $handle), json_encode([
		'state'   => $flow['s'],
		'created' => time(),
		'data'    => [
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'] ?? '',
			'expires_in'    => (int)($tokens['expires_in'] ?? 3600),
			'scope'         => $tokens['scope'] ?? $providers[$provider]['scope'],
			'email'         => isset($tokens['id_token']) ? id_token_email($tokens['id_token']) : '',
		],
	]), LOCK_EX);

	if ($written === false) {
		$back(['crawlwp_oauth_error' => 'server_error']);
	}

	@chmod(handoff_path($config['storage_dir'], $handle), 0600);

	$back(['crawlwp_oauth_handle' => $handle]);
}

function health(array $config): void
{
	$checks = [
		'google'  => ! empty($config['google']['client_id']) && ! empty($config['google']['client_secret']),
		'bing'    => ! empty($config['bing']['client_id']) && ! empty($config['bing']['client_secret']),
		'secret'  => strlen((string)$config['secret']) >= 32,
		'storage' => is_dir($config['storage_dir']) && is_writable($config['storage_dir']),
		'curl'    => function_exists('curl_init'),
	];

	json(in_array(false, $checks, true) ? 503 : 200, ['ok' => ! in_array(false, $checks, true)] + $checks);
}

/**
 * Per-IP requests per minute (config `rate_limit`, default 120). Generous because many sites on one
 * shared host share an IP; it only stops floods. Uses REMOTE_ADDR unless `client_ip_header` names a
 * header set by a trusted CDN in front of the proxy (e.g. HTTP_CF_CONNECTING_IP).
 */
function rate_limit(array $config): void
{
	$limit  = (int)($config['rate_limit'] ?? 120);
	$header = (string)($config['client_ip_header'] ?? '');
	$ip     = (string)(($header !== '' ? $_SERVER[$header] ?? '' : '') ?: ($_SERVER['REMOTE_ADDR'] ?? ''));

	if ($limit <= 0 || $ip === '') {
		return;
	}

	$file = rtrim($config['storage_dir'], '/') . '/rl-' . sha1($ip) . '-' . intdiv(time(), 60) . '.cnt';
	$fh   = fopen($file, 'c+');

	if ( ! $fh) {
		return;
	}

	flock($fh, LOCK_EX);
	$count = (int)stream_get_contents($fh) + 1;
	ftruncate($fh, 0);
	rewind($fh);
	fwrite($fh, (string)$count);
	flock($fh, LOCK_UN);
	fclose($fh);

	if ($count > $limit) {
		header('Retry-After: 60');
		json(429, ['error' => 'rate_limited']);
	}
}

function fetch_handoff(array $config): void
{
	$handle = (string)($_POST['handle'] ?? '');
	$state  = (string)($_POST['state'] ?? '');

	if ( ! preg_match('/^[a-f0-9]{48}$/', $handle)) {
		json(400, ['error' => 'invalid_handle']);
	}

	$path    = handoff_path($config['storage_dir'], $handle);
	$handoff = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;

	@unlink($path); // One attempt only.

	if ( ! $handoff || time() - (int)$handoff['created'] > HANDOFF_TTL || ! hash_equals((string)$handoff['state'], hash('sha256', $state))) {
		json(400, ['error' => 'invalid_handle']);
	}

	json(200, $handoff['data']);
}

function refresh(array $providers, array $config): void
{
	$provider = (string)($_POST['provider'] ?? '');
	$token    = (string)($_POST['refresh_token'] ?? '');

	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($providers[$provider]) || $token === '') {
		json(400, ['error' => 'invalid_request']);
	}

	if (is_revoked($config, $provider, $token)) {
		json(400, ['error' => 'invalid_grant']);
	}

	$tokens = post_form($providers[$provider]['token'], [
		'grant_type'    => 'refresh_token',
		'refresh_token' => $token,
		'client_id'     => $config[$provider]['client_id'],
		'client_secret' => $config[$provider]['client_secret'],
	]);

	if (empty($tokens['access_token'])) {
		// invalid_grant means the user revoked access: the site should disconnect.
		json(400, ['error' => (string)($tokens['error'] ?? 'refresh_failed')]);
	}

	json(200, [
		'access_token'  => $tokens['access_token'],
		'expires_in'    => (int)($tokens['expires_in'] ?? 3600),
		'refresh_token' => $tokens['refresh_token'] ?? '',
	]);
}

/**
 * Disconnect: remember the refresh token as revoked so the proxy never refreshes it again, even
 * if a copy survives in an old database backup. Not revoked at Google/Bing, because that cancels
 * the user's grant for every site connected with the same account.
 */
function revoke(array $providers, array $config): void
{
	$provider = (string)($_POST['provider'] ?? '');
	$token    = (string)($_POST['refresh_token'] ?? '');

	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($providers[$provider]) || $token === '' || strlen($token) > 2048) {
		json(400, ['error' => 'invalid_request']);
	}

	if (is_revoked($config, $provider, $token)) {
		json(200, ['revoked' => true]);
	}

	// Only remember tokens the provider still accepts. A made-up or already dead token needs no entry, so
	// unauthenticated callers can't fill the disk with deny-list files.
	$tokens = post_form($providers[$provider]['token'], [
		'grant_type'    => 'refresh_token',
		'refresh_token' => $token,
		'client_id'     => $config[$provider]['client_id'],
		'client_secret' => $config[$provider]['client_secret'],
	]);

	if ( ! empty($tokens['access_token']) && file_put_contents(revoked_path($config, $provider, $token), (string)time(), LOCK_EX) === false) {
		json(500, ['error' => 'storage_failed']);
	}

	json(200, ['revoked' => true]);
}

function is_revoked(array $config, string $provider, string $token): bool
{
	return is_file(revoked_path($config, $provider, $token));
}

/**
 * Only a keyed hash of the token is kept, so the deny list itself leaks nothing.
 */
function revoked_path(array $config, string $provider, string $token): string
{
	return rtrim($config['storage_dir'], '/') . '/revoked-' . hash_hmac('sha256', $provider . '|' . $token, (string)$config['secret']) . '.txt';
}

/* ---------- site ownership ---------- */

/**
 * Whether the signed-in account manages the site the tokens are for.
 *
 * @return string 'ok', or an error code for the site: no_site_access, site_check_failed, missing_scope.
 */
function site_check(array $provider, array $tokens, string $host, array $config): string
{
	// Kill switch for emergencies only (e.g. the provider's site list API is down for everyone).
	if (array_key_exists('verify_site_ownership', $config) && ! $config['verify_site_ownership']) {
		return 'ok';
	}

	// Without the Search Console / Webmaster scope the site list can't be read, and the connection would be useless.
	// Bing may leave `scope` out of its token response; then the site list call below decides.
	if (isset($tokens['scope']) && ! str_contains((string)$tokens['scope'], $provider['required'])) {
		return 'missing_scope';
	}

	$list = get_json($provider['sites'], (string)$tokens['access_token']);

	if ($list === null) {
		return 'site_check_failed';
	}

	return site_matches(site_urls($list), $host) ? 'ok' : 'no_site_access';
}

/**
 * Site addresses from a Google `sites.list` or Bing `GetUserSites` response. Unverified entries are left out.
 *
 * @return string[]
 */
function site_urls(array $list): array
{
	$urls = [];

	// Google: {"siteEntry":[{"siteUrl":"sc-domain:example.com","permissionLevel":"siteOwner"}]}
	foreach ((array)($list['siteEntry'] ?? []) as $site) {
		if (is_array($site) && is_string($site['siteUrl'] ?? null) && ($site['permissionLevel'] ?? '') !== 'siteUnverifiedUser') {
			$urls[] = $site['siteUrl'];
		}
	}

	// Bing: {"d":[{"Url":"https://example.com/","IsVerified":true}]}
	foreach ((array)($list['d'] ?? []) as $site) {
		if (is_array($site) && is_string($site['Url'] ?? null) && ($site['IsVerified'] ?? true) !== false) {
			$urls[] = $site['Url'];
		}
	}

	return $urls;
}

/**
 * Whether one of the account's properties covers $host: a domain property (sc-domain:example.com) for the
 * host or a parent domain, or a URL property on the same host. "www." is ignored on both sides; ports aren't
 * compared (whoever owns the host owns every port on it).
 */
function site_matches(array $site_urls, string $host): bool
{
	$bare = static fn(string $h): string => (string)preg_replace('/^www\./', '', rtrim(strtolower(trim($h)), '.'));
	$host = $bare($host);

	if ($host === '') {
		return false;
	}

	foreach ($site_urls as $url) {
		$url = trim((string)$url);

		if (stripos($url, 'sc-domain:') === 0) {
			$domain = $bare(substr($url, 10));

			if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
				return true;
			}

			continue;
		}

		$property = parse_url($url, PHP_URL_HOST);

		if (is_string($property) && $property !== '' && $bare($property) === $host) {
			return true;
		}
	}

	return false;
}

/* ---------- helpers ---------- */

function is_valid_return_url(string $url): bool
{
	$parts = parse_url($url);

	return $parts
		&& in_array($parts['scheme'] ?? '', ['http', 'https'], true)
		&& ! empty($parts['host'])
		// letters, digits, dots and hyphens only (IDNs arrive as punycode): no "\" or other tricks that
		// make the shown name differ from where the browser goes.
		&& preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $parts['host'])
		&& ! isset($parts['user'])
		&& ! isset($parts['pass'])
		&& str_starts_with($parts['path'] ?? '', '/')
		&& ! preg_match('#//|\\\\|:#', $parts['path'] ?? '')
		&& str_ends_with($parts['path'] ?? '', '/wp-admin/admin-post.php');
}

function post_form(string $url, array $fields): array
{
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => http_build_query($fields),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_HTTPHEADER     => ['Accept: application/json'],
	]);
	$body = curl_exec($ch);
	curl_close($ch);

	return is_string($body) ? (array)json_decode($body, true) : [];
}

/**
 * GET a JSON API with a bearer token. Null on any failure (network, non-200, not JSON).
 */
function get_json(string $url, string $access_token): ?array
{
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $access_token],
	]);
	$body = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);

	$data = is_string($body) ? json_decode($body, true) : null;

	return $code === 200 && is_array($data) ? $data : null;
}

function id_token_email(string $jwt): string
{
	// Received directly from Google over TLS, so the payload is read without verifying the signature.
	$payload = json_decode((string)base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);

	return (string)($payload['email'] ?? '');
}

function sign(array $data, array $config): string
{
	$body = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

	return $body . '.' . hash_hmac('sha256', $body, $config['secret']);
}

function verify(string $value, array $config): ?array
{
	[$body, $mac] = array_pad(explode('.', $value, 2), 2, '');

	if ($body === '' || ! hash_equals(hash_hmac('sha256', $body, $config['secret']), $mac)) {
		return null;
	}

	return json_decode((string)base64_decode(strtr($body, '-_', '+/')), true) ?: null;
}

function handoff_path(string $dir, string $handle): string
{
	return rtrim($dir, '/') . '/' . $handle . '.json';
}

function gc_handoffs(string $dir): void
{
	foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
		if (filemtime($file) < time() - HANDOFF_TTL) {
			@unlink($file);
		}
	}

	// Rate-limit counters are per minute: anything older than two minutes is finished.
	foreach (glob(rtrim($dir, '/') . '/rl-*.cnt') ?: [] as $file) {
		if (filemtime($file) < time() - 120) {
			@unlink($file);
		}
	}
}

function redirect(string $url): void
{
	header('Cache-Control: no-store');
	header('Location: ' . $url, true, 302);
	exit;
}

function json(int $status, array $data): void
{
	http_response_code($status);
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode($data);
	exit;
}

function fail(int $status, string $message): void
{
	http_response_code($status);
	header('Content-Type: text/plain; charset=utf-8');
	exit($message);
}
