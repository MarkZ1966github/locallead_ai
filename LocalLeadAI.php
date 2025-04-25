<?php
/**
 * Plugin Name: LocalLead AI
 * Plugin URI:  https://www.markzschiegner.com/locallead-ai
 * Description: Automate local lead generation for service businesses by scraping directories, sending AI-generated outreach, and managing follow-ups—all within WordPress.
 * Version:     1.0.7
 * Author:      Mark Z Marketing
 * Author URI:  https://www.markzschiegner.com
 * License:     GPL v2 or later
 * Text Domain: locallead-ai
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LocalLeadAI {
    private static $instance;
    public $version = '1.0.7';

    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
            self::$instance->hooks();
        }
        return self::$instance;
    }

    private function hooks() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_shortcode( 'locallead_ai_form', [ $this, 'render_form' ] );
        add_action( 'wp_ajax_nopriv_locallead_ai_get_leads', [ $this, 'ajax_get_leads' ] );
        add_action( 'wp_ajax_locallead_ai_get_leads', [ $this, 'ajax_get_leads' ] );
        add_action( 'wp_ajax_locallead_ai_email_results', [ $this, 'ajax_email_results' ] );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'locallead-ai', plugin_dir_url( __FILE__ ) . 'assets/css/locallead-ai.css', [], $this->version );
        wp_enqueue_script( 'locallead-ai', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', [ 'jquery' ], $this->version, true );
        wp_localize_script( 'locallead-ai', 'LocalLeadAI', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'can_email_all' => current_user_can( 'edit_posts' ),
            'upgrade_url'   => 'https://example.com/upgrade',
        ] );
    }

    public function render_form() {
        ob_start(); ?>
        <div class="locallead-form-wrap">
            <input type="text" id="ll-location" placeholder="Enter ZIP or City" />
            <input type="text" id="ll-industry" placeholder="Service (e.g., roofer)" />
            <button id="ll-submit" class="locallead-btn">Find Leads</button>

            <?php if ( current_user_can( 'edit_posts' ) ) : ?>
                <div class="ll-upgrade-section" style="display:none;">
                    <label class="ll-email-all-label">
                        <input type="checkbox" id="ll-email-all" /> Email All Results
                    </label><br><br>
                    <button id="ll-email-btn" class="locallead-btn" style="display:none;">Send Leads</button><br><br>
                    <button id="ll-export-btn" class="locallead-btn" style="display:none;">Download CSV</button>
                </div>
                <p class="ll-upgrade-note">Want to email full results or export all data? <a href="<?php echo esc_url( $this->localize['upgrade_url'] ); ?>">Upgrade to Pro</a> to unlock these features.</p>
            <?php endif; ?>

            <div id="ll-results"></div>
        </div>
        <?php return ob_get_clean();
    }

    public function ajax_get_leads() {
        $loc     = sanitize_text_field( $_POST['location'] ?? '' );
        $ind     = sanitize_text_field( $_POST['industry'] ?? '' );
        $opts    = get_option( 'locallead_ai_options', [] );
        $api_key = sanitize_text_field( $opts['gmap_api_key'] ?? '' );
        $radius  = intval( $opts['radius'] ?? 10 ) * 1609;

        if ( ! $loc || ! $ind || ! $api_key ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        $is_full = current_user_can( 'edit_posts' );
        $leads   = $this->fetch_leads( $loc, $ind, $api_key, $radius );
        if ( ! $is_full ) {
            $leads = array_slice( $leads, 0, 10 );
        }
        wp_send_json_success( $leads );
    }

    public function ajax_email_results() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        $loc     = sanitize_text_field( $_POST['location'] ?? '' );
        $ind     = sanitize_text_field( $_POST['industry'] ?? '' );
        $opts    = get_option( 'locallead_ai_options', [] );
        $api_key = sanitize_text_field( $opts['gmap_api_key'] ?? '' );
        $radius  = intval( $opts['radius'] ?? 10 ) * 1609;

        $leads = $this->fetch_leads( $loc, $ind, $api_key, $radius );

        // Build HTML table for email
        $html  = '<h2>Your LocalLead AI Full Results</h2>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
        $html .= '<thead><tr><th>Name</th><th>Address</th><th>Contact Page</th><th>Email</th><th>Phone</th></tr></thead><tbody>';
        foreach ( $leads as $l ) {
            $name    = esc_html( $l['name'] );
            $address = esc_html( $l['address'] );
            $contact = esc_url( $l['contact'] );
            $email   = esc_html( $l['email'] );
            $phone   = esc_html( $l['phone'] );

            $html .= '<tr>';
            $html .= $contact ? "<td><a href=\"{$contact}\" target=\"_blank\">{$name}</a></td>" : "<td>{$name}</td>";
            $html .= "<td>{$address}</td>";
            $html .= $contact ? "<td><a href=\"{$contact}\" target=\"_blank\">Contact</a></td>" : '<td></td>';
            $html .= $email   ? "<td><a href=\"mailto:{$email}\">{$email}</a></td>" : '<td></td>';
            $html .= "<td>{$phone}</td>";
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $to      = wp_get_current_user()->user_email;
        $subject = 'Your LocalLead AI Full Results';

        wp_mail( $to, $subject, $html, $headers );
        wp_send_json_success( 'Email sent to ' . $to );
    }

    private function fetch_leads( $loc, $ind, $api_key, $radius ) {
        // Geocode
        $geo = wp_remote_get( "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode( $loc ) . "&key={$api_key}" );
        $geo = json_decode( wp_remote_retrieve_body( $geo ), true );
        if ( empty( $geo['results'][0]['geometry']['location'] ) ) {
            return [];
        }
        $coords = $geo['results'][0]['geometry']['location'];

        // Nearby search
        $places = wp_remote_get( "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location={$coords['lat']},{$coords['lng']}&radius={$radius}&keyword=" . urlencode( $ind ) . "&key={$api_key}" );
        $places = json_decode( wp_remote_retrieve_body( $places ), true );
        if ( empty( $places['results'] ) ) {
            return [];
        }

        $leads = [];
        foreach ( $places['results'] as $r ) {
            $name    = $r['name'] ?? '';
            $address = $r['vicinity'] ?? '';
            $details = wp_remote_get( "https://maps.googleapis.com/maps/api/place/details/json?place_id={$r['place_id']}&fields=name,formatted_phone_number,website,vicinity&key={$api_key}" );
            $info    = json_decode( wp_remote_retrieve_body( $details ), true )['result'] ?? [];
            $phone   = $info['formatted_phone_number'] ?? '';
            $website = $info['website'] ?? '';
            $contact = $website ? rtrim( parse_url( $website, PHP_URL_SCHEME ) . '://' . parse_url( $website, PHP_URL_HOST ), '/' ) . '/contact' : '';

            // Scrape for email
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

            $leads[] = [ 'name' => $name, 'address' => $address, 'website' => $website, 'contact' => $contact, 'email' => $email, 'phone' => $phone ];
        }
        return $leads;
    }
}
LocalLeadAI::instance();
