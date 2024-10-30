<?php
/**
 * WP-CLI command structure.
 */

declare(strict_types=1);

namespace Imageshop\WordPress\CLI;

use Imageshop\WordPress\Attachment;
use Imageshop\WordPress\REST_Controller;

class Duplicates {

	private $verbose = false;

	private $delay = 5;

	private $dry_run = false;

	private $end_log = array();

	public function __construct() {}

	/**
	 * Generate a report on the images that should exist on the site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Choose the format to receive the report in.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *      # Generate a table report.
	 *      $ wp imageshop media duplicates report --format=table
	 *
	 * @when after_wp_load
	 */
	public function report( $args, $assoc_args ) {
		global $wpdb;

		$fields = array(
			'Post ID',
			'Attachment Name',
			'Imageshop ID',
		);

		$values = array();

		$attachments = $wpdb->get_results(
			"
			SELECT
		       p.ID,
		       p.post_title,
		       pm.meta_value
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

		foreach ( $attachments as $attachment ) {
			$values[] = array(
				'Post ID'         => $attachment->ID,
				'Attachment Name' => $attachment->post_title,
				'Imageshop ID'    => $attachment->meta_value,
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'], $values, $fields );
	}

	/**
	 * Clear out duplicate entries in Imageshop with the same name.
	 *
	 * When doing an import, if there are latency issues, it may at times happen that
	 * an unintentional duplicate entry is added for an image. The bigger your media
	 * library is when starting the improt job, the higher the risk of this happening to
	 * multiple files is.
	 *
	 * This command will therefore let you process all your images, and double-check
	 * that none of them have duplicate entries.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show verbose information while processing.
	 *
	 * [--reduced-delay]
	 * : Wait 2 seconds between each operation instead of the usual 5.
	 *
	 * [--no-delay]
	 * : Do not have any delay between operations.
	 *
	 * [--dry-run]
	 * : Simulate the actions, without performing the delete sequence.
	 *
	 * @when after_wp_load
	 */
	public function delete( $args, $assoc_args ) {
		global $wpdb;
		$imageshop = new REST_Controller();
		$interface = array( \get_option( 'imageshop_upload_interface' ) );

		if ( isset( $assoc_args['verbose'] ) ) {
			$this->verbose = true;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->dry_run = true;
		}

		if ( isset( $assoc_args['reduced-delay'] ) ) {
			$this->delay = 2;
		}

		if ( isset( $assoc_args['no-delay'] ) ) {
			$this->delay = 0;
		}

		$deleted = 0;

		$attachments = $wpdb->get_results(
			"
			SELECT
		       DISTINCT( p.ID ),
		       p.post_title,
		       pm.meta_value
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

		\WP_CLI::log( sprintf( 'Checking for duplicates among %d attachments...', count( $attachments ) ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Checking attachments', count( $attachments ) );

		foreach ( $attachments as $attachment ) {
			$page           = 0;
			$total_pages    = null;
			$per_page       = 80;
			$has_more_items = true;

			while ( $has_more_items ) {
				$search_query = array(
					'Querystring'  => $attachment->post_title,
					'Page'         => $page,
					'Pagesize'     => $per_page,
					'InterfaceIds' => $interface,
				);

				$results = $imageshop->search( $search_query );

				if ( empty( $results ) ) {
					$has_more_items = false;
					continue;
				}

				if ( ! isset( $results->DocumentList ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$results->DocumentList` is provided by the SaaS API.
					\WP_CLI::error( 'No document list found in the search results' );
					return false;
				}

				if ( count( $results->DocumentList ) < 2 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$results->DocumentList` is provided by the SaaS API.
					if ( $this->verbose ) {
						\WP_CLI::log( sprintf( 'Only one entry matching the title `%s`.', $attachment->post_title ) );
						$has_more_items = false;
						continue;
					}
				}

				foreach ( $results->DocumentList as $document ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$results->DocumentList` is provided by the SaaS API.
					if ( (int) $document->DocumentID !== (int) $attachment->meta_value && (string) $document->Name === (string) $attachment->post_title ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$document->DocumentID` and `$document->Name` are provided by the SaaS API.
						if ( $this->verbose ) {
							$notice = sprintf( 'Sending delete request for Imageshop ID %d - Name `%s` with original ID %d', $document->DocumentID, $document->Name, $attachment->meta_value ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$document->DocumentID` and `$document->Name` are provided by the SaaS API.

							\WP_CLI::log( $notice );
							$this->end_log[] = $notice;
						}

						if ( ! $this->dry_run ) {
							$imageshop->delete_document( $document->DocumentID ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$document->DocumentID` is provided by the SaaS API.
						}

						$deleted++;
					}
				}

				if ( null === $total_pages ) {
					$total_pages = ceil( ( isset( $results->NumberOfDocuments ) ? $results->NumberOfDocuments : 0 ) / $per_page ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `$results->NumberOfDocuments` is provided by the SaaS API.
				}

				$page++;

				if ( $page > $total_pages ) {
					$has_more_items = false;
				}
			}

			if ( 0 !== $this->delay ) {
				sleep( $this->delay );
			}

			$progress->tick();
		}

		$progress->finish();

		if ( ! empty( $this->end_log ) && $this->verbose ) {
			\WP_CLI::log( '## Begin report of deleted files' );
			foreach ( $this->end_log as $log ) {
				\WP_CLI::log( $log );
			}
			\WP_CLI::log( '## End report on deleted files' );
		}

		\WP_CLI::success( sprintf( 'Finished removing duplicates. A total of %d media items were deleted.', $deleted ) );
		return true;
	}

}

\WP_CLI::add_command( 'imageshop media duplicates', __NAMESPACE__ . '\\Duplicates' );
