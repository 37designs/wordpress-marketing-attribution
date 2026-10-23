<?php
/**
 * Updates this plugin from GitHub releases, through the normal WordPress update screens.
 *
 * Each release must have a zip asset whose top folder is the plugin folder (the release
 * workflow in .github/workflows/release.yml builds it). Works with a public repository;
 * no token is needed. The GitHub response is cached for 6 hours.
 */

defined( 'ABSPATH' ) || exit;

final class TSSC_Updater {

	const REPO      = '37designs/wordpress-marketing-attribution';
	const CACHE_KEY = 'tssc_github_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** @var string Plugin basename, e.g. gf-source-capture/gf-source-capture.php */
	private static $basename;

	/** @var string Installed version. */
	private static $version;

	public static function init( $plugin_file, $version ) {
		self::$basename = plugin_basename( $plugin_file );
		self::$version  = $version;

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'keep_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
	}

	/** The latest release as array( version, package, notes, published ), or null. */
	private static function latest() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) && ! empty( $data['assets'] ) ) {
				foreach ( $data['assets'] as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
						$release = array(
							'version'   => ltrim( $data['tag_name'], 'vV' ),
							'package'   => $asset['browser_download_url'],
							'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
							'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
							'url'       => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
						);
						break;
					}
				}
			}
		}

		// Cache failures too (as an empty array) so a GitHub outage doesn't slow every admin page.
		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $release;
	}

	/** Adds this plugin to the update list when GitHub has a newer release. */
	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::latest();
		$item    = (object) array(
			'id'          => self::REPO,
			'slug'        => dirname( self::$basename ),
			'plugin'      => self::$basename,
			'new_version' => $release ? $release['version'] : self::$version,
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $release ? $release['package'] : '',
		);

		if ( $release && version_compare( $release['version'], self::$version, '>' ) ) {
			$transient->response[ self::$basename ] = $item;
		} else {
			// Listing it under no_update lets WordPress show the "Enable auto-updates" link.
			$transient->no_update[ self::$basename ] = $item;
		}
		return $transient;
	}

	/** Fills the "View details" popup on the Plugins screen. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( self::$basename ) !== $args->slug ) {
			return $result;
		}

		$release = self::latest();
		return (object) array(
			'name'          => 'Three Seven Gravity Forms Source Capture',
			'slug'          => $args->slug,
			'version'       => $release ? $release['version'] : self::$version,
			'author'        => '<a href="https://threesevenmarketing.com">Three Seven</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release ? $release['package'] : '',
			'last_updated'  => $release ? $release['published'] : '',
			'sections'      => array(
				'changelog' => $release && $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : 'See the release notes on GitHub.',
			),
		);
	}

	/**
	 * Keeps the installed folder name when updating. Without this, a site that installed the
	 * plugin under a different folder name would get a second copy and the plugin would deactivate.
	 */
	public static function keep_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || self::$basename !== $hook_extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . dirname( self::$basename ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}

	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}
}
