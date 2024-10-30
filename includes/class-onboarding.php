<?php
/**
 * The Onboarding class.
 */

declare(strict_types=1);

namespace Imageshop\WordPress;

/**
 * Class Onboarding
 */
class Onboarding {
	private static $instance;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		if ( ! Imageshop::get_instance()->onboarding_completed() ) {
			\add_action( 'admin_notices', array( $this, 'onboarding_notice' ) );
			\add_action( 'admin_enqueue_scripts', array( $this, 'onboarding_styles' ) );
			\add_action( 'rest_api_init', array( $this, 'onboarding_rest_endpoints' ) );
		}
	}

	/**
	 * Return a singleton instance of this class.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Capability-permission callback for the onboarding rest endpoint.
	 *
	 * @return bool
	 */
	public function user_can_setup_plugin(): bool {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * Register WordPress REST API endpoints.
	 *
	 * @return void
	 */
	public function onboarding_rest_endpoints() {
		\register_rest_route(
			'imageshop/v1',
			'onboarding/token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_test_token' ),
				'permission_callback' => array( $this, 'user_can_setup_plugin' ),
			)
		);

		\register_rest_route(
			'imageshop/v1',
			'onboarding/interfaces',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_interfaces' ),
					'permission_callback' => array( $this, 'user_can_setup_plugin' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_set_interface' ),
					'permission_callback' => array( $this, 'user_can_setup_plugin' ),
				),
			)
		);

		\register_rest_route(
			'imageshop/v1',
			'onboarding/import',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_import_media_start' ),
					'permission_callback' => array( $this, 'user_can_setup_plugin' ),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_import_media_status' ),
					'permission_callback' => array( $this, 'user_can_setup_plugin' ),
				),
			)
		);

		\register_rest_route(
			'imageshop/v1',
			'onboarding/completed',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_completed' ),
				'permission_callback' => array( $this, 'user_can_setup_plugin' ),
			)
		);
	}

	/**
	 * Register that the onboarding has been completed.
	 *
	 * @return \WP_REST_Response
	 */
	function rest_completed() {
		\update_option( 'imageshop_onboarding_completed', true );

		return new \WP_REST_Response( true, 200 );
	}

	/**
	 * Return the current status of exporting media from WordPress to Imageshop.
	 *
	 * @return \WP_REST_Response
	 */
	function rest_import_media_status() {
		$sync = Sync::get_instance();

		return new \WP_REST_Response(
			$sync->get_media_import_status(),
			200
		);
	}

	/**
	 * Start the export process of media from WordPress to Imageshop.
	 *
	 * @return \WP_REST_Response
	 */
	function rest_import_media_start() {
		$sync = Sync::get_instance();

		return $sync->sync_remote();
	}

	/**
	 * Test and validate the Imageshgop API token provided.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_test_token( \WP_REST_Request $request ) {
		$token = $request->get_param( 'token' );

		$imageshop = new REST_Controller( $token );

		if ( $imageshop->test_valid_token() ) {
			\update_option( 'imageshop_api_key', $token );

			return new \WP_REST_Response(
				array(
					'valid'   => true,
					'message' => 'The token is valid',
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'valid'   => false,
				'message' => 'The token is not valid, or no user found',
			),
			400
		);
	}

	/**
	 * Get the available Imageshop interfaces for the current API user.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_interfaces() {
		$imageshop = REST_Controller::get_instance();

		return new \WP_REST_Response(
			array(
				'interfaces' => $imageshop->get_interfaces( true ),
			),
			200
		);
	}

	/**
	 * Set the default Imageshop interface for this site.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return void
	 */
	public function rest_set_interface( \WP_REST_Request $request ) {
		$interface = $request->get_param( 'interface' );

		\update_option( 'imageshop_upload_interface', $interface );
	}

	/**
	 * Enqueue the onboarding scripts and styles.
	 *
	 * @return void
	 */
	public function onboarding_styles() {
		if ( ! $this->user_can_setup_plugin() ) {
			return;
		}

		$asset = require_once IMAGESHOP_ABSPATH . '/build/onboarding.asset.php';

		\wp_enqueue_style( 'imageshop-onboarding', \plugins_url( 'build/onboarding.css', IMAGESHOP_PLUGIN_BASE_NAME ), array(), $asset['version'] );
		\wp_enqueue_script(
			'imageshop-onboarding',
			\plugins_url( 'build/onboarding.js', IMAGESHOP_PLUGIN_BASE_NAME ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}

	/**
	 * Output the onbaording notice to the user.
	 *
	 * @return void
	 */
	public function onboarding_notice() {
		if ( ! $this->user_can_setup_plugin() ) {
			return;
		}
		?>

		<div id="imageshop-onboarding"></div>

		<?php
	}
}
