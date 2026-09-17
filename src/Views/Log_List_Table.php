<?php
/**
 * Log class.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\Views;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\Utils;
use WP_List_Table;

class Log_List_Table extends WP_List_Table
{
	/**
	 * Logger instance.
	 *
	 * @var Logger $logger
	 */
	private $logger;

	/**
	 * WP_OSA instance.
	 *
	 * @var WPOSA $wposa
	 */
	public $wposa;

	/**
	 * Log constructor.
	 *
	 * @param Logger $logger Logger instance.
	 * @param WPOSA $wposa WPOSA instance.
	 */
	public function __construct(Logger $logger, WPOSA $wposa)
	{
		$this->logger = $logger;
		$this->wposa  = $wposa;

		parent::__construct(array(
			'singular' => 'log',
			'plural'   => 'logs',
			'ajax'     => false,
		));

		$this->bulk_action_handler();

		// screen option
		add_screen_option('per_page', array(
			'label'   => esc_html__('Log', 'mihdan-index-now'),
			'default' => 20,
			'option'  => 'logs_per_page',
		));

		$this->prepare_items();

		add_action('wp_print_scripts', [__CLASS__, '_list_table_css']);
	}

	/**
	 * Get total items.
	 *
	 * @return int
	 */
	private function get_total_items()
	{
		global $wpdb;

		$table_name = $this->logger->get_logger_table_name();

		return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore
	}

	/**
	 * Get items.
	 *
	 * @param $per_page
	 * @param $cur_page
	 * @param $order_by
	 * @param $order
	 *
	 * @return array|object|null
	 */
	private function get_items($per_page, $cur_page, $order_by, $order)
	{
		global $wpdb;

		$table_name = $this->logger->get_logger_table_name();

		$order_by = sanitize_sql_orderby(" {$order_by} {$order} ");

		$from = ($cur_page - 1) * $per_page;

		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM {$table_name} ORDER BY {$order_by} LIMIT %d, %d",
				$from,
				$per_page
			)
		);
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items()
	{
		$per_page_option = get_current_screen()->get_option('per_page');
		$per_page        = (int)get_user_meta(get_current_user_id(), $per_page_option['option'], true) ?: $per_page_option['default'];

		$this->set_pagination_args(
			[
				'total_items' => $this->get_total_items(),
				'per_page'    => $per_page,
			]
		);

		$cur_page = (int)$this->get_pagenum();

		$orderby = 'created_at';
		$order   = 'DESC';

		$this->items = $this->get_items($per_page, $cur_page, $orderby, $order);
	}

	/**
	 * Get table columns.
	 *
	 * @return string[]
	 */
	public function get_columns()
	{
		return [
			'cb'            => '<input type="checkbox" />',
			'log_id'        => 'ID',
			'search_engine' => 'SE',
			'direction'     => 'Dir',
			'level'         => 'Level',
			'status_code'   => 'Status',
			'message'       => 'Message',
			'created_at'    => 'Date',
		];
	}

	// сортируемые колонки
	function get_sortable_columns()
	{
		return [
			'status_code' => ['status_code', 'asc'],
		];
	}

	protected function get_bulk_actions()
	{
		return [
			'delete' => 'Delete',
		];
	}

	static function _list_table_css()
	{
		?>
		<style>
			table.logs .column-log_id {
				width: 3em;
			}

			table.logs .column-level {
				width: 4em;
			}

			table.logs .column-direction {
				width: 4em;
			}

			table.logs .column-search_engine {
				width: 8.5em;
			}

			table.logs .column-status_code {
				width: 6em;
			}

			table.logs .column-created_at {
				width: 10em;
			}

			table.logs span.level {
				border-radius: 50%;
				width: 6px;
				height: 6px;
				color: transparent;
				display: inline-block;
				vertical-align: middle;
			}

			table.logs span.level--error {
				background-color: #f00;
			}

			table.logs span.level--info,
			table.logs span.level--debug {
				background-color: #0f0;
			}
		</style>
		<?php
	}

	// вывод каждой ячейки таблицы...
	function column_default($item, $colname)
	{
		if ($colname === 'post_id') {
			if ($item->$colname === '0') {
				return '-';
			}

			return sprintf(
				'%d: <a href="%s" target="_blank">%s</a>',
				$item->$colname,
				Utils::normalized_get_permalink($item->$colname),
				get_the_title($item->$colname));
		} elseif ($colname === 'level') {
			return sprintf('<span class="level level--%s" title="%s">.</span>', $item->$colname, $item->$colname);
		} elseif ($colname === 'direction') {
			switch ($item->$colname) {
				case 'outgoing' :
					$data = '<span class="dashicons dashicons-arrow-up-alt" title="Outgoing request"></span>';
					break;

				case 'incoming' :
					$data = '<span class="dashicons dashicons-arrow-down-alt" title="Incoming request"></span>';
					break;

				default :
					$data = '<span class="dashicons dashicons-marker" title="Internal request"></span>';
			}

			return $data;
		} elseif ($colname === 'created_at') {
			return get_date_from_gmt($item->$colname, 'd.m.Y H:i:s');
		} elseif ($colname === 'message') {
			return wp_kses_post( $item->message ?? '');
		} else {
			return isset($item->$colname) ? esc_html($item->$colname) : '';
		}

	}


	function column_cb($item)
	{
		echo '<input type="checkbox" name="log_rows[]" id="cb-select-' . esc_attr($item->log_id) . '" value="' . esc_attr($item->log_id) . '" />';
	}

	/**
	 * Bulk action handler for custom table.
	 */
	private function bulk_action_handler()
	{
		if (isset($_POST['log_rows']) && is_array($_POST['log_rows']) && 'delete' === $this->current_action()) {

			global $wpdb;

			// Bail if user is not an admin or without admin privileges.
			if ( ! current_user_can('manage_options')) return;

			check_admin_referer('bulk-' . $this->_args['plural']);

			$log_rows   = array_map('absint', $_POST['log_rows']);
			$table_name = $this->logger->get_logger_table_name();

			$how_many     = count($log_rows);
			$placeholders = array_fill(0, $how_many, '%d');
			$format       = implode(', ', $placeholders);

			$query = "DELETE FROM {$table_name} WHERE log_id IN ({$format})";

			$wpdb->query(
				$wpdb->prepare($query, $log_rows)
			);
		}
	}
}
