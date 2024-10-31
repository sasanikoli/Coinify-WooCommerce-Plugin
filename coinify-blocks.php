<?php
// Enqueue block assets for WooCommerce Blocks support.
function coinify_register_block_assets()
{
    // Check if WooCommerce Blocks are active and available.
    if (function_exists('wc_get_cart_url') && class_exists('Automattic\WooCommerce\Blocks\Package')) {
        // Enqueue the JavaScript file for block-based checkout.
        wp_register_script(
            'coinify-block-asset',
            plugins_url('assets/js/block-coinify.js', __FILE__),
            ['wp-blocks', 'wp-element', 'wp-i18n', 'wc-blocks-checkout'],
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/block-coinify.js'),
            true
        );

        // Enqueue the script if it's a block checkout page.
        if (is_checkout()) {
            wp_enqueue_script('coinify-block-asset');
        }
    }
}

add_action('enqueue_block_assets', 'coinify_register_block_assets');