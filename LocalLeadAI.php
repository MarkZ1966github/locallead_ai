<?php
/**
 * Plugin Name: LocalLead AI
 * Plugin URI:  https://www.markzschiegner.com/locallead-ai
 * Description: Automate local lead generation for service businesses by scraping directories, sending AI-generated outreach, and managing follow-ups—all within WordPress.
 * Version:     1.0.0
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
        /**
         * Singleton instance
         *
         * @var LocalLeadAI
         */
        private static $instance;

        /**
         * Plugin version
         *
         * @var string
         */
        public $version = '1.0.0';

        /**
         * Get singleton instance
         *
         * @return LocalLeadAI
         */
        public static function instance() {
            if ( ! isset( self::$instance ) && ! ( self::$instance instanceof LocalLeadAI ) ) {
                self::$instance = new LocalLeadAI();
                self::$instance->setup_hooks();
            }
            return self::$instance;
        }

        /**
         * Constructor
         */
        private function __construct() {
            // Intentionally left blank
        }

        /**
         * Setup WordPress hooks
         */
        private function setup_hooks() {
            // Admin menu and pages
            add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
            // Register settings
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            // Enqueue scripts/styles
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        }

        /**
         * Enqueue admin CSS/JS
         */
        public function enqueue_admin_assets( $hook ) {
            if ( strpos( $hook, 'locallead-ai' ) === false ) {
                return;
            }
            wp_enqueue_style( 'locallead-ai-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), $this->version );
            wp_enqueue_script( 'locallead-ai-admin', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', array( 'jquery' ), $this->version, true );
        }

        /**
         * Register the plugin settings page
         */
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

        /**
         * Render the main dashboard page
         */
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

        /**
         * Register plugin settings, sections, and fields
         */
        public function register_settings() {
            register_setting(
                'locallead_ai_options',
                'locallead_ai_options',
                array( $this, 'sanitize_settings' )
            );

            add_settings_section(
                'locallead_ai_main',
                __( 'Targeting Settings', 'locallead-ai' ),
                null,
                'locallead-ai-dashboard'
            );

            // ZIP Code field
            add_settings_field(
                'zip_code',
                __( 'ZIP Code / City', 'locallead-ai' ),
                array( $this, 'field_zip_code_cb' ),
                'locallead-ai-dashboard',
                'locallead_ai_main'
            );

            // Radius field
            add_settings_field(
                'radius',
                __( 'Search Radius (miles)', 'locallead-ai' ),
                array( $this, 'field_radius_cb' ),
                'locallead-ai-dashboard',
                'locallead_ai_main'
            );

            // Industry field
            add_settings_field(
                'industry',
                __( 'Service Industry', 'locallead-ai' ),
                array( $this, 'field_industry_cb' ),
                'locallead-ai-dashboard',
                'locallead_ai_main'
            );
        }

        /**
         * Sanitize settings input
         *
         * @param array $input
         * @return array
         */
        public function sanitize_settings( $input ) {
            $sanitized = array();
            $sanitized['zip_code'] = sanitize_text_field( $input['zip_code'] );
            $sanitized['radius']  = intval( $input['radius'] );
            $sanitized['industry'] = sanitize_text_field( $input['industry'] );
            return $sanitized;
        }

        /**
         * Callback for ZIP Code field
         */
        public function field_zip_code_cb() {
            $options = get_option( 'locallead_ai_options' );
            printf(
                '<input type="text" id="zip_code" name="locallead_ai_options[zip_code]" value="%s" class="regular-text" />',
                isset( $options['zip_code'] ) ? esc_attr( $options['zip_code'] ) : ''
            );
        }

        /**
         * Callback for radius field
         */
        public function field_radius_cb() {
            $options = get_option( 'locallead_ai_options' );
            printf(
                '<input type="number" id="radius" name="locallead_ai_options[radius]" value="%s" min="1" max="100" />',
                isset( $options['radius'] ) ? esc_attr( $options['radius'] ) : '10'
            );
        }

        /**
         * Callback for industry field
         */
        public function field_industry_cb() {
            $options = get_option( 'locallead_ai_options' );
            printf(
                '<input type="text" id="industry" name="locallead_ai_options[industry]" value="%s" class="regular-text" placeholder="e.g., roofer, realtor" />',
                isset( $options['industry'] ) ? esc_attr( $options['industry'] ) : ''
            );
        }

    }

    // Initialize plugin
    LocalLeadAI::instance();
}
