<?php
/**
 * Plugin Name: LocalLead AI
 * Plugin URI:  https://www.markzschiegner.com/locallead-ai
 * Description: Automate local lead generation for service businesses by scraping directories, sending AI-generated outreach, and managing follow-ups—all within WordPress.
 * Version:     1.0.1
 * Author:      Mark Z Marketing
 * Author URI:  https://www.markzschiegner.com
 * License:     GPL v2 or later
 * Text Domain: locallead-ai
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'LocalLeadAI' ) ) {
    class LocalLeadAI {
        private static $instance;
        public $version = '1.0.1';

        public static function instance() {
            if ( ! isset( self::$instance ) ) {
                self::$instance = new LocalLeadAI();
                self::$instance->setup_hooks();
            }
            return self::$instance;
        }

        private function __construct() {}

        private function setup_hooks() {
            add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

            // Shortcode registration
            add_shortcode( 'locallead_ai_form', array( $this, 'render_lead_form' ) );

            // AJAX handlers for form
            add_action( 'wp_ajax_nopriv_locallead_ai_get_leads', array( $this, 'ajax_get_leads' ) );
            add_action( 'wp_ajax_locallead_ai_get_leads', array( $this, 'ajax_get_leads' ) );
        }

        public function enqueue_admin_assets( $hook ) {
            if ( strpos( $hook, 'locallead-ai' ) === false ) return;
            wp_enqueue_style( 'locallead-ai-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), $this->version );
            wp_enqueue_script( 'locallead-ai-admin', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', array( 'jquery' ), $this->version, true );
        }

        public function enqueue_frontend_assets() {
            wp_enqueue_style( 'locallead-ai-style', plugin_dir_url( __FILE__ ) . 'assets/css/locallead-ai.css', array(), $this->version );
            wp_enqueue_script( 'locallead-ai-frontend', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', array( 'jquery' ), $this->version, true );
            wp_localize_script( 'locallead-ai-frontend', 'LocalLeadAI', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
            ) );
        }

        public function register_admin_menu() {
            add_menu_page(
                __( 'LocalLead AI', 'locallead-ai' ),
                __( 'LocalLead AI', 'locallead-ai' ),
                'manage_options',
                'locallead-ai-dashboard',
                array( $this, 'render_dashboard' ),
                'dashicons-location-alt',
                60
            );
        }

        public function render_dashboard() {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'LocalLead AI Settings', 'locallead-ai' ); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'locallead_ai_options' );
                    do_settings_sections( 'locallead-ai-dashboard' );
                    submit_button();
                    ?>
                </form>
            </div>
            <?php
        }

        public function register_settings() {
            register_setting( 'locallead_ai_options', 'locallead_ai_options', array( $this, 'sanitize_settings' ) );

            add_settings_section( 'locallead_ai_main', __( 'Targeting & API Settings', 'locallead-ai' ), null, 'locallead-ai-dashboard' );

            add_settings_field( 'zip_code', __( 'Default ZIP Code / City', 'locallead-ai' ), array( $this, 'field_zip_code_cb' ), 'locallead-ai-dashboard', 'locallead_ai_main' );
            add_settings_field( 'radius', __( 'Search Radius (miles)', 'locallead-ai' ), array( $this, 'field_radius_cb' ), 'locallead-ai-dashboard', 'locallead_ai_main' );
            add_settings_field( 'industry', __( 'Service Industry', 'locallead-ai' ), array( $this, 'field_industry_cb' ), 'locallead-ai-dashboard', 'locallead_ai_main' );
            add_settings_field( 'gmap_api_key', __( 'Google Maps API Key', 'locallead-ai' ), array( $this, 'field_gmap_key_cb' ), 'locallead-ai-dashboard', 'locallead_ai_main' );
        }

        public function sanitize_settings( $input ) {
            $sanitized = array();
            $sanitized['zip_code']    = sanitize_text_field( $input['zip_code'] );
            $sanitized['radius']      = intval( $input['radius'] );
            $sanitized['industry']    = sanitize_text_field( $input['industry'] );
            $sanitized['gmap_api_key']= sanitize_text_field( $input['gmap_api_key'] );
            return $sanitized;
        }

        public function field_zip_code_cb() {
            $opts = get_option( 'locallead_ai_options' );
            printf('<input type="text" name="locallead_ai_options[zip_code]" value="%s" class="regular-text" />', esc_attr( $opts['zip_code'] ?? '' ));
        }

        public function field_radius_cb() {
            $opts = get_option( 'locallead_ai_options' );
            printf('<input type="number" name="locallead_ai_options[radius]" value="%s" min="1" max="100" />', esc_attr( $opts['radius'] ?? '10' ));
        }

        public function field_industry_cb() {
            $opts = get_option( 'locallead_ai_options' );
            printf('<input type="text" name="locallead_ai_options[industry]" value="%s" class="regular-text" placeholder="e.g., roofer, realtor" />', esc_attr( $opts['industry'] ?? '' ));
        }

        public function field_gmap_key_cb() {
            $opts = get_option( 'locallead_ai_options' );
            printf('<input type="text" name="locallead_ai_options[gmap_api_key]" value="%s" class="regular-text" placeholder="Your Google Maps API Key" />', esc_attr( $opts['gmap_api_key'] ?? '' ));
        }

        /**
         * Shortcode output: lead search form
         */
        public function render_lead_form() {
            ob_start();
            ?>
            <div class="locallead-form-wrap">
                <input type="text" id="ll-location" placeholder="Enter ZIP or City" />
                <input type="text" id="ll-industry" placeholder="Service (e.g., roofer)" />
                <button id="ll-submit" class="locallead-btn">Find Leads</button>
                <div id="ll-results"></div>
            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * AJAX: fetch leads via Google Maps Places API
         */
        public function ajax_get_leads() {
            $location = sanitize_text_field( $_POST['location'] ?? '' );
            $industry = sanitize_text_field( $_POST['industry'] ?? '' );
            $opts = get_option( 'locallead_ai_options' );
            $api_key = $opts['gmap_api_key'] ?? '';

            if ( ! $api_key || ! $location || ! $industry ) {
                wp_send_json_error( 'Missing parameters.' );
            }

            // Geocode location
            $geo = wp_remote_get( "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode( $location ) . "&key={$api_key}" );
            $geo = json_decode( wp_remote_retrieve_body( $geo ), true );
            if ( empty( $geo['results'][0]['geometry']['location'] ) ) {
                wp_send_json_error( 'Could not geocode location.' );
            }
            $coords = $geo['results'][0]['geometry']['location'];

            // Places search
            $places = wp_remote_get( "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location={$coords['lat']},{$coords['lng']}&radius=" . intval( $opts['radius'] ) * 1609 . "&keyword=" . urlencode( $industry ) . "&key={$api_key}" );
            $places = json_decode( wp_remote_retrieve_body( $places ), true );

            if ( empty( $places['results'] ) ) {
                wp_send_json_error( 'No leads found.' );
            }

            // Return basic lead info
            $leads = array_map( function( $r ) {
                return array(
                    'name'    => $r['name'],
                    'address' => $r['vicinity'],
                    'place_id'=> $r['place_id'],
                );
            }, $places['results'] );

            wp_send_json_success( $leads );
        }

    }

    LocalLeadAI::instance();
}
