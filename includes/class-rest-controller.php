<?php
/**
 * REST Controller class.
 */

declare( strict_types = 1 );

namespace Imageshop\WordPress;

/**
 * Class REST_Controller
 */
class REST_Controller {
	private const IMAGESHOP_API_BASE_URL               = 'https://api.imageshop.no';
	private const IMAGESHOP_CDN_PREFIX                 = '	https://v.imgi.no';
	private const IMAGESHOP_API_CAN_UPLOAD             = '/Login/CanUpload';
	private const IMAGESHOP_API_WHOAMI                 = '/Login/WhoAmI';
	private const IMAGESHOP_API_CREATE_DOCUMENT        = '/Document/CreateDocument';
	private const IMAGESHOP_API_GET_DOCUMENT           = '/Document/GetDocumentById';
	private const IMAGESHOP_API_DOWNLOAD               = '/Download';
	private const IMAGESHOP_API_GET_PERMALINK          = '/Permalink/GetPermalink';
	private const IMAGESHOP_API_CREATE_PERMALINK       = '/Permalink/CreatePermaLink2';
	private const IMAGESHOP_API_GET_ORIGINAL_PERMALINK = '/Permalink/CreatePermalinkFromOriginal';
	private const IMAGESHOP_API_GET_INTERFACE          = '/Interface/GetInterfaces';
	private const IMAGESHOP_API_GET_SEARCH             = '/Search2';
	private const IMAGESHOP_API_GET_CATEGORIES         = '/Category/GetCategoriesTree';
	private const IMAGESHOP_API_GET_DOCUMENT_LINK      = '/Document/GetDocumentLink';
	private const IMAGESHOP_API_DELETE_DOCUMENT        = '/Document/DeleteDocument';
	private const IMAGESHOP_API_GET_PERMALINK_URL      = '/Permalink/CreatePermaLinks';
	private const IMAGESHOP_API_SET_METADATA           = '/Document/SetMetadata';

	/**
	 * @var REST_Controller
	 */
	private static $instance;

	/**
	 * @var string
	 */
	private string $api_token;

	/**
	 * @var string
	 */
	private string $language = 'en';

	/**
	 * @var array
	 */
	private $queued_permalinks = array();

	public $interfaces;


	/**
	 * Class constructor.
	 *
	 * @param string $api_token Optional. An Imageshop API token.
	 */
	public function __construct( $token = null ) {
		if ( null !== $token ) {
			$this->api_token = $token;
		} else {
			$this->api_token = \get_option( 'imageshop_api_key', '' );
		}

		// If WordPress' language function is available, attempt to set the active language.
		if ( function_exists( 'get_user_locale' ) ) {
			$this->set_language( \get_locale() );
		}

		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Class destructor.
	 *
	 * Runs bulk operations that have been collected to reduce network load.
	 */
	public function __destruct() {
		if ( ! empty( $this->queued_permalinks ) ) {
			$this->generate_permalinks_url( $this->queued_permalinks );
		}
	}

	/**
	 * Set the active language used for locale-related API responses.
	 *
	 * @param string $locale The locale to use for lookups.
	 *
	 * @return void
	 */
	public function set_language( $locale ) {
		$language_code = '';

		// Short locale formats of 2 characters are in the ISO-639-1 format, which is expected by the Imageshop API.
		if ( 2 === strlen( $locale ) ) {
			$language_code = strtolower( $locale );
		} else {
			// The fallback is just a basic pattern matcher for manually defined supported languages.
			$available_locales = Imageshop::available_locales();

			foreach ( $available_locales as $code => $attributes ) {
				foreach ( $attributes['iso_codes'] as $iso => $type ) {
					switch ( $type ) {
						case 'string':
							if ( $iso === $locale ) {
								$language_code = $code;
							}
							break;
						case 'regex':
							if ( preg_match( $iso, $locale ) ) {
								$language_code = $code;
							}
							break;
					}
				}
			}
		}

		// Default language code fallback for API requests.
		if ( empty( $language_code ) ) {
			$language_code = 'en';
		}

		$this->language = $language_code;
	}

	/**
	 * Get the language code currently in use for API requests.
	 *
	 * @return string
	 */
	public function get_language() {
		return $this->language;
	}

	/**
	 * Register WordPress REST API endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			'imageshop/v1',
			'/categories/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_categories' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function( $param ) {
							return \is_numeric( $param ) || 'all' === $param;
						},
					),
				),
				'permission_callback' => function() {
					return \current_user_can( 'upload_files' );
				},
			)
		);
	}

	/**
	 * WordPress REST API endpoint for getting available Imageshop categories.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_categories( \WP_REST_Request $request ) {
		$interface = $request->get_param( 'id' );

		$categories = $this->get_categories( $interface );

		return new \WP_REST_Response( $categories, 200 );
	}

	/**
	 * A collection of headers to be used with Imageshop API requests.
	 *
	 * @return array
	 */
	public function get_headers(): array {
		return array(
			'Accept'       => 'application/json',
			'token'        => $this->api_token,
			'Content-Type' => 'application/json',
		);
	}

	/**
	 * Perform a request against the Imageshop API.
	 *
	 * @param string $url  The API endpoint to request.
	 * @param array  $args A JSON encoded string of arguments for the API call.
	 *
	 * @return array|mixed
	 */
	public function execute_request( string $url, array $args ) {
		try {
			$response      = \wp_remote_request( $url, $args );
			$response_code = \wp_remote_retrieve_response_code( $response );

			if ( ! \in_array( $response_code, array( 200, 201 ), true ) ) {
				return array(
					'code'    => \wp_remote_retrieve_response_code( $response ),
					'message' => \wp_remote_retrieve_response_message( $response ),
				);
			}

			return \json_decode( \wp_remote_retrieve_body( $response ) );
		} catch ( \Exception $e ) {
			return array(
				'code'    => $e->getCode(),
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Validate that the current API user can upload files to Imageshop.
	 *
	 * @return mixed|void
	 */
	public function can_upload() {
		$args = array(
			'method'  => 'GET',
			'headers' => $this->get_headers(),
		);

		return $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_CAN_UPLOAD, $args );
	}

	/**
	 * Create a new document with Imageshop.
	 *
	 * Creating a document is the same as uploading a file, and pushes a base64 encoded
	 * version of the file to the Imageshop services for processing.
	 *
	 * @param string $b64_file_content Base64 encoded file content.
	 * @param string $name             Name of the file.
	 *
	 * @return array|mixed
	 */
	public function create_document( $b64_file_content, $name ) {
		$pyload = array(
			'bFile'         => $b64_file_content,
			'fileName'      => \str_replace( '/', '_', $name ),
			'interfaceName' => \get_option( 'imageshop_upload_interface' ),
			'doc'           => array(
				'Active'     => true,
				'interfaces' => array(
					array(
						'id' => \get_option( 'imageshop_upload_interface' ),
					),
				),
			),
		);

		$args = array(
			'method'  => 'POST',
			'headers' => $this->get_headers(),
			'body'    => \wp_json_encode( $pyload ),
		);

		return $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_CREATE_DOCUMENT, $args );
	}

	/**
	 * Get the permalink token for an image on the Imageshop CDN.
	 *
	 * @param int    $document_id     The Imageshop document ID.
	 * @param int    $width           The width of the image.
	 * @param int    $height          The height of the image.
	 * @param string $permalink_token Optional. The token used for the permalink URL
	 *
	 * @return mixed
	 */
	public function get_permalink_token( $document_id, $width, $height, $permalink_token = null ) {
		$payload = array(
			'language'        => $this->language,
			'documentid'      => $document_id,
			'cropmode'        => 'ZOOM',
			'width'           => $width,
			'height'          => $height,
			'x1'              => 0,
			'y1'              => 0,
			'x2'              => 100,
			'y2'              => 100,
			'previewwidth'    => 100,
			'previewheight'   => 100,
			'optionalurlhint' => \site_url( '/' ),
		);

		// A permalink token may not always be supplied, so append it where needed.
		if ( null !== $permalink_token ) {
			$payload['permalinktoken'] = sprintf(
				'%s-%dx%d',
				$permalink_token,
				$width,
				$height
			);
		}

		$payload_hash = \md5( \wp_json_encode( $payload ) );

		$ret = \get_transient( 'imageshop_permalink_' . $payload_hash );

		if ( false === $ret ) {
			$args = array(
				'method'  => 'POST',
				'headers' => $this->get_headers(),
				'body'    => \wp_json_encode( $payload ),
			);
			$ret  = $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_PERMALINK, $args );

			\set_transient( 'imageshop_permalink_' . $payload_hash, $ret );
		}

		return $ret->permalinktoken;
	}

	/**
	 * Get the permalink for an image on the Imageshop CDN.
	 *
	 * @param int    $document_id     The Imageshop document ID.
	 * @param int    $width           The width of the image.
	 * @param int    $height          The height of the image.
	 * @param string $permalink_token Optional. The token used for the permalink URL
	 *
	 * @return mixed
	 */
	public function get_permalink( $document_id, $width, $height, $permalink_token = null ) {
		$payload = array(
			'language'        => $this->language,
			'documentid'      => $document_id,
			'cropmode'        => 'ZOOM',
			'width'           => $width,
			'height'          => $height,
			'x1'              => 0,
			'y1'              => 0,
			'x2'              => 100,
			'y2'              => 100,
			'previewwidth'    => 100,
			'previewheight'   => 100,
			'optionalurlhint' => \site_url( '/' ),
		);

		// A permalink token may not always be supplied, so append it where needed.
		if ( null !== $permalink_token ) {
			$payload['permalinktoken'] = sprintf(
				'%s-%dx%d',
				$permalink_token,
				$width,
				$height
			);
		}

		$payload_hash = \md5( \wp_json_encode( $payload ) );

		$ret = \get_transient( 'imageshop_permalink_' . $payload_hash );

		if ( false === $ret ) {
			$args = array(
				'method'  => 'POST',
				'headers' => $this->get_headers(),
				'body'    => \wp_json_encode( $payload ),
			);
			$ret  = $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_CREATE_PERMALINK, $args );

			\set_transient( 'imageshop_permalink_' . $payload_hash, $ret );
		}

		return $ret->url;
	}

	/**
	 * Generate permalinks for the already prepared links from `self::create_peramlinks_url`.
	 *
	 * @param array $payloads An array of payloads to request asynchronous permalinks for at once.
	 *
	 * @return void
	 */
	public function generate_permalinks_url( $payloads ) {
		$payload = $payloads;

		$args = array(
			'method'  => 'POST',
			'headers' => $this->get_headers(),
			'body'    => \wp_json_encode( $payload ),
		);
		$this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_PERMALINK_URL, $args );
	}

	/**
	 * Generate the link that will hold our image ahead of time.
	 *
	 * @param int    $document_id     The Imageshop document ID.
	 * @param int    $width           The width of the image.
	 * @param int    $height          The height of the image.
	 * @param string $permalink_token The generated GUID for this permalink.
	 *
	 * @return string
	 */
	public function create_permalinks_url( $document_id, $width, $height, $permalink_token ) {
		$generated_uuid = sprintf(
			'%s-%dx%d',
			$permalink_token,
			$width,
			$height
		);

		$this->queued_permalinks[] = array(
			'language'        => $this->language,
			'documentid'      => $document_id,
			'width'           => $width,
			'height'          => $height,
			'cropmode'        => 'ZOOM',
			'x1'              => 0,
			'y1'              => 0,
			'x2'              => 100,
			'y2'              => 100,
			'previewwidth'    => 100,
			'previewheight'   => 100,
			'optionalurlhint' => \site_url( '/' ),
			'permalinktoken'  => $generated_uuid,
		);

		return sprintf(
			'%s/%s',
			\untrailingslashit( self::IMAGESHOP_CDN_PREFIX ),
			$generated_uuid
		);
	}

	/**
	 * Return a permalink to the original image.
	 *
	 * @param int $document_id Imageshop Document ID.
	 *
	 * @return string
	 */
	public function get_original_permalink( $document_id ) {
		$url = \add_query_arg(
			array(
				'language'   => $this->language,
				'documentId' => $document_id,
			),
			self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_ORIGINAL_PERMALINK
		);

		$payload_hash = \md5( \wp_json_encode( $url ) );

		$ret = \get_transient( 'imageshop_original_permalink_' . $payload_hash );

		if ( false === $ret ) {
			$args = array(
				'method'  => 'GET',
				'headers' => $this->get_headers(),
			);

			$ret = $this->execute_request( $url, $args );

			if ( ! empty( $ret ) ) {
				\set_transient( 'imageshop_original_permalink_' . $payload_hash, $ret );
			}
		}

		return $ret;
	}

	/**
	 * Return a list of interfaces available to the given API user.
	 *
	 * @param bool $ignore_cache Default `false`. Define if the cached interfaces should be ignored, and a
	 *                           fresh copy be gotten via the API.
	 * @return array
	 */
	public function get_interfaces( $ignore_cache = false ) {
		if ( empty( $this->interfaces ) ) {
			$interfaces = \get_transient( 'imageshop_interfaces' );

			if ( false === $interfaces || $ignore_cache ) {
				$args    = array(
					'method'  => 'GET',
					'headers' => $this->get_headers(),
				);
				$request = $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_INTERFACE, $args );

				if ( \is_wp_error( $request ) ) {
					return $this->interfaces;
				}

				$interfaces = $request;

				\set_transient( 'imageshop_interfaces', $interfaces, HOUR_IN_SECONDS );
			}

			$this->interfaces = $interfaces;
		}

		return $this->interfaces;
	}

	/**
	 * Get an interface by its ID.
	 *
	 * @param int $id
	 *
	 * @return object|null
	 */
	public function get_interface_by_id( $id ) {
		$interfaces = $this->get_interfaces();

		foreach ( $interfaces as $interface ) {
			if ( $id === $interface->Id ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$interface->Id` is provided by the SaaS API.
				return $interface;
			}
		}

		return null;
	}

	/**
	 * Get an interface by its name.
	 *
	 * @param string $name
	 *
	 * @return object|null
	 */
	public function get_interface_by_name( $name ) {
		$interfaces = $this->get_interfaces();

		foreach ( $interfaces as $interface ) {
			if ( $name === $interface->Name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$interface->Name` is provided by the SaaS API.
				return $interface;
			}
		}

		return null;
	}

	/**
	 * Get a list of available categories for the given interface and language.
	 *
	 * @param int|null $interface The interface to return categories from.
	 * @param string   $lang      The language to return categories for.
	 *
	 * @return array
	 */
	public function get_categories( $interface = null, $lang = null ) {
		if ( null === $interface ) {
			$interface = \get_option( 'imageshop_upload_interface' );
		}
		if ( null === $lang ) {
			$lang = $this->language;
		}

		$transient_key = 'imageshop_categories_' . $interface . '_' . $lang;

		$categories = \get_transient( $transient_key );

		if ( false === $categories ) {
			$args    = array(
				'method'  => 'GET',
				'headers' => $this->get_headers(),
			);
			$request = $this->execute_request(
				\add_query_arg(
					array(
						'interfacename' => $interface,
						'language'      => $lang,
					),
					self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_CATEGORIES
				),
				$args
			);

			if ( \is_wp_error( $request ) ) {
				return array();
			}

			$categories = $request;

			\set_transient( $transient_key, $categories, HOUR_IN_SECONDS );
		}

		return $categories->Root->Children; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$categories->Root->Children` is provided by the SaaS API.
	}

	/**
	 * Perform a document search on the Imageshop service.
	 *
	 * @param array $attributes An array of search criteria.
	 *
	 * @return array
	 */
	public function search( array $attributes ) {
		$interface_ids  = array();
		$interface_list = $this->get_interfaces();

		foreach ( $interface_list as $interface ) {
			$interface_ids[] = $interface->Id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$interface->Id` is defined by the third party SaaS API.
		}

		// set default search attributes
		$attributes = \array_merge(
			array(
				'InterfaceIds'  => $interface_ids,
				'Language'      => $this->language,
				'Querystring'   => '',
				'Page'          => 0,
				'Pagesize'      => 80,
				'DocumentType'  => array( 'IMAGE' ),
				'SortBy'        => 'DEFAULT',
				'SortDirection' => 'DESC',
			),
			$attributes
		);

		$args    = array(
			'method'  => 'POST',
			'headers' => $this->get_headers(),
			'body'    => \wp_json_encode( $attributes ),
		);
		$results = $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_SEARCH, $args );

		if ( \is_wp_error( $results ) ) {
			return array();
		}

		return $results;
	}

	/**
	 * Get the details of a document from Imageshop by its ID.
	 *
	 * @param int $id The Imageshop document ID.
	 *
	 * @return array|mixed|object
	 */
	public function get_document( $id ) {
		$url  = \add_query_arg(
			array(
				'language'   => $this->language,
				'DocumentID' => $id,
			),
			self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_DOCUMENT
		);
		$args = array(
			'method'  => 'GET',
			'headers' => $this->get_headers(),
		);

		$ret = $this->execute_request( $url, $args );

		if ( \is_wp_error( $ret ) ) {
			return (object) array();
		}

		return $ret;
	}

	/**
	 * Get a sub-documents temporary URL for downloading a file off S3.
	 *
	 * This is a fail-safe method, used to grab an original image, and generate the file sizes when they,
	 * for whatever reason, are missing form the primary media object.
	 *
	 * @param string $interface_name    Name of the interface the media belongs to.
	 * @param string $sub_document_path The sub document path for the specific media size.
	 *
	 * @return string|null
	 */
	public function get_document_link( $interface_name, $sub_document_path ) {
		$url = \add_query_arg(
			array(
				'interfacename'   => $interface_name,
				'subdocumentpath' => $sub_document_path,
			),
			self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_GET_DOCUMENT_LINK
		);

		$args = array(
			'method'  => 'GET',
			'headers' => $this->get_headers(),
		);

		$ret = $this->execute_request( $url, $args );

		if ( \is_wp_error( $ret ) ) {
			return null;
		}

		return $ret;
	}

	/**
	 * Delete an attachment from the Imageshop database.
	 *
	 * This is a destructive action, and as such is not enabled on accounts by default.
	 * If there are needs for this feature, please have it unlocked by your Imageshop
	 * contact first.
	 *
	 * @param int $document_id Imageshop ID of the document to delete.
	 *
	 * @return \WP_Error|string
	 */
	public function delete_document( $document_id ) {
		$url = \add_query_arg(
			array(
				'documentId' => $document_id,
			),
			self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_DELETE_DOCUMENT
		);

		$args = array(
			'method'  => 'GET',
			'headers' => $this->get_headers(),
		);

		$ret = $this->execute_request( $url, $args );

		return $ret;
	}

	/**
	 * Test if the active API token is valid.
	 *
	 * @return bool
	 */
	public function test_valid_token() {

		$args = array(
			'method'  => 'GET',
			'headers' => $this->get_headers(),
		);

		$ret = $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_WHOAMI, $args );

		return ( ! \is_wp_error( $ret ) && ! isset( $ret['code'] ) && ! empty( $ret ) );
	}

	/**
	 * Return a singleton instance of this class.
	 *
	 * @return self
	 */
	public static function get_instance(): REST_Controller {
		if ( ! self::$instance ) {
			self::$instance = new REST_Controller();
		}

		return self::$instance;
	}

	/**
	 * Request a download source for an Imageshop document.
	 *
	 * @param int $document_id The ID of the document to be downloaded.
	 *
	 * @return array|mixed
	 */
	public function download( $document_id ) {
		$payload = array(
			'DocumentId'           => $document_id,
			'Quality'              => 'OriginalFile',
			'DownloadAsAttachment' => false,
		);
		$args    = array(
			'method'  => 'POST',
			'headers' => $this->get_headers(),
			'body'    => \wp_json_encode( $payload ),
		);

		return $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_DOWNLOAD, $args );
	}

	public function update_meta( $document_id, $payload ) {
		$payload = array_merge(
			array(
				'DocumentId' => $document_id,
			),
			$payload
		);

		$args = array(
			'method'  => 'PUT',
			'headers' => $this->get_headers(),
			'body'    => \wp_json_encode( $payload ),
		);

		return $this->execute_request( self::IMAGESHOP_API_BASE_URL . self::IMAGESHOP_API_SET_METADATA, $args );
	}
}
