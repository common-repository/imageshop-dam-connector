<?php
/**
 * WP-CLI command structure.
 */

declare(strict_types=1);

namespace Imageshop\WordPress\CLI;

use Imageshop\WordPress\Attachment;

class Media {

	private $verbose = false;

	private $delay = 5;

	private $force = false;

	public function __construct() {

	}

	/**
	 * Export content from the media library to Imageshop.
	 *
	 * By default, when performing mass-operations, you will need to wait 5 seconds
	 * between each activity.
	 *
	 * ## OPTIONS
	 *
	 * [<ID>]
	 * : The Post ID for an attachment to export
	 *
	 * [--all]
	 * : Export all media found in the media library to Imageshop.
	 *
	 * [--missing]
	 * : Export any content found in the media library that does not have an Imageshop reference.
	 *
	 * [--force]
	 * : Force export a file, even if it is marked as exported already.
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
	 *      # Export the attachment with ID 123
	 *      $ wp imageshop media export 123
	 *      Success: The attachment has been exported to your default Imageshop interface.
	 *
	 * @when after_wp_load
	 */
	public function export( $args, $assoc_args ) {
		if ( isset( $assoc_args['verbose'] ) ) {
			$this->verbose = true;
		}

		if ( isset( $assoc_args['reduced-delay'] ) ) {
			$this->delay = 2;
		}

		if ( isset( $assoc_args['no-delay'] ) ) {
			$this->delay = 0;
		}

		if ( isset( $assoc_args['force'] ) ) {
			$this->force = true;
		}

		if ( isset( $assoc_args['all'] ) ) {
			return $this->all();
		}

		if ( isset( $assoc_args['missing'] ) ) {
			return $this->missing();
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

	private function single( $post_id ) {
		$imageshop = Attachment::get_instance();

		$export = $imageshop->export_to_imageshop( (int) $post_id, $this->force );

		if ( false === $export ) {
			\WP_CLI::error( 'An error occurred while exporting the media item, please try again, or check your error log.' );
			return false;
		}

		\WP_CLI::success( 'The attachment has been exported to your default Imageshop interface.' );
		return true;
	}

	private function all() {
		global $wpdb;

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
		    "
		);

		if ( empty( $attachments ) ) {
			\WP_CLI::warning( 'No attachments were found on this site.' );
			return false;
		}

		$imageshop_attachment = Attachment::get_instance();

		\WP_CLI::log( sprintf( 'Starting export of %d attachments...', count( $attachments ) ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting attachments', count( $attachments ) );

		foreach ( $attachments as $attachment ) {
			if ( $this->verbose ) {
				\WP_CLI::log( sprintf( 'Processing attachment with ID %d', $attachment->ID ) );
			}
			$imageshop_attachment->export_to_imageshop( (int) $attachment->ID, $this->force );

			if ( 0 !== $this->delay ) {
				sleep( $this->delay );
			}

			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success( 'All attachments have been exported.' );
		return true;
	}

	private function missing() {
		global $wpdb;

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
				NOT EXISTS (
				    SELECT
				    	1
			    	FROM
				    	{$wpdb->postmeta} as spm
			    	WHERE
				    	spm.post_id = p.ID
			    	AND
						spm.meta_key = '_imageshop_document_id'
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
			\WP_CLI::warning( 'No attachments were found to be missing from Imageshop.' );
			return false;
		}

		$imageshop_attachment = Attachment::get_instance();

		\WP_CLI::log( sprintf( 'Starting export of %d attachments...', count( $attachments ) ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting attachments', count( $attachments ) );

		foreach ( $attachments as $attachment ) {
			if ( $this->verbose ) {
				\WP_CLI::log( sprintf( 'Processing attachment with ID %d', $attachment->ID ) );
			}
			$imageshop_attachment->export_to_imageshop( (int) $attachment->ID );

			if ( 0 !== $this->delay ) {
				sleep( $this->delay );
			}

			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success( 'All attachments have been exported.' );
		return true;
	}

}

\WP_CLI::add_command( 'imageshop media', __NAMESPACE__ . '\\Media' );
