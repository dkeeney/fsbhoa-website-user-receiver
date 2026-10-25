<?php
/**
 * Plugin Name:       FSBHOA Website User Receiver
 * Plugin URI:        https://github.com/fsbhoa/
 * Description:       Receives user sync payloads from the local Access Control system and updates WordPress users.
 * Version:           1.0.1
 * Author:            FSBHOA
 * License:           Private/Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fsbhoa_Website_User_Receiver {

    private $api_key_option = 'fsbhoa_receiver_api_key';
    private $option_group   = 'fsbhoa_receiver_options';
    private $page_slug      = 'fsbhoa-web-sync-receiver';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_fsbhoa_generate_receiver_key', [ $this, 'ajax_generate_key' ] );
        add_action( 'rest_api_init', [ $this, 'register_sync_endpoint' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_plugin_action_links' ] );
    }

    // ========================================================================
    // ADMIN SETTINGS
    // ========================================================================

    public function add_settings_page() {
        // Using add_menu_page creates a top-level menu item instead of a submenu
        add_menu_page(
            'Website User Receive Configuration', // The text in the browser tab/header
            'Website User Receive',               // The text in the left-hand sidebar menu
            'manage_options',                     // Required capability
            $this->page_slug,                     // Menu slug
            [ $this, 'render_settings_page' ],    // Callback function to render the page
            'dashicons-download',                 // Built-in WP icon (downward arrow for receiving)
            80                                    // Position it lower in the menu list
        );
    }

    public function add_plugin_action_links( $links ) {
        // Updated URL structure: top-level pages use admin.php instead of options-general.php
        $settings_link = '<a href="admin.php?page=' . $this->page_slug . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function register_settings() {
        add_settings_section(
            'fsbhoa_receiver_main_section',
            'Receiver Configuration',
            [ $this, 'render_section_description' ],
            $this->page_slug
        );

        add_settings_field(
            'fsbhoa_receiver_api_key',
            'Incoming API Key',
            [ $this, 'render_api_key_field' ],
            $this->page_slug,
            'fsbhoa_receiver_main_section'
        );
        register_setting( $this->option_group, $this->api_key_option, 'sanitize_text_field' );

        // Safe Mode Toggle
        add_settings_field(
            'fsbhoa_enable_deletions',
            'Enable Hard Deletions',
            [ $this, 'render_checkbox_field' ],
            $this->page_slug,
            'fsbhoa_receiver_main_section',
            [
                'id'   => 'fsbhoa_enable_deletions',
                'desc' => 'If unchecked, the sync runs in "Safe Mode". Orphaned residents will be reported back to the sender but NOT actually deleted.'
            ]
        );
        register_setting( $this->option_group, 'fsbhoa_enable_deletions', 'sanitize_text_field' );
    }

    public function render_section_description() {
        echo '<p>Generate the secure key required to accept incoming data, then copy it to the Sender plugin on your local network.</p>';
    }

    public function render_api_key_field() {
        $value = get_option( $this->api_key_option, '' );
        ?>
        <input type="text" name="<?php echo esc_attr( $this->api_key_option ); ?>" id="fsbhoa_receiver_api_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text" readonly="readonly" placeholder="Click generate to create a new key" />
        <button type="button" class="button" id="btn-generate-receiver-key">Generate New Key</button>
        <p class="description">Copy this key exactly and paste it into the local Sender configuration.</p>
        <?php
    }

    public function render_checkbox_field( $args ) {
        $id    = $args['id'];
        $desc  = $args['desc'] ?? '';
        $value = get_option( $id, '0' );

        echo "<input type='checkbox' name='" . esc_attr($id) . "' id='" . esc_attr($id) . "' value='1' " . checked( '1', $value, false ) . " />";
        if ( $desc ) {
            echo "<p class='description'>" . esc_html($desc) . "</p>";
        }
    }

    public function ajax_generate_key() {
        check_ajax_referer( 'fsbhoa_generate_key_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $new_key = base64_encode( random_bytes( 32 ) );
        wp_send_json_success( [ 'api_key' => $new_key ] );
    }

    private function handle_unmanaged_deletions() {
        if ( isset( $_GET['delete_unmanaged_user'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'delete_unmanaged_' . intval($_GET['delete_unmanaged_user']) ) ) {
                $user_id = intval( $_GET['delete_unmanaged_user'] );
    
                // Fetch the target user to check their roles
                $target_user = get_userdata( $user_id );
                $is_target_admin = $target_user && in_array( 'administrator', (array) $target_user->roles, true );
    
                // Block deletion if it's the current user OR any administrator
                if ( current_user_can( 'delete_users' ) && $user_id !== get_current_user_id() && ! $is_target_admin ) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user( $user_id );
                    echo 'User successfully deleted.';
                } else {
                    echo 'Cannot delete this user (Admin or Protected).';
                }
            }
        }
    }

    public function render_settings_page() {
        // Intercept any delete button clicks before rendering the page
        $this->handle_unmanaged_deletions();
        ?>
        <div class="wrap">
            <h1>FSBHOA Sync Receiver</h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_group );
                do_settings_sections( $this->page_slug );
                submit_button();
                ?>
            </form>

            <hr style="margin: 30px 0;">

            <h2>Unmanaged Website Users</h2>
            <p>These users exist in the website database but do not have an Access Control watermark. They will not be updated or deleted by the sync process.</p>

            <?php
            $unmanaged_users = get_users([
                'meta_query' => [
                    [
                        'key'     => 'fsbhoa_local_ac_id',
                        'compare' => 'NOT EXISTS'
                    ]
                ]
            ]);

            $total_unmanaged = count($unmanaged_users);

            if ( empty( $unmanaged_users ) ) {
                echo '<p><em>All users are currently managed by the sync system.</em></p>';
            } else {
                echo '<p><strong>Total Unmanaged Users: ' . esc_html( $total_unmanaged ) . '</strong></p>';
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr>';
                echo '<th>ID</th><th>Email</th><th>Name</th><th>Roles</th><th>Registered</th><th>Action</th>';
                echo '</tr></thead><tbody>';

                foreach ( $unmanaged_users as $u ) {
                    $roles_list = !empty( $u->roles ) ? implode( ', ', $u->roles ) : 'None';
                    $name       = trim( $u->first_name . ' ' . $u->last_name );
                    $is_admin   = in_array( 'administrator', (array) $u->roles, true );

                    // Generate the secure URL for the delete button
                    $delete_url = wp_nonce_url(
                        admin_url( 'admin.php?page=' . $this->page_slug . '&delete_unmanaged_user=' . $u->ID ),
                        'delete_unmanaged_' . $u->ID
                    );

                    echo '<tr>';
                    echo '<td>' . intval( $u->ID ) . '</td>';
                    echo '<td><strong>' . esc_html( $u->user_email ) . '</strong></td>';
                    echo '<td>' . esc_html( $name ) . '</td>';
                    echo '<td>' . esc_html( $roles_list ) . '</td>';
                    echo '<td>' . esc_html( date( 'Y-m-d', strtotime( $u->user_registered ) ) ) . '</td>';

                    // The Delete Button Column
                    echo '<td>';
                    if ( $u->ID === get_current_user_id() ) {
                        echo '<em>Current User</em>';
                    } elseif ( $is_admin ) {
                        echo '<em>Admin (Protected)</em>';
                    } else {
                        echo '<a href="' . esc_url( $delete_url ) . '" class="button button-small" style="color: #a00; border-color: #a00;" onclick="return confirm(\'Permanently delete ' . esc_js( $u->user_email ) . '?\');">Delete</a>';
                    }
                    echo '</td>';

                    echo '</tr>';
                }
    
                echo '</tbody></table>';
            }
            ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#btn-generate-receiver-key').on('click', function() {
                if ( ! confirm('Are you sure? Generating a new key will instantly break the connection until you update the Sender with the new key.') ) {
                    return;
                }

                $.post(ajaxurl, {
                    action: 'fsbhoa_generate_receiver_key',
                    nonce: '<?php echo wp_create_nonce( "fsbhoa_generate_key_nonce" ); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#fsbhoa_receiver_api_key').val(response.data.api_key);
                        $('#fsbhoa_receiver_api_key').css('background-color', '#e7f5ea');
                        setTimeout(function(){ $('#fsbhoa_receiver_api_key').css('background-color', ''); }, 1000);
                        $('#btn-generate-receiver-key').after('<span style="color:red; margin-left:10px;" id="save-reminder">Don\'t forget to click Save Changes!</span>');
                        setTimeout(function(){ $('#save-reminder').fadeOut(); }, 4000);
                    } else {
                        alert('Error generating key: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ========================================================================
    // REST API LOGIC
    // ========================================================================

    public function register_sync_endpoint() {
        register_rest_route( 'fsbhoa/v1', '/ac-web-sync', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'process_sync_payload' ],
            'permission_callback' => [ $this, 'verify_api_key' ],
        ] );
    }

    public function verify_api_key( WP_REST_Request $request ) {
        $provided_key = $request->get_header( 'x-fsbhoa-api-key' );
        $stored_key   = get_option( $this->api_key_option, '' );

        if ( empty( $stored_key ) ) {
            return new WP_Error( 'rest_forbidden', 'Receiver API key is not configured.', [ 'status' => 500 ] );
        }

        if ( empty( $provided_key ) || ! hash_equals( $stored_key, $provided_key ) ) {
            return new WP_Error( 'rest_forbidden', 'Invalid or missing API key.', [ 'status' => 401 ] );
        }

        return true;
    }

    public function process_sync_payload( WP_REST_Request $request ) {
        $payload = $request->get_json_params();

        if ( empty( $payload ) || ! is_array( $payload ) ) {
            return new WP_Error( 'rest_bad_request', 'Invalid payload. Expected an array of users.', [ 'status' => 400 ] );
        }

        $sync_timestamp = time();
        $processed = 0;
        $created   = 0;
        $updated   = 0;
        $errors    = [];

        // Extract all incoming local IDs for the shared email check
        $all_incoming_ids = [];
        foreach ( $payload as $u ) {
            if ( ! empty( $u['ac_id'] ) ) {
                $all_incoming_ids[] = (string) $u['ac_id'];
            }
        }

        // --- THE WATERMARK PHASE ---
        foreach ( $payload as $user_data ) {
            // 0. Extract the data for this specific user first!
            $email      = isset($user_data['email']) ? trim($user_data['email']) : '';
            $first_name = isset($user_data['first_name']) ? $user_data['first_name'] : 'Unknown';
            $last_name  = isset($user_data['last_name']) ? $user_data['last_name'] : 'Unknown';


            // 1. Silently skip residents who do not have an email on file
            if ( empty( $email ) ) {
                continue;
            }
            if ( ! is_email( $user_data['email'] ) ) {
                $errors[] = sprintf( 
                    'Skipped record: Invalid email for %s %s. (Attempted email: "%s")', 
                    $first_name, 
                    $last_name, 
                    $email 
                );
                continue;
            }

            $email      = sanitize_email( $user_data['email'] );
            $first_name = sanitize_text_field( $user_data['first_name'] ?? '' );
            $last_name  = sanitize_text_field( $user_data['last_name'] ?? '' );
            $ac_id      = sanitize_text_field( $user_data['ac_id'] ?? '' );

            $existing_user = get_user_by( 'email', $email );

            if ( $existing_user ) {
                // THE ADMIN SHIELD (Update Phase): Do not alter Admin accounts
                if ( in_array( 'administrator', (array) $existing_user->roles, true ) ) {
                    $processed++;
                    continue; // Skip altering the admin entirely
                }

                // SHARED EMAIL BYPASS: If this WP account is claimed by a DIFFERENT resident in this batch
                $existing_ac_id = get_user_meta( $existing_user->ID, 'fsbhoa_local_ac_id', true );
                if ( ! empty( $existing_ac_id ) && $existing_ac_id !== (string)$ac_id && in_array( $existing_ac_id, $all_incoming_ids, true ) ) {
                    $processed++;
                    continue; // Skip to prevent overwriting the spouse
                }

                $current_first = $existing_user->first_name;
                $current_last  = $existing_user->last_name;

                if ( $current_first !== $first_name || $current_last !== $last_name ) {
                    $update_data = [
                        'ID'         => $existing_user->ID,
                        'first_name' => $first_name,
                        'last_name'  => $last_name,
                    ];

                    $user_id = wp_update_user( $update_data );

                    if ( ! is_wp_error( $user_id ) ) {
                        $updated++;
                        if ( ! empty( $ac_id ) ) update_user_meta( $user_id, 'fsbhoa_local_ac_id', $ac_id );
                        update_user_meta( $user_id, 'fsbhoa_last_sync', $sync_timestamp );
                    } else {
                        $errors[] = $email . ' (' . $user_id->get_error_message() . ')';
                    }
                } else {
                    // Nothing changed! Just touch the watermark and timestamp.
                    if ( ! empty( $ac_id ) ) update_user_meta( $existing_user->ID, 'fsbhoa_local_ac_id', $ac_id );
                    update_user_meta( $existing_user->ID, 'fsbhoa_last_sync', $sync_timestamp );
                    $updated++;
                }
            } else {
                $insert_data = [
                    'user_email' => $email,
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                    'user_login' => $email,
                    'user_pass'  => wp_generate_password( 24, true, true ),
                    'role'       => 'subscriber',
                ];

                $user_id = wp_insert_user( $insert_data );

                if ( ! is_wp_error( $user_id ) ) {
                    $created++;
                    if ( ! empty( $ac_id ) ) update_user_meta( $user_id, 'fsbhoa_local_ac_id', $ac_id );
                    update_user_meta( $user_id, 'fsbhoa_last_sync', $sync_timestamp );
                } else {
                    $errors[] = $email . ' (' . $user_id->get_error_message() . ')';
                }
            }
            $processed++;
        }

        // --- THE SWEEP PHASE (HARD DELETE OR SAFE MODE) ---
        $deleted = 0;
        $safe_mode_emails = [];
        $enable_deletions = get_option( 'fsbhoa_enable_deletions', '0' );

        $orphaned_users = get_users([
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'     => 'fsbhoa_local_ac_id',
                    'compare' => 'EXISTS'
                ],
                [
                    'key'     => 'fsbhoa_last_sync',
                    'value'   => $sync_timestamp,
                    'compare' => '<'
                ]
            ]
        ]);

        if ( $enable_deletions === '1' ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        foreach ( $orphaned_users as $orphan ) {
            if ( in_array( 'administrator', (array) $orphan->roles, true ) ) {
                continue;
            }
            if ( $enable_deletions === '1' ) {
                wp_delete_user( $orphan->ID );
                $deleted++;
            } else {
                // Safe mode: just log their email
                $safe_mode_emails[] = $orphan->user_email;
            }
        }

        return rest_ensure_response( [
            'status'   => 'success',
            'message'  => 'Sync payload processed.',
            'summary'  => [
                'total_processed'  => $processed,
                'users_created'    => $created,
                'users_updated'    => $updated,
                'users_deleted'    => $deleted,
                'safe_mode_caught' => $safe_mode_emails, // Send the list back to the sender
                'errors'           => $errors,
            ]
        ] );
    }
}

new Fsbhoa_Website_User_Receiver();

