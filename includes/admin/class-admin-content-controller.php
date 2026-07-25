<?php
/**
 * Controller for the Content Export screen: the post/page/CPT picker,
 * the dependency preview ("12 pages + 47 media files + 3 templates ≈ 86
 * MB") shown before exporting, and starting the export job.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Content_Controller {

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_content_list_posts']  = array( __CLASS__, 'list_posts' );
		$handlers['avix_content_preview']     = array( __CLASS__, 'preview' );
		$handlers['avix_content_start_export'] = array( __CLASS__, 'start_export' );
		return $handlers;
	}

	public static function exportable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return apply_filters( 'avix_migration_exportable_post_types', array_values( $types ) );
	}

	public static function list_posts() {
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$type     = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$status   = isset( $_POST['post_status'] ) ? sanitize_key( wp_unslash( $_POST['post_status'] ) ) : '';
		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per_page = 20;

		$allowed_types = self::exportable_post_types();
		$query_types   = ( '' !== $type && in_array( $type, $allowed_types, true ) ) ? array( $type ) : $allowed_types;

		$args = array(
			'post_type'      => $query_types,
			'post_status'    => '' !== $status ? array( $status ) : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			's'              => $search,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'ID'        => $post->ID,
				'title'     => $post->post_title ? $post->post_title : __( '(no title)', 'avix-migration' ),
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'date'      => mysql2date( get_option( 'date_format' ), $post->post_date ),
			);
		}

		wp_send_json_success(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'post_types'  => $allowed_types,
			)
		);
	}

	public static function preview() {
		$post_ids = self::read_post_ids();
		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No posts selected.', 'avix-migration' ) ), 400 );
		}

		$deps = Avix_Migration_Content_Dependency_Resolver::resolve( $post_ids );

		$estimated_bytes = 0;
		foreach ( $deps['attachment_ids'] as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file && is_readable( $file ) ) {
				$estimated_bytes += filesize( $file );
			}
		}

		wp_send_json_success(
			array(
				'post_count'       => count( $post_ids ),
				'template_count'   => count( $deps['template_ids'] ),
				'attachment_count' => count( $deps['attachment_ids'] ),
				'estimated_bytes'  => $estimated_bytes,
				'warnings'         => $deps['warnings'],
			)
		);
	}

	public static function start_export() {
		$post_ids = self::read_post_ids();
		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No posts selected.', 'avix-migration' ) ), 400 );
		}

		$meta = array(
			'post_ids'       => $post_ids,
			'destination_id' => isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : 'local',
		);

		$steps = array(
			'Avix_Migration_Job_Steps_Content_Prepare',
			'Avix_Migration_Job_Steps_Content_Collect',
			'Avix_Migration_Job_Steps_Content_Write_Json',
			'Avix_Migration_Job_Steps_Content_Copy_Media',
			'Avix_Migration_Job_Steps_Content_Finalize',
			'Avix_Migration_Job_Steps_Export_Upload',
		);

		$job = Avix_Migration_Job_Store::create( 'export_content', $steps, $meta );

		Avix_Migration_Util_Logger::info( $job->id, 'Content export job created.', array( 'post_count' => count( $post_ids ) ) );

		wp_send_json_success( array( 'job_id' => $job->id ) );
	}

	private static function read_post_ids() {
		if ( empty( $_POST['post_ids'] ) ) {
			return array();
		}
		$raw = wp_unslash( $_POST['post_ids'] );
		$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}
}
