<?php
/**
 * Plugin Name: LocalLead AI
 * Plugin URI:  https://www.bizleadslocal.com/
 * Description: Automate local lead generation.
 * Version:     1.1.3
 * Author:      Mark Z Marketing
 * Author URI:  https://www.markzmarketing.com/
 * License:     GPL v2 or later
 * Text Domain: locallead-ai
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LocalLeadAI {
    private static $instance;
    public $version = '1.1.0';

    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
            self::$instance->hooks();
        }
        return self::$instance;
    }

    private function hooks() {
        add_action( 'template_redirect', [ $this, 'require_login' ] );
        add_filter( 'login_redirect', [ $this, 'login_redirect_to_form' ], 10, 3 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_shortcode( 'locallead_ai_form', [ $this, 'render_form' ] );
        add_action( 'wp_ajax_nopriv_locallead_ai_get_leads', [ $this, 'ajax_get_leads' ] );
        add_action( 'wp_ajax_locallead_ai_get_leads', [ $this, 'ajax_get_leads' ] );
        add_action( 'wp_ajax_locallead_ai_email_results', [ $this, 'ajax_email_results' ] );
        add_action( 'wp_ajax_locallead_ai_download_csv', [ $this, 'ajax_download_csv' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'locallead-ai', plugin_dir_url( __FILE__ ) . 'assets/css/locallead-ai.css', [], $this->version );
        wp_enqueue_script( 'locallead-ai', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', ['jquery'], $this->version, true );
        wp_localize_script( 'locallead-ai', 'LocalLeadAI', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'can_email_all' => current_user_can( 'edit_posts' ),
            'upgrade_pro_url' => 'https://bizleadslocal.com/upgrade-to-pro/',
            'upgrade_elite_url' => 'https://bizleadslocal.com/upgrade-to-elite/',
        ] );
    }

    public function require_login() {
        if ( is_singular() && has_shortcode( get_post()->post_content, 'locallead_ai_form' ) ) {
            if ( ! is_user_logged_in() ) {
                $redirect = urlencode( home_url( '/biz-leads-local/' ) );
                $register_url = site_url( "/wp-login.php?action=register&redirect_to={$redirect}" );
                wp_redirect( $register_url );
                exit;
            }
        }
    }

    public function login_redirect_to_form( $redirect_to, $request, $user ) {
        if ( isset( $user->roles ) && is_array( $user->roles ) ) {
            return home_url( '/biz-leads-local/' );
        }
        return $redirect_to;
    }

    public function render_form() {
        ob_start(); ?>
        <div class="locallead-form-wrap">
            <input type="text" id="ll-location" placeholder="Enter ZIP or City" />
            <input type="text" id="ll-industry" placeholder="Service (e.g., roofer)" />
            <button id="ll-submit" class="locallead-btn">Find Leads</button>
            <?php if ( is_user_logged_in() ) : ?>
                <div class="ll-upgrade-section">
                    <?php if ( current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_others_posts' ) ) : ?>
                        <p class="ll-email-note">To See ALL the results - Please Email them to Yourself!</p>
                    <?php elseif ( current_user_can( 'edit_others_posts' ) ) : ?>
                        <p class="ll-email-note">To See ALL the results - Please Email them to Yourself - OR - Download a CSV file</p>
                    <?php endif; ?>
                    <label class="ll-email-all-label">
                        <input type="checkbox" id="ll-email-all" /> Email All Results
                    </label><br><br>
                    <button id="ll-email-btn" class="locallead-btn" style="display:none;">Send Leads</button><br><br>
                    <?php if ( current_user_can( 'edit_others_posts' ) ) : ?>
                        <button id="ll-export-btn" class="locallead-btn" style="display:none;">Download CSV</button>
                    <?php endif; ?>
                </div>
                <?php if ( ! current_user_can( 'edit_posts' ) ) : ?>
                    <p class="ll-results-note">Would you like to see more than 5 results at a time - and be able to email ALL results to you? Upgrade to Pro and if you'd like to download ALL the results as a csv file - Upgrade to Elite!</p>
                <?php endif; ?>
                <?php if ( ! current_user_can( 'edit_others_posts' ) ) : ?>
                    <p class="ll-upgrade-note">
                        <?php if ( current_user_can( 'edit_posts' ) ) : ?>
                            Upgrade for more: <a href="<?php echo esc_url( self::$instance->get_localize('upgrade_elite_url') ); ?>">Elite - $49/month</a>
                        <?php else : ?>
                            Upgrade for more: <a href="<?php echo esc_url( self::$instance->get_localize('upgrade_pro_url') ); ?>">Pro - $29/month</a> or 
                            <a href="<?php echo esc_url( self::$instance->get_localize('upgrade_elite_url') ); ?>">Elite - $49/month</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
            <div id="ll-results"></div>
        </div>
        <?php return ob_get_clean();
    }

    public function ajax_get_leads() {
        $loc = sanitize_text_field( $_POST['location'] ?? '' );
        $ind = sanitize_text_field( $_POST['industry'] ?? '' );
        $opts = get_option( 'locallead_ai_options', [] );
        $api_key = sanitize_text_field( $opts['gmap_api_key'] ?? '' );
        $radius = intval( $opts['radius'] ?? 10 ) * 1609;
        if ( ! $loc || ! $ind || ! $api_key ) {
            wp_send_json_error( 'Missing parameters.' );
        }
        $leads = $this->fetch_leads( $loc, $ind, $api_key, $radius );
        error_log( 'LocalLeadAI Get Leads: User ' . get_current_user_id() . ' fetched ' . count( $leads ) . ' leads for location ' . $loc . ', industry ' . $ind );
        if ( ! current_user_can( 'edit_posts' ) ) {
            $leads = array_slice( $leads, 0, 5 );
        } elseif ( current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_others_posts' ) ) {
            $leads = array_slice( $leads, 0, 7 );
        }
        wp_send_json_success( $leads );
    }

    public function ajax_email_results() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        $loc = sanitize_text_field( $_POST['location'] ?? '' );
        $ind = sanitize_text_field( $_POST['industry'] ?? '' );
        $opts = get_option( 'locallead_ai_options', [] );
        $api_key = sanitize_text_field( $opts['gmap_api_key'] ?? '' );
        $radius = intval( $opts['radius'] ?? 10 ) * 1609;
        if ( ! $loc || ! $ind || ! $api_key ) {
            wp_send_json_error( 'Missing parameters.' );
        }
        $leads = $this->fetch_leads( $loc, $ind, $api_key, $radius );
        error_log( 'LocalLeadAI Email Results: User ' . get_current_user_id() . ' fetched ' . count( $leads ) . ' leads for location ' . $loc . ', industry ' . $ind );
        $html = '<h2>Your Biz Leads Local Full Results</h2><table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;"><thead><tr><th>Name</th><th>Address</th><th>Contact</th><th>Email</th><th>Phone</th></tr></thead><tbody>';
        foreach ( $leads as $l ) {
            $name = esc_html( $l['name'] );
            $address = esc_html( $l['address'] );
            $contact = esc_url( $l['contact'] );
            $email = esc_html( $l['email'] );
            $phone = esc_html( $l['phone'] );
            $html .= "<tr><td>" . ($contact ? "<a href=\"{$contact}\" target=\"_blank\">{$name}</a>" : $name) . "</td><td>{$address}</td><td>" . ($contact ? "<a href=\"{$contact}\" target=\"_blank\">Contact</a>" : '') . "</td><td>" . ($email ? "<a href=\"mailto:{$email}\">{$email}</a>" : '') . "</td><td>{$phone}</td></tr>";
        }
        $html .= '</tbody></table>';
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $to = wp_get_current_user()->user_email;
        $subject = 'Your Biz Leads Local Full Results';
        wp_mail( $to, $subject, $html, $headers );
        wp_send_json_success( 'Email sent to ' . $to );
    }

    public function ajax_download_csv() {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_others_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions for CSV download.' );
        }
        $loc = sanitize_text_field( $_POST['location'] ?? '' );
        $ind = sanitize_text_field( $_POST['industry'] ?? '' );
        $opts = get_option( 'locallead_ai_options', [] );
        $api_key = sanitize_text_field( $opts['gmap_api_key'] ?? '' );
        $radius = intval( $opts['radius'] ?? 10 ) * 1609;
        if ( ! $loc || ! $ind || ! $api_key ) {
            wp_send_json_error( 'Missing parameters for CSV download.' );
        }
        $leads = $this->fetch_leads( $loc, $ind, $api_key, $radius );
        error_log( 'LocalLeadAI CSV Download: User ' . get_current_user_id() . ' fetched ' . count( $leads ) . ' leads for location ' . $loc . ', industry ' . $ind );
        wp_send_json_success( $leads );
    }

    private function fetch_leads( $loc, $ind, $api_key, $radius ) {
        $geo = wp_remote_get( "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode( $loc ) . "&key={$api_key}" );
        $geo = json_decode( wp_remote_retrieve_body( $geo ), true );
        if ( empty( $geo['results'][0]['geometry']['location'] ) ) {
            return [];
        }
        $coords = $geo['results'][0]['geometry']['location'];
        $places = wp_remote_get( "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location={$coords['lat']},{$coords['lng']}&radius={$radius}&keyword=" . urlencode( $ind ) . "&key={$api_key}" );
        $places = json_decode( wp_remote_retrieve_body( $places ), true );
        if ( empty( $places['results'] ) ) {
            return [];
        }
        $leads = [];
        foreach ( $places['results'] as $r ) {
            $name = $r['name'] ?? '';
            $address = $r['vicinity'] ?? '';
            $details = wp_remote_get( "https://maps.googleapis.com/maps/api/place/details/json?place_id={$r['place_id']}&fields=name,formatted_phone_number,website,vicinity&key={$api_key}" );
            $info = json_decode( wp_remote_retrieve_body( $details ), true )['result'] ?? [];
            $phone = $info['formatted_phone_number'] ?? '';
            $website = $info['website'] ?? '';
            $contact = $website ? rtrim( parse_url( $website, PHP_URL_SCHEME ) . '://' . parse_url( $website, PHP_URL_HOST ), '/' ) . '/contact' : '';
            $email = '';
            if ( $contact ) {
                $page = wp_remote_get( $contact );
                if ( ! is_wp_error( $page ) ) {
                    $body = wp_remote_retrieve_body( $page );
                    if ( preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $body, $m ) ) {
                        $email = $m[0];
                    }
                }
            }
            $leads[] = [
                'name' => $name,
                'address' => $address,
                'contact' => $contact,
                'email' => $email,
                'phone' => $phone,
                'website' => $website,
            ];
        }
        return $leads;
    }

    public function register_admin_menu() {
        add_menu_page( 'LocalLead AI', 'LocalLead AI', 'manage_options', 'locallead-ai-settings', [ $this, 'render_admin_page' ], 'dashicons-location-alt', 60 );
    }

    public function register_settings() {
        register_setting( 'locallead_ai_options', 'locallead_ai_options', [ $this, 'sanitize_settings' ] );
        add_settings_section( 'llai_main', 'API & Default Settings', null, 'locallead-ai-settings' );
        add_settings_field( 'gmap_api_key', 'Google Maps API Key', [ $this, 'field_gmap_key_cb' ], 'locallead-ai-settings', 'llai_main' );
        add_settings_field( 'zip_code', 'Default ZIP / City', [ $this, 'field_zip_code_cb' ], 'locallead-ai-settings', 'llai_main' );
        add_settings_field( 'radius', 'Search Radius (miles)', [ $this, 'field_radius_cb' ], 'locallead-ai-settings', 'llai_main' );
        add_settings_field( 'industry', 'Default Industry', [ $this, 'field_industry_cb' ], 'locallead-ai-settings', 'llai_main' );
    }

    public function sanitize_settings( $input ) {
        return [
            'gmap_api_key' => sanitize_text_field( $input['gmap_api_key'] ?? '' ),
            'zip_code' => sanitize_text_field( $input['zip_code'] ?? '' ),
            'radius' => intval( $input['radius'] ?? 10 ),
            'industry' => sanitize_text_field( $input['industry'] ?? '' ),
        ];
    }

    public function field_gmap_key_cb() {
        $opts = get_option( 'locallead_ai_options', [] );
        printf( '<input type="text" name="locallead_ai_options[gmap_api_key]" value="%s" class="regular-text" />', esc_attr( $opts['gmap_api_key'] ?? '' ) );
    }

    public function field_zip_code_cb() {
        $opts = get_option( 'locallead_ai_options', [] );
        printf( '<input type="text" name="locallead_ai_options[zip_code]" value="%s" class="regular-text" />', esc_attr( $opts['zip_code'] ?? '' ) );
    }

    public function field_radius_cb() {
        $opts = get_option( 'locallead_ai_options', [] );
        printf( '<input type="number" name="locallead_ai_options[radius]" value="%s" min="1" max="100" />', esc_attr( $opts['radius'] ?? '10' ) );
    }

    public function field_industry_cb() {
        $opts = get_option( 'locallead_ai_options', [] );
        printf( '<input type="text" name="locallead_ai_options[industry]" value="%s" class="regular-text" placeholder="e.g., roofer" />', esc_attr( $opts['industry'] ?? '' ) );
    }

    public function render_admin_page() {
        ?><div class="wrap"><h1>LocalLead AI Settings</h1><form method="post" action="options.php"><?php settings_fields( 'locallead_ai_options' ); do_settings_sections( 'locallead-ai-settings' ); submit_button(); ?></form></div><?php
    }

    public function get_localize( $key ) {
        global $wp_scripts;
        $script = $wp_scripts->registered['locallead-ai'];
        return isset( $script->extra['data'] ) ? json_decode( $script->extra['data'], true )[$key] : '';
    }
}

LocalLeadAI::instance();