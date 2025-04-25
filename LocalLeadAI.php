<?php
/**
 * Plugin Name: LocalLead AI
 * Plugin URI:  https://www.markzschiegner.com/locallead-ai
 * Description: Automate local lead generation for service businesses by scraping directories, sending AI-generated outreach, and managing follow-ups—all within WordPress.
 * Version:     1.0.3
 * Author:      Mark Z Marketing
 * Author URI:  https://www.markzschiegner.com
 * License:     GPL v2 or later
 * Text Domain: locallead-ai
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LocalLeadAI {
    private static $instance;
    public $version = '1.0.3';

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
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'locallead-ai', plugin_dir_url( __FILE__ ) . 'assets/css/locallead-ai.css', [], $this->version );
        wp_enqueue_script( 'locallead-ai', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', [ 'jquery' ], $this->version, true );
        wp_localize_script( 'locallead-ai', 'LocalLeadAI', [ 'ajax_url' => admin_url( 'admin-ajax.php' ) ] );
    }

    public function render_form() {
        ob_start(); ?>
        <div class="locallead-form-wrap">
            <input type="text" id="ll-location" placeholder="Enter ZIP or City" />
            <input type="text" id="ll-industry" placeholder="Service (e.g., roofer)" />
            <button id="ll-submit" class="locallead-btn">Find Leads</button>
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

        // Geocode
        $geo = wp_remote_get( "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode( $loc ) . "&key={$api_key}" );
        $geo = json_decode( wp_remote_retrieve_body( $geo ), true );
        if ( empty( $geo['results'][0]['geometry']['location'] ) ) {
            wp_send_json_error( 'Could not geocode location.' );
        }
        $coords = $geo['results'][0]['geometry']['location'];

        // Nearby search
        $places = wp_remote_get( "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location={$coords['lat']},{$coords['lng']}&radius={$radius}&keyword=" . urlencode( $ind ) . "&key={$api_key}" );
        $places = json_decode( wp_remote_retrieve_body( $places ), true );

        if ( empty( $places['results'] ) ) {
            wp_send_json_error( 'No leads found.' );
        }

        $leads = [];
        foreach ( $places['results'] as $r ) {
            // Build name & address
            $name    = $r['name'] ?? '';
            $address = $r['vicinity'] ?? '';

            // Determine website via details lookup
            $details = wp_remote_get( "https://maps.googleapis.com/maps/api/place/details/json?place_id={$r['place_id']}&fields=name,formatted_phone_number,website,vicinity&key={$api_key}" );
            $details = json_decode( wp_remote_retrieve_body( $details ), true );
            $info    = $details['result'] ?? [];

            $phone   = $info['formatted_phone_number'] ?? '';
            $website = $info['website'] ?? '';

            // Build contact page URL
            $contact_link = '';
            if ( $website ) {
                $origin       = parse_url( $website, PHP_URL_SCHEME ) . '://' . parse_url( $website, PHP_URL_HOST );
                $contact_link = rtrim( $origin, '/' ) . '/contact';
            }

            // Scrape contact page for first email
            $email = '';
            if ( $contact_link ) {
                $page = wp_remote_get( $contact_link );
                if ( ! is_wp_error( $page ) ) {
                    $body = wp_remote_retrieve_body( $page );
                    if ( preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $body, $m ) ) {
                        $email = $m[0];
                    }
                }
            }

            $leads[] = [
                'name'    => $name,
                'address' => $address,
                'website' => $website,
                'contact' => $contact_link,
                'email'   => $email,
                'phone'   => $phone,
            ];
        }

        wp_send_json_success( $leads );
    }
}
LocalLeadAI::instance();
