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
 *
 * Supports private repositories via QBMBot_GITHUB_TOKEN (wp-config) or the
 * github_token setting. Public repos continue to work without a token.
 */
final class Updater {

	public const GITHUB_OWNER = 'm1kfb';
	public const GITHUB_REPO  = 'qbmbot';
	public const CACHE_KEY    = 'qbmbot_github_release';
	public const CACHE_TTL    = 12 * HOUR_IN_SECONDS;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register update hooks (admin / cron only).
	 */
	public function register(): void {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'authorize_github_request' ), 10, 2 );
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
	 * Attach Bearer token for private-repo API and zip downloads.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function authorize_github_request( array $args, string $url ): array {
		$token = $this->token();
		if ( '' === $token || ! $this->is_qbmbot_github_url( $url ) ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;
		$args['headers']['User-Agent']    = 'QBMBOT-WordPress-Plugin/' . QBMBot_VERSION;

		// Release asset API downloads require octet-stream Accept.
		if ( preg_match( '#api\.github\.com/.*/releases/assets/\d+#', $url ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
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
			'slug'         => dirname( QBMBot_BASENAME ),
			'plugin'       => QBMBot_BASENAME,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'icons'        => array(),
			'banners'      => array(),
			'tested'       => get_bloginfo( 'version' ),
			'requires'     => '6.4',
			'requires_php' => '8.1',
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
	 * Resolve token: wp-config constant wins over settings.
	 */
	public function token(): string {
		if ( defined( 'QBMBot_GITHUB_TOKEN' ) ) {
			$token = trim( (string) constant( 'QBMBot_GITHUB_TOKEN' ) );
			if ( '' !== $token ) {
				return $token;
			}
		}
		return trim( (string) $this->settings->get( 'github_token', '' ) );
	}

	/**
	 * Whether a token is available (constant or settings).
	 */
	public function has_token(): bool {
		return '' !== $this->token();
	}

	/**
	 * Fetch and cache the latest GitHub release metadata.
	 *
	 * @return array{version:string,package:string,url:string,notes:string,published_at:string}|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( self::GITHUB_OWNER ),
			rawurlencode( self::GITHUB_REPO )
		);

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'QBMBOT-WordPress-Plugin/' . QBMBot_VERSION,
		);
		$token   = $this->token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// Do not cache failures (private repo without token / rate limit / outage).
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = $this->normalize_version( (string) ( $data['tag_name'] ?? '' ) );
		$package = $this->find_zip_package( $data, '' !== $token );
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
	 * When authenticated, prefer the releases/assets API URL so private zips download reliably.
	 *
	 * @param array<string, mixed> $data          GitHub release JSON.
	 * @param bool                 $authenticated Whether a token is in use.
	 */
	private function find_zip_package( array $data, bool $authenticated ): string {
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
			if ( ! str_ends_with( strtolower( $name ), '.zip' ) ) {
				continue;
			}

			$url = $authenticated
				? $this->asset_api_url( $asset )
				: (string) ( $asset['browser_download_url'] ?? '' );

			if ( '' === $url ) {
				$url = (string) ( $asset['browser_download_url'] ?? '' );
			}
			if ( '' === $url ) {
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

	/**
	 * API URL for a release asset (works with Bearer + Accept: application/octet-stream).
	 *
	 * @param array<string, mixed> $asset Asset row.
	 */
	private function asset_api_url( array $asset ): string {
		$id = (int) ( $asset['id'] ?? 0 );
		if ( $id <= 0 ) {
			return '';
		}
		return sprintf(
			'https://api.github.com/repos/%s/%s/releases/assets/%d',
			rawurlencode( self::GITHUB_OWNER ),
			rawurlencode( self::GITHUB_REPO ),
			$id
		);
	}

	/**
	 * Whether this request is for our GitHub repo (API or release download).
	 *
	 * @param string $url URL.
	 */
	private function is_qbmbot_github_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$repo = '/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;

		if ( 'api.github.com' === $host ) {
			return false !== stripos( $path, '/repos' . $repo . '/' );
		}

		if ( in_array( $host, array( 'github.com', 'www.github.com' ), true ) ) {
			return 0 === stripos( $path, $repo . '/releases/' );
		}

		return false;
	}
}
