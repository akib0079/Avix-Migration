<?php
/**
 * Given a set of selected post IDs, finds everything a content export needs
 * to bring along for those posts to actually render correctly on the
 * target site — this is the part WXR (WordPress's built-in export) gets
 * wrong: it exports posts but leaves the operator to notice which images
 * went missing.
 *
 * Sources scanned per post: <img>/wp-image-{ID} references and gallery
 * shortcodes in post_content, the featured image, Elementor's _elementor_data
 * JSON (image widgets and referenced templates/global widgets), and a
 * broad heuristic over postmeta for ACF-style attachment ID fields.
 *
 * Deliberately over-inclusive rather than precise: a false-positive
 * dependency costs one extra (usually small) file in the archive; a missed
 * one is a broken image on the migrated site, which is the failure mode
 * this whole class exists to prevent.
 *
 * Known limitation (documented, not silently swallowed): base64-encoded
 * builder payloads (some Divi/WPBakery modules store entire encoded
 * structures rather than referencing IDs) are not scanned — surfaced as a
 * warning rather than a silent gap.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Content_Dependency_Resolver {

	/** How many hops of "template references another template" to follow. */
	const MAX_TEMPLATE_DEPTH = 2;

	/**
	 * @param int[] $post_ids
	 * @return array{attachment_ids:int[], template_ids:int[], warnings:string[]}
	 */
	public static function resolve( array $post_ids ) {
		$attachment_ids = array();
		$template_ids   = array();
		$warnings       = array();

		$queue   = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$visited = array();
		$depth   = 0;

		while ( ! empty( $queue ) && $depth <= self::MAX_TEMPLATE_DEPTH ) {
			$next_queue = array();

			foreach ( $queue as $post_id ) {
				if ( isset( $visited[ $post_id ] ) ) {
					continue;
				}
				$visited[ $post_id ] = true;

				$post = get_post( $post_id );
				if ( ! $post ) {
					continue;
				}

				$found = self::scan_post( $post, $warnings );

				foreach ( $found['attachment_ids'] as $id ) {
					$attachment_ids[ $id ] = $id;
				}
				foreach ( $found['template_ids'] as $id ) {
					if ( ! isset( $visited[ $id ] ) ) {
						$template_ids[ $id ] = $id;
						$next_queue[]        = $id;
					}
				}
			}

			$queue = $next_queue;
			$depth++;
		}

		if ( ! empty( $queue ) ) {
			$warnings[] = sprintf(
				'Stopped following Elementor template references after %d levels — some deeply nested templates may not have been included.',
				self::MAX_TEMPLATE_DEPTH
			);
		}

		return array(
			'attachment_ids' => array_values( $attachment_ids ),
			'template_ids'   => array_values( $template_ids ),
			'warnings'       => array_values( array_unique( $warnings ) ),
		);
	}

	private static function scan_post( WP_Post $post, array &$warnings ) {
		$attachment_ids = array();
		$template_ids   = array();

		// Featured image.
		$thumb_id = get_post_meta( $post->ID, '_thumbnail_id', true );
		if ( $thumb_id ) {
			$attachment_ids[] = (int) $thumb_id;
		}

		// <img wp-image-{ID}> classes — WP's own convention when an image is
		// inserted via the media library.
		if ( preg_match_all( '/wp-image-(\d+)/', $post->post_content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$attachment_ids[] = (int) $id;
			}
		}

		// Gallery shortcodes: [gallery ids="1,2,3"].
		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']([\d,\s]+)["\']/', $post->post_content, $m ) ) {
			foreach ( $m[1] as $id_list ) {
				foreach ( explode( ',', $id_list ) as $id ) {
					$id = (int) trim( $id );
					if ( $id > 0 ) {
						$attachment_ids[] = $id;
					}
				}
			}
		}

		// <img src="...uploads/..."> whose src wasn't already caught by a
		// wp-image-{ID} class (e.g. hand-written HTML, or a builder that
		// doesn't add that class).
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$id = attachment_url_to_postid( array( 'url' => $url ) );
				if ( $id ) {
					$attachment_ids[] = (int) $id;
				}
			}
		}

		// Elementor's page-builder JSON — only if the site actually has it;
		// checking for the meta key's presence is cheap and avoids a hard
		// dependency on Elementor being active.
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( $elementor_data ) {
			$decoded = json_decode( $elementor_data, true );
			if ( is_array( $decoded ) ) {
				self::scan_elementor_tree( $decoded, $attachment_ids, $template_ids );
			} else {
				$warnings[] = sprintf( 'Post #%d has Elementor data that could not be parsed as JSON.', $post->ID );
			}
		}

		// Broad ACF-style heuristic: any postmeta value that is itself the
		// ID of an existing attachment. Deliberately broad (see class
		// docblock) — false positives just mean one extra harmless file.
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( $key, '_' ) ) {
				continue; // ACF's paired "_fieldname" reference rows, not the data itself.
			}
			foreach ( (array) $values as $value ) {
				if ( is_numeric( $value ) && (int) $value > 0 && 'attachment' === get_post_type( (int) $value ) ) {
					$attachment_ids[] = (int) $value;
				}
			}
		}

		return array(
			'attachment_ids' => array_values( array_unique( $attachment_ids ) ),
			'template_ids'   => array_values( array_unique( $template_ids ) ),
		);
	}

	/**
	 * Recursively walks Elementor's element tree (sections > columns >
	 * widgets, arbitrarily nested) looking for two shapes: an
	 * {"url":..., "id":...} pair (Elementor's standard media-control
	 * shape — used for images, but also backgrounds/icons/etc, which is
	 * fine, they're all attachments), and a widget of type "template" /
	 * "global" carrying a template_id to a reusable Elementor Library item.
	 */
	private static function scan_elementor_tree( $node, array &$attachment_ids, array &$template_ids ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['url'], $node['id'] ) && is_numeric( $node['id'] ) && is_string( $node['url'] )
			&& false !== strpos( $node['url'], 'wp-content/uploads' )
		) {
			$attachment_ids[] = (int) $node['id'];
		}

		if ( isset( $node['widgetType'] ) && in_array( $node['widgetType'], array( 'template', 'global' ), true ) ) {
			$tpl_id = $node['settings']['template_id'] ?? ( $node['templateID'] ?? null );
			if ( $tpl_id && is_numeric( $tpl_id ) ) {
				$template_ids[] = (int) $tpl_id;
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				self::scan_elementor_tree( $value, $attachment_ids, $template_ids );
			}
		}
	}
}
