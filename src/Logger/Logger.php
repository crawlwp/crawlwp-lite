<?php
namespace Mihdan\IndexNow\Logger;

use Mihdan\IndexNow\Dependencies\Psr\Log\AbstractLogger;
use Mihdan\IndexNow\Utils;

class Logger extends AbstractLogger {
	public function get_logger_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'crawlwp_log';
	}

	/**
	 * Whether logging is enabled in the plugin settings (default: enabled).
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return Utils::wposa_get_option( 'enable', 'logs', 'on' ) === 'on';
	}

	public function log( $level, $message, array $context = [] ) {
		global $wpdb;

		if ( ! $this->is_enabled() ) {
			return;
		}

		$defaults = [
			'created_at'    => current_time( 'mysql', 1 ),
			'level'         => $level,
			'message'       => $message,
			'search_engine' => 'site',
			'direction'     => 'outgoing',
			'status_code'   => 200,
		];

		$context = (array) wp_parse_args( $context, $defaults );

		$data = wp_kses_post_deep( $context );

		$wpdb->insert( $this->get_logger_table_name(), $data );
	}
}
