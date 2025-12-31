<?php
/**
 * Plugin Name: Multisite Signup Approval
 * Description: Adds a site request field to user registration and lets network admins approve requests before creating a subsite.
 * Version:     1.0.0
 * Author:      Joseph Galea
 * Network:     true
 */

if (!defined('ABSPATH')) {
    exit;
}

class MSS_Multisite_Signup_Approval {

    const META_SITE_NAME  = 'mss_requested_site_name';
    const META_STATUS     = 'mss_request_status'; // pending, approved, rejected

    public function __construct() {
        // Ensure we're on multisite
        if (!is_multisite()) {
            add_action('admin_notices', [$this, 'notice_not_multisite']);
            return;
        }

        // Front-end registration flow
        add_action('register_form',         [$this, 'add_site_name_field']);
        add_filter('registration_errors',   [$this, 'validate_site_name_field'], 10, 3);
        add_action('user_register',         [$this, 'store_site_request_meta']);

        // Network admin UI
        add_action('network_admin_menu',    [$this, 'add_network_menu']);

        // Handle approve/reject actions (network admin)
        add_action('admin_post_mss_approve_request', [$this, 'handle_approve_request']);
        add_action('admin_post_mss_reject_request',  [$this, 'handle_reject_request']);

        // Admin notices after actions
        add_action('network_admin_notices', [$this, 'network_admin_notices']);
    }

    /**
     * Warn if plugin is enabled on non-multisite.
     */
    public function notice_not_multisite() {
        ?>
        <div class="notice notice-error">
            <p><strong>Multisite Signup Approval</strong> requires WordPress Multisite to be enabled.</p>
        </div>
        <?php
    }

    /**
     * Add "Desired Site Name" field to registration form.
     */
    public function add_site_name_field() {
        $value = isset($_POST['site_name']) ? esc_attr($_POST['site_name']) : '';
        ?>
        <p>
            <label for="site_name">Desired Site Name (subdomain)<br/>
                <input type="text" name="site_name" id="site_name" class="input" value="<?php echo $value; ?>" size="25" />
            </label>
        </p>
        <?php
    }

    /**
     * Validate the "Desired Site Name" field.
     */
    public function validate_site_name_field($errors, $sanitized_user_login, $user_email) {
        if (empty($_POST['site_name'])) {
            $errors->add('site_name_error', '<strong>Error:</strong> Please choose a site name.');
            return $errors;
        }

        $site_name = sanitize_title_with_dashes(wp_unslash($_POST['site_name']));

        if (!preg_match('/^[a-z0-9-]+$/', $site_name)) {
            $errors->add('site_name_error', '<strong>Error:</strong> Site name may only contain lowercase letters, numbers, and hyphens.');
        }

        // Check if domain already exists in network
        $network = get_network();
        if ($network && $network->domain) {
            $domain = $site_name . '.' . $this->strip_www($network->domain);
            if (domain_exists($domain, '/')) {
                $errors->add('site_name_error', '<strong>Error:</strong> That site name is already taken.');
            }
        }

        return $errors;
    }

    /**
     * Store requested site name + mark request as pending.
     */
    public function store_site_request_meta($user_id) {
        if (empty($_POST['site_name'])) {
            return;
        }

        $site_name = sanitize_title_with_dashes(wp_unslash($_POST['site_name']));

        update_user_meta($user_id, self::META_SITE_NAME, $site_name);
        update_user_meta($user_id, self::META_STATUS, 'pending');

        // Notify network admin
        $admin_email = get_site_option('admin_email');
        if ($admin_email) {
            $user = get_userdata($user_id);
            $subject = 'New site request pending approval';
            $message = sprintf(
                "A new user has requested a site:\n\nUsername: %s\nEmail: %s\nRequested site: %s\n\nReview requests in Network Admin > Site Requests.",
                $user->user_login,
                $user->user_email,
                $site_name
            );
            wp_mail($admin_email, $subject, $message);
        }
    }

    /**
     * Add "Site Requests" page in Network Admin.
     */
    public function add_network_menu() {
        add_menu_page(
            'Site Requests',
            'Site Requests',
            'manage_network_users',
            'mss-site-requests',
            [$this, 'render_site_requests_page'],
            'dashicons-admin-multisite',
            60
        );
    }

    /**
     * Render the Site Requests page.
     */
    public function render_site_requests_page() {
        if (!current_user_can('manage_network_users')) {
            wp_die(__('You do not have permission to access this page.', 'mss'));
        }

        $args = [
            'meta_query' => [
                [
                    'key'   => self::META_STATUS,
                    'value' => 'pending',
                ],
            ],
            'fields' => 'all',
            'number' => 200,
        ];
        $pending_users = get_users($args);

        ?>
        <div class="wrap">
            <h1>Pending Site Requests</h1>

            <?php if (empty($pending_users)) : ?>
                <p>No pending requests.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Requested Site Name</th>
                        <th>Requested Domain</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $network = get_network();
                    $network_domain = $network ? $this->strip_www($network->domain) : $_SERVER['HTTP_HOST'];

                    foreach ($pending_users as $user) :
                        $site_name = get_user_meta($user->ID, self::META_SITE_NAME, true);
                        $domain    = $site_name . '.' . $network_domain;

                        $approve_url = wp_nonce_url(
                            network_admin_url('admin-post.php?action=mss_approve_request&user_id=' . $user->ID),
                            'mss_approve_request_' . $user->ID
                        );
                        $reject_url = wp_nonce_url(
                            network_admin_url('admin-post.php?action=mss_reject_request&user_id=' . $user->ID),
                            'mss_reject_request_' . $user->ID
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html($user->user_login); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><?php echo esc_html($site_name); ?></td>
                            <td><?php echo esc_html($domain); ?></td>
                            <td>
                                <a href="<?php echo esc_url($approve_url); ?>">Approve</a> |
                                <a href="<?php echo esc_url($reject_url); ?>">Reject</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Handle approval: create site, assign user as admin, send email.
     */
    public function handle_approve_request() {
        if (!current_user_can('manage_network_users')) {
            wp_die(__('You do not have permission to do this.', 'mss'));
        }

        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        check_admin_referer('mss_approve_request_' . $user_id);

        $user = get_userdata($user_id);
        if (!$user) {
            $this->redirect_with_message('mss-site-requests', 'mss_error', 'invalid_user');
        }

        $site_name = get_user_meta($user_id, self::META_SITE_NAME, true);
        $status    = get_user_meta($user_id, self::META_STATUS, true);

        if (!$site_name || $status !== 'pending') {
            $this->redirect_with_message('mss-site-requests', 'mss_error', 'invalid_request');
        }

        $network = get_network();
        $network_domain = $network ? $this->strip_www($network->domain) : $_SERVER['HTTP_HOST'];
        $domain = $site_name . '.' . $network_domain;
        $path   = '/';
        $title  = ucfirst($site_name) . ' Website';

        if (domain_exists($domain, $path)) {
            $this->redirect_with_message('mss-site-requests', 'mss_error', 'domain_exists');
        }

        $site_id = wpmu_create_blog(
            $domain,
            $path,
            $title,
            $user_id,
            [],
            $network ? $network->id : 1
        );

        if (is_wp_error($site_id)) {
            $this->redirect_with_message('mss-site-requests', 'mss_error', 'create_failed');
        }

        // Ensure user is admin of new site
        add_user_to_blog($site_id, $user_id, 'administrator');

        // Update status
        update_user_meta($user_id, self::META_STATUS, 'approved');

        // Send email
        $subject = 'Your site has been approved';
        $message = sprintf(
            "Hi %s,\n\nYour site request has been approved.\n\nYou can access your site here:\nhttps://%s\n\nYou can log in with your existing account.",
            $user->user_login,
            $domain
        );
        wp_mail($user->user_email, $subject, $message);

        $this->redirect_with_message('mss-site-requests', 'mss_approved', '1');
    }

    /**
     * Handle rejection: send email and (optionally) delete user.
     */
    public function handle_reject_request() {
        if (!current_user_can('manage_network_users')) {
            wp_die(__('You do not have permission to do this.', 'mss'));
        }

        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        check_admin_referer('mss_reject_request_' . $user_id);

        $user = get_userdata($user_id);
        if (!$user) {
            $this->redirect_with_message('mss-site-requests', 'mss_error', 'invalid_user');
        }

        $status = get_user_meta($user_id, self::META_STATUS, true);
        if ($status !== 'pending') {
            $this->redirect_with_message('mss-site-requests', 'mss_error', 'invalid_request');
        }

        // Send rejection email
        $subject = 'Your site request was not approved';
        $message = sprintf(
            "Hi %s,\n\nWe’re sorry, but your site request was not approved.\n\nIf you believe this is an error, please contact the administrator.",
            $user->user_login
        );
        wp_mail($user->user_email, $subject, $message);

        // Mark as rejected (you can choose to delete user instead)
        update_user_meta($user_id, self::META_STATUS, 'rejected');

        // Optional: delete user (uncomment if you want this behavior)
        // wp_delete_user($user_id);

        $this->redirect_with_message('mss-site-requests', 'mss_rejected', '1');
    }

    /**
     * Show success/error notices in Network Admin.
     */
    public function network_admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'mss-site-requests') {
            return;
        }

        if (!empty($_GET['mss_approved'])) {
            echo '<div class="notice notice-success"><p>Site request approved and site created.</p></div>';
        }

        if (!empty($_GET['mss_rejected'])) {
            echo '<div class="notice notice-warning"><p>Site request rejected.</p></div>';
        }

        if (!empty($_GET['mss_error'])) {
            $code = sanitize_text_field(wp_unslash($_GET['mss_error']));
            $message = 'An error occurred.';
            if ($code === 'domain_exists') {
                $message = 'Error: A site with that domain already exists.';
            } elseif ($code === 'create_failed') {
                $message = 'Error: Failed to create the site. Please check server logs.';
            } elseif ($code === 'invalid_user' || $code === 'invalid_request') {
                $message = 'Error: Invalid user or request.';
            }
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
        }
    }

    /**
     * Helper: redirect back to the Network Admin page with query args.
     */
    private function redirect_with_message($page, $key, $value) {
        $url = add_query_arg(
            [
                'page' => $page,
                $key   => $value,
            ],
            network_admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Helper: remove leading www. from domain.
     */
    private function strip_www($domain) {
        return preg_replace('/^www\./i', '', $domain);
    }
}

new MSS_Multisite_Signup_Approval();
