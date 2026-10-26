<?php

namespace Mihdan\IndexNow\SEOCore\LocalSeo;

use Mihdan\IndexNow\SEOCore\SettingsFieldsTrait;
use Mihdan\IndexNow\SEOCore\SiteInfoSettings\SiteInfoSettings;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Settings › Local SEO: one business location as LocalBusiness structured
 * data, and the [crawlwp_local_business] shortcode.
 *
 * Google requires a name and an address, and recommends coordinates, opening
 * hours, phone, price range, the page URL and, for places that serve food,
 * the menu and cuisine:
 * https://developers.google.com/search/docs/appearance/structured-data/local-business
 *
 * Option row: crawlwp_local_seo. Sites set up before this section existed
 * keep their values in crawlwp_site_info until this section is saved.
 *
 * Premium (Advanced Local SEO) adds locations and special opening hours
 * through the crawlwp_local_seo_fields action and the
 * crawlwp_local_special_hours, crawlwp_local_business_data and
 * crawlwp_local_business_node filters.
 */
class LocalSeo
{
	use SettingsFieldsTrait;

	public const SECTION = 'local_seo';

	public const OPTION = 'crawlwp_local_seo';

	/** Keys this section took over from Site Information. */
	private const LEGACY_KEYS = ['local_enabled', 'local_type', 'local_street', 'local_city', 'local_region', 'local_postal', 'local_country', 'local_phone'];

	/** Types Google treats as food establishments (menu and cuisine apply). */
	public const FOOD_TYPES = ['FoodEstablishment', 'Restaurant', 'FastFoodRestaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'IceCreamShop', 'Brewery', 'Winery'];

	private static bool $style_printed = false;

	public function __construct()
	{
		/* Priority 6: right after Site Information. */
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 6, 2);
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
		add_filter('crawlwp_site_graph', [$this, 'add_to_graph']);
		add_shortcode('crawlwp_local_business', [$this, 'shortcode']);
	}

	public static function is_pro(): bool
	{
		return (bool) apply_filters('crawlwp_is_pro_active', defined('CRAWLWP_DETACH_LIBSODIUM'));
	}

	/**
	 * Schema.org business types, by label.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array
	{
		$types = [
			'LocalBusiness'               => __('Local business (general)', 'mihdan-index-now'),
			'AccountingService'           => __('Accountant', 'mihdan-index-now'),
			'Attorney'                    => __('Lawyer / attorney', 'mihdan-index-now'),
			'AutoDealer'                  => __('Car dealer', 'mihdan-index-now'),
			'AutoRepair'                  => __('Car repair', 'mihdan-index-now'),
			'Bakery'                      => __('Bakery', 'mihdan-index-now'),
			'BarOrPub'                    => __('Bar or pub', 'mihdan-index-now'),
			'BeautySalon'                 => __('Beauty salon', 'mihdan-index-now'),
			'CafeOrCoffeeShop'            => __('Café or coffee shop', 'mihdan-index-now'),
			'ChildCare'                   => __('Child care', 'mihdan-index-now'),
			'ClothingStore'               => __('Clothing store', 'mihdan-index-now'),
			'Dentist'                     => __('Dentist', 'mihdan-index-now'),
			'Electrician'                 => __('Electrician', 'mihdan-index-now'),
			'ElectronicsStore'            => __('Electronics store', 'mihdan-index-now'),
			'EmploymentAgency'            => __('Employment agency', 'mihdan-index-now'),
			'ExerciseGym'                 => __('Gym', 'mihdan-index-now'),
			'FastFoodRestaurant'          => __('Fast food restaurant', 'mihdan-index-now'),
			'FinancialService'            => __('Financial service', 'mihdan-index-now'),
			'FoodEstablishment'           => __('Food and drink (other)', 'mihdan-index-now'),
			'FurnitureStore'              => __('Furniture store', 'mihdan-index-now'),
			'GeneralContractor'           => __('Builder / general contractor', 'mihdan-index-now'),
			'GroceryStore'                => __('Grocery store / supermarket', 'mihdan-index-now'),
			'HairSalon'                   => __('Hair salon', 'mihdan-index-now'),
			'HardwareStore'               => __('Hardware store', 'mihdan-index-now'),
			'HomeAndConstructionBusiness' => __('Home services', 'mihdan-index-now'),
			'Hotel'                       => __('Hotel', 'mihdan-index-now'),
			'HVACBusiness'                => __('Heating and air conditioning', 'mihdan-index-now'),
			'IceCreamShop'                => __('Ice cream shop', 'mihdan-index-now'),
			'InsuranceAgency'             => __('Insurance agency', 'mihdan-index-now'),
			'LegalService'                => __('Legal service', 'mihdan-index-now'),
			'LodgingBusiness'             => __('Lodging (guest house, B&B)', 'mihdan-index-now'),
			'MedicalBusiness'             => __('Medical business', 'mihdan-index-now'),
			'MedicalClinic'               => __('Medical clinic', 'mihdan-index-now'),
			'MovingCompany'               => __('Moving company', 'mihdan-index-now'),
			'NightClub'                   => __('Night club', 'mihdan-index-now'),
			'Optician'                    => __('Optician', 'mihdan-index-now'),
			'Pharmacy'                    => __('Pharmacy', 'mihdan-index-now'),
			'Physician'                   => __('Doctor', 'mihdan-index-now'),
			'Plumber'                     => __('Plumber', 'mihdan-index-now'),
			'ProfessionalService'         => __('Professional service', 'mihdan-index-now'),
			'RealEstateAgent'             => __('Real estate agent', 'mihdan-index-now'),
			'Restaurant'                  => __('Restaurant', 'mihdan-index-now'),
			'RoofingContractor'           => __('Roofer', 'mihdan-index-now'),
			'SelfStorage'                 => __('Self storage', 'mihdan-index-now'),
			'SportsActivityLocation'      => __('Sports centre', 'mihdan-index-now'),
			'Store'                       => __('Shop (other)', 'mihdan-index-now'),
			'TravelAgency'                => __('Travel agency', 'mihdan-index-now'),
			'VeterinaryCare'              => __('Vet', 'mihdan-index-now'),
			'Winery'                      => __('Winery', 'mihdan-index-now'),
			'Brewery'                     => __('Brewery', 'mihdan-index-now'),
		];

		$general = ['LocalBusiness' => $types['LocalBusiness']];
		unset($types['LocalBusiness']);
		asort($types);

		/** Filter the business types offered in Local SEO (schema.org type => label). */
		return (array) apply_filters('crawlwp_local_business_types', $general + $types);
	}

	// -------------------------------------------------------------------------
	// Stored settings
	// -------------------------------------------------------------------------

	/**
	 * The saved settings, or the old Site Information values on sites that
	 * haven't saved this section yet.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array
	{
		$saved = get_option(self::OPTION, null);

		if (is_array($saved) && $saved !== []) {
			return $saved;
		}

		$old      = get_option('crawlwp_site_info', []);
		$old      = is_array($old) ? $old : [];
		$settings = array_intersect_key($old, array_flip(self::LEGACY_KEYS));

		if (! empty($old['local_hours'])) {
			$settings['hours']        = self::parse_legacy_hours((string) $old['local_hours']);
			$settings['legacy_hours'] = (string) $old['local_hours'];
		}

		/* Copy them over once: saving Site Information no longer keeps these keys. */
		if ($settings !== [] && $saved === null) {
			add_option(self::OPTION, $settings);
		}

		return $settings;
	}

	/**
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	public static function get(string $key, $default = '')
	{
		$settings = self::settings();

		return $settings[$key] ?? $default;
	}

	/**
	 * Hours typed in the old free-text box ("Mo-Fr 09:00-17:00", one range a line).
	 *
	 * @return array<string, array<int, array{0:string, 1:string}>>|null Null when a line can't be read.
	 */
	public static function parse_legacy_hours(string $text): ?array
	{
		$codes = ['mo' => 'mon', 'tu' => 'tue', 'we' => 'wed', 'th' => 'thu', 'fr' => 'fri', 'sa' => 'sat', 'su' => 'sun'];
		$order = array_values($codes);
		$hours = array_fill_keys($order, []);
		$any   = false;

		foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
			$line = trim($line);

			if ($line === '') {
				continue;
			}

			if (! preg_match('/^([A-Za-z,\-\s]+?)\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $line, $m)) {
				return null;
			}

			$from = OpeningHours::time($m[2]);
			$to   = OpeningHours::time($m[3]);

			if ($from === '' || $to === '') {
				return null;
			}

			foreach (explode(',', strtolower(preg_replace('/\s+/', '', $m[1]))) as $part) {
				$ends = explode('-', $part);
				$a    = $codes[substr($ends[0], 0, 2)] ?? null;
				$b    = isset($ends[1]) ? ($codes[substr($ends[1], 0, 2)] ?? null) : $a;

				if ($a === null || $b === null) {
					return null;
				}

				/* Fr-Mo wraps past Sunday. */
				$start = (int) array_search($a, $order, true);
				$count = ((int) array_search($b, $order, true) - $start + 7) % 7 + 1;

				for ($n = 0; $n < $count; $n++) {
					$day = $order[($start + $n) % 7];

					if (count($hours[$day]) < 2) {
						$hours[$day][] = [$from, $to];
						$any           = true;
					}
				}
			}
		}

		return $any ? $hours : null;
	}

	/**
	 * The business as one location: clean values ready for schema and display.
	 *
	 * @param array<string, mixed> $s           Settings (Local SEO section, or a Premium location).
	 * @param int                  $location_id 0 for the main business.
	 *
	 * @return array<string, mixed>
	 */
	public static function normalize(array $s, int $location_id = 0): array
	{
		$type = (string) ($s['local_type'] ?? 'LocalBusiness');
		$lat  = is_numeric($s['local_lat'] ?? null) && abs((float) $s['local_lat']) <= 90 ? (string) (float) $s['local_lat'] : '';
		$lng  = is_numeric($s['local_lng'] ?? null) && abs((float) $s['local_lng']) <= 180 ? (string) (float) $s['local_lng'] : '';
		$name = trim((string) ($s['local_name'] ?? ''));

		if ($name === '') {
			$name = (string) SiteInfoSettings::get('site_name', '') ?: (string) get_bloginfo('name');
		}

		$hours = OpeningHours::sanitize($s['hours'] ?? null);

		return [
			'location_id'  => $location_id,
			'id'           => home_url('/') . '#localbusiness',
			'type'         => preg_match('/^[A-Za-z]+$/', $type) ? $type : 'LocalBusiness',
			'name'         => $name,
			'url'          => home_url('/'),
			'street'       => trim((string) ($s['local_street'] ?? '')),
			'city'         => trim((string) ($s['local_city'] ?? '')),
			'region'       => trim((string) ($s['local_region'] ?? '')),
			'postal'       => trim((string) ($s['local_postal'] ?? '')),
			'country'      => preg_match('/^[A-Za-z]{2}$/', trim((string) ($s['local_country'] ?? ''))) ? strtoupper(trim((string) $s['local_country'])) : '',
			'lat'          => $lat !== '' && $lng !== '' ? $lat : '',
			'lng'          => $lat !== '' && $lng !== '' ? $lng : '',
			'phone'        => trim((string) ($s['local_phone'] ?? '')),
			'price'        => in_array($s['local_price'] ?? '', ['$', '$$', '$$$', '$$$$'], true) ? (string) $s['local_price'] : '',
			'menu'         => in_array($type, self::FOOD_TYPES, true) ? esc_url_raw((string) ($s['local_menu'] ?? '')) : '',
			'cuisine'      => in_array($type, self::FOOD_TYPES, true) ? trim((string) ($s['local_cuisine'] ?? '')) : '',
			'hours'        => $hours,
			/* Old free-text hours the editor couldn't read: still sent to Google until hours are entered in the editor. */
			'legacy_hours' => $hours === null ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($s['legacy_hours'] ?? '')) ?: []))) : [],
		];
	}

	/**
	 * The main business, or null when Local SEO is off.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function business(): ?array
	{
		$settings = self::settings();

		return ($settings['local_enabled'] ?? 'off') === 'on' ? self::normalize($settings) : null;
	}

	/**
	 * Special opening hours that apply to a location (Premium adds them).
	 *
	 * @return array<int, array{from:string, to:string, ranges:array<int, array{0:string, 1:string}>}> Empty ranges = closed.
	 */
	public static function special_hours(int $location_id = 0): array
	{
		/**
		 * Filter the special (holiday) opening hours of the main business (0) or a location.
		 *
		 * @param array $special     [['from' => 'Y-m-d', 'to' => 'Y-m-d', 'ranges' => [['09:00', '13:00']]]]; no ranges = closed.
		 * @param int   $location_id Location post ID, 0 for the main business.
		 */
		return (array) apply_filters('crawlwp_local_special_hours', [], $location_id);
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * The LocalBusiness node for a location.
	 *
	 * @param array<string, mixed> $d From normalize().
	 *
	 * @return array<string, mixed>
	 */
	public static function node(array $d): array
	{
		$node = [
			'@type' => $d['type'],
			'@id'   => $d['id'],
			'name'  => $d['name'],
			'url'   => $d['url'],
		];

		$address = array_filter([
			'@type'           => 'PostalAddress',
			'streetAddress'   => $d['street'],
			'addressLocality' => $d['city'],
			'addressRegion'   => $d['region'],
			'postalCode'      => $d['postal'],
			'addressCountry'  => $d['country'],
		]);

		if (count($address) > 1) {
			$node['address'] = $address;
		}

		if ($d['lat'] !== '') {
			$node['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $d['lat'], 'longitude' => (float) $d['lng']];
		}

		foreach (['phone' => 'telephone', 'price' => 'priceRange', 'menu' => 'menu', 'cuisine' => 'servesCuisine'] as $key => $property) {
			if ($d[$key] !== '') {
				$node[$property] = $d[$key];
			}
		}

		$specs = $d['hours'] !== null ? OpeningHours::to_schema($d['hours']) : [];
		$today = wp_date('Y-m-d');

		foreach (self::special_hours((int) $d['location_id']) as $special) {
			/* Past dates are no use to Google. */
			if (($special['to'] ?? '') < $today) {
				continue;
			}

			foreach ($special['ranges'] ?: [['00:00', '00:00']] as [$from, $to]) {
				/* Closed all day is opens = closes = 00:00. */
				$specs[] = [
					'@type'        => 'OpeningHoursSpecification',
					'opens'        => $from,
					'closes'       => $to,
					'validFrom'    => $special['from'],
					'validThrough' => $special['to'],
				];
			}
		}

		if ($specs !== []) {
			$node['openingHoursSpecification'] = $specs;
		} elseif ($d['legacy_hours'] !== []) {
			$node['openingHours'] = $d['legacy_hours'];
		}

		/**
		 * Filter a LocalBusiness node.
		 *
		 * @param array $node The node.
		 * @param array $d    The location's details (location_id 0 = main business).
		 */
		return (array) apply_filters('crawlwp_local_business_node', $node, $d);
	}

	/**
	 * Add the main business to the site graph.
	 *
	 * @param array $graph
	 */
	public function add_to_graph($graph): array
	{
		$graph    = (array) $graph;
		$business = self::business();

		if ($business === null || ! isset($graph['@graph']) || ! is_array($graph['@graph'])) {
			return $graph;
		}

		$node     = self::node($business);
		$home_url = home_url('/');
		$logo_id  = $home_url . '#/schema/logo/image/';

		foreach ($graph['@graph'] as $graph_node) {
			if (! is_array($graph_node)) {
				continue;
			}

			/* The business belongs to the site's Organization. */
			if (($graph_node['@id'] ?? '') === $home_url . '#organization') {
				$node['parentOrganization'] = ['@id' => $home_url . '#organization'];
			}

			/* Reuse the site logo, emitted on the Organization or Person node. */
			if (($graph_node['logo']['@id'] ?? '') === $logo_id || ($graph_node['image']['@id'] ?? '') === $logo_id) {
				$node['image'] = ['@id' => $logo_id];
			}
		}

		$graph['@graph'][] = $node;

		return $graph;
	}

	// -------------------------------------------------------------------------
	// Settings screen
	// -------------------------------------------------------------------------

	public function enqueue(): void
	{
		if (($_GET['page'] ?? '') === 'crawlwp') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::enqueue_admin_assets();
		}
	}

	/**
	 * Scripts and styles of the hours editor and business fields (also used by Premium's location screen).
	 */
	public static function enqueue_admin_assets(): void
	{
		$url = CRAWLWP_PLUGIN_URL . 'src/SEOCore/LocalSeo/assets/';

		wp_enqueue_style('crawlwp-local-seo', $url . 'local-seo.css', [], CRAWLWP_VERSION);
		wp_enqueue_script('crawlwp-local-seo', $url . 'local-seo.js', ['jquery'], CRAWLWP_VERSION, true);
		wp_localize_script('crawlwp-local-seo', 'crawlwpLocalSeo', ['foodTypes' => self::FOOD_TYPES]);
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance = null): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$s     = self::settings();
		$field = static function (string $key) use ($s): string {
			return self::OPTION . '[' . $key . ']';
		};
		$value = static function (string $key) use ($s): string {
			return esc_attr((string) ($s[$key] ?? ''));
		};

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Local SEO', 'mihdan-index-now'),
		]);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_business',
			__('Your business', 'mihdan-index-now'),
			__('Shown to Google as a LocalBusiness, so your address, phone and opening hours can appear in search and on Google Maps. Leave this off if visitors don\'t come to a physical place.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'      => 'local_enabled',
			'type'    => 'switch',
			'name'    => __('Enable local business details', 'mihdan-index-now'),
			'default' => $s['local_enabled'] ?? 'off',
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'local_type',
			'type'    => 'select',
			'name'    => __('Business type', 'mihdan-index-now'),
			'default' => $s['local_type'] ?? 'LocalBusiness',
			'options' => self::types(),
			'class'   => 'wposa-form-table__row cwp-local-type-row',
			'desc'    => esc_html__('Pick the closest type. Google uses it to decide which searches to show you in.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'local_name',
			'type'        => 'text',
			'name'        => __('Business name', 'mihdan-index-now'),
			'placeholder' => (string) SiteInfoSettings::get('site_name', '') ?: get_bloginfo('name'),
			'desc'        => esc_html__('Leave empty to use the name in Site Information.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'local_address',
			'type' => 'html',
			'name' => __('Address', 'mihdan-index-now'),
			'desc' => self::address_fields($field, $value),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'local_geo',
			'type' => 'html',
			'name' => __('Map pin', 'mihdan-index-now'),
			'desc' => self::geo_fields($field, $value),
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'local_phone',
			'type'        => 'text',
			'name'        => __('Phone', 'mihdan-index-now'),
			'default'     => $s['local_phone'] ?? '',
			'placeholder' => '+234 803 000 0000',
			'desc'        => esc_html__('Include the country code.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'local_price',
			'type'    => 'select',
			'name'    => __('Price range', 'mihdan-index-now'),
			'options' => self::price_options(),
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'local_menu',
			'type'        => 'text',
			'name'        => __('Menu', 'mihdan-index-now'),
			'placeholder' => 'https://',
			'class'       => 'wposa-form-table__row cwp-local-food',
			'desc'        => esc_html__('Link to your menu page.', 'mihdan-index-now'),
			'sanitize_callback' => 'esc_url_raw',
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'local_cuisine',
			'type'        => 'text',
			'name'        => __('Cuisine', 'mihdan-index-now'),
			'placeholder' => __('e.g. Nigerian, Italian, Vegan', 'mihdan-index-now'),
			'class'       => 'wposa-form-table__row cwp-local-food',
		]);

		$this->add_heading($wposa, self::SECTION, 'heading_hours', __('Opening hours', 'mihdan-index-now'));

		$wposa->add_field(self::SECTION, [
			'id'   => 'local_hours_editor',
			'type' => 'html',
			'name' => __('Hours', 'mihdan-index-now'),
			'desc' => self::hours_field($field('hours'), $s),
		]);

		$this->add_heading($wposa, self::SECTION, 'heading_display', __('Show it on your site', 'mihdan-index-now'));

		$wposa->add_field(self::SECTION, [
			'id'   => 'local_shortcode',
			'type' => 'html',
			'name' => __('Shortcode', 'mihdan-index-now'),
			'desc' => '<code>[crawlwp_local_business]</code><p class="description">' . esc_html__('Shows your address, phone, opening hours and a "Get directions" link, for example on a contact page or in the footer. In the block editor, add it with the Shortcode block. To show only some parts: [crawlwp_local_business show="address,phone"].', 'mihdan-index-now') . '</p>',
		]);

		if (self::is_pro()) {
			/**
			 * Add Premium's Advanced Local SEO fields to the Local SEO section.
			 *
			 * @param WPOSA  $wposa   Settings API.
			 * @param string $section Section id.
			 */
			do_action('crawlwp_local_seo_fields', $wposa, self::SECTION);
		} else {
			$wposa->add_field(self::SECTION, [
				'id'   => 'local_upsell',
				'type' => 'html',
				'name' => '',
				'desc' => self::upsell(),
			]);
		}
	}

	/**
	 * @return array<string, string>
	 */
	public static function price_options(): array
	{
		return [
			''     => __('Not shown', 'mihdan-index-now'),
			'$'    => __('$ (inexpensive)', 'mihdan-index-now'),
			'$$'   => __('$$ (moderate)', 'mihdan-index-now'),
			'$$$'  => __('$$$ (expensive)', 'mihdan-index-now'),
			'$$$$' => __('$$$$ (very expensive)', 'mihdan-index-now'),
		];
	}

	/**
	 * Street, city, region, postal code and country inputs.
	 *
	 * @param callable $field Key → input name.
	 * @param callable $value Key → escaped value.
	 */
	public static function address_fields(callable $field, callable $value): string
	{
		$input = static function (string $key, string $placeholder) use ($field, $value): string {
			return sprintf('<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%3$s">', esc_attr($field($key)), $value($key), esc_attr($placeholder));
		};

		return '<div class="cwp-local-address">'
			. $input('local_street', __('Street address', 'mihdan-index-now'))
			. '<div>' . $input('local_city', __('City', 'mihdan-index-now')) . $input('local_region', __('State / region', 'mihdan-index-now')) . '</div>'
			. '<div>' . $input('local_postal', __('Postal code', 'mihdan-index-now')) . $input('local_country', __('Country code, e.g. NG', 'mihdan-index-now')) . '</div>'
			. '</div><p class="description">' . esc_html__('Google requires the name and address. The country is the two-letter code: NG, US, GB…', 'mihdan-index-now') . '</p>';
	}

	/**
	 * Latitude and longitude inputs.
	 */
	public static function geo_fields(callable $field, callable $value): string
	{
		return sprintf(
			'<div class="cwp-local-geo"><input type="text" inputmode="decimal" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%3$s"> <input type="text" inputmode="decimal" name="%4$s" value="%5$s" placeholder="%6$s" aria-label="%6$s"></div><p class="description">%7$s</p>',
			esc_attr($field('local_lat')),
			$value('local_lat'),
			esc_attr__('Latitude, e.g. 6.44741', 'mihdan-index-now'),
			esc_attr($field('local_lng')),
			$value('local_lng'),
			esc_attr__('Longitude, e.g. 3.45531', 'mihdan-index-now'),
			esc_html__('So Google puts the pin in the right place. In Google Maps, right-click your business and click the numbers at the top of the menu to copy them.', 'mihdan-index-now')
		);
	}

	/**
	 * The hours editor, with a note when old free-text hours couldn't be read.
	 *
	 * @param array<string, mixed> $s
	 */
	public static function hours_field(string $name, array $s): string
	{
		$hours = OpeningHours::sanitize($s['hours'] ?? null);
		$html  = OpeningHours::editor($name, $hours);

		if ($hours === null && ! empty($s['legacy_hours'])) {
			$html .= '<p class="description cwp-local-legacy">' . sprintf(
				/* translators: %s: the hours as typed before */
				esc_html__('Your hours were saved as text before: %s. Please enter them again above.', 'mihdan-index-now'),
				'<code>' . esc_html(str_replace(["\r", "\n"], ['', '; '], trim((string) $s['legacy_hours']))) . '</code>'
			) . '</p>';
		}

		return $html . '<p class="description">' . esc_html__('Untick a day to show it as closed. "Add hours" is for a break in the day.', 'mihdan-index-now') . '</p>';
	}

	private static function upsell(): string
	{
		$points = [
			__('<b>Multiple locations</b>, each with its own page, address, phone, hours and schema', 'mihdan-index-now'),
			__('<b>Holiday and special opening hours</b> for any date, for all locations or one', 'mihdan-index-now'),
			__('<b>Location pages</b> that show each branch\'s details and are added to your sitemap', 'mihdan-index-now'),
		];

		$html = '<div class="cwp-local-upsell"><span class="cwp-local-upsell__tag">' . esc_html__('CrawlWP Premium', 'mihdan-index-now') . '</span>';
		$html .= '<h3>' . esc_html__('Advanced Local SEO', 'mihdan-index-now') . '</h3>';
		$html .= '<p>' . esc_html__('For businesses with more than one branch, and the agencies building their sites.', 'mihdan-index-now') . '</p><ul>';

		foreach ($points as $point) {
			$html .= '<li>' . wp_kses($point, ['b' => []]) . '</li>';
		}

		return $html . '</ul><a class="button button-primary" target="_blank" href="' . esc_url('https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-local-seo') . '">' . esc_html__('Upgrade to CrawlWP Premium', 'mihdan-index-now') . '</a></div>';
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * [crawlwp_local_business show="name,address,phone,hours,directions" location=""]
	 *
	 * @param array|string $atts
	 */
	public function shortcode($atts): string
	{
		$atts = shortcode_atts(['show' => 'name,address,phone,hours,directions', 'location' => ''], (array) $atts, 'crawlwp_local_business');

		/**
		 * Filter the business shown by the shortcode, e.g. a Premium location for location="…".
		 *
		 * @param array|null $business Details from LocalSeo::normalize(), null when Local SEO is off.
		 * @param array      $atts     Shortcode attributes.
		 */
		$business = apply_filters('crawlwp_local_business_data', self::business(), $atts);

		return is_array($business) ? self::render($business, array_map('trim', explode(',', (string) $atts['show']))) : '';
	}

	/**
	 * The visible business details.
	 *
	 * @param array<string, mixed> $d    From normalize().
	 * @param string[]             $show Parts to show.
	 */
	public static function render(array $d, array $show = ['name', 'address', 'phone', 'hours', 'directions']): string
	{
		$html = '';

		if (in_array('name', $show, true)) {
			$html .= '<p class="crawlwp-lb__name"><strong>' . esc_html($d['name']) . '</strong></p>';
		}

		$line2   = trim($d['city'] . ($d['region'] !== '' ? ', ' . $d['region'] : '') . ' ' . $d['postal']);
		$address = array_filter([$d['street'], $line2]);

		if (in_array('address', $show, true) && $address !== []) {
			$html .= '<p class="crawlwp-lb__address">' . implode('<br>', array_map('esc_html', $address)) . '</p>';
		}

		if (in_array('phone', $show, true) && $d['phone'] !== '') {
			$html .= '<p class="crawlwp-lb__phone"><a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $d['phone'])) . '">' . esc_html($d['phone']) . '</a></p>';
		}

		if (in_array('hours', $show, true) && $d['hours'] !== null) {
			$today = self::today_text($d);
			$html .= $today !== '' ? '<p class="crawlwp-lb__today">' . esc_html($today) . '</p>' : '';
			$html .= '<table class="crawlwp-lb__hours"><tbody>';

			foreach (OpeningHours::display_rows($d['hours']) as $row) {
				$html .= '<tr><th scope="row">' . esc_html($row['days']) . '</th><td>' . esc_html($row['hours']) . '</td></tr>';
			}

			$html .= '</tbody></table>';
		}

		if (in_array('directions', $show, true) && ($d['lat'] !== '' || $address !== [])) {
			$destination = $d['lat'] !== '' ? $d['lat'] . ',' . $d['lng'] : implode(', ', array_filter([$d['street'], $d['city'], $d['region'], $d['postal'], $d['country']]));
			$html       .= '<p class="crawlwp-lb__directions"><a href="' . esc_url('https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($destination)) . '" target="_blank" rel="noopener">' . esc_html__('Get directions', 'mihdan-index-now') . '</a></p>';
		}

		if ($html === '') {
			return '';
		}

		$style = '';

		if (! self::$style_printed) {
			self::$style_printed = true;
			$style = '<style>.crawlwp-local-business p{margin:0 0 .6em}.crawlwp-lb__hours{border-collapse:collapse;margin:0 0 .8em}.crawlwp-lb__hours th,.crawlwp-lb__hours td{padding:.15em 1.2em .15em 0;border:0;text-align:left;font-weight:normal;vertical-align:top}.crawlwp-lb__today{font-weight:600}</style>';
		}

		return $style . '<div class="crawlwp-local-business">' . $html . '</div>';
	}

	/**
	 * "Open today: 9:00 am – 5:00 pm", "Closed today", or a special day's hours.
	 *
	 * @param array<string, mixed> $d
	 */
	public static function today_text(array $d): string
	{
		$today = wp_date('Y-m-d');

		foreach (self::special_hours((int) $d['location_id']) as $special) {
			if (($special['from'] ?? '') <= $today && $today <= ($special['to'] ?? '')) {
				return $special['ranges'] === []
					? __('Closed today', 'mihdan-index-now')
					/* translators: %s: today's opening hours */
					: sprintf(__('Open today: %s', 'mihdan-index-now'), OpeningHours::ranges_text($special['ranges']));
			}
		}

		/* wp_date('N'): 1 (Monday) to 7 (Sunday), in the site's time zone. */
		$day    = array_keys(OpeningHours::DAYS)[(int) wp_date('N') - 1];
		$ranges = $d['hours'][$day] ?? [];

		return $ranges === []
			? __('Closed today', 'mihdan-index-now')
			/* translators: %s: today's opening hours */
			: sprintf(__('Open today: %s', 'mihdan-index-now'), OpeningHours::ranges_text($ranges));
	}
}
