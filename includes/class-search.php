<?php
/**
 * Search class.
 */

declare(strict_types=1);

namespace Imageshop\WordPress;

/**
 * Class Search
 */
class Search {

	private $imageshop;
	private $attachment;
	private static $instance;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		if ( Imageshop::get_instance()->onboarding_completed() ) {
			$this->imageshop  = REST_Controller::get_instance();
			$this->attachment = Attachment::get_instance();

			\add_action( 'wp_ajax_query-attachments', array( $this, 'search_media' ), 0 );
			//\add_action( 'wp_ajax_upload-attachment', array( $this, 'search_media' ), 0 );
			\add_filter( 'rest_prepare_attachment', array( $this, 'rest_image_override' ), 10, 2 );
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
	 * Modify the attachment post object response.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Post          $post     The original attachment post.
	 *
	 * @return mixed
	 */
	public function rest_image_override( $response, $post ) {
		if ( 'attachment' !== $post->post_type ) {
			return $response;
		}
		if ( ! $post->_imageshop_document_id ) {
			return $response;
		}

		$media_details = $post->_imageshop_media_sizes;

		if ( empty( $media_details ) ) {
			$att           = Attachment::get_instance();
			$media_details = $att->generate_imageshop_metadata( $post );
		}

		$response->data['media_details'] = $media_details;

		/*
		 * Trigger WP_Cron.
		 *
		 * Any new image sizes that may be needed should have been scheduled at this point
		 * so instructing WordPress to run the cron system should generate image sizes in
		 * the most timely manner for the editorial needs.
		 */
		\spawn_cron();

		return $response;
	}

	/**
	 * Filter out unneccesary Imageshop data when doing a direct WordPress library search.
	 *
	 * @param \WP_Query $query The query being performed.
	 * @return void
	 */
	public function skip_imageshop_items( $query ) {
		$meta = $query->get( 'meta_query' );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$meta[] = array(
			'key'     => '_wp_attached_file',
			'compare' => 'EXISTS',
		);

		$query->set( 'meta_query', $meta );
	}

	/**
	 * Override WordPress normal media search with the Imageshop search behavior.
	 */
	public function search_media() {
		$media = array();

		// If Imageshop isn't the search origin, return early and let something else handle the process.
		if ( isset( $_POST['query']['imageshop_origin'] ) && 'imageshop' !== $_POST['query']['imageshop_origin'] ) {
			\add_action( 'pre_get_posts', array( $this, 'skip_imageshop_items' ) );
			return;
		}

		// Do not process queries for documents or videos, which are not (yet) handled by Imageshop.
		if ( isset( $_POST['query']['post_mime_type'] ) ) {
			if (
				( is_array( $_POST['query']['post_mime_type'] ) && ! in_array( 'image', $_POST['query']['post_mime_type'], true ) ) ||
				( is_string( $_POST['query']['post_mime_type'] ) && 'image' !== strtolower( $_POST['query']['post_mime_type'] ) )
			) {
				\add_action( 'pre_get_posts', array( $this, 'skip_imageshop_items' ) );

				return;
			}
		}

		$search_attributes = array(
			'Pagesize'      => 25,
			'Querystring'   => null,
			'SortDirection' => null,
			'Page'          => null,
			'InterfaceIds'  => null,
			'CategoryIds'   => null,
		);

		if ( isset( $_POST['query']['imageshop_language'] ) && ! empty( $_POST['query']['imageshop_language'] ) ) {
			$this->imageshop->set_language( $_POST['query']['imageshop_language'] );
		}

		$search_attributes = $this->validate_and_assign_search_attributes( $search_attributes, $_POST['query'] );

		$search_results = $this->imageshop->search( $search_attributes );

		\header( 'X-WP-Total: ' . (int) $search_results->NumberOfDocuments ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$search_Results->NumberOfDocuments` is provided by the SaaS API.
		\header( 'X-WP-TotalPages: ' . (int) ceil( ( $search_results->NumberOfDocuments / $search_attributes['Pagesize'] ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$search_results->NumberOfDocuments` and `$search_attributes['Pagesize']` are provided by the SaaS API.

		foreach ( $search_results->DocumentList as $result ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$search_results->DocumentList` is provided by the SaaS API.
			$this->attachment->append_document( $result->DocumentID, $result ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$result->DocumentID` is provided by the SaaS API.
			$media[] = $this->imageshop_pseudo_post( $result, ( isset( $search_attributes['InterfaceIds'] ) ? $search_attributes['InterfaceIds'][0] : null ) );
		}

		\wp_send_json_success( $media );

		\wp_die();
	}

	/**
	 * Validate, and then sanitize, any input passed as search arguments.
	 *
	 * @param array $fields {
	 *     An associative array of search parameters, and their default values.
	 *
	 *     @type string $field         The field name as used in the Imageshop API.
	 *     @type mixed  $default_value The default value, if `null` is passed, the argument will be skipped.
	 * }
	 * @param array $post_request An associative array of `$_POST` request input values that should be mapped.
	 *
	 * @return array
	 */
	public function validate_and_assign_search_attributes( $fields, $post_request ) {
		$attributes = array();

		foreach ( $fields as $field => $default_value ) {
			switch ( $field ) {
				case 'Querystring':
					if ( isset( $post_request['s'] ) ) {
						$attributes[ $field ] = \htmlspecialchars( $post_request['s'] );
					} elseif ( null !== $default_value ) {
						$attributes[ $field ] = $default_value;
					}
					break;
				case 'SortDirection':
					$allowed_values = array( 'DESC', 'ASC' );
					if ( isset( $post_request['order'] ) && in_array( $post_request['order'], $allowed_values, true ) ) {
						$attributes[ $field ] = \htmlspecialchars( $post_request['order'] );
					} elseif ( null !== $default_value ) {
						$attributes[ $field ] = $default_value;
					}
					break;
				case 'Page':
					if ( isset( $post_request['paged'] ) && preg_match( '/^([0-9]*)$/', $post_request['paged'] ) ) {
						// Subtract one, as Imageshop starts with page 0.
						$attributes[ $field ] = ( \absint( $post_request['paged'] ) - 1 );

						// Never allow a negative value for this case.
						if ( $attributes[ $field ] < 0 ) {
							$attributes[ $field ] = 0;
						}
					} elseif ( null !== $default_value ) {
						$attributes[ $field ] = $default_value;
					}
					break;
				case 'Pagesize':
					if ( isset( $post_request['paged'] ) && preg_match( '/^([0-9]*)$/', $post_request['posts_per_page'] ) ) {
						// Subtract one, as Imageshop starts with page 0.
						$attributes[ $field ] = \absint( $post_request['posts_per_page'] );

						// Never allow a value below `1` for this case.
						if ( $attributes[ $field ] < 1 ) {
							$attributes[ $field ] = 1;
						}
					} elseif ( null !== $default_value ) {
						$attributes[ $field ] = $default_value;
					}
					break;
				case 'InterfaceIds':
					if ( isset( $post_request['imageshop_interface'] ) && ! empty( $post_request['imageshop_interface'] ) ) {
						$attributes[ $field ] = array();
						$values               = (array) $post_request['imageshop_interface'];

						foreach ( $values as $value ) {
							if ( preg_match( '/^([0-9]*)$/', $value ) ) {
								$attributes[ $field ][] = \absint( $value );
							}
						}
					} elseif ( null !== $default_value ) {
						$attributes[ $field ] = $default_value;
					}
					break;
				case 'CategoryIds':
					if ( isset( $post_request['imageshop_category'] ) && ! empty( $post_request['imageshop_category'] ) ) {
						$attributes[ $field ] = array();
						$values               = (array) $post_request['imageshop_category'];

						foreach ( $values as $value ) {
							if ( preg_match( '/^([0-9]*)$/', $value ) ) {
								$attributes[ $field ][] = \absint( $value );
							}
						}
					} elseif ( null !== $default_value ) {
						$attributes[ $field ] = $default_value;
					}
					break;
			}
		}

		return $attributes;
	}

	/**
	 * Creates a pseudo-object mirroring what is needed from WP_Post.
	 *
	 * The media searches are returning complete WP_Post objects, so we need to provide the expected data
	 * via our own means to ensure that media searches show up as expected, but with data from the
	 * Imageshop source library instead.
	 *
	 * @param object $media
	 *
	 * @return object
	 */
	private function imageshop_pseudo_post( $media, $interface = null ) {
		if ( null === $interface ) {
			$interface = \get_option( 'imageshop_upload_interface' );
		}

		$wp_post = \get_posts(
			array(
				'posts_per_page' => 1,
				'meta_key'       => '_imageshop_document_id',
				'meta_value'     => $media->DocumentID, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->DocumentID` is provided by the SaaS API.
				'post_type'      => 'attachment',
			)
		);

		$media_file_type = \wp_check_filetype( $media->FileName )['type']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->FileName` is provided by the SaaS API.

		// Fallback handling, if the image format is unknown and returns an empty string.
		if ( empty( $media_file_type ) && isset( $media->IsImage ) && true === \boolval( $media->IsImage ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->IsImage` is provided by the SaaS API.
			/*
			 * Although this is not necessarily the right mime type, it is only used by WordPress to
			 * determine if an image is allowed to be displayed, and Imageshop does conversions
			 * behind the scenes to create JPEG variants for all secondary media formats (such as TIFF, etc.),
			 * making this is a safe fallback.
			 */
			$media_file_type = 'image/jpeg';
		}

		if ( ! $wp_post ) {
			$wp_post_id = \wp_insert_post(
				array(
					'post_type'      => 'attachment',
					'post_title'     => $media->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Name` is provided by the SaaS API.
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_date_gmt'  => \gmdate( 'Y-m-d H:i:s', \strtotime( $media->Created ) ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Created` is provided by the SaaS API.
					'post_mime_type' => $media_file_type,
					'meta_input'     => array(
						'_imageshop_document_id' => $media->DocumentID, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->DocumentID` is provided by the SaaS API.
					),
				)
			);
		} else {
			if ( \is_array( $wp_post ) ) {
				$wp_post_id = $wp_post[0]->ID;
			} else {
				$wp_post_id = $wp_post->ID;
			}
		}

		$caption = Attachment::generate_attachment_caption( $media );

		$original_media = Attachment::get_document_original_file( $media, null );

		if ( null === $original_media ) {
			$original_media = (object) array(
				'Width'         => 0,
				'Height'        => 0,
				'FileSize'      => 0,
				'FileExtension' => 'jpg',
			);
		} elseif ( $original_media && ( 0 === $original_media->Width || 0 === $original_media->Height ) && count( $media->InterfaceList ) >= 1 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Width`, `$original_media->Height`, and `$media->InterfaceList` are provided by the SaaS API.
			$dimensions = $this->attachment->get_original_dimensions( $interface, $original_media );

			$original_media->Width  = $dimensions['width']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Width` is provided by the SaaS API.
			$original_media->Height = $dimensions['height']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Height` is provided by the SaaS API.
		}

		$full_size_url = null;
		$image_sizes   = array();

		if ( $media_file_type && \stristr( $media_file_type, 'image' ) ) {

			$full_size_url = $this->attachment->get_permalink_for_size( $media->DocumentID, $media->FileName, $original_media->Width, $original_media->Height, false ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->DocumentID`, `$media->FileName`, `$original_media->Width`, and `$oreiginal_media->Height` are provided by the SaaS API.

			if ( null !== $full_size_url ) {
				$full_size_url = $full_size_url['source_url'];

				$image_sizes = array(
					'full' => array(
						'url'         => $full_size_url,
						'width'       => $original_media->Width, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Width` is provided by the SaaS API.
						'height'      => $original_media->Height, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Height` is provided by the SaaS API.
						'orientation' => ( $original_media->Height > $original_media->Width ? 'portrait' : 'landscape' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Height` and `$original_media->Width` are provided by the SaaS API.
					),
				);
			}
		}

		$image_sizes = array_merge(
			$image_sizes,
			array(
				'medium'    => array(
					'url' => $media->FullscreenThumbUrl, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->FullscreenThumbUrl` are provided by the SaaS API.
				),
				'thumbnail' => array(
					'url' => $media->DetailThumbUrl, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->DetailThumbUrl` are provided by the SaaS API.
				),
			)
		);

		return (object) array(
			'filename'              => $media->FileName, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->FileName` is provided by the SaaS API.
			'id'                    => $wp_post_id,
			'meta'                  => false,
			'date'                  => strtotime( $media->Created ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Created` is provided by the SaaS API.
			'dateFormatted'         => $media->Created, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Created` is provided by the SaaS API.
			'name'                  => $media->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Name` is provided by the SaaS API.
			'sizes'                 => $image_sizes,
			'compat'                => array(
				'item' => $this->generate_attachment_form_fields( $wp_post_id, $media ),
				'meta' => $this->generate_attachment_meta_fields( $wp_post_id, $media ),
			),
			'status'                => 'inherit',
			'title'                 => $media->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Name` is provided by the SaaS API.
			'url'                   => ( null !== $full_size_url ? $full_size_url : '' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->ListThumbUrl` is provided by the SaaS API.
			'menuOrder'             => 0,
			'alt'                   => $media->Description, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Description` is provided by the SaaS API.
			'description'           => $media->Description, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Description` is provided by the SaaS API.
			'caption'               => $caption,
			'height'                => $original_media->Height, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Height` is provided by the SaaS API.
			'width'                 => $original_media->Width, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Width` is provided by the SaaS API.
			'filesizeInBytes'       => $original_media->FileSize, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->FileSize` is provided by the SaaS API.
			'filesizeHumanReadable' => sprintf(
				'%s (%s)',
				\size_format( $original_media->FileSize ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->FileSize` is provided by the SaaS API.
				__( 'Original image size, different sizes will be presented to site visitors', 'imageshop-dam-connector' )
			),
			'orientation'           => ( $original_media->Height > $original_media->Width ? 'portrait' : 'landscape' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$original_media->Height` and `$original_media->Width` are provided by the SaaS API.
			'type'                  => ( $media->IsImage ? 'image' : ( $media->IsVideo ? 'video' : 'document' ) ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->IsImage` and `$media->IsVideo` are provided by the SaaS API.
		);
	}

	/**
	 * Generate attachment meta values for displaying in the media modal.
	 *
	 * @param int $post_id  The WordPress attachment Post ID.
	 * @param object $media The Imageshop media object.
	 *
	 * @return string
	 */
	private function generate_attachment_meta_fields( $post_id, $media ) {
		$fields = array();

		$no_date_placeholder = sprintf(
			'<em>%s</em>',
			esc_html__( 'No date set', 'imageshop-dam-connector' )
		);

		if ( ! empty( $media->PublishedUntil ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->PublishedUntil` is provided by the SaaS API.
			$fields[] = sprintf(
				'<div class="imageshpo-publish-until"><strong>%s</strong> %s</div>',
				esc_html__( 'Publish until:', 'imageshop-dam-connector' ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $media->PublishedUntil ) ) ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->PublishedUntil` is provided by the SaaS API.
			);
		}

		if ( ! empty( $media->RightsExpiration ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->RightsExpiration` is provided by the SaaS API.
			$fields[] = sprintf(
				'<div class="imageshpo-right-expires"><strong>%s</strong> %s</div>',
				esc_html__( 'Right expires:', 'imageshop-dam-connector' ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $media->RightsExpiration ) ) ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->RightsExpiration` is provided by the SaaS API.
			);
		}

		$fields[] = sprintf(
			'<div class="imageshpo-document-code"><strong>%s</strong> %s</div>',
			esc_html__( 'Imagesshop Document Code:', 'imageshop-dam-connector' ),
			esc_html( $media->Code ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Code` is provided by the SaaS API.
		);

		return implode( "\n", $fields );
	}

	/**
	 * Generate custom form fields and areas for the media modal.
	 *
	 * @param int $post_id  The WordPress attachment Post ID.
	 * @param object $media The Imageshop media object.
	 *
	 * @return string
	 */
	private function generate_attachment_form_fields( $post_id, $media ) {
		$prefix = 'imageshop_';
		$fields = array();
		$output = '';

		$fields['name'] = array(
			'label' => __( 'Name', 'imageshop-dam-connector' ),
			'value' => $media->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Name` is provided by the SaaS API.
		);

		$fields['credits'] = array(
			'label' => __( 'Credits', 'imageshop-dam-connector' ),
			'value' => $media->Credits, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Credits` is provided by the SaaS API.
		);

		$fields['rights'] = array(
			'label' => __( 'Rights', 'imageshop-dam-connector' ),
			'value' => $media->Rights, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Rights` is provided by the SaaS API.
		);

		$fields['description'] = array(
			'label' => __( 'Description', 'imageshop-dam-connector' ),
			'value' => $media->Description, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Description` is provided by the SaaS API.
			'type'  => 'longtext',
		);

		$fields['language'] = array(
			'label'   => __( 'Language', 'imageshop-dam-connector' ),
			'value'   => $this->imageshop->get_language(),
			'type'    => 'select',
			'options' => array(
				'en' => esc_html__( 'English', 'imageshop-dam-connector' ),
				'da' => esc_html__( 'Danish', 'imageshop-dam-connector' ),
				'no' => esc_html__( 'Norwegian', 'imageshop-dam-connector' ),
				'sv' => esc_html__( 'Swedish', 'imageshop-dam-connector' ),
			),
		);

		$fields['tags'] = array(
			'label' => __( 'Tags', 'imageshop-dam-connector' ),
			'value' => $media->Tags, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->Tags` is provided by the SaaS API.
		);

		foreach ( $fields as $key => $field ) {
			$type = ( isset( $field['type'] ) ? $field['type'] : 'text' );

			switch ( $type ) {
				case 'longtext':
					$output .= sprintf(
						'<div class="setting"><label for="%1$s" class="name">%2$s</label><textarea type="text" id="%1$s" name="%1$s">%3$s</textarea></div>',
						esc_attr( $prefix . sanitize_title( $key ) ),
						esc_html( $field['label'] ),
						esc_attr( $field['value'] )
					);
					break;
				case 'select':
					$options = array();

					foreach ( $field['options'] as $slug => $option ) {
						$options[] = sprintf(
							'<option value="%s"%s>%s</option>',
							esc_attr( $slug ),
							selected( $slug, $field['value'], false ),
							esc_html( $option )
						);
					}
					$output .= sprintf(
						'<div class="setting"><label for="%1$s" class="name">%2$s</label><select id="%1$s" name="%1$s">%3$s</select></div>',
						esc_attr( $prefix . sanitize_title( $key ) ),
						esc_html( $field['label'] ),
						implode( "\n", $options )
					);
					break;
				case 'text':
				default:
					$output .= sprintf(
						'<div class="setting"><label for="%1$s" class="name">%2$s</label><input type="text" id="%1$s" name="%1$s" value="%3$s"></div>',
						esc_attr( $prefix . sanitize_title( $key ) ),
						esc_html( $field['label'] ),
						esc_attr( $field['value'] )
					);
			}
		}

		// Only add form submission if fields have been introduced.
		if ( ! empty( $output ) ) {
			$nonce_action = sprintf(
				'imageshop_edit-%d',
				$media->DocumentID // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->DocumentID` is provided by the SaaS API.
			);

			$output .= sprintf(
				'<input type="hidden" name="imageshop_edit-id" value="%d">',
				esc_attr( $media->DocumentID ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$media->DocumentID` is provided by the SaaS API.
			);
			$output .= wp_nonce_field( $nonce_action, 'imageshop_edit-nonce', false, false );
			$output .= wp_nonce_field( 'wp_rest', '_wpnonce', false, false );
			$output .= '<div class="imageshop-edit-form-feedback error"></div>';
			$output .= sprintf(
				'
				<div class="imageshop-edit-toggle-wrapper">
					<button type="button" class="button button-small button-primary imageshop_perform_edit">%s</button>
					<button type="button" class="button button-small imageshop-edit-cancel">%s</button>
				</div>',
				esc_html__( 'Update Imageshop details', 'imageshop-dam-connector' ),
				esc_html__( 'Cancel', 'imageshop-dam-connector' )
			);

			$output = sprintf(
				'
				<div class="imageshop-edit-toggle-wrapper">
					<button type="button" class="button button-small button-link imageshop-flush-cache" data-imageshop-id="%d" data-flush-nonce="%s">%s</button>
					<button type="button" class="button button-small button-link imageshop-edit-toggle-visibility">%s</button>
				</div>
				<div class="imageshop-ajax-generic-feedback notice"></div>
				<div class="imageshop-edit-form">%s</div>',
				esc_attr( $post_id ),
				esc_attr( wp_create_nonce( 'imageshop-flush-cache-' . $post_id ) ),
				esc_html__( 'Regenerate Imageshop cache', 'imageshop-dam-connector' ),
				esc_html__( 'Edit Imageshop details', 'imageshop-dam-connector' ),
				$output
			);
		}

		return $output;
	}
}
