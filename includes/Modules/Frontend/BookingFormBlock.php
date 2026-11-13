<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Forms\Services\FormScope;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The booking form as a block.
 *
 * The block stores nothing but its two attributes and is rendered on the server by the same
 * method as the [plugixa_car_parking] shortcode, so there is one way the wizard is drawn. Its
 * name is in the content of pages already published and never changes.
 *
 * In the editor the block shows that same render, styled by the public stylesheet, and offers
 * the site's forms and locations to choose from.
 */
final class BookingFormBlock {

	public const NAME = 'plugixacarparking/booking-form';

	private const EDITOR_SCRIPT = 'plugixa-car-parking-block';

	public function __construct(
		private readonly Shortcodes $shortcodes,
		private readonly Assets $assets,
		private readonly FormRepository $forms,
		private readonly LocationRepository $locations
	) {}

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$script = 'assets/blocks/booking-form/editor.js';
		$path   = PLUGIXA_CARPARKING_PATH . $script;

		// Written against the wp.* globals, so there is nothing to build.
		wp_register_script(
			self::EDITOR_SCRIPT,
			PLUGIXA_CARPARKING_URL . $script,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			file_exists( $path ) ? (string) filemtime( $path ) : PLUGIXA_CARPARKING_VERSION,
			true
		);

		$this->assets->register_style();

		register_block_type(
			self::NAME,
			array(
				'api_version'          => 3,
				'title'                => __( 'Car Parking Booking Form', 'plugixa-car-parking' ),
				'description'          => __( 'A form visitors book a parking space with.', 'plugixa-car-parking' ),
				'category'             => 'widgets',
				'icon'                 => 'car',
				'editor_script'        => self::EDITOR_SCRIPT,
				// The editor only. On the site the stylesheet is enqueued by the render itself,
				// so it loads on the pages the form is on and on no others.
				'editor_style_handles' => array( Assets::STYLE ),
				'render_callback'      => array( $this, 'render' ),
				// Wide and full width, so a theme with a narrow content column can still give the
				// form the room its two-column designs need.
				'supports'             => array( 'align' => array( 'wide', 'full' ) ),
				'attributes'           => array(
					'location' => array(
						'type'    => 'string',
						'default' => '',
					),
					'form'     => array(
						'type'    => 'string',
						'default' => '',
					),
					// Empty: drawn in the look the form chose.
					'design'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'align'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Hand the editor script the forms and locations the block's controls list.
	 */
	public function editor_data(): void {
		wp_localize_script( self::EDITOR_SCRIPT, 'PlugixaCarParkingBlock', $this->choices() );
	}

	/**
	 * The forms and locations a page can be given.
	 *
	 * Each form carries the location ids it books at (empty for all of them), and
	 * `defaultLocations` is the default form's, which a block that names no form is shown. The
	 * editor narrows its Location list to these, so a block is not given a location its form
	 * would ignore.
	 *
	 * @return array{forms: array<int, array{value:string,label:string,locations:int[]}>, locations: array<int, array{value:string,label:string,id:int}>, defaultLocations: int[]}
	 */
	public function choices(): array {
		$forms = array();

		foreach ( $this->forms->query()->where( 'status = %s', array( 'active' ) )->order_by( 'name', 'ASC' )->paginate( 200, 0 )->get() as $form ) {
			$forms[] = array(
				'value'     => (string) ( $form['slug'] ?? '' ),
				'label'     => (string) ( $form['name'] ?? $form['slug'] ?? '' ),
				'locations' => FormScope::location_ids( $form ),
			);
		}

		$locations = array();

		foreach ( $this->locations->active() as $location ) {
			$locations[] = array(
				'value' => (string) ( $location['slug'] ?? $location['id'] ),
				'label' => (string) $location['name'],
				'id'    => (int) $location['id'],
			);
		}

		return array(
			'forms'            => $forms,
			'locations'        => $locations,
			'defaultLocations' => FormScope::location_ids( $this->forms->default_form() ?? array() ),
		);
	}

	/**
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public function render( $attributes ): string {
		$html = $this->shortcodes->render(
			array(
				'location' => $attributes['location'] ?? '',
				'form'     => $attributes['form'] ?? '',
				'design'   => $attributes['design'] ?? '',
			)
		);

		// A block set to wide or full is wrapped in the class the theme widens; one that is not
		// is printed exactly as the shortcode prints it.
		$align = (string) ( $attributes['align'] ?? '' );
		if ( '' === $html || ! in_array( $align, array( 'wide', 'full' ), true ) ) {
			return $html;
		}

		return '<div class="wp-block-plugixacarparking-booking-form align' . esc_attr( $align ) . '">' . $html . '</div>';
	}
}
