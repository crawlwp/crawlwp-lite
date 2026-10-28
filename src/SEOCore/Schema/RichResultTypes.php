<?php
/**
 * Rich result types included in the free plugin.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\SEOCore\Schema;

/**
 * Definitions follow Google's required and recommended properties:
 * https://developers.google.com/search/docs/appearance/structured-data/search-gallery
 */
class RichResultTypes
{
	public static function all(): array
	{
		return [
			'Product'             => self::product(),
			'Recipe'              => self::recipe(),
			'Event'               => self::event(),
			'Video'               => self::video(),
			'SoftwareApplication' => self::software(),
			'Review'              => self::review(),
		] + self::premium_placeholders();
	}

	private static function rating_fields(): array
	{
		return [
			'rating_value' => ['label' => __('Average rating', 'mihdan-index-now'), 'type' => 'number', 'min' => 1, 'max' => 5, 'group' => __('Rating', 'mihdan-index-now'), 'hint' => __('Out of 5. Only use real ratings shown on this page.', 'mihdan-index-now')],
			'rating_count' => ['label' => __('Number of ratings', 'mihdan-index-now'), 'type' => 'number', 'min' => 1, 'group' => __('Rating', 'mihdan-index-now')],
		];
	}

	/**
	 * Currency, required once a non-zero price is entered.
	 */
	private static function currency_field(): array
	{
		return [
			'label'       => __('Currency', 'mihdan-index-now'),
			'type'        => 'currency',
			'required'    => ['field' => 'price', 'nonzero' => true],
			'placeholder' => 'USD',
			'hint'        => __('Three-letter code, e.g. USD, EUR, NGN.', 'mihdan-index-now'),
		];
	}

	public static function availability_options(): array
	{
		return [
			'InStock'      => __('In stock', 'mihdan-index-now'),
			'OutOfStock'   => __('Out of stock', 'mihdan-index-now'),
			'PreOrder'     => __('Pre-order', 'mihdan-index-now'),
			'BackOrder'    => __('Back order', 'mihdan-index-now'),
			'Discontinued' => __('Discontinued', 'mihdan-index-now'),
		];
	}

	private static function product(): array
	{
		return [
			'label'                => __('Product', 'mihdan-index-now'),
			'hint'                 => __('WooCommerce products get Product data automatically. Use this for products you sell without WooCommerce.', 'mihdan-index-now'),
			'required_any'         => [['price'], ['rating_value', 'rating_count']],
			'required_any_message' => __('A price or a rating', 'mihdan-index-now'),
			'fields'               => [
				'name'         => ['label' => __('Product name', 'mihdan-index-now'), 'type' => 'text', 'required' => true, 'default' => 'title'],
				'description'  => ['label' => __('Description', 'mihdan-index-now'), 'type' => 'textarea', 'default' => 'description'],
				'image'        => ['label' => __('Image URL', 'mihdan-index-now'), 'type' => 'url', 'default' => 'image', 'hint' => __('Defaults to the featured image.', 'mihdan-index-now')],
				'brand'        => ['label' => __('Brand', 'mihdan-index-now'), 'type' => 'text'],
				'sku'          => ['label' => __('SKU', 'mihdan-index-now'), 'type' => 'text'],
				'price'        => ['label' => __('Price', 'mihdan-index-now'), 'type' => 'number', 'min' => 0, 'group' => __('Price', 'mihdan-index-now')],
				'currency'     => self::currency_field() + ['group' => __('Price', 'mihdan-index-now')],
				'availability' => ['label' => __('Availability', 'mihdan-index-now'), 'type' => 'select', 'options' => self::availability_options(), 'group' => __('Price', 'mihdan-index-now')],
			] + self::rating_fields(),
			'build'                => [__CLASS__, 'build_product'],
		];
	}

	public static function build_product(array $v, array $c): array
	{
		return [
			'@type'           => 'Product',
			'name'            => $v['name'],
			'description'     => $v['description'],
			'image'           => $v['image'],
			'sku'             => $v['sku'],
			'brand'           => $v['brand'] !== '' ? ['@type' => 'Brand', 'name' => $v['brand']] : [],
			'offers'          => RichResults::offer($v['price'], $v['currency'], $c['url'], $v['availability']),
			'aggregateRating' => RichResults::aggregate_rating($v['rating_value'], $v['rating_count']),
		];
	}

	private static function recipe(): array
	{
		return [
			'label'  => __('Recipe', 'mihdan-index-now'),
			'fields' => [
				'name'         => ['label' => __('Recipe name', 'mihdan-index-now'), 'type' => 'text', 'required' => true, 'default' => 'title'],
				'image'        => ['label' => __('Image URL', 'mihdan-index-now'), 'type' => 'url', 'required' => true, 'default' => 'image', 'hint' => __('Defaults to the featured image.', 'mihdan-index-now')],
				'description'  => ['label' => __('Description', 'mihdan-index-now'), 'type' => 'textarea', 'default' => 'description'],
				'prep_time'    => ['label' => __('Prep time (minutes)', 'mihdan-index-now'), 'type' => 'minutes'],
				'cook_time'    => ['label' => __('Cook time (minutes)', 'mihdan-index-now'), 'type' => 'minutes'],
				'yield'        => ['label' => __('Serves', 'mihdan-index-now'), 'type' => 'text', 'placeholder' => __('e.g. 4 servings', 'mihdan-index-now')],
				'calories'     => ['label' => __('Calories per serving', 'mihdan-index-now'), 'type' => 'number', 'min' => 0],
				'category'     => ['label' => __('Category', 'mihdan-index-now'), 'type' => 'text', 'placeholder' => __('e.g. Dinner, Dessert', 'mihdan-index-now')],
				'cuisine'      => ['label' => __('Cuisine', 'mihdan-index-now'), 'type' => 'text', 'placeholder' => __('e.g. Nigerian, Italian', 'mihdan-index-now')],
				'ingredients'  => ['label' => __('Ingredients', 'mihdan-index-now'), 'type' => 'list', 'hint' => __('One per line.', 'mihdan-index-now')],
				'instructions' => ['label' => __('Steps', 'mihdan-index-now'), 'type' => 'list', 'hint' => __('One step per line.', 'mihdan-index-now')],
			] + self::rating_fields(),
			'build'  => [__CLASS__, 'build_recipe'],
		];
	}

	public static function build_recipe(array $v, array $c): array
	{
		$total = (int) $v['prep_time'] + (int) $v['cook_time'];

		return [
			'@type'              => 'Recipe',
			'name'               => $v['name'],
			'image'              => $v['image'],
			'description'        => $v['description'],
			'author'             => $c['defaults']['author'] !== '' ? ['@type' => 'Person', 'name' => $c['defaults']['author']] : [],
			'datePublished'      => $c['defaults']['date'],
			'prepTime'           => RichResults::iso_minutes($v['prep_time']),
			'cookTime'           => RichResults::iso_minutes($v['cook_time']),
			'totalTime'          => RichResults::iso_minutes((string) $total),
			'recipeYield'        => $v['yield'],
			'recipeCategory'     => $v['category'],
			'recipeCuisine'      => $v['cuisine'],
			'nutrition'          => $v['calories'] !== '' ? ['@type' => 'NutritionInformation', 'calories' => $v['calories'] . ' calories'] : [],
			'recipeIngredient'   => RichResults::lines($v['ingredients']),
			'recipeInstructions' => array_map(function ($step) {
				return ['@type' => 'HowToStep', 'text' => $step];
			}, RichResults::lines($v['instructions'])),
			'aggregateRating'    => RichResults::aggregate_rating($v['rating_value'], $v['rating_count']),
		];
	}

	private static function event(): array
	{
		$offline = ['field' => 'attendance', 'in' => ['', 'OfflineEventAttendanceMode', 'MixedEventAttendanceMode']];
		$online  = ['field' => 'attendance', 'in' => ['OnlineEventAttendanceMode', 'MixedEventAttendanceMode']];
		$tickets = __('Tickets', 'mihdan-index-now');

		return [
			'label'  => __('Event', 'mihdan-index-now'),
			'fields' => [
				'name'        => ['label' => __('Event name', 'mihdan-index-now'), 'type' => 'text', 'required' => true, 'default' => 'title'],
				'status'      => ['label' => __('Status', 'mihdan-index-now'), 'type' => 'select', 'empty' => __('Scheduled', 'mihdan-index-now'), 'options' => [
					'EventCancelled'   => __('Cancelled', 'mihdan-index-now'),
					'EventPostponed'   => __('Postponed', 'mihdan-index-now'),
					'EventRescheduled' => __('Rescheduled', 'mihdan-index-now'),
					'EventMovedOnline' => __('Moved online', 'mihdan-index-now'),
				]],
				'start'       => ['label' => __('Starts', 'mihdan-index-now'), 'type' => 'datetime', 'required' => true],
				'end'         => ['label' => __('Ends', 'mihdan-index-now'), 'type' => 'datetime'],
				'attendance'  => ['label' => __('Attendance', 'mihdan-index-now'), 'type' => 'select', 'empty' => __('In person', 'mihdan-index-now'), 'options' => [
					'OnlineEventAttendanceMode'  => __('Online', 'mihdan-index-now'),
					'MixedEventAttendanceMode'   => __('In person and online', 'mihdan-index-now'),
				]],
				'venue'       => ['label' => __('Venue name', 'mihdan-index-now'), 'type' => 'text', 'required' => $offline],
				'address'     => ['label' => __('Venue address', 'mihdan-index-now'), 'type' => 'text', 'required' => $offline, 'placeholder' => __('Street, city, postcode, country', 'mihdan-index-now')],
				'online_url'  => ['label' => __('Online event link', 'mihdan-index-now'), 'type' => 'url', 'required' => $online],
				'description' => ['label' => __('Description', 'mihdan-index-now'), 'type' => 'textarea', 'default' => 'description'],
				'image'       => ['label' => __('Image URL', 'mihdan-index-now'), 'type' => 'url', 'default' => 'image', 'hint' => __('Defaults to the featured image.', 'mihdan-index-now')],
				'organizer'   => ['label' => __('Organiser', 'mihdan-index-now'), 'type' => 'text', 'default' => 'organization', 'hint' => __('Defaults to your organisation in Site Information.', 'mihdan-index-now')],
				'performer'   => ['label' => __('Performer or speaker', 'mihdan-index-now'), 'type' => 'text'],
				'price'       => ['label' => __('Price', 'mihdan-index-now'), 'type' => 'number', 'min' => 0, 'group' => $tickets, 'hint' => __('0 for a free event.', 'mihdan-index-now')],
				'currency'    => self::currency_field() + ['group' => $tickets],
				'ticket_url'  => ['label' => __('Ticket link', 'mihdan-index-now'), 'type' => 'url', 'group' => $tickets],
				'valid_from'  => ['label' => __('Tickets on sale from', 'mihdan-index-now'), 'type' => 'datetime', 'group' => $tickets],
			],
			'build'  => [__CLASS__, 'build_event'],
		];
	}

	public static function build_event(array $v, array $c): array
	{
		$mode     = $v['attendance'] !== '' ? $v['attendance'] : 'OfflineEventAttendanceMode';
		$location = [];

		if ($mode !== 'OnlineEventAttendanceMode') {
			$location[] = ['@type' => 'Place', 'name' => $v['venue'], 'address' => RichResults::address($v['address'])];
		}

		if ($mode !== 'OfflineEventAttendanceMode') {
			$location[] = ['@type' => 'VirtualLocation', 'url' => $v['online_url']];
		}

		$offer = RichResults::offer($v['price'], $v['currency'], $v['ticket_url'] !== '' ? $v['ticket_url'] : $c['url']);

		if ($offer) {
			$offer['validFrom'] = RichResults::iso_datetime($v['valid_from']);
		}

		return [
			'@type'               => 'Event',
			'name'                => $v['name'],
			'description'         => $v['description'],
			'image'               => $v['image'],
			'startDate'           => RichResults::iso_datetime($v['start']),
			'endDate'             => RichResults::iso_datetime($v['end']),
			'eventStatus'         => 'https://schema.org/' . ($v['status'] !== '' ? $v['status'] : 'EventScheduled'),
			'eventAttendanceMode' => 'https://schema.org/' . $mode,
			'location'            => count($location) === 1 ? $location[0] : $location,
			'organizer'           => $v['organizer'] !== '' ? ['@type' => 'Organization', 'name' => $v['organizer'], 'url' => $c['defaults']['site']] : [],
			'performer'           => $v['performer'] !== '' ? ['@type' => 'Person', 'name' => $v['performer']] : [],
			'offers'              => $offer,
		];
	}

	private static function video(): array
	{
		return [
			'label'                => __('Video', 'mihdan-index-now'),
			'hint'                 => __('For a page whose main content is a video.', 'mihdan-index-now'),
			'required_any'         => [['content_url'], ['embed_url']],
			'required_any_message' => __('A video file link or an embed link', 'mihdan-index-now'),
			'fields'               => [
				'name'        => ['label' => __('Video title', 'mihdan-index-now'), 'type' => 'text', 'required' => true, 'default' => 'title'],
				'description' => ['label' => __('Description', 'mihdan-index-now'), 'type' => 'textarea', 'default' => 'description'],
				'thumbnail'   => ['label' => __('Thumbnail URL', 'mihdan-index-now'), 'type' => 'url', 'required' => true, 'default' => 'image', 'hint' => __('Defaults to the featured image.', 'mihdan-index-now')],
				'upload_date' => ['label' => __('Upload date', 'mihdan-index-now'), 'type' => 'date', 'required' => true, 'default' => 'date', 'hint' => __('Defaults to the post date.', 'mihdan-index-now')],
				'duration'    => ['label' => __('Length', 'mihdan-index-now'), 'type' => 'duration', 'placeholder' => '12:30', 'hint' => __('Minutes and seconds, or hours:minutes:seconds.', 'mihdan-index-now')],
				'content_url' => ['label' => __('Video file link', 'mihdan-index-now'), 'type' => 'url', 'hint' => __('The URL of the video file itself, e.g. .mp4.', 'mihdan-index-now')],
				'embed_url'   => ['label' => __('Embed link', 'mihdan-index-now'), 'type' => 'url', 'hint' => __('The player URL, e.g. https://www.youtube.com/embed/…', 'mihdan-index-now')],
			],
			'build'                => [__CLASS__, 'build_video'],
		];
	}

	public static function build_video(array $v, array $c): array
	{
		return [
			'@type'        => 'VideoObject',
			'name'         => $v['name'],
			'description'  => $v['description'] !== '' ? $v['description'] : $v['name'],
			'thumbnailUrl' => $v['thumbnail'],
			'uploadDate'   => $v['upload_date'],
			'duration'     => RichResults::iso_duration($v['duration']),
			'contentUrl'   => $v['content_url'],
			'embedUrl'     => $v['embed_url'],
		];
	}

	private static function software(): array
	{
		return [
			'label'  => __('Software app', 'mihdan-index-now'),
			'fields' => [
				'name'         => ['label' => __('App name', 'mihdan-index-now'), 'type' => 'text', 'required' => true, 'default' => 'title'],
				'category'     => ['label' => __('Category', 'mihdan-index-now'), 'type' => 'select', 'options' => [
					'BusinessApplication'      => __('Business', 'mihdan-index-now'),
					'DeveloperApplication'     => __('Developer tools', 'mihdan-index-now'),
					'DesignApplication'        => __('Design', 'mihdan-index-now'),
					'EducationalApplication'   => __('Education', 'mihdan-index-now'),
					'FinanceApplication'       => __('Finance', 'mihdan-index-now'),
					'GameApplication'          => __('Game', 'mihdan-index-now'),
					'HealthApplication'        => __('Health', 'mihdan-index-now'),
					'LifestyleApplication'     => __('Lifestyle', 'mihdan-index-now'),
					'MultimediaApplication'    => __('Multimedia', 'mihdan-index-now'),
					'SecurityApplication'      => __('Security', 'mihdan-index-now'),
					'UtilitiesApplication'     => __('Utilities', 'mihdan-index-now'),
					'WebApplication'           => __('Web app', 'mihdan-index-now'),
				]],
				'os'           => ['label' => __('Operating system', 'mihdan-index-now'), 'type' => 'text', 'placeholder' => __('e.g. Windows, macOS, Android, WordPress', 'mihdan-index-now')],
				'price'        => ['label' => __('Price', 'mihdan-index-now'), 'type' => 'number', 'min' => 0, 'required' => true, 'hint' => __('0 if it is free.', 'mihdan-index-now')],
				'currency'     => self::currency_field(),
				'rating_value' => ['label' => __('Average rating', 'mihdan-index-now'), 'type' => 'number', 'min' => 1, 'max' => 5, 'required' => true, 'group' => __('Rating', 'mihdan-index-now'), 'hint' => __('Out of 5. Google needs a rating for app results. Only use real ratings shown on this page.', 'mihdan-index-now')],
				'rating_count' => ['label' => __('Number of ratings', 'mihdan-index-now'), 'type' => 'number', 'min' => 1, 'required' => true, 'group' => __('Rating', 'mihdan-index-now')],
			],
			'build'  => [__CLASS__, 'build_software'],
		];
	}

	public static function build_software(array $v, array $c): array
	{
		return [
			'@type'               => 'SoftwareApplication',
			'name'                => $v['name'],
			'applicationCategory' => $v['category'],
			'operatingSystem'     => $v['os'],
			'offers'              => RichResults::offer($v['price'], $v['currency']),
			'aggregateRating'     => RichResults::aggregate_rating($v['rating_value'], $v['rating_count']),
		];
	}

	private static function review(): array
	{
		return [
			'label'  => __('Review', 'mihdan-index-now'),
			'hint'   => __('For a page reviewing one thing. Google ignores reviews a business writes about itself.', 'mihdan-index-now'),
			'fields' => [
				'item_type' => ['label' => __('What is reviewed', 'mihdan-index-now'), 'type' => 'select', 'required' => true, 'options' => [
					'Product'             => __('Product', 'mihdan-index-now'),
					'Book'                => __('Book', 'mihdan-index-now'),
					'Course'              => __('Course', 'mihdan-index-now'),
					'Event'               => __('Event', 'mihdan-index-now'),
					'Game'                => __('Game', 'mihdan-index-now'),
					'LocalBusiness'       => __('Local business', 'mihdan-index-now'),
					'Movie'               => __('Movie', 'mihdan-index-now'),
					'Recipe'              => __('Recipe', 'mihdan-index-now'),
					'SoftwareApplication' => __('Software app', 'mihdan-index-now'),
					'CreativeWorkSeason'  => __('TV season', 'mihdan-index-now'),
					'MusicRecording'      => __('Music recording', 'mihdan-index-now'),
				]],
				'item_name' => ['label' => __('Name of what is reviewed', 'mihdan-index-now'), 'type' => 'text', 'required' => true],
				'rating'    => ['label' => __('Your rating', 'mihdan-index-now'), 'type' => 'number', 'min' => 1, 'max' => 5, 'required' => true, 'hint' => __('Out of 5.', 'mihdan-index-now')],
				'author'    => ['label' => __('Reviewer', 'mihdan-index-now'), 'type' => 'text', 'required' => true, 'default' => 'author', 'hint' => __('Defaults to the post author.', 'mihdan-index-now')],
				'body'      => ['label' => __('Summary', 'mihdan-index-now'), 'type' => 'textarea', 'default' => 'description'],
			],
			'build'  => [__CLASS__, 'build_review'],
		];
	}

	public static function build_review(array $v, array $c): array
	{
		return [
			'@type'         => 'Review',
			'itemReviewed'  => ['@type' => $v['item_type'], 'name' => $v['item_name']],
			'reviewRating'  => ['@type' => 'Rating', 'ratingValue' => RichResults::number($v['rating']), 'bestRating' => 5, 'worstRating' => 1],
			'author'        => ['@type' => 'Person', 'name' => $v['author']],
			'reviewBody'    => $v['body'],
			'datePublished' => $c['defaults']['date'],
		];
	}

	/**
	 * Premium types, shown locked until CrawlWP Premium registers them.
	 */
	private static function premium_placeholders(): array
	{
		$types = [
			'Course'        => [__('Course', 'mihdan-index-now'), __('Show your courses in Google\'s course carousel, with the course provider and price.', 'mihdan-index-now')],
			'JobPosting'    => [__('Job posting', 'mihdan-index-now'), __('Show your job openings in Google\'s job search, with salary, location and how to apply.', 'mihdan-index-now')],
			'LocalBusiness' => [__('Local business', 'mihdan-index-now'), __('Describe a branch or location on its own page, with address, phone and opening hours.', 'mihdan-index-now')],
			'Movie'         => [__('Movie', 'mihdan-index-now'), __('Describe a film with its director, release date and rating, for Google\'s movie carousel.', 'mihdan-index-now')],
			'Dataset'       => [__('Dataset', 'mihdan-index-now'), __('Make your data discoverable in Google Dataset Search.', 'mihdan-index-now')],
		];

		$out = [];

		foreach ($types as $id => [$label, $pitch]) {
			$out[$id] = ['label' => $label, 'premium' => true, 'pitch' => $pitch, 'fields' => [], 'build' => null];
		}

		return $out;
	}
}
