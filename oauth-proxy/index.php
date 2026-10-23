<?php
/**
 * CrawlWP OAuth proxy.
 *
 * Holds CrawlWP's Google and Bing OAuth client secrets so WordPress sites can connect
 * without creating their own API projects. It never stores tokens beyond a one-time,
 * five-minute hand-off.
 *
 * Routes (all on this one file):
 *   GET  ?action=authorize&provider=google|bing&return_url=…&state=…  Start the consent flow.
 *   GET  ?code=…  /  ?error=…                                            Provider callback (the registered redirect URI).
 *   POST ?action=fetch    handle, state                                  Site collects its tokens (server to server).
 *   POST ?action=refresh  provider, refresh_token                        Exchange a refresh token for a new access token.
 *   GET  ?action=health                                                  For uptime monitors: 200 when ready, 503 otherwise.
 */

declare(strict_types=1);

const HANDOFF_TTL = 300;
const FLOW_TTL    = 900;
const COOKIE      = 'cwp_oauth_flow';

$providers = [
	'google' => [
		'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
		'token'     => 'https://oauth2.googleapis.com/token',
		'scope'     => 'openid email https://www.googleapis.com/auth/webmasters.readonly',
		'extra'     => ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'],
	],
	'bing'   => [
		'authorize' => 'https://www.bing.com/webmasters/oauth/authorize',
		'token'     => 'https://www.bing.com/webmasters/oauth/token',
		'scope'     => 'webmaster.manage',
		'extra'     => [],
	],
];

// Tests define CRAWLWP_OAUTH_PROXY_LIB_ONLY to load the functions below without handling a request.
if ( ! defined('CRAWLWP_OAUTH_PROXY_LIB_ONLY')) {
	$config = require __DIR__ . '/config.php';

	gc_handoffs($config['storage_dir']);

	$action = $_GET['action'] ?? '';

	if ($action === 'health') {
		health($config);
	}

	rate_limit($config);

	if ($action === 'authorize') {
		authorize($providers, $config);
	} elseif ($action === 'fetch') {
		fetch_handoff($config);
	} elseif ($action === 'refresh') {
		refresh($providers, $config);
	} elseif (isset($_GET['code']) || isset($_GET['error'])) {
		callback($providers, $config);
	} else {
		http_response_code(404);
		exit('Not found');
	}
}

function authorize(array $providers, array $config): void
{
	$provider   = (string)($_GET['provider'] ?? '');
	$return_url = (string)($_GET['return_url'] ?? '');
	$site_state = (string)($_GET['state'] ?? '');

	if ( ! isset($providers[$provider]) || empty($config[$provider]['client_id'])) {
		fail(400, 'Unknown provider.');
	}

	if ( ! is_valid_return_url($return_url)) {
		fail(400, 'Invalid return URL.');
	}

	if ( ! preg_match('/^[A-Za-z0-9]{16,64}$/', $site_state)) {
		fail(400, 'Invalid state.');
	}

	$nonce = bin2hex(random_bytes(16));

	// Bing does not document echoing `state`, so the flow is tied to the browser with a cookie instead.
	setcookie(COOKIE . '_' . $provider, sign(['p' => $provider, 'r' => $return_url, 's' => $site_state, 'n' => $nonce, 't' => time()], $config), [
		'expires'  => time() + FLOW_TTL,
		'path'     => '/',
		'secure'   => true,
		'httponly' => true,
		'samesite' => 'Lax',
	]);

	$p = $providers[$provider];

	redirect($p['authorize'] . '?' . http_build_query([
			'response_type' => 'code',
			'client_id'     => $config[$provider]['client_id'],
			'redirect_uri'  => $config['redirect_uri'],
			'scope'         => $p['scope'],
			'state'         => $nonce,
		] + $p['extra']));
}

function callback(array $providers, array $config): void
{
	// One cookie per provider so Google and Bing can be connected in parallel tabs.
	// Google always returns `state`; a callback without it can only be Bing's.
	$flow = null;

	foreach (array_keys($providers) as $name) {
		$candidate = verify((string)($_COOKIE[COOKIE . '_' . $name] ?? ''), $config);

		if ($candidate && (isset($_GET['state']) ? hash_equals($candidate['n'], (string)$_GET['state']) : $name === 'bing')) {
			$flow = $candidate;
			break;
		}
	}

	if ( ! $flow || time() - (int)$flow['t'] > FLOW_TTL) {
		fail(400, 'This connection request has expired. Go back to your WordPress dashboard and click Connect again.');
	}

	setcookie(COOKIE . '_' . $flow['p'], '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);

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

	$handle = bin2hex(random_bytes(24));

	file_put_contents(handoff_path($config['storage_dir'], $handle), json_encode([
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

	if ( ! $handoff || time() - (int)$handoff['created'] > HANDOFF_TTL || ! hash_equals((string)$handoff['state'], $state)) {
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

/* ---------- helpers ---------- */

function is_valid_return_url(string $url): bool
{
	$parts = parse_url($url);

	return $parts
		&& in_array($parts['scheme'] ?? '', ['http', 'https'], true)
		&& ! empty($parts['host'])
		&& ! isset($parts['user'])
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
