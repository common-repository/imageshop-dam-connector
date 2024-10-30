<?php
/**
 * WP-CLI command structure.
 */

declare(strict_types=1);

namespace Imageshop\WordPress\CLI;

use Imageshop\WordPress\Attachment;
use Imageshop\WordPress\REST_Controller;

class Meta {

	private $verbose = false;

	private $delay = 5;

	public function __construct() {

	}

	/**
	 * Update the metadata for an attachment.
	 *
	 * This will allow you to refresh, or add, the meta value references for media between WordPress and
	 * the Imageshop SaaS.
	 *
	 * By default, when performing mass-operations, you will need to wait 5 seconds
	 * between each activity.
	 *
	 * ## OPTIONS
	 *
	 * [<ID>]
	 * : The Post ID for the attachment.
	 *
	 * [--all]
	 * : Update the metadata of all media attachments.
	 *
	 * [--verbose]
	 * : Provide more verbose details during export operations.
	 *
	 * [--reduced-delay]
	 * : Only wait 2 seconds between each operation when mass-updating meta values.
	 *
	 * [--no-delay]
	 * : Remove the delay between operations when mass-updating meta values.
	 *
	 * ## EXAMPLES
	 *
	 *      # Update the metadata for the attachment with ID 123
	 *      $ wp imageshop meta update 123
	 *      Success: The metadata for the attachment has been updated.
	 *
	 *      # Update the metadata for all attachments
	 *      $ wp imageshop meta update --all
	 *      Success: All metadata has been updated.
	 *
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {
		if ( isset( $assoc_args['verbose'] ) ) {
			$this->verbose = true;
		}

		if ( isset( $assoc_args['reduced-delay'] ) ) {
			$this->delay = 2;
		}

		if ( isset( $assoc_args['no-delay'] ) ) {
			$this->delay = 0;
		}

		if ( isset( $assoc_args['all'] ) ) {
			return $this->all();
		}

		if ( ! isset( $args[0] ) ) {
			\WP_CLI::error( 'Please provide a Post ID for your attachment.' );
			return false;
		}

		if ( ! \ctype_digit( $args[0] ) ) {
			\WP_CLI::error( sprintf( 'The supplied Post ID, `%s`, is not a valid number.', $args[0] ) );
			return false;
		}

		$this->single( $args[0] );
	}

	private function single( $id ) {
		$attachment = get_post( $id );

		if ( ! $attachment || \is_wp_error( $attachment ) ) {
			\WP_CLI::error( sprintf( 'Could not find any post with the ID `%d`', $id ) );
			return false;
		}

		if ( 'attachment' !== $attachment->post_type ) {
			\WP_CLI::error( sprintf( 'The post with ID `%d` is not an attachment, but is instead marked as a `%s`', $attachment->ID, $attachment->post_type ) );
			return false;
		}

		$imageshop_attachment = Attachment::get_instance();

		$this->validate_file( $id );

		$media_details = $imageshop_attachment->generate_imageshop_metadata( $attachment );

		if ( empty( $media_details['sizes'] ) ) {
			\WP_CLI::warning( 'The metadata update request was sent, but had no data returned, is this a valid media item?' );
			return false;
		}

		\WP_CLI::success( sprintf( 'Metadata has been updated, and now contains a total of %d entries.', count( $media_details['sizes'] ) ) );
		return true;
	}

	private function all() {
		global $wpdb;

		$imageshop_attachment = Attachment::get_instance();

		$attachments = $wpdb->get_results(
			"
			SELECT
		       DISTINCT( p.ID )
			FROM
				{$wpdb->posts} AS p
		    LEFT JOIN
			    {$wpdb->postmeta} AS pm
			        ON (p.ID = pm.post_id)
			WHERE
				p.post_type = 'attachment'
			AND
			(
				pm.meta_key = '_imageshop_document_id'
				AND
				(
				    pm.meta_value IS NOT NULL
				        OR
				    pm.meta_value != ''
			    )
			)
			AND
		        EXISTS (
		            SELECT
						1
					FROM
					     {$wpdb->postmeta} as spm
		            WHERE
						spm.post_id = p.ID
					AND
				        spm.meta_key = '_wp_attached_file'
				    AND
					(
					    spm.meta_value IS NOT NULL
					        AND
					    spm.meta_value != ''
					)
				)
		    "
		);

		if ( empty( $attachments ) ) {
			\WP_CLI::warning( 'No attachments with an Imageshop connection were found.' );
			return false;
		}

		\WP_CLI::log( sprintf( 'Starting meta update for %d attachments...', count( $attachments ) ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Updating metadata', count( $attachments ) );

		foreach ( $attachments as $attachment ) {
			if ( $this->verbose ) {
				\WP_CLI::log( sprintf( 'Processing attachment with ID %d', $attachment->ID ) );
			}

			$this->validate_file( $attachment->ID );

			$imageshop_attachment->generate_imageshop_metadata( get_post( $attachment->ID ) );

			if ( 0 !== $this->delay ) {
				sleep( $this->delay );
			}

			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success( 'All metadata has been updated' );
		return true;
	}

	private function validate_file( $post_id ) {
		$imageshop     = Attachment::get_instance();
		$imageshop_api = REST_Controller::get_instance();
		$imageshop_id  = get_post_meta( $post_id, '_imageshop_document_id', true );

		if ( empty( $imageshop_id ) ) {
			if ( $this->verbose ) {
				\WP_CLI::log( 'Missing Imageshop reference for the attachment, exporting now...' );
			}

			$imageshop->export_to_imageshop( (int) $post_id, true );
		} else {
			$validate = $imageshop_api->get_document( $imageshop_id );

			if ( empty( $validate ) || ! isset( $validate->SubDocumentList ) ) { // / phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$validate->SubDocumentList` are provided by the SaaS API.
				if ( $this->verbose ) {
					\WP_CLI::log( sprintf( 'No valid media reference returned from Imageshop under attachment ID %d, regenerating...', $imageshop_id ) );
				}

				$imageshop->export_to_imageshop( (int) $post_id, true );
			}
		}
	}

}

\WP_CLI::add_command( 'imageshop meta', __NAMESPACE__ . '\\Meta' );
