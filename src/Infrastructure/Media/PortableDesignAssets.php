<?php
namespace Certloom\Infrastructure\Media;

use Certloom\Application\Design\PortableAssetManifest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges portable design-package assets to the local WordPress Media Library.
 *
 * Export turns local attachment references into content-addressed `asset:key`
 * references. Import installs the embedded bytes as new local attachments and
 * rewrites those references before the design enters normal persistence.
 */
final class PortableDesignAssets {
	/** @var PortableAssetManifest */
	private $manifest;

	public function __construct( ?PortableAssetManifest $manifest = null ) {
		$this->manifest = $manifest ?: new PortableAssetManifest();
	}

	/**
	 * @param array<string,mixed> $layout
	 * @return array{layout:array<string,mixed>,assets:array<string,array<string,mixed>>}|\WP_Error
	 */
	public function prepare_certificate_export( array $layout ) {
		$assets = array();
		$result = $this->rewrite_certificate_assets_for_export( $layout, $assets );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$validated = $this->manifest->validate( $assets );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return array(
			'layout' => $result,
			'assets' => $validated,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @return array{blocks:array<int,array<string,mixed>>,assets:array<string,array<string,mixed>>}|\WP_Error
	 */
	public function prepare_email_export( array $blocks, array $theme = array() ) {
		$assets = array();
		$out    = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) { continue; }
			$rewritten = $this->rewrite_email_block_for_export( $block, $assets );
			if ( is_wp_error( $rewritten ) ) { return $rewritten; }
			$out[] = $rewritten;
		}
		foreach ( array( 'background_image', 'content_background_image' ) as $key ) {
			if ( empty( $theme[ $key ] ) ) { continue; }
			$rewritten = $this->portable_email_url( (string) $theme[ $key ], $assets );
			if ( is_wp_error( $rewritten ) ) { return $rewritten; }
			$theme[ $key ] = $rewritten;
		}
		$validated = $this->manifest->validate( $assets );
		if ( is_wp_error( $validated ) ) { return $validated; }
		return array( 'blocks' => array_values( $out ), 'theme' => $theme, 'assets' => $validated );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function rewrite_email_block_for_export( array $block, array &$assets ) {
		if ( 'image' === (string) ( $block['type'] ?? '' ) && ! empty( $block['url'] ) ) {
			$url = $this->portable_email_url( (string) $block['url'], $assets );
			if ( is_wp_error( $url ) ) { return $url; }
			$block['url'] = $url;
		}
		if ( 'columns' === (string) ( $block['type'] ?? '' ) && is_array( $block['columns'] ?? null ) ) {
			foreach ( $block['columns'] as $index => $column ) {
				if ( ! is_array( $column ) || 'image' !== (string) ( $column['kind'] ?? '' ) || empty( $column['url'] ) ) { continue; }
				$url = $this->portable_email_url( (string) $column['url'], $assets );
				if ( is_wp_error( $url ) ) { return $url; }
				$block['columns'][ $index ]['url'] = $url;
			}
		}
		return $block;
	}

	/** @return string|\WP_Error */
	private function portable_email_url( $url, array &$assets ) {
		$url = (string) $url;
		$id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
		if ( ! $id ) { return $url; }
		$asset = $this->collect_attachment( $id );
		if ( is_wp_error( $asset ) ) { return $asset; }
		$key = (string) $asset['key'];
		$assets[ $key ] = $asset['descriptor'];
		return 'asset:' . $key;
	}

	/**
	 * @param array<string,mixed> $layout
	 * @param array<string,mixed> $assets
	 * @return array{layout:array<string,mixed>,created_ids:array<int,int>}|\WP_Error
	 */
	public function install_certificate_assets( array $layout, array $assets ) {
		$references = array();
		$this->collect_certificate_package_references( $layout, $references );
		$prepared = $this->prepare_import( $assets, $references );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$installed = $this->install_assets( $prepared );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$rewritten = $this->rewrite_certificate_assets_for_import( $layout, $installed['ids'] );
		if ( is_wp_error( $rewritten ) ) {
			$this->cleanup( $installed['created_ids'] );
			return $rewritten;
		}

		return array(
			'layout'      => $rewritten,
			'created_ids' => $installed['created_ids'],
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @param array<string,mixed> $assets
	 * @return array{blocks:array<int,array<string,mixed>>,created_ids:array<int,int>}|\WP_Error
	 */
	public function install_email_assets( array $blocks, array $assets, array $theme = array() ) {
		$references = array();
		foreach ( $blocks as $block ) { if ( is_array( $block ) ) { $this->collect_email_block_references( $block, $references ); } }
		foreach ( array( 'background_image', 'content_background_image' ) as $key ) {
			if ( 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', (string) ( $theme[ $key ] ?? '' ), $match ) ) { $references[ $match[1] ] = true; }
		}
		$prepared = $this->prepare_import( $assets, $references );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$installed = $this->install_assets( $prepared );
		if ( is_wp_error( $installed ) ) { return $installed; }
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) { continue; }
			$rewritten = $this->rewrite_email_block_for_import( $block, $installed['urls'] );
			if ( is_wp_error( $rewritten ) ) { $this->cleanup( $installed['created_ids'] ); return $rewritten; }
			$out[] = $rewritten;
		}
		foreach ( array( 'background_image', 'content_background_image' ) as $key ) {
			if ( 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', (string) ( $theme[ $key ] ?? '' ), $match ) ) {
				if ( empty( $installed['urls'][ $match[1] ] ) ) { $this->cleanup( $installed['created_ids'] ); return new \WP_Error( 'certloom_email_design_asset_url_missing', __( 'Certloom imported an image but could not create a usable Media Library URL for it.', 'certloom' ) ); }
				$theme[ $key ] = $installed['urls'][ $match[1] ];
			}
		}
		return array( 'blocks' => array_values( $out ), 'theme' => $theme, 'created_ids' => $installed['created_ids'] );
	}

	private function collect_email_block_references( array $block, array &$references ) {
		if ( 'image' === (string) ( $block['type'] ?? '' ) && 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', (string) ( $block['url'] ?? '' ), $match ) ) { $references[ $match[1] ] = true; }
		if ( 'columns' === (string) ( $block['type'] ?? '' ) && is_array( $block['columns'] ?? null ) ) {
			foreach ( $block['columns'] as $column ) { if ( is_array( $column ) && 'image' === (string) ( $column['kind'] ?? '' ) && 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', (string) ( $column['url'] ?? '' ), $match ) ) { $references[ $match[1] ] = true; } }
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	private function rewrite_email_block_for_import( array $block, array $urls ) {
		if ( 'image' === (string) ( $block['type'] ?? '' ) ) {
			$block['url'] = $this->installed_email_url( (string) ( $block['url'] ?? '' ), $urls );
			if ( is_wp_error( $block['url'] ) ) { return $block['url']; }
		}
		if ( 'columns' === (string) ( $block['type'] ?? '' ) && is_array( $block['columns'] ?? null ) ) {
			foreach ( $block['columns'] as $index => $column ) {
				if ( ! is_array( $column ) || 'image' !== (string) ( $column['kind'] ?? '' ) ) { continue; }
				$url = $this->installed_email_url( (string) ( $column['url'] ?? '' ), $urls );
				if ( is_wp_error( $url ) ) { return $url; }
				$block['columns'][ $index ]['url'] = $url;
			}
		}
		return $block;
	}

	/** @return string|\WP_Error */
	private function installed_email_url( $url, array $urls ) {
		if ( 1 !== preg_match( '/^asset:(media-[a-f0-9]{24})$/', $url, $match ) ) { return $url; }
		if ( empty( $urls[ $match[1] ] ) ) { return new \WP_Error( 'certloom_email_design_asset_url_missing', __( 'Certloom imported an image but could not create a usable Media Library URL for it.', 'certloom' ) ); }
		return (string) $urls[ $match[1] ];
	}

	/** @param array<int,int> $attachment_ids */
	public function cleanup( array $attachment_ids ) {
		foreach ( array_reverse( array_values( array_unique( array_map( 'absint', $attachment_ids ) ) ) ) as $attachment_id ) {
			if ( $attachment_id && function_exists( 'wp_delete_attachment' ) ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
	}

	/**
	 * @param array<string,mixed> $value
	 * @param array<string,array<string,mixed>> $assets
	 * @return array<string,mixed>|\WP_Error
	 */
	private function rewrite_certificate_assets_for_export( array $value, array &$assets ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( 'asset' === (string) $key && is_string( $item ) ) {
				if ( 1 === preg_match( '/^attachment:([1-9][0-9]*)$/', $item, $match ) ) {
					$asset = $this->collect_attachment( absint( $match[1] ) );
					if ( is_wp_error( $asset ) ) {
						return $asset;
					}
					$asset_key            = (string) $asset['key'];
					$assets[ $asset_key ] = $asset['descriptor'];
					$out[ $key ]           = 'asset:' . $asset_key;
					continue;
				}
				if ( 0 === strpos( $item, 'asset:' ) ) {
					return new \WP_Error(
						'certloom_template_export_named_asset_unavailable',
						__( 'This certificate design uses a named local asset that is not backed by a portable Media Library file.', 'certloom' )
					);
				}
			}

			if ( is_array( $item ) ) {
				$rewritten = $this->rewrite_certificate_assets_for_export( $item, $assets );
				if ( is_wp_error( $rewritten ) ) {
					return $rewritten;
				}
				$out[ $key ] = $rewritten;
				continue;
			}
			$out[ $key ] = $item;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $value
	 * @param array<string,int> $ids
	 * @return array<string,mixed>|\WP_Error
	 */
	private function rewrite_certificate_assets_for_import( array $value, array $ids ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( 'asset' === (string) $key && is_string( $item ) && 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', $item, $match ) ) {
				if ( empty( $ids[ $match[1] ] ) ) {
					return new \WP_Error(
						'certloom_template_import_asset_missing',
						__( 'This certificate design references an embedded image that is missing from its package.', 'certloom' )
					);
				}
				$out[ $key ] = 'attachment:' . absint( $ids[ $match[1] ] );
				continue;
			}
			if ( is_array( $item ) ) {
				$rewritten = $this->rewrite_certificate_assets_for_import( $item, $ids );
				if ( is_wp_error( $rewritten ) ) {
					return $rewritten;
				}
				$out[ $key ] = $rewritten;
				continue;
			}
			$out[ $key ] = $item;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $value
	 * @param array<string,bool> $references
	 */
	private function collect_certificate_package_references( array $value, array &$references ) {
		foreach ( $value as $key => $item ) {
			if ( 'asset' === (string) $key && is_string( $item ) && 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', $item, $match ) ) {
				$references[ $match[1] ] = true;
			}
			if ( is_array( $item ) ) {
				$this->collect_certificate_package_references( $item, $references );
			}
		}
	}

	/**
	 * @param array<string,mixed> $assets
	 * @param array<string,bool> $references
	 * @return array<string,array<string,mixed>>|\WP_Error
	 */
	private function prepare_import( array $assets, array $references ) {
		$validated = $this->manifest->validate( $assets );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$asset_keys = array_keys( $validated );
		$ref_keys   = array_keys( $references );
		sort( $asset_keys, SORT_STRING );
		sort( $ref_keys, SORT_STRING );
		if ( $asset_keys !== $ref_keys ) {
			return new \WP_Error(
				'certloom_design_asset_reference_mismatch',
				__( 'The design package embedded-media manifest does not exactly match the images used by the design.', 'certloom' )
			);
		}
		return $validated;
	}

	/**
	 * @return array{key:string,descriptor:array<string,mixed>}|\WP_Error
	 */
	private function collect_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! function_exists( 'get_attached_file' ) || ! function_exists( 'get_post_mime_type' ) ) {
			return new \WP_Error(
				'certloom_design_asset_api_unavailable',
				__( 'WordPress Media Library APIs are unavailable, so this design cannot be exported with its local images.', 'certloom' )
			);
		}

		$path = get_attached_file( $attachment_id, true );
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return new \WP_Error(
				'certloom_design_asset_file_missing',
				__( 'A local image used by this design is missing or unreadable. Repair that Media Library item before exporting.', 'certloom' )
			);
		}

		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return new \WP_Error(
				'certloom_design_asset_file_missing',
				__( 'A local image used by this design could not be resolved safely.', 'certloom' )
			);
		}

		$mime = strtolower( trim( (string) get_post_mime_type( $attachment_id ) ) );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			return new \WP_Error(
				'certloom_design_asset_type_unsupported',
				__( 'Portable Certloom design packages support local PNG and JPEG images only.', 'certloom' )
			);
		}

		$size = filesize( $real );
		if ( false === $size || $size < 1 || $size > PortableAssetManifest::MAX_ASSET_BYTES ) {
			return new \WP_Error(
				'certloom_design_asset_size',
				__( 'A local image used by this design exceeds Certloom’s safe portable-media size limit.', 'certloom' )
			);
		}

		$bytes = file_get_contents( $real );
		if ( false === $bytes || strlen( $bytes ) !== (int) $size ) {
			return new \WP_Error(
				'certloom_design_asset_read_failed',
				__( 'Certloom could not read a local image used by this design.', 'certloom' )
			);
		}

		$sha256  = hash( 'sha256', $bytes );
		$key     = 'media-' . substr( $sha256, 0, 24 );
		$default = 'image.' . ( 'image/png' === $mime ? 'png' : 'jpg' );
		$filename = basename( $real );
		if ( function_exists( 'sanitize_file_name' ) ) {
			$filename = sanitize_file_name( $filename );
		}
		if ( '' === $filename ) {
			$filename = $default;
		}

		$descriptor = array(
			'filename' => $filename,
			'mime'     => $mime,
			'sha256'   => $sha256,
			'size'     => (int) $size,
			'data'     => base64_encode( $bytes ),
		);
		$validated = $this->manifest->validate( array( $key => $descriptor ) );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return array(
			'key'        => $key,
			'descriptor' => $validated[ $key ],
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $assets
	 * @return array{ids:array<string,int>,urls:array<string,string>,created_ids:array<int,int>}|\WP_Error
	 */
	private function install_assets( array $assets ) {
		if ( empty( $assets ) ) {
			return array( 'ids' => array(), 'urls' => array(), 'created_ids' => array() );
		}
		if ( ! $this->load_wordpress_media_apis() ) {
			return new \WP_Error(
				'certloom_design_asset_import_api_unavailable',
				__( 'WordPress Media Library APIs are unavailable, so Certloom cannot install this design’s embedded images.', 'certloom' )
			);
		}

		$ids         = array();
		$urls        = array();
		$created_ids = array();
		foreach ( $assets as $key => $asset ) {
			$bytes = base64_decode( (string) $asset['data'], true );
			if ( false === $bytes ) {
				$this->cleanup( $created_ids );
				return new \WP_Error( 'certloom_design_asset_integrity', __( 'An embedded image failed its integrity check.', 'certloom' ) );
			}

			$upload = wp_upload_bits( (string) $asset['filename'], null, $bytes );
			if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
				$this->cleanup( $created_ids );
				return new \WP_Error(
					'certloom_design_asset_upload_failed',
					__( 'Certloom could not install one of this design’s embedded images in the WordPress Media Library.', 'certloom' )
				);
			}

			$file = (string) $upload['file'];
			if ( ! is_file( $file ) || ! hash_equals( (string) $asset['sha256'], hash_file( 'sha256', $file ) ) ) {
				$this->delete_uploaded_file( $file );
				$this->cleanup( $created_ids );
				return new \WP_Error(
					'certloom_design_asset_upload_integrity',
					__( 'WordPress wrote an embedded image incorrectly, so Certloom stopped the import.', 'certloom' )
				);
			}

			$attachment = array(
				'post_mime_type' => (string) $asset['mime'],
				'post_title'     => $this->attachment_title( (string) $asset['filename'] ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);
			$attachment_id = wp_insert_attachment( $attachment, $file, 0, true );
			if ( is_wp_error( $attachment_id ) || ! absint( $attachment_id ) ) {
				$this->delete_uploaded_file( $file );
				$this->cleanup( $created_ids );
				return is_wp_error( $attachment_id )
					? $attachment_id
					: new \WP_Error( 'certloom_design_asset_attachment_failed', __( 'Certloom could not register an imported image in the WordPress Media Library.', 'certloom' ) );
			}

			$attachment_id = absint( $attachment_id );
			$created_ids[] = $attachment_id;
			$ids[ $key ]   = $attachment_id;

			if ( function_exists( 'wp_generate_attachment_metadata' ) && function_exists( 'wp_update_attachment_metadata' ) ) {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
				if ( is_array( $metadata ) ) {
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}

			$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';
			if ( is_string( $url ) && '' !== $url ) {
				$urls[ $key ] = $url;
			}
		}

		return array(
			'ids'         => $ids,
			'urls'        => $urls,
			'created_ids' => $created_ids,
		);
	}

	private function load_wordpress_media_apis() {
		if ( ! function_exists( 'wp_upload_bits' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_insert_attachment' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/media.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		return function_exists( 'wp_upload_bits' ) && function_exists( 'wp_insert_attachment' );
	}

	private function delete_uploaded_file( $file ) {
		$file = (string) $file;
		if ( '' === $file || ! is_file( $file ) ) {
			return;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $file );
			return;
		}
		unlink( $file );
	}

	private function attachment_title( $filename ) {
		$title = pathinfo( (string) $filename, PATHINFO_FILENAME );
		if ( function_exists( 'sanitize_text_field' ) ) {
			$title = sanitize_text_field( $title );
		}
		return '' !== trim( (string) $title ) ? (string) $title : __( 'Imported Certloom design image', 'certloom' );
	}
}
