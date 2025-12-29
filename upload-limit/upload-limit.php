<?php
/**
 * Plugin Name: Network Upload Size Limit
 * Description: Limits upload size based on a value configured in Network Admin. Works on multisite as a must-use plugin.
 * Author: Your Name
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;


define("DEFAULT_UPLOAD_LIMIT_MB", 5);

/**
 * Get the configured upload limit (in bytes)
 */
function nuls_get_upload_limit() {
    $mb = (int) get_site_option( 'nuls_upload_limit_mb', DEFAULT_UPLOAD_LIMIT_MB ); // default 10MB
    return $mb * 1024 * 1024;
}

/**
 * Enforce upload size limit
 */
function nuls_filter_upload( $file ) {
    $limit = nuls_get_upload_limit();

    if ( isset( $file['size'] ) && $file['size'] > $limit ) {
        $file['error'] = sprintf(
            'This file is too large. Maximum allowed upload size is %d MB.',
            get_site_option( 'nuls_upload_limit_mb', DEFAULT_UPLOAD_LIMIT_MB )
        );
    }

    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'nuls_filter_upload' );

/**
 * Add Network Admin settings page
 */
function nuls_network_menu() {
    add_submenu_page(
        'settings.php',
        'Upload Limit',
        'Upload Limit',
        'manage_network_options',
        'nuls-upload-limit',
        'nuls_settings_page'
    );
}
add_action( 'network_admin_menu', 'nuls_network_menu' );

/**
 * Render settings page
 */
function nuls_settings_page() {
    if ( isset( $_POST['nuls_limit_nonce'] ) && wp_verify_nonce( $_POST['nuls_limit_nonce'], 'nuls_save_limit' ) ) {
        $limit = max( 1, intval( $_POST['nuls_upload_limit_mb'] ) );
        update_site_option( 'nuls_upload_limit_mb', $limit );
        echo '<div class="updated"><p>Upload limit updated.</p></div>';
    }

    $current = (int) get_site_option( 'nuls_upload_limit_mb', DEFAULT_UPLOAD_LIMIT_MB );
    ?>
    <div class="wrap">
        <h1>Network Upload Size Limit</h1>
        <form method="post">
            <?php wp_nonce_field( 'nuls_save_limit', 'nuls_limit_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nuls_upload_limit_mb">Max Upload Size (MB)</label></th>
                    <td>
                        <input type="number" min="1" id="nuls_upload_limit_mb" name="nuls_upload_limit_mb" value="<?php echo esc_attr( $current ); ?>" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Add UI warnings before upload (media modal + classic uploader)
 */
function nuls_admin_upload_warning() {
    $limit_mb = (int) get_site_option( 'nuls_upload_limit_mb', DEFAULT_UPLOAD_LIMIT_MB );
    $limit_text = sprintf( 'Maximum upload size allowed on this network: %d MB', $limit_mb );

    ?>
    <style>
        .nuls-warning {
            background: #fff3cd;
            border-left: 4px solid #ffca2c;
            padding: 10px 12px;
            margin: 10px 0;
            font-size: 14px;
        }
    </style>

    <script>
    (function($){
        // Add warning to classic uploader
        $(document).ready(function(){
            var warning = $('<div class="nuls-warning"><?php echo esc_js( $limit_text ); ?></div>');
            $('#wpbody-content .wrap h1').first().after(warning);
        });

        // Add warning inside media modal
        wp.media.view.Modal.prototype.on('open', function() {
            setTimeout(function(){
                var $content = $('.media-frame-content:visible');
                if ($content.length && !$('.nuls-warning', $content).length) {
                    $content.prepend(
                        '<div class="nuls-warning"><?php echo esc_js( $limit_text ); ?></div>'
                    );
                }
            }, 300);
        });
    })(jQuery);
    </script>
    <?php
}
add_action( 'admin_head-upload.php', 'nuls_admin_upload_warning' );
add_action( 'admin_head-media-new.php', 'nuls_admin_upload_warning' );
add_action( 'admin_head', 'nuls_admin_upload_warning' );
