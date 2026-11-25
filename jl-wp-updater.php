<?php
/**
 * Plugin Name: JL WP Updater
 * Description: Manage GitHub release updates for selected WordPress plugins and themes.
 * Version: 1.1.1
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Update URI: https://github.com/jasrasr/jl-wp-updater
 * Text Domain: jl-wp-updater
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

final class JL_WP_Updater {
	private const OPTION = 'jl_wp_updater_managed_v2';
	private const LEGACY_OPTION = 'jl_wp_updater_managed';
	private const SELF = 'plugin:jl-wp-updater/jl-wp-updater.php';
	private const PACK = 'plugin:jl-wp-plugins-pack/jl-wp-plugins-pack.php';
	private const THEME = 'theme:jl-wp-theme-dark';
	private const NONCE = 'jl_wp_updater';

	public static function boot(): void {
		add_filter( 'update_plugins_github.com', [ self::class, 'plugin_offer' ], 10, 3 );
		add_filter( 'update_themes_github.com', [ self::class, 'theme_offer' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ self::class, 'check_source' ], 10, 4 );
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_jl_wp_updater', [ self::class, 'action' ] );
	}

	private static function managed(): array {
		$selected = get_site_option( self::OPTION, false );
		if ( false === $selected ) {
			$previous = (array) get_site_option( self::LEGACY_OPTION, [] );
			$selected = array_merge( $previous, [ self::PACK, self::THEME ] );
		}
		return array_unique( array_merge( [ self::SELF ], (array) $selected ) );
	}

	private static function item( string $type, string $file, array $headers ): ?array {
		$slug  = 'plugin' === $type ? dirname( $file ) : $file;
		$entry = 'plugin' === $type ? basename( $file ) : 'style.css';
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $slug )
			|| ( 'plugin' === $type && ! preg_match( '/^[a-zA-Z0-9_-]+\.php$/D', $entry ) )
			|| ( 'plugin' === $type && $file !== "$slug/$entry" )
			|| ! preg_match( '~^https://github\.com/([a-zA-Z0-9-]+/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)/?$~D', $headers['UpdateURI'] ?? '', $repo ) ) {
			return null;
		}
		return [
			'type' => $type, 'file' => $file, 'slug' => $slug, 'entry' => $entry,
			'repo' => $repo[1], 'name' => $headers['Name'] ?? $slug, 'version' => $headers['Version'] ?? '',
		];
	}

	private static function candidates(): array {
		$items = [];
		if ( current_user_can( 'update_plugins' ) ) {
			foreach ( get_plugins() as $file => $headers ) {
				$item = self::item( 'plugin', $file, $headers );
				if ( null !== $item ) {
					$items[ 'plugin:' . $file ] = $item;
				}
			}
		}
		if ( current_user_can( 'update_themes' ) ) {
			foreach ( wp_get_themes() as $file => $theme ) {
				$item = self::item( 'theme', $file, [
					'Name' => $theme->get( 'Name' ), 'Version' => $theme->get( 'Version' ), 'UpdateURI' => $theme->get( 'UpdateURI' ) ?: '',
				] );
				if ( null !== $item ) {
					$items[ 'theme:' . $file ] = $item;
				}
			}
		}
		return $items;
	}

	private static function release( array $item, bool $refresh = false ) {
		$repo = $item['repo'];
		$slug = $item['slug'];
		$key  = 'jl_wp_release_' . md5( strtolower( $repo ) . ':' . $slug );
		if ( ! $refresh && false !== ( $cached = get_site_transient( $key ) ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', [
			'timeout' => 8,
			'limit_response_size' => 262144,
			'headers' => [ 'Accept' => 'application/vnd.github+json' ],
		] );
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$tag    = $data['tag_name'] ?? '';
		$offer  = new WP_Error( 'jl_release', __( 'No stable release with the expected ZIP is available.', 'jl-wp-updater' ) );
		if ( is_wp_error( $response ) ) {
			$offer = $response;
		} elseif ( 200 !== $status && 404 !== $status ) {
			/* translators: %d: HTTP response status. */
			$offer = new WP_Error( 'jl_github', sprintf( __( 'GitHub returned HTTP %d.', 'jl-wp-updater' ), $status ) );
		} elseif ( 200 === $status && empty( $data['draft'] ) && empty( $data['prerelease'] )
			&& is_string( $tag ) && preg_match( '/^v?(\d+\.\d+\.\d+)$/D', $tag, $version ) ) {
			$url     = 'https://github.com/' . $repo . '/releases';
			$package = $url . '/download/' . $tag . '/' . $slug . '.zip';
			foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
				$download = $asset['browser_download_url'] ?? '';
				if ( ! is_string( $download ) || 0 !== strcasecmp( $download, $package ) || 'uploaded' !== ( $asset['state'] ?? '' ) ) {
					continue;
				}
				$offer = [ 'slug' => $slug, 'version' => $version[1], 'url' => $url . '/tag/' . $tag, 'package' => $package, 'jl_wp_updater' => true ];
				break;
			}
		}
		set_site_transient( $key, $offer, is_wp_error( $offer ) ? 300 : 900 );
		return $offer;
	}

	public static function plugin_offer( $update, array $headers, string $file ) {
		return self::offer( $update, 'plugin', $headers, $file );
	}

	public static function theme_offer( $update, array $headers, string $file ) {
		return self::offer( $update, 'theme', $headers, $file );
	}

	private static function offer( $update, string $type, array $headers, string $file ) {
		if ( ! in_array( "$type:$file", self::managed(), true ) ) {
			return $update;
		}
		$item = self::item( $type, $file, $headers );
		if ( null === $item ) {
			return $update;
		}
		$release = self::release( $item );
		return is_wp_error( $release ) ? $update : $release + [ $type => $file ];
	}

	public static function check_source( $source, $working_directory, $upgrader, array $context ) {
		$type = $context['type'] ?? '';
		$file = $context[ $type ] ?? '';
		if ( is_wp_error( $source ) || 'update' !== ( $context['action'] ?? '' )
			|| ! in_array( $type, [ 'plugin', 'theme' ], true ) || ! is_string( $file ) ) {
			return $source;
		}

		$updates = get_site_transient( 'update_' . $type . 's' );
		$offer   = (array) ( $updates->response[ $file ] ?? [] );
		if ( empty( $offer['jl_wp_updater'] ) ) {
			return $source;
		}

		global $wp_filesystem;
		$slug  = 'plugin' === $type ? dirname( $file ) : $file;
		$entry = 'plugin' === $type ? basename( $file ) : 'style.css';
		$path  = trailingslashit( $source ) . $entry;
		if ( $slug !== basename( untrailingslashit( $source ) ) || ! $wp_filesystem->is_file( $path ) ) {
			return new WP_Error( 'jl_package', __( 'The update ZIP does not contain the expected folder and main file.', 'jl-wp-updater' ) );
		}
		$contents = $wp_filesystem->get_contents( $path );
		if ( ! is_string( $contents ) || ! preg_match( '/^[ \t\/*#@]*Version:[ \t]*([^\r\n*]+)/mi', substr( $contents, 0, 8192 ), $match )
			|| trim( $match[1] ) !== ( $offer['new_version'] ?? '' ) ) {
			return new WP_Error( 'jl_package_version', __( 'The version in the ZIP does not match the release.', 'jl-wp-updater' ) );
		}
		return $source;
	}

	private static function capability(): string {
		return current_user_can( 'update_plugins' ) ? 'update_plugins' : 'update_themes';
	}

	public static function menu(): void {
		add_submenu_page( is_multisite() ? 'settings.php' : 'tools.php', 'JL WP Updater', 'JL WP Updater', self::capability(), 'jl-wp-updater', [ self::class, 'screen' ] );
	}

	public static function screen(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Access denied.', 'jl-wp-updater' ), '', [ 'response' => 403 ] );
		}
		$managed = self::managed();
		?>
		<div class="wrap"><h1>JL WP Updater</h1>
		<p><?php esc_html_e( 'Choose which installed plugins and themes to update from their GitHub releases. Only items with a GitHub Update URI are listed.', 'jl-wp-updater' ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<?php wp_nonce_field( self::NONCE ); ?>
		<input type="hidden" name="action" value="jl_wp_updater">
		<table class="widefat striped">
		<thead><tr><th scope="col"><?php esc_html_e( 'Manage', 'jl-wp-updater' ); ?></th><th scope="col"><?php esc_html_e( 'Plugin or theme', 'jl-wp-updater' ); ?></th><th scope="col"><?php esc_html_e( 'Installed', 'jl-wp-updater' ); ?></th><th scope="col"><?php esc_html_e( 'GitHub release', 'jl-wp-updater' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'jl-wp-updater' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( self::candidates() as $id => $item ) :
			$enabled = in_array( $id, $managed, true );
			$release = $enabled ? self::release( $item ) : null;
			?>
			<tr><td><input type="checkbox" name="managed[]" value="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( $item['name'] ); ?>" <?php checked( $enabled ); ?> <?php disabled( self::SELF === $id ); ?>></td>
			<td><strong><?php echo esc_html( $item['name'] ); ?></strong><br><a href="<?php echo esc_url( 'https://github.com/' . $item['repo'] ); ?>"><?php echo esc_html( $item['repo'] ); ?></a></td>
			<td><?php echo esc_html( $item['version'] ); ?></td>
			<td><?php echo esc_html( is_wp_error( $release ) ? $release->get_error_message() : ( $release['version'] ?? '—' ) ); ?></td>
			<td><?php if ( is_array( $release ) && $item['version'] === $release['version'] ) : ?>
				<button class="button" name="operation" value="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Reinstall current version', 'jl-wp-updater' ); ?></button>
			<?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<p><button class="button button-primary" name="operation" value="save"><?php esc_html_e( 'Save managed updates', 'jl-wp-updater' ); ?></button>
		<button class="button" name="operation" value="check"><?php esc_html_e( 'Check now', 'jl-wp-updater' ); ?></button></p>
		</form></div>
		<?php
	}

	public static function action(): void {
		if ( ! current_user_can( self::capability() ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Access denied.', 'jl-wp-updater' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_text_field( wp_unslash( $_POST['operation'] ) ) : '';
		$items     = self::candidates();
		$managed   = self::managed();
		if ( 'save' === $operation ) {
			$submitted = isset( $_POST['managed'] ) && is_array( $_POST['managed'] ) ? wp_unslash( $_POST['managed'] ) : [];
			$selected  = array_intersect( array_keys( $items ), array_filter( $submitted, 'is_string' ) );
			update_site_option( self::OPTION, array_values( array_unique( array_merge( array_diff( $managed, array_keys( $items ) ), $selected ) ) ) );
		} elseif ( 'check' === $operation ) {
			foreach ( array_intersect_key( $items, array_flip( $managed ) ) as $item ) {
				self::release( $item, true );
			}
		} elseif ( isset( $items[ $operation ] ) && in_array( $operation, $managed, true ) ) {
			self::reinstall( $items[ $operation ] );
		} else {
			wp_die( esc_html__( 'Unknown update action.', 'jl-wp-updater' ), '', [ 'response' => 400 ] );
		}

		if ( current_user_can( 'update_plugins' ) ) {
			delete_site_transient( 'update_plugins' );
			if ( 'check' === $operation ) {
				wp_update_plugins();
			}
		}
		if ( current_user_can( 'update_themes' ) ) {
			delete_site_transient( 'update_themes' );
			if ( 'check' === $operation ) {
				wp_update_themes();
			}
		}
		$url = is_multisite() ? network_admin_url( 'settings.php?page=jl-wp-updater' ) : admin_url( 'tools.php?page=jl-wp-updater' );
		wp_safe_redirect( $url );
		exit;
	}

	private static function reinstall( array $item ): void {
		$release = self::release( $item, true );
		if ( is_wp_error( $release ) ) {
			wp_die( esc_html( $release->get_error_message() ) );
		}
		if ( $item['version'] !== $release['version'] ) {
			wp_die( esc_html__( 'Reinstall requires the same installed and release version.', 'jl-wp-updater' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$type = $item['type'];
		$file = $item['file'];
		$hook = 'site_transient_update_' . $type . 's';
		$supply = static function ( $updates ) use ( $release, $type, $file ) {
			$updates = is_object( $updates ) ? clone $updates : new stdClass();
			$offer = $release + [ $type => $file, 'new_version' => $release['version'] ];
			$updates->response[ $file ] = 'plugin' === $type ? (object) $offer : $offer;
			return $updates;
		};
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = 'plugin' === $type ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
		add_filter( $hook, $supply );
		try {
			$result = $upgrader->upgrade( $file );
		} finally {
			remove_filter( $hook, $supply );
		}
		if ( true !== $result ) {
			$error = is_wp_error( $result ) ? $result : $skin->get_errors();
			wp_die( esc_html( $error->get_error_message() ?: __( 'WordPress could not write the update files.', 'jl-wp-updater' ) ) );
		}
	}
}

JL_WP_Updater::boot();
