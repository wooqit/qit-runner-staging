<?php
/**
 * Plugin Name: Seed Media Library
 * Description: Ensures the image-01/02/03 attachments the Woo Core E2E specs expect exist in the media library.
 *
 * WooCommerce Core imports these during environment provisioning
 * (`tests/e2e/bin/test-env-setup.sh`), and `utils/media.ts` resolves them by
 * slug. QIT provisions its own environment and does not run that script, so the
 * attachments are created here instead — keeping `utils/media.ts` and every spec
 * that depends on it identical to upstream.
 *
 * Specs use the resulting `source_url` for downloadable-product files, which must
 * live inside the site's own uploads directory to satisfy WooCommerce's Approved
 * Download Directories. The image content itself is never asserted on, so a small
 * generated PNG is enough.
 *
 * @package qit-woo-e2e
 */

declare(strict_types=1);

add_action(
	'init',
	function () {
		// The media library only needs seeding once per environment.
		if ( 'done' === get_option( 'qit_e2e_seeded_media' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		foreach ( array( 'image-01', 'image-02', 'image-03' ) as $slug ) {
			$existing = get_posts(
				array(
					'post_type'      => 'attachment',
					'name'           => $slug,
					'posts_per_page' => 1,
					'post_status'    => 'inherit',
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $existing ) ) {
				continue;
			}

			$filename = $slug . '.png';
			$path     = trailingslashit( $uploads['path'] ) . $filename;

			if ( ! file_exists( $path ) ) {
				$image = imagecreatetruecolor( 64, 64 );
				imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 200, 200 ) );
				imagepng( $image, $path );
				imagedestroy( $image );
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => 'image/png',
					'post_title'     => $slug,
					'post_name'      => $slug,
					'post_content'   => '',
					'post_status'    => 'inherit',
				),
				$path
			);

			if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
				continue;
			}

			wp_update_attachment_metadata(
				$attachment_id,
				wp_generate_attachment_metadata( $attachment_id, $path )
			);
		}

		update_option( 'qit_e2e_seeded_media', 'done' );
	}
);
