<?php
/**
 * Main Class file for `WP_OSA`
 *
 * Main class that deals with all other classes.
 *
 * @since   1.0.0
 * @package WPOSA
 */

namespace Mihdan\IndexNow\Views;

use Mihdan\IndexNow\Utils;

/**
 * WP_OSA.
 *
 * WP Settings API Class.
 *
 * @since 1.0.0
 */
class WPOSA
{

	/**
	 * Allowed HTML tags and attributes for wp_kses().
	 */
	private const ALLOWED_HTML = [
		'strong'   => [],
		'b'        => [],
		'i'        => [],
		'code'     => [],
		'ul'       => [
			'class' => true,
		],
		'ol'       => [],
		'li'       => [
			'class' => true,
		],
		'br'       => [
			'class' => true,
		],
		'fields'   => [],
		'label'    => [
			'for' => true,
		],
		'select'   => [
			'class' => true,
			'name'  => true,
			'id'    => true,
		],
		'option'   => [
			'value'    => true,
			'selected' => true,
		],
		'div'      => [
			'id'         => true,
			'style'      => true,
			'class'      => true,
			'data-w'     => true,
			'data-group' => true,
			'data-*'     => true,
		],
		'button'   => [
			'id'            => true,
			'class'         => true,
			'type'          => true,
			'style'         => true,
			'title'         => true,
			'aria-expanded' => true,
			'aria-controls' => true,
			'aria-label'    => true,
			'data-group'    => true,
			'data-*'        => true,
		],
		'a'        => [
			'id'      => true,
			'class'   => true,
			'href'    => true,
			'style'   => true,
			'title'   => true,
			'target'  => true,
		],
		'img'      => [
			'src'    => true,
			'width'  => true,
			'height' => true,
		],
		'p'        => [
			'class' => true,
		],
		'h1'       => [
			'class' => true,
		],
		'h2'       => [
			'class' => true,
		],
		'nav'      => [
			'class'      => true,
			'aria-label' => true,
		],
		'span'     => [
			'id'          => true,
			'class'       => true,
			'style'       => true,
			'aria-hidden' => true,
			'data-*'      => true,
		],
		'table'    => [
			'class' => true,
		],
		'thead'    => [
			'class' => true,
		],
		'tfoot'    => [
			'class' => true,
		],
		'tbody'    => [
			'class' => true,
		],
		'tr'       => [
			'class' => true,
		],
		'th'       => [
			'class' => true,
		],
		'td'       => [
			'class' => true,
		],
		'textarea' => [
			'name'            => true,
			'class'           => true,
			'id'              => true,
			'rows'            => true,
			'cols'            => true,
			'style'           => true,
			'placeholder'     => true,
			'readonly'        => true,
			'disabled'        => true,
			'data-cwp-tm'     => true,
			'data-cwp-entity' => true,
			'data-*'          => true,
		],
		'input'    => [
			'id'                 => true,
			'class'              => true,
			'type'               => true,
			'name'               => true,
			'value'              => true,
			'placeholder'        => true,
			'checked'            => true,
			'readonly'           => true,
			'onclick'            => true,
			'disabled'           => true,
			'style'              => true,
			'data-default-color' => true,
			'data-cwp-tm'        => true,
			'data-cwp-entity'    => true,
			'data-*'             => true,
		],
		'script'   => [
			'src'   => true,
			'async' => true,
		],
		'svg'      => array(
			'class'       => true,
			'xmlns'       => true,
			'width'       => true,
			'height'      => true,
			'viewBox'     => true,
			'viewbox'     => true,
			'fill'        => true,
			'stroke'      => true,
			'aria-hidden' => true,
			'focusable'   => true,
		),
		'g'        => array(
			'clip-path' => true,
		),
		'path'     => array(
			'd'               => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'fill-rule'       => true,
			'clip-rule'       => true,
		),
		'defs'     => true,
		'clipPath' => array(
			'id' => true,
		),
		'rect'     => array(
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'fill'   => true,
		),
	];

	/**
	 * Plugin name.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	private $sub_menu_slug;

	/**
	 * Plugin prefix.
	 *
	 * @var string
	 */
	private $plugin_prefix;

	/**
	 * Sections array.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private $sections_array = array();

	/**
	 * Sections array.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private $header_menu_array = array();

	/**
	 * Fields array.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private $fields_array = array();

	/**
	 * Sidebar card array.
	 *
	 * @var array $sidebar_cards
	 */
	private $sidebar_cards = [];

	/**
	 * Submenu page title.
	 *
	 * @var string
	 */
	public $sub_page_title;

	private $enable_blank_mode = false;

	/**
	 * Per-request option cache. Keyed by the prefixed section/option name.
	 *
	 * @var array
	 */
	private $option_cache = [];

	/**
	 * Flat sanitize-callback index built lazily on first sanitize call.
	 * Keyed by field id, value is the callable (or false).
	 *
	 * @var array|null
	 */
	private $sanitize_index = null;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name Plugin name.
	 * @param string $plugin_version Pluign version.
	 * @param string $plugin_slug Plugin slug.
	 *
	 * @since  1.0.0
	 */
	public function __construct(string $plugin_name = 'WPOSA', string $plugin_version = '0.1', string $plugin_slug = 'WPOSA', string $plugin_prefix = 'WPOSA', $sub_menu_slug = '', $sub_page_title = '')
	{
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;
		$this->plugin_slug    = $plugin_slug;
		$this->plugin_prefix  = $plugin_prefix;
		$this->sub_page_title = $sub_page_title;
		$this->sub_menu_slug  = $sub_menu_slug;
	}

	public function enable_blank_mode()
	{
		$this->enable_blank_mode = true;

		return $this;
	}

	public function get_prefix(): string
	{
		return $this->plugin_prefix;
	}

	public function setup_hooks()
	{
		// Enqueue the admin scripts.
		add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

		// Hook it up.
		add_action('admin_init', array($this, 'admin_init'));

		// Menu.
		add_action('admin_menu', array($this, 'admin_menu'));
	}

	/**
	 * Admin Scripts.
	 *
	 * @since 1.0.0
	 */
	public function admin_scripts()
	{
		global $wp_version;

		// Only load CrawlWP assets on CrawlWP admin pages.
		$screen = get_current_screen();
		if ( ! $screen || strpos($screen->id, 'crawlwp') === false) {
			return;
		}

		// jQuery is needed.
		wp_enqueue_script('jquery');

		// Color Picker.
		wp_enqueue_script(
			'iris',
			admin_url('js/iris.min.js'),
			array('jquery-ui-draggable', 'jquery-ui-slider', 'jquery-touch-punch'),
			$wp_version,
			true
		);

		// Admin settings JS (extracted from the former inline script() method).
		wp_enqueue_script(
			'crawlwp-admin',
			CRAWLWP_PLUGIN_URL . 'src/Views/assets/admin.js',
			array('jquery', 'iris'),
			CRAWLWP_VERSION,
			true
		);
		wp_localize_script(
			'crawlwp-admin',
			'wposaAdmin',
			array(
				'prefix'      => CRAWLWP_PREFIX,
				'redirectUrl' => esc_url(admin_url('admin.php?page=' . Utils::get_plugin_slug())),
				'yandexState' => wp_create_nonce('yandex_oauth_nonce'),
			)
		);

		// Admin stylesheet.
		wp_enqueue_style(
			'crawlwp-admin',
			CRAWLWP_PLUGIN_URL . 'src/Views/assets/admin.css',
			array(),
			CRAWLWP_VERSION
		);
	}


	/**
	 * Set Sections.
	 *
	 * @param array $sections
	 *
	 * @since 1.0.0
	 */
	public function set_sections($sections)
	{
		// Bail if not array.
		if ( ! is_array($sections)) {
			return false;
		}

		// Assign to the sections array.
		$this->sections_array = $sections;

		return $this;
	}


	/**
	 * Add a single section.
	 *
	 * @param array $menu
	 *
	 * @return false|WPOSA
	 */
	public function add_header_menu($menu)
	{
		// Bail if not array.
		if ( ! is_array($menu)) {
			return false;
		}

		$menu['id'] = $this->plugin_prefix . '_' . $menu['id'];

		// Assign the section to sections array.
		$this->header_menu_array[] = $menu;

		return $this;
	}


	/**
	 * Add a single section.
	 *
	 * @param array $section
	 *
	 * @since 1.0.0
	 */
	public function add_section($section)
	{
		// Bail if not array.
		if ( ! is_array($section)) {
			return false;
		}

		$section['id'] = $this->plugin_prefix . '_' . $section['id'];
		if (isset($section['header_menu_id'])) {
			$section['header_menu_id'] = $this->plugin_prefix . '_' . $section['header_menu_id'];
		}
		// Assign the section to sections array.
		$this->sections_array[] = $section;

		return $this;
	}


	/**
	 * Set Fields.
	 *
	 * @since 1.0.0
	 */
	public function set_fields($fields)
	{
		// Bail if not array.
		if ( ! is_array($fields)) {
			return false;
		}

		// Assign the fields.
		$this->fields_array = $fields;

		return $this;
	}


	/**
	 * Add a single field.
	 *
	 * @since 1.0.0
	 */
	public function add_field($section, $field_array)
	{
		// Set the defaults
		$defaults = array(
			'id'   => '',
			'name' => '',
			'desc' => '',
			'type' => 'text',
		);

		// Combine the defaults with user's arguments.
		$arg = wp_parse_args($field_array, $defaults);

		// Each field is an array named against its section.
		$this->fields_array[$this->plugin_prefix . '_' . $section][] = $arg;
		// Invalidate the sanitize index so a newly added field is included.
		$this->sanitize_index = null;

		return $this;
	}

	/**
	 * Add sidebar cards.
	 *
	 * @param array $card
	 *
	 * @return $this
	 */
	public function add_sidebar_card(array $card): WPOSA
	{
		$this->sidebar_cards[] = $card;

		return $this;
	}

	/**
	 * Remove or empty out all sidebar cards.
	 *
	 * @return $this
	 */
	public function remove_all_sidebar_cards(): WPOSA
	{
		$this->sidebar_cards = [];

		return $this;
	}

	public function get_sidebar_cards()
	{
		return $this->sidebar_cards;
	}

	public function get_sidebar_cards_total()
	{
		return count($this->get_sidebar_cards());
	}

	private function convert_array_to_attributes(array $args): string
	{
		if (empty($args)) {
			return '';
		}

		$result = [];

		foreach ($args as $attr_key => $attr_value) {
			if ($attr_value === true || $attr_value === false) {
				if ($attr_value === true) {
					$result[] = esc_attr($attr_key);
				}
			} else {
				$result[] = sprintf(
					'%s="%s"',
					esc_attr($attr_key),
					esc_attr($attr_value)
				);
			}
		}

		return implode(' ', $result);
	}

	/**
	 * @return string
	 */
	public function get_active_header_menu()
	{
		return ! empty($_GET['wposa-menu']) ?
			sanitize_text_field($_GET['wposa-menu']) :
			($this->header_menu_array[0]['id'] ?? '');
	}

	/**
	 * Initialize API.
	 *
	 * Initializes and registers the settings sections and fields.
	 * Usually this should be called at `admin_init` hook.
	 */
	function admin_init()
	{
		if (isset($_REQUEST['action'], $_REQUEST['crawlwp_options_save']) && $_REQUEST['action'] == 'update' && current_user_can('manage_options')) {

			$option_page = ! empty($_REQUEST['option_page']) ? sanitize_text_field($_REQUEST['option_page']) : '';

			check_admin_referer($option_page . '-options');

			// Only registered section ids may be saved through this handler.
			$section_ids = array_flip(array_column($this->sections_array, 'id'));

			foreach ($_POST as $k => $v) {

				if (strstr($k, 'submit_') !== false) {
					$name = str_replace('submit_', '', $k);

					if ( ! isset($section_ids[$name])) {
						continue;
					}

					$db_options = get_option($name, []);

					$db_options = ! is_array($db_options) ? [] : $db_options;

					$posted_data = isset($_POST[$name]) && is_array($_POST[$name]) ? $_POST[$name] : [];

					$submitted_data = apply_filters('wposa_submitted_data', $posted_data, $name, $_POST);

					$value = array_replace($db_options, $this->sanitize_section_data($name, wp_unslash($submitted_data)));

					update_option($name, $value);

					wp_safe_redirect(add_query_arg(['settings-updated' => 'true'], Utils::get_current_url_query_string()));
					exit;
				}
			}
		}
		/**
		 * Register the sections.
		 *
		 * Sections array is like this:
		 *
		 * $sections_array = array (
		 *   $section_array,
		 *   $section_array,
		 *   $section_array,
		 * );
		 *
		 * Section array is like this:
		 *
		 * $section_array = array (
		 *   'id'    => 'section_id',
		 *   'title' => 'Section Title'
		 * );
		 *
		 * @since 1.0.0
		 */
		foreach ($this->sections_array as $section) {

			// Only call add_option when the option is genuinely missing; the
			// cached get_option() already returns false for non-existent keys
			// so we bypass it here to avoid polluting the option_cache with
			// a false entry before the option is created.
			if ( ! array_key_exists($section['id'], $this->option_cache)) {
				$this->option_cache[$section['id']] = get_option($section['id']);
			}
			if ($this->option_cache[$section['id']] === false) {
				add_option($section['id'], '', '', false);
				$this->option_cache[$section['id']] = [];
			}

			// Deals with sections description.
			if (isset($section['desc']) && ! empty($section['desc'])) {
				// Build HTML.
				$section['desc'] = '<div class="inside wposa-section-description">' . wp_kses($section['desc'], self::ALLOWED_HTML) . '</div>';

				// Create the callback for description.
				$callback = function () use ($section) {
					echo wp_kses(str_replace('"', '\"', $section['desc']), self::ALLOWED_HTML);
				};

			} elseif (isset($section['callback'])) {
				$callback = $section['callback'];
			} else {
				$callback = null;
			}

			/**
			 * Add a new section to a settings page.
			 *
			 * @param string $id
			 * @param string $title
			 * @param callable $callback
			 * @param string $page | Page is same as section ID.
			 *
			 * @since 1.0.0
			 */
			add_settings_section($section['id'], $section['title'] ?? '', $callback, $section['id']);
		} // foreach ended.
		/**
		 * Register settings fields.
		 *
		 * Fields array is like this:
		 *
		 * $fields_array = array (
		 *   $section => $field_array,
		 *   $section => $field_array,
		 *   $section => $field_array,
		 * );
		 *
		 *
		 * Field array is like this:
		 *
		 * $field_array = array (
		 *   'id'   => 'id',
		 *   'name' => 'Name',
		 *   'type' => 'text',
		 * );
		 *
		 * @since 1.0.0
		 */
		foreach ($this->fields_array as $section => $field_array) {
			foreach ($field_array as $field) {
				// ID.
				$id = $field['id'] ?? false;

				// Type.
				$type = $field['type'] ?? 'text';

				// Name.
				$name = $field['name'] ?? 'No Name Added';

				// Label for.
				$label_for = "{$section}[{$field['id']}]";

				// Description.
				$description = $field['desc'] ?? '';

				// Size.
				$size = $field['size'] ?? null;

				// Options.
				$options = $field['options'] ?? '';

				// Standard default value.
				$default = $field['default'] ?? '';

				// Standard default placeholder.
				$placeholder = $field['placeholder'] ?? '';

				// Readonly attribute.
				$readonly = $field['readonly'] ?? false;

				// Sanitize Callback.
				$sanitize_callback = $field['sanitize_callback'] ?? '';

				$help_tab = $field['help_tab'] ?? '';
				$class    = $field['class'] ?? "wposa-form-table__row wposa-form-table__row_type_{$type} wposa-form-table__row_{$section}_{$id}";

				$args = array(
					'id'                => $id,
					'type'              => $type,
					'name'              => $name,
					'label_for'         => $label_for,
					'desc'              => $description,
					'section'           => $section,
					'size'              => $size,
					'options'           => $options,
					'std'               => $default,
					'placeholder'       => $placeholder,
					'rows'              => $field['rows'] ?? null,
					'sanitize_callback' => $sanitize_callback,
					'attributes'        => [
						'readonly' => $readonly,
					],
					'class'             => $class,
				);

				if (isset($field['value'])) {
					$args['value'] = $field['value'];
				}

				if (isset($field['attributes'])) {
					$args['attributes'] = array_merge($args['attributes'], $field['attributes']);
				}

				if ( ! empty($field['button_class'])) {
					$args['button_class'] = $field['button_class'];
				}

				if ($help_tab) {
					$name .= $this->show_help_tab_toggle($help_tab);
				}

				/**
				 * Add a new field to a section of a settings page.
				 *
				 * @param string $id
				 * @param string $title
				 * @param callable $callback
				 * @param string $page
				 * @param string $section = 'default'
				 * @param array $args = array()
				 *
				 * @since 1.0.0
				 */
				// @param string 	$id
				$field_id = $section . '[' . $field['id'] . ']';

				add_settings_field(
					$field_id,
					$name,
					array($this, 'callback_' . $type),
					$section,
					$section,
					$args
				);
			}
		}

		// Creates our settings in the fields table.
		foreach ($this->sections_array as $section) {
			/**
			 * Registers a setting and its sanitization callback.
			 *
			 * @param string $field_group | A settings group name.
			 * @param string $field_name | The name of an option to sanitize and save.
			 * @param callable $sanitize_callback = ''
			 *
			 * @since 1.0.0
			 */
			register_setting($section['id'], $section['id'], array($this, 'sanitize_fields'));
		}

	}


	/**
	 * Sanitize callback for Settings API fields.
	 *
	 * @since 1.0.0
	 */
	public function sanitize_fields($fields)
	{
		if (is_array($fields)) {
			foreach ($fields as $field_slug => $field_value) {
				$sanitize_callback = $this->get_sanitize_callback($field_slug);
				// If callback is set, call it.
				if ($sanitize_callback) {
					$fields[$field_slug] = call_user_func($sanitize_callback, $field_value);
				}
			}
		}

		return $fields;
	}

	/**
	 * Sanitize the posted data of a section according to each registered field type.
	 *
	 * Values for field ids that are not registered in the section keep the
	 * generic Utils::clean_data() behaviour.
	 *
	 * @param string $section_id Section (option) id.
	 * @param mixed  $data       Submitted (unslashed) data.
	 *
	 * @return array
	 */
	public function sanitize_section_data(string $section_id, $data): array
	{
		if ( ! is_array($data)) {
			return [];
		}

		$fields = [];
		foreach ($this->fields_array[$section_id] ?? [] as $field) {
			if ( ! empty($field['id'])) {
				$fields[$field['id']] = $field;
			}
		}

		$sanitized = [];

		foreach ($data as $field_id => $value) {
			if ( ! isset($fields[$field_id])) {
				$sanitized[$field_id] = Utils::clean_data($value);
				continue;
			}

			$sanitized[$field_id] = $this->sanitize_field_value($fields[$field_id], $value);
		}

		return $sanitized;
	}

	/**
	 * Sanitize a single field value based on its type.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Submitted value.
	 *
	 * @return mixed
	 */
	private function sanitize_field_value(array $field, $value)
	{
		$type    = $field['type'] ?? 'text';
		$options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
		$default = $field['default'] ?? '';

		if ( ! empty($field['sanitize_callback']) && is_callable($field['sanitize_callback'])) {
			return call_user_func($field['sanitize_callback'], $value);
		}

		switch ($type) {
			case 'checkbox':
			case 'switch':
				return $value === 'on' ? 'on' : 'off';

			case 'select':
			case 'radio':
				if (is_scalar($value) && array_key_exists((string)$value, $options)) {
					return sanitize_text_field((string)$value);
				}

				return $default;

			case 'multicheck':
				if ( ! is_array($value)) {
					return [];
				}

				$value = array_filter(array_intersect_key($value, $options), 'is_scalar');

				return array_intersect($value, array_keys($options));

			case 'url':
			case 'image':
			case 'file':
				return is_scalar($value) ? esc_url_raw((string)$value) : '';

			case 'number':
				if (is_array($value)) {
					return Utils::clean_data($value);
				}

				return is_numeric($value) && (string)(int)$value !== (string)$value ? (float)$value : (int)$value;

			case 'textarea':
				if (is_array($value)) {
					return Utils::clean_data($value);
				}

				return ! empty($field['allow_html'])
					? wp_kses_post((string)$value)
					: sanitize_textarea_field((string)$value);

			case 'html':
				return is_array($value) ? Utils::clean_data($value) : wp_kses_post((string)$value);

			default:
				return is_array($value) ? Utils::clean_data($value) : sanitize_text_field((string)$value);
		}
	}


	/**
	 * Get sanitization callback for given option slug
	 *
	 * @param string $slug option slug.
	 *
	 * @return mixed string | bool false
	 * @since  1.0.0
	 */
	function get_sanitize_callback($slug = '')
	{
		if (empty($slug)) {
			return false;
		}

		// Build a flat slug→callback index on the first call instead of
		// running a double-loop on every field during sanitization.
		if ($this->sanitize_index === null) {
			$this->sanitize_index = [];
			foreach ($this->fields_array as $field_array) {
				foreach ($field_array as $field) {
					$cb = isset($field['sanitize_callback']) && is_callable($field['sanitize_callback'])
						? $field['sanitize_callback']
						: false;
					$this->sanitize_index[$field['id']] = $cb;
				}
			}
		}

		return $this->sanitize_index[$slug] ?? false;
	}


	/**
	 * Get field description for display
	 *
	 * @param array $args settings field args
	 */
	public function get_field_description($args)
	{
		if ( ! empty($args['desc'])) {
			$desc = sprintf(
				'<p class="description">%s</p>',
				is_callable($args['desc'])
					? call_user_func($args['desc'])
					: $args['desc']
			);
		} else {
			$desc = '';
		}

		return wp_kses($desc, self::ALLOWED_HTML);
	}


	/**
	 * Displays a title field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_title($args)
	{
		$value = esc_attr($this->get_option($args['id'], $args['section'], $args['std']));

		echo esc_html($value);
	}


	/**
	 * Displays a text field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_text($args)
	{
		$value = isset($args['value']) ? esc_attr($args['value']) : esc_attr($this->get_option($args['id'], $args['section'], $args['std']));
		$size  = isset($args['size']) && ! is_null($args['size']) ? $args['size'] : 'regular';
		$type  = isset($args['type']) ? $args['type'] : 'text';

		$attributes = $this->convert_array_to_attributes($args['attributes']);

		$html = sprintf(
			'<input type="%1$s" class="%2$s-text" id="%3$s[%4$s]" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s" %7$s/>',
			esc_attr($type),
			esc_attr($size),
			esc_attr($args['section']),
			esc_attr($args['id']),
			esc_attr($value),
			esc_attr($args['placeholder']),
			$attributes
		);

		$html .= $this->get_field_description($args);

		echo $html;
	}


	/**
	 * Displays a url field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_url($args)
	{
		$this->callback_text($args);
	}

	/**
	 * Displays a number field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_number($args)
	{
		$this->callback_text($args);
	}

	/**
	 * Displays a checkbox for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_checkbox($args)
	{

		$value = esc_attr($this->get_option($args['id'], $args['section'], $args['std']));
		$html  = '<fieldset>';
		$html  .= sprintf('<label for="wposa-%1$s[%2$s]">', $args['section'], $args['id']);
		$html  .= sprintf('<input type="hidden" name="%1$s[%2$s]" value="off" />', $args['section'], $args['id']);
		$html  .= sprintf('<input type="checkbox" class="checkbox" id="wposa-%1$s[%2$s]" name="%1$s[%2$s]" value="on" %3$s />', $args['section'], $args['id'], checked($value, 'on', false));
		$html  .= sprintf('%1$s</label>', $args['desc']);
		$html  .= '</fieldset>';

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a iOS switch checkbox for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_switch($args)
	{

		$value = esc_attr($this->get_option($args['id'], $args['section'], $args['std']));
		$html  = '<fieldset>';
		$html  .= sprintf('<label for="wposa-%1$s[%2$s]">', $args['section'], $args['id']);
		$html  .= sprintf('<input type="hidden" name="%1$s[%2$s]" value="off" />', $args['section'], $args['id']);
		$html  .= sprintf('<input type="checkbox" class="wposa-field wposa-field--switch" id="wposa-%1$s[%2$s]" name="%1$s[%2$s]" value="on" %3$s />', $args['section'], $args['id'], checked($value, 'on', false));
		$html  .= sprintf('%1$s</label>', $args['desc']);
		$html  .= '</fieldset>';

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a multicheckbox a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_multicheck($args)
	{

		$value = $this->get_option($args['id'], $args['section'], $args['std']);

		$html = '<fieldset>';
		$html .= sprintf(
			'<input type="hidden" name="%s[%s][]" value="" />',
			$args['section'], $args['id']
		);
		foreach ($args['options'] as $key => $label) {
			$checked = isset($value[$key]) ? $value[$key] : '0';
			$html    .= sprintf('<label for="wposa-%1$s[%2$s][%3$s]">', $args['section'], $args['id'], $key);
			$html    .= sprintf('<input type="checkbox" class="checkbox" id="wposa-%1$s[%2$s][%3$s]" name="%1$s[%2$s][%3$s]" value="%3$s" %4$s />', $args['section'], $args['id'], $key, checked($checked, $key, false));
			$html    .= sprintf('%1$s</label><br>', $label);
		}
		$html .= $this->get_field_description($args);
		$html .= '</fieldset>';

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a multicheckbox a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_radio($args)
	{

		$value = $this->get_option($args['id'], $args['section'], $args['std']);

		$html = '<fieldset>';
		foreach ($args['options'] as $key => $label) {
			$html .= sprintf('<label for="wposa-%1$s[%2$s][%3$s]">', $args['section'], $args['id'], $key);
			$html .= sprintf('<input type="radio" class="radio" id="wposa-%1$s[%2$s][%3$s]" name="%1$s[%2$s]" value="%3$s" %4$s />', $args['section'], $args['id'], $key, checked($value, $key, false));
			$html .= sprintf('%1$s</label><br>', $label);
		}
		$html .= $this->get_field_description($args);
		$html .= '</fieldset>';

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a selectbox for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_select($args)
	{

		$value = $this->get_option($args['id'], $args['section'], $args['std']);
		$size  = isset($args['size']) && ! is_null($args['size']) ? $args['size'] : 'regular';

		$html = sprintf('<select class="%1$s" name="%2$s[%3$s]" id="%2$s[%3$s]">', $size, $args['section'], $args['id']);
		foreach ($args['options'] as $key => $label) {
			$html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($key), selected($value, $key, false), esc_html($label));
		}
		$html .= '</select>';
		$html .= $this->get_field_description($args);

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a textarea for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_textarea($args)
	{

		$value = esc_textarea($this->get_option($args['id'], $args['section'], $args['std']));
		$size  = isset($args['size']) && ! is_null($args['size']) ? $args['size'] : 'regular';
		$rows  = isset($args['rows']) && ! is_null($args['rows']) ? absint($args['rows']) : 5;

		$attributes = $this->convert_array_to_attributes($args['attributes'] ?? []);

		$html = sprintf(
			'<textarea rows="%1$s" cols="55" class="%2$s-text" id="%3$s[%4$s]" name="%3$s[%4$s]" placeholder="%5$s" %6$s>%7$s</textarea>',
			esc_attr($rows),
			esc_attr($size),
			esc_attr($args['section']),
			esc_attr($args['id']),
			esc_attr($args['placeholder'] ?? ''),
			$attributes,
			$value
		);
		$html .= $this->get_field_description($args);

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a textarea for a settings field
	 *
	 * @param array $args settings field args.
	 */
	function callback_html($args)
	{
		echo is_callable($args['desc']) ? call_user_func($args['desc']) : $args['desc'];
	}

	/**
	 * Displays a file upload field for a settings field
	 *
	 * @param array $args settings field args.
	 */
	function callback_file($args)
	{

		$value = esc_attr($this->get_option($args['id'], $args['section'], $args['std']));
		$size  = isset($args['size']) && ! is_null($args['size']) ? $args['size'] : 'regular';
		$id    = $args['section'] . '[' . $args['id'] . ']';
		$label = isset($args['options']['button_label']) ?
			$args['options']['button_label'] :
			__('Choose File');

		$html = sprintf('<input type="text" class="%1$s-text wpsa-url" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s"/>', $size, $args['section'], $args['id'], $value);
		$html .= '<input type="button" class="button wpsa-browse" value="' . $label . '" />';
		$html .= $this->get_field_description($args);

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays an image upload field with a preview
	 *
	 * @param array $args settings field args.
	 */
	function callback_image($args)
	{

		$value = esc_attr($this->get_option($args['id'], $args['section'], $args['std']));
		$size  = isset($args['size']) && ! is_null($args['size']) ? $args['size'] : 'regular';
		$id    = $args['section'] . '[' . $args['id'] . ']';
		$label = $args['options']['button_label'] ?? __('Choose Image');

		$attributes = $this->convert_array_to_attributes($args['attributes'] ?? []);

		$html = sprintf('<input type="text" class="%1$s-text wpsa-url" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s" %5$s/>', $size, $args['section'], $args['id'], $value, $attributes);
		$html .= '<input type="button" class="button wpsa-browse" value="' . $label . '" />';
		$html .= $this->get_field_description($args);

		if ($value !== '') {
			$html .= sprintf('<p class="wpsa-image-preview"><img src="%s"/></p>', esc_url($value));
		}

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a password field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_password($args)
	{

		$value = esc_attr($this->get_option($args['id'], $args['section'], $args['std']));
		$size  = isset($args['size']) && ! is_null($args['size']) ? $args['size'] : 'regular';

		$html = sprintf('<input type="password" class="%1$s-text" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s"/>', $size, $args['section'], $args['id'], $value);
		$html .= $this->get_field_description($args);

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Displays a color picker field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_color($args)
	{

		$value = esc_attr($this->get_option($args['id'], $args['section'], $args['std'], $args['placeholder']));
		$size  = isset($args['size']) && ! is_null($args['size']) ? $args['size'] : 'regular';

		$html = sprintf('<input type="text" class="%1$s-text color-picker" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s" data-default-color="%5$s" placeholder="%6$s" />', $size, $args['section'], $args['id'], $value, $args['std'], $args['placeholder']);
		$html .= $this->get_field_description($args);

		echo wp_kses($html, self::ALLOWED_HTML);
	}


	/**
	 * Displays a separator field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_separator($args)
	{
		?>
		<div class="wposa-field wposa-field--separator"></div>
		<?php
	}

	/**
	 * Displays a Button field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_button($args)
	{
		$value   = $args['placeholder'] ?? __('Submit');
		$class   = $args['button_class'] ?? 'button-secondary';
		$id      = $args['id'] ?? time();
		$onclick = $args['attributes']['onclick'] ?? '';
		?>
		<input
			type="button"
			id="<?php echo esc_attr($id); ?>"
			onclick="<?php echo esc_attr($onclick); ?>"
			value="<?php echo esc_attr($value); ?>"
			class="button <?php echo esc_attr($class); ?>"
		/>
		<?php echo wp_kses($this->get_field_description($args), self::ALLOWED_HTML); ?>
		<?php
	}

	/**
	 * Displays a Button field for a settings field
	 *
	 * @param array $args settings field args
	 */
	function callback_hidden($args)
	{
		$value = $this->get_option($args['id'], $args['section'], $args['std']);
		?>
		<input
			type="hidden"
			name="<?php echo esc_attr($args['section']); ?>[<?php echo esc_attr($args['id']); ?>]"
			value="<?php echo esc_attr($value); ?>"
		/>
		<?php
	}

	/**
	 * Get the value of a settings field
	 *
	 * @param string $option settings field name.
	 * @param string $section the section name this field belongs to.
	 * @param mixed $default default text if it's not found.
	 *
	 * @return mixed
	 */
	public function get_option(string $option, string $section, $default = '')
	{
		$section     = str_replace($this->plugin_prefix . '_', '', $section);
		$option_name = $this->plugin_prefix . '_' . $section;

		// Cache the entire option row for the lifetime of this request so that
		// rendering 30 fields in one section only triggers a single DB read.
		if ( ! array_key_exists($option_name, $this->option_cache)) {
			$this->option_cache[$option_name] = get_option($option_name);
		}

		$options = $this->option_cache[$option_name];

		if (isset($options[$option])) {
			return apply_filters('wposa/get_option', $options[$option], $option, $section, $default);
		}

		return apply_filters('wposa/get_option', $default, $option, $section, $default);
	}

	public function set_option(string $option, $value, string $section): bool
	{
		$name = $this->plugin_prefix . '_' . $section;

		// Get option (use cache when available).
		$options = array_key_exists($name, $this->option_cache)
			? $this->option_cache[$name]
			: get_option($name);

		if (empty($options) || ! is_array($options)) {
			$options = [];
		}

		// Update option and invalidate the cache entry.
		$options[$option] = $value;
		unset($this->option_cache[$name]);

		return update_option($name, $options);
	}

	private function getMenuIcon()
	{
		return 'data:image/svg+xml;base64,' . base64_encode('<svg viewBox="0 0 109.05 109" xmlns="http://www.w3.org/2000/svg"><g fill="#fff"><path d="m76.41 0h-68.28c-2.24 0-4.27.91-5.74 2.38s-2.39 3.5-2.39 5.74v78.24c0 2.23.92 4.25 2.39 5.72v.02c1.47 1.46 3.5 2.38 5.74 2.38h6.43v6.4c0 2.23.92 4.27 2.39 5.74h.01c1.47 1.46 3.5 2.38 5.73 2.38h78.23c2.24 0 4.27-.92 5.74-2.38 1.47-1.47 2.39-3.51 2.39-5.74v-68.42zm27.63 100.88c0 .82-.33 1.62-.91 2.2-.6.6-1.38.92-2.21.92h-78.23c-.84 0-1.63-.33-2.22-.93-.57-.57-.9-1.37-.9-2.19v-78.24c0-.84.34-1.64.92-2.22s1.36-.9 2.2-.9h63.43v17.26c0 .73.6 1.33 1.33 1.33h16.59z" fill-rule="evenodd"/><path d="m71.66 79.72c-1.34 0-2.43 1.09-2.43 2.43v3.33c0 1.34 1.09 2.42 2.43 2.42s2.43-1.08 2.43-2.42v-3.33c0-1.34-1.09-2.43-2.43-2.43z"/><path d="m79.08 71.05c-1.34 0-2.42 1.08-2.42 2.42v12.01c0 1.34 1.08 2.42 2.42 2.42s2.43-1.08 2.43-2.42v-12.01c0-1.34-1.08-2.42-2.43-2.42z"/><path d="m64.24 76.25c-1.34 0-2.43 1.09-2.43 2.43v6.8c0 1.34 1.09 2.42 2.43 2.42s2.42-1.08 2.42-2.42v-6.8c0-1.34-1.08-2.43-2.42-2.43z"/><path d="m87.45 40.11c-1.84 0-3.33-1.5-3.33-3.33v-15.26h-61.43c-.3 0-.59.11-.8.32s-.32.5-.32.8v78.24c0 .29.12.57.32.78.22.22.5.34.8.34h78.23c.29 0 .57-.12.79-.34s.33-.49.33-.78v-60.77zm-59.53.56h48.78c1.19 0 2.15.96 2.15 2.15s-.96 2.15-2.15 2.15h-48.77c-1.19 0-2.15-.96-2.15-2.15s.97-2.15 2.15-2.15zm14.59 47.53h-14.59c-1.18 0-2.15-.96-2.15-2.15s.97-2.15 2.15-2.15h14.59c1.19 0 2.15.96 2.15 2.15s-.96 2.15-2.15 2.15zm6.95-14.4h-21.54c-1.18 0-2.15-.97-2.15-2.16s.97-2.15 2.15-2.15h21.54c1.19 0 2.15.96 2.15 2.15s-.96 2.16-2.15 2.16zm-21.54-14.41c-1.18 0-2.15-.97-2.15-2.15s.97-2.16 2.15-2.16h27.08c1.19 0 2.15.97 2.15 2.16s-.96 2.15-2.15 2.15zm71.11 39.04c-.79.89-2.14.98-3.04.19l-6.29-5.54c-3.63 3.04-8.31 4.88-13.42 4.88-5.77 0-11.01-2.35-14.8-6.14-3.78-3.78-6.13-9.02-6.13-14.79s2.3-10.9 6.02-14.68l.11-.12c3.79-3.79 9.03-6.13 14.8-6.13s11.01 2.34 14.8 6.13 6.13 9.03 6.13 14.8c0 4.89-1.69 9.39-4.51 12.95l6.13 5.41c.9.79.98 2.15.2 3.04z"/><path d="m86.51 65.84c-1.34 0-2.43 1.09-2.43 2.43v17.21c0 1.34 1.09 2.42 2.43 2.42s2.43-1.08 2.43-2.42v-17.21c0-1.34-1.09-2.43-2.43-2.43z"/></g></svg>');
	}

	/**
	 * Add submenu page to the Settings main menu.
	 */
	public function admin_menu()
	{
		if ( ! empty($this->sub_menu_slug)) {

			$hook = add_submenu_page(
				$this->plugin_slug,
				$this->sub_page_title,
				$this->sub_page_title,
				'manage_options',
				$this->sub_menu_slug,
				array($this, 'plugin_page')
			);

		} else {

			$hook = add_menu_page(
				$this->plugin_name,
				$this->plugin_name,
				'manage_options',
				$this->plugin_slug,
				array($this, 'plugin_page'),
				$this->getMenuIcon()
			);

			add_submenu_page(
				$this->plugin_slug,
				$this->plugin_name,
				esc_html__('Settings', 'mihdan-index-now'),
				'manage_options',
				$this->plugin_slug,
				array($this, 'plugin_page')
			);
		}

		add_action("load-" . $hook, function () {
			do_action('wpposa_load_menu_hook', $this->sub_menu_slug, $this->plugin_slug);
			add_action('admin_notices', [$this, 'admin_notices']);
		});
	}

	public function admin_notices()
	{
		if (Utils::_GET_var('settings-updated') == 'true') {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php _e('Settings saved.', 'mihdan-index-now'); ?></p>
			</div>
			<?php
		}
	}

	public function plugin_page()
	{
		$review_url  = 'https://wordpress.org/support/plugin/mihdan-index-now/reviews/?filter=5#new-post';
		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-header-top';

		$active_header_menu_id = $this->get_active_header_menu();

		$flag = false;

		foreach ($this->sections_array as $section) {

			if ( ! empty($active_header_menu_id)) {
				$header_menu_id = $section['header_menu_id'] ?? '';
				if ($header_menu_id != $active_header_menu_id) continue;
			}

			$flag = true;
		}

		if ( ! $flag) return;

		$wp_removable_query_args = array_merge(['paged', 'cwp-page', 'cwp-query', 'preset', 'start_date', 'end_date'], wp_removable_query_args());

		?>
		<div class="wposa">
			<div class="wposa-new-header">
				<div class="wposa-branding">
					<img class="wposa-logo" src="<?php echo esc_url(Utils::get_plugin_asset_url('images/icons/crawlwp-favicon.svg')); ?>" width="80" alt=""/>
					<h1><?php echo esc_html($this->sub_page_title) ?></h1>
				</div>

				<nav class="wposa-tabs">
					<?php foreach ($this->header_menu_array as $menu) :
						$url = esc_url(remove_query_arg($wp_removable_query_args, add_query_arg(['wposa-menu' => $menu['id']])));
						$active_class = $this->get_active_header_menu() == $menu['id'] ? ' wposa-tab-active' : '';
						?>
						<a href="<?php echo $url ?>" class="wposa-tab<?php echo $active_class; ?>"><?php echo esc_html($menu['title']) ?></a>
					<?php endforeach; ?>
				</nav>

				<div class="wposa-header-right">
					<a href="<?php echo esc_url($review_url) ?>" target="_blank" class="wposa-header-action review">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
							<path fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="2.5" d="m12 2l3.104 6.728l7.358.873l-5.44 5.03l1.444 7.268L12 18.28L5.534 21.9l1.444-7.268L1.538 9.6l7.359-.873L12 2Z"></path>
						</svg>
						<?php esc_html_e('Review', 'mihdan-index-now'); ?>
					</a> <a href="https://crawlwp.com/docs/" target="_blank" class="wposa-header-action translate">
						<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 56 56">
							<path fill="currentColor" d="M15.555 53.125h24.89c4.852 0 7.266-2.461 7.266-7.336V24.508c0-3.024-.328-4.336-2.203-6.258L32.57 5.102c-1.78-1.829-3.234-2.227-5.882-2.227H15.555c-4.828 0-7.266 2.484-7.266 7.36v35.554c0 4.898 2.438 7.336 7.266 7.336m.187-3.773c-2.414 0-3.68-1.29-3.68-3.633V10.305c0-2.32 1.266-3.657 3.704-3.657h10.406v13.618c0 2.953 1.5 4.406 4.406 4.406h13.36v21.047c0 2.343-1.243 3.633-3.68 3.633ZM31 21.132c-.914 0-1.29-.374-1.29-1.312V7.375l13.5 13.758Z"/>
						</svg>
						<?php esc_html_e('Documentation', 'mihdan-index-now'); ?>
					</a>
					<?php if ( ! defined('CRAWLWP_DETACH_LIBSODIUM')) { ?>
						<a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" class="button-primary plugin-upgrade">
							<?php esc_html_e('CrawlWP Premium', 'mihdan-index-now'); ?>
						</a>
					<?php } ?>
				</div>
			</div>
			<div class="wrap">
				<?php // useful to keep admin notices at the top
				?>
				<h2 style="display:none">CrawlWP</h2>
				<?php if ($this->get_sidebar_cards_total() === 0) : ?>
					<?php $this->show_forms(); ?>
				<?php else : ?>
					<div class="wposa__grid">
						<div class="wposa__column wposa__content">
							<?php $this->show_forms(); ?>
						</div>
						<?php if ($this->get_sidebar_cards_total()) : ?>
							<div class="wposa__column" style="padding-right: 10px">
								<?php foreach ($this->get_sidebar_cards() as $card) : ?>
									<div class="card wposa-card wposa-card--<?php echo esc_attr($this->get_prefix()) ?>_<?php echo esc_attr($card['id']) ?>">
										<?php if ( ! empty($card['title'])) : ?>
											<h2 class="title wposa__title wposa__title--h2 wposa-card__title"><?php echo esc_html($card['title']) ?></h2>
										<?php endif; ?>
										<div class="wposa-card__content">
											<?php echo wp_kses($card['desc'], self::ALLOWED_HTML); ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Show navigations as tab
	 *
	 * Shows all the settings section labels as tab
	 */
	function show_navigation()
	{
		$html = sprintf(
			'<nav class="wposa-nav-tab-wrapper" aria-label="%s">',
			esc_html__('Secondary Navigation', 'wposa')
		);

		$groups = array();

		foreach ($this->sections_array as $tab) {

			$group_id = ! empty($tab['nav_group']) ? $tab['nav_group'] : '';

			// Ungrouped sections keep rendering exactly as before.
			if ($group_id === '') {
				$html .= $this->get_navigation_item($tab);
				continue;
			}

			if ( ! isset($groups[$group_id])) {
				$groups[$group_id] = array(
					'label' => $tab['nav_group_label'] ?? $group_id,
					'items' => '',
				);
			}

			if (empty($groups[$group_id]['label']) && ! empty($tab['nav_group_label'])) {
				$groups[$group_id]['label'] = $tab['nav_group_label'];
			}

			$groups[$group_id]['items'] .= $this->get_navigation_item($tab);
		}

		foreach ($groups as $group_id => $group) {
			$html .= sprintf('<div class="wposa-nav-group" data-group="%s">', esc_attr($group_id));
			$html .= sprintf(
				'<button type="button" class="wposa-nav-group__toggle" aria-expanded="true">%1$s<span class="wposa-nav-group__chevron" aria-hidden="true">%2$s</span></button>',
				esc_html($group['label']),
				$this->get_navigation_group_chevron()
			);
			$html .= '<div class="wposa-nav-group__items">' . $group['items'] . '</div>';
			$html .= '</div>';
		}

		$html .= '</nav>';

		echo wp_kses($html, self::ALLOWED_HTML);
	}

	/**
	 * Build the markup of a single navigation item.
	 *
	 * @param array $tab Section array.
	 *
	 * @return string
	 */
	private function get_navigation_item($tab): string
	{
		if (isset($tab['disabled']) && $tab['disabled'] === true) {
			if (isset($tab['badge'])) {
				return sprintf('<span class="wposa-nav-tab wposa-nav-tab--disabled" id="%1$s-tab">%2$s <span class="wposa-badge">%3$s</span></span>', $tab['id'], $tab['title'], $tab['badge']);
			}

			return sprintf('<span class="wposa-nav-tab wposa-nav-tab--disabled" id="%1$s-tab">%2$s</span>', $tab['id'], $tab['title']);
		}

		return sprintf('<a href="#%1$s" class="wposa-nav-tab" id="%1$s-tab">%2$s</a>', $tab['id'], $tab['title']);
	}

	/**
	 * Chevron icon used by the navigation group toggle.
	 *
	 * @return string
	 */
	private function get_navigation_group_chevron(): string
	{
		return '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	public function blank_mode_do_settings_sections($page)
	{

		global $wp_settings_sections, $wp_settings_fields;

		foreach ((array)$wp_settings_sections[$page] as $section) {

			$section = $section['id'];

			if (isset($wp_settings_fields[$page][$section])) {

				foreach ((array)$wp_settings_fields[$page][$section] as $field) {

					call_user_func($field['callback'], $field['args']);
				}
			}
		}
	}

	public function enable_blank_mode_show_forms($default)
	{
		?>
		<div>
			<h2 style="display:none"><?php echo $this->sub_page_title ?></h2>
			<?php foreach ($this->sections_array as $form) :
				$form = wp_parse_args($form, $default);
				$this->blank_mode_do_settings_sections($form['id']);
			endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Show the section settings forms
	 *
	 * This function displays every sections in a different form
	 */
	function show_forms()
	{
		$default = array(
			'label_submit' => null,
			'submit_type'  => 'primary',
			'wrap'         => false,
			'attributes'   => null,
			'reset_button' => true,
		);

		if ($this->enable_blank_mode):
			$this->enable_blank_mode_show_forms($default);
		else:
			$this->show_navigation();
			?>
			<div class="metabox-holder">
				<?php foreach ($this->sections_array as $form) : ?>
					<?php
					$form = wp_parse_args($form, $default);
					?>
					<!-- style="display: none;" -->
					<div id="<?php echo esc_attr($form['id']); ?>" class="group">
						<form class="wposa__form" method="post">
							<?php
							do_action('wsa_form_top_' . $form['id'], $form);
							settings_fields($form['id']);
							do_settings_sections($form['id']);
							do_action('wsa_form_bottom_' . $form['id'], $form);
							?>
							<div class="wposa-footer">
								<div class="wposa-footer__column wposa-footer__column--left">
									<input type="hidden" name="crawlwp_options_save" value="true">
									<?php submit_button($form['label_submit'], $form['submit_type'], 'submit_' . $form['id'], $form['wrap'], $form['attributes']); ?>
								</div>
							</div>
						</form>
					</div>
				<?php endforeach; ?>
			</div>
		<?php
		endif;
		$this->script();
	}

	/**
	 * Show help tab toggle.
	 *
	 * @param string $tab_id Tab identified.
	 * @param string $tab_icon Tab icon.
	 */
	private function show_help_tab_toggle($tab_id, $tab_icon = '?')
	{
		$is_url = strstr($tab_id, 'http');
		$href   = $is_url ? $tab_id : '#';
		$title  = $is_url ? __('Click to view help guide', 'mihdan-index-now') : __('Click to show Help tab', 'mihdan-index-now');

		$class  = 'wpsa-help-tab-toggle' . ($is_url ? ' is-url' : '');
		$target = $is_url ? '_blank' : '_self';
		ob_start();
		?>
		<a href="<?php echo esc_url($href); ?>" target="<?php echo esc_attr($target); ?>" title="<?php echo esc_attr($title); ?>" class="<?php echo esc_attr($class); ?>" data-tab="<?php echo esc_attr($tab_id); ?>"><?php echo esc_html($tab_icon); ?></a>
		<?php
		return ob_get_clean();
	}

	public function script()
	{
		// All JS is now in assets/admin.js — nothing to output inline.
	}
}
