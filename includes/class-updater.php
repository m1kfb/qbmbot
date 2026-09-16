<?php
/**
 * Check GitHub Releases and register updates with WordPress.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Surfaces new GitHub release zips in Plugins → Updates.
 */
final class Updater {

	public const GITHUB_OWNER = 'm1kfb';
	public const GITHUB_REPO  = 'qbmbot';
	public const CACHE_KEY    = 'qbmbot_github_release';
	public const CACHE_TTL    = 12 * HOUR_IN_SECONDS;

	/**
	 * Register update hooks (admin / cron only).
	 */
	public function register(): void {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		// When WordPress force-checks for updates, drop our GitHub cache too.
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );
		add_action( 'load-update-core.php', array( $this, 'maybe_force_refresh' ) );
		add_action( 'load-plugins.php', array( $this, 'maybe_force_refresh' ) );
	}

	/**
	 * Clear cached GitHub release metadata.
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Bust cache when an admin clicks “Check again”.
	 */
	public function maybe_force_refresh(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WP core update screens use force-check.
		if ( ! empty( $_GET['force-check'] ) ) {
			$this->clear_cache();
		}
	}

	/**
	 * Add QBMBOT to the plugin update transient when a newer release exists.
	 *
	 * @param object|false $transient Update transient.
	 * @return object|false
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		if ( version_compare( QBMBot_VERSION, $release['version'], '>=' ) ) {
			return $transient;
		}

		$update = (object) array(
			'slug'        => dirname( QBMBot_BASENAME ),
			'plugin'      => QBMBot_BASENAME,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => get_bloginfo( 'version' ),
			'requires'    => '6.4',
			'requires_php'=> '8.1',
		);

		$transient->response[ QBMBot_BASENAME ] = $update;

		return $transient;
	}

	/**
	 * Changelog / details in the “View version X details” modal.
	 *
	 * @param false|object|array $result Plugin info result.
	 * @param string             $action API action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || dirname( QBMBot_BASENAME ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'QBMBOT',
			'slug'          => dirname( QBMBot_BASENAME ),
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '">Queen B Marketing</a>',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'requires'      => '6.4',
			'requires_php'  => '8.1',
			'tested'        => get_bloginfo( 'version' ),
			'sections'      => array(
				'description' => 'AI chat widget and Contact Form 7 auto-responder for SME / trades businesses.',
				'changelog'   => $release['notes'] ?: 'See the release on GitHub for details.',
			),
			'last_updated'  => $release['published_at'],
		);
	}

	/**
	 * Fetch and cache the latest GitHub release metadata.
	 *
	 * @return array{version:string,package:string,url:string,notes:string,published_at:string}|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return $cached;
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( self::GITHUB_OWNER ),
			rawurlencode( self::GITHUB_REPO )
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'QBMBOT-WordPress-Plugin/' . QBMBot_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// Do not cache failures (private repo / rate limit / outage).
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = $this->normalize_version( (string) ( $data['tag_name'] ?? '' ) );
		$package = $this->find_zip_url( $data );
		if ( '' === $version || '' === $package ) {
			return null;
		}

		$release = array(
			'version'      => $version,
			'package'      => $package,
			'url'          => (string) ( $data['html_url'] ?? 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO ),
			'notes'        => (string) ( $data['body'] ?? '' ),
			'published_at' => (string) ( $data['published_at'] ?? '' ),
		);

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Strip a leading "v" from tags like v1.0.1.
	 *
	 * @param string $tag Git tag.
	 */
	private function normalize_version( string $tag ): string {
		$tag = ltrim( trim( $tag ), 'vV' );
		return preg_match( '/^\d+\.\d+\.\d+/', $tag ) ? $tag : '';
	}

	/**
	 * Pick the WordPress upload zip from release assets.
	 *
	 * @param array<string, mixed> $data GitHub release JSON.
	 */
	private function find_zip_url( array $data ): string {
		$assets = $data['assets'] ?? array();
		if ( ! is_array( $assets ) ) {
			return '';
		}

		$fallback = '';
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );
			if ( '' === $url || ! str_ends_with( strtolower( $name ), '.zip' ) ) {
				continue;
			}
			if ( 0 === stripos( $name, 'qbmbot-' ) ) {
				return $url;
			}
			if ( '' === $fallback ) {
				$fallback = $url;
			}
		}

		return $fallback;
	}
}
