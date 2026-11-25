<?php
/**
 * Plugin Name: JL WP Updater
 * Description: GitHub release updates for JL WP Updater and JL WP Plugins Pack.
 * Version: 1.0.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Update URI: https://github.com/jasrasr/jl-wp-updater
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

final class JL_WP_Updater {
    private const OWNER = 'jasrasr';
    private const PLUGINS = [
        'jl-wp-updater' => 'JL WP Updater',
        'jl-wp-plugins-pack' => 'JL WP Plugins Pack',
    ];

    public static function boot(): void {
        add_filter('update_plugins_github.com', [self::class, 'offer'], 10, 3);
        add_filter('upgrader_source_selection', [self::class, 'check_source'], 10, 4);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_jl_wp_updater', [self::class, 'action']);
    }

    private static function release(string $slug, bool $refresh = false) {
        $key = 'jl_wp_release_' . $slug;
        if (!$refresh && false !== ($cached = get_site_transient($key))) {
            return $cached;
        }
        $repo = self::OWNER . '/' . $slug;
        $response = wp_safe_remote_get('https://api.github.com/repos/' . $repo . '/releases/latest', [
            'timeout' => 8,
            'limit_response_size' => 262144,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);
        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $tag = $data['tag_name'] ?? '';
        $offer = new WP_Error('jl_release', 'No stable release with the expected ZIP is available.');
        if (is_wp_error($response)) {
            $offer = $response;
        } elseif ($status !== 200 && $status !== 404) {
            $offer = new WP_Error('jl_github', sprintf('GitHub returned HTTP %d.', $status));
        } elseif ($status === 200 && empty($data['draft']) && empty($data['prerelease'])
            && is_string($tag) && preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $version)) {
            $url = 'https://github.com/' . $repo . '/releases';
            $package = $url . '/download/' . $tag . '/' . $slug . '.zip';
            foreach (($data['assets'] ?? []) as $asset) {
                if (($asset['browser_download_url'] ?? '') !== $package || ($asset['state'] ?? '') !== 'uploaded') continue;
                $offer = ['slug' => $slug, 'version' => $version[1], 'url' => $url . '/tag/' . $tag, 'package' => $package];
                break;
            }
        }
        set_site_transient($key, $offer, is_wp_error($offer) ? 300 : 900);
        return $offer;
    }

    public static function offer($update, array $headers, string $file) {
        $slug = dirname($file);
        if (!isset(self::PLUGINS[$slug]) || $file !== self::file($slug)
            || ($headers['UpdateURI'] ?? '') !== 'https://github.com/' . self::OWNER . '/' . $slug) {
            return $update;
        }
        $release = self::release($slug);
        return is_wp_error($release) ? $update : $release;
    }

    public static function check_source($source, $working_directory, $upgrader, array $context) {
        $file = $context['plugin'] ?? '';
        $slug = dirname($file);
        if (is_wp_error($source) || ($context['action'] ?? '') !== 'update'
            || !isset(self::PLUGINS[$slug]) || $file !== self::file($slug)) {
            return $source;
        }

        global $wp_filesystem;
        if (basename(untrailingslashit($source)) !== $slug
            || !$wp_filesystem->is_file(trailingslashit($source) . $slug . '.php')) {
            return new WP_Error('jl_package', 'The update ZIP does not contain the expected plugin folder and PHP file.');
        }
        return $source;
    }

    private static function file(string $slug): string {
        return $slug . '/' . $slug . '.php';
    }

    public static function menu(): void {
        add_management_page('JL WP Updater', 'JL WP Updater', 'update_plugins', 'jl-wp-updater', [self::class, 'screen']);
    }

    public static function screen(): void {
        if (!current_user_can('update_plugins')) wp_die('Access denied.', '', ['response' => 403]);
        $plugins = get_plugins();
        ?>
        <div class="wrap"><h1>JL WP Updater</h1>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
        <?php wp_nonce_field('jl_wp_updater'); ?>
        <input type="hidden" name="action" value="jl_wp_updater">
        <p><button class="button" name="operation" value="check">Check now</button></p>
        <table class="widefat striped">
        <thead><tr><th>Plugin</th><th>Installed</th><th>GitHub release</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach (self::PLUGINS as $slug => $name):
            $installed = $plugins[self::file($slug)]['Version'] ?? '';
            $release = self::release($slug);
            ?>
            <tr><td><?php echo esc_html($name); ?></td>
            <td><?php echo esc_html($installed ?: 'Not installed'); ?></td>
            <td><?php echo esc_html(is_wp_error($release) ? $release->get_error_message() : $release['version']); ?></td>
            <td><?php if (!is_wp_error($release) && $installed === $release['version']): ?>
                <button class="button" name="operation" value="<?php echo esc_attr($slug); ?>">Reinstall current version</button>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></form></div>
        <?php
    }

    public static function action(): void {
        if (!current_user_can('update_plugins') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Access denied.', '', ['response' => 403]);
        }
        check_admin_referer('jl_wp_updater');
        $operation = $_POST['operation'] ?? '';
        if ($operation === 'check') {
            foreach (array_keys(self::PLUGINS) as $slug) self::release($slug, true);
            delete_site_transient('update_plugins');
            wp_update_plugins();
        } elseif (is_string($operation) && isset(self::PLUGINS[$operation])) {
            self::reinstall($operation);
        } else {
            wp_die('Unknown update action.', '', ['response' => 400]);
        }
        wp_safe_redirect(admin_url('tools.php?page=jl-wp-updater'));
        exit;
    }

    private static function reinstall(string $slug): void {
        $file = self::file($slug);
        $installed = get_plugins()[$file]['Version'] ?? '';
        $release = self::release($slug, true);
        if (is_wp_error($release)) wp_die(esc_html($release->get_error_message()));
        if ($installed !== $release['version']) wp_die('Reinstall requires the same installed and release version.');
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Feed the approved same-version package to the normal plugin upgrade path for this request.
        $supply = static function ($updates) use ($release, $file) {
            $updates = is_object($updates) ? clone $updates : new stdClass();
            $updates->response[$file] = (object) ($release + ['plugin' => $file, 'new_version' => $release['version']]);
            return $updates;
        };
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        add_filter('site_transient_update_plugins', $supply);
        try {
            $result = $upgrader->upgrade($file);
        } finally {
            remove_filter('site_transient_update_plugins', $supply);
        }
        if ($result !== true) {
            $error = is_wp_error($result) ? $result : $skin->get_errors();
            wp_die(esc_html($error->get_error_message() ?: 'WordPress could not write the plugin files.'));
        }
    }
}

JL_WP_Updater::boot();
