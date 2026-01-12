<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Plugin_Favorites_Assets {

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
    }

    /**
     * Enqueue admin JavaScript and CSS
     */
    public static function enqueue_admin_assets($hook) {
        // Only load on plugins page
        if ($hook !== 'plugins.php') {
            return;
        }

        wp_enqueue_style(
            'plugin-favorites',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/plugin-favorites.css',
            array(),
            PLUGIN_FAVORITES_VERSION
        );

        wp_enqueue_script(
            'plugin-favorites',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/plugin-favorites.js',
            array('jquery'),
            PLUGIN_FAVORITES_VERSION,
            true
        );

        wp_localize_script('plugin-favorites', 'pluginFavorites', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('plugin_favorites_nonce')
        ));
    }
}
