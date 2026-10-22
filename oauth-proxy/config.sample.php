<?php
/**
 * Copy to config.php on the proxy server and fill in. Never commit config.php.
 */
return [
	// The public URL of index.php. Register exactly this URL as the redirect URI
	// in both the Google Cloud OAuth client and the Bing Webmaster OAuth client.
	'redirect_uri' => 'https://auth.crawlwp.com/',

	// Long random string used to sign the flow cookie: php -r "echo bin2hex(random_bytes(32));"
	'secret'       => '',

	// Writable folder outside the web root for the five-minute token hand-off files.
	'storage_dir'  => '/var/lib/crawlwp-oauth',

	'google'       => [
		'client_id'     => '',
		'client_secret' => '',
	],

	'bing'         => [
		'client_id'     => '',
		'client_secret' => '',
	],
];
