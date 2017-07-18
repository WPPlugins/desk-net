<?php

/**
 * @package wp-desk-net
 * @author  Desk-Net GmbH
 */

require_once 'template/DeskNetTemplateMatchingPage.php';
include_once 'DeskNetDeleteItems.php';
include_once 'DeskNetRequestMethod.php';

class WPDeskNetCore {
	/**
	 * @const string Base URL
	 */
	const DN_BASE_URL = 'https://desk-net.com';

    /**
     * @var string Token
     */
    protected $token;

    /**
     * @var integer Error Message
     *
     * default value 1 - Successful update
     */
    protected $errorMessage = 1;

    /**
     * @var array Warning Message
     */
    protected $warningMessage = array();

    /**
     * @var boolean Status Upload page
     */
    protected $statusUpload = false;

    function __construct() {
        register_activation_hook( 'desk-net/wp-desk-net.php', array( $this, 'wp_desk_net_activate' ) );
        register_deactivation_hook( 'desk-net/wp-desk-net.php', array( $this, 'wp_desk_net_deactivate' ) );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_init' ) );
        add_action( 'edit_form_after_title', array( $this, 'desk_net_description' ) );

        add_filter( 'plugin_action_links_' . 'desk-net/wp-desk-net.php', array( $this, 'add_action_links' ) );
    }

    /**
     * Initialize admin menu
     *
     */
    function admin_menu() {

        add_menu_page(
            __( 'Desk-Net', 'wp_desk_net' ),
            __( 'Desk-Net', 'wp_desk_net' ),
            'manage_options',
            'wp-desk-net',
            array( $this, 'settings_page' ),
            'dashicons-arrow-right-alt2',
            6
        );

        add_submenu_page(
            'wp-desk-net',
            __( 'WordPress Credentials', 'wp_desk_net' ),
            __( 'WordPress Credentials', 'wp_desk_net' ),
            'manage_options',
            'wp-desk-net'
        );

        add_submenu_page(
            'wp-desk-net',
            __( 'Desk-Net Credentials', 'wp_desk_net' ),
            __( 'Desk-Net Credentials', 'wp_desk_net' ),
            'manage_options',
            'wp_desk_net_credential',
            array( $this, 'credential_page' )
        );

        add_submenu_page(
            'wp-desk-net',
            __( 'Status Matching', 'wp_desk_net' ),
            __( 'Status Matching', 'wp_desk_net' ),
            'manage_options',
            'wp_desk_net_status-matching',
            array( $this, 'status_matching_page' )
        );

        add_submenu_page(
            'wp-desk-net',
            __( 'Category Matching', 'wp_desk_net' ),
            __( 'Category Matching', 'wp_desk_net' ),
            'manage_options',
            'wp_desk_net_category-matching',
            array( $this, 'category_matching_page' )
        );

        add_submenu_page(
            'wp-desk-net',
            __( 'Content Settings', 'wp_desk_net' ),
            __( 'Content Settings', 'wp_desk_net' ),
            'manage_options',
            'wp_desk_net_content-matching',
            array( $this, 'content_matching_page' )
        );

        add_submenu_page(
            'wp-desk-net',
            __( 'Desk-Net ID in URL', 'wp_desk_net' ),
            __( 'Desk-Net ID in URL', 'wp_desk_net' ),
            'manage_options',
            'wp_desk_net_permalinks',
            array( $this, 'permalinks_page' )
        );
    }

    /**
     * Perform template settings page
     *
     */
    function settings_page() {

        echo '<div class="wrap">';

        settings_errors();

        echo '<form action="options.php" method="post" id="generate-credentials">';

        settings_fields( 'desk_net_settings' );
        do_settings_sections( 'desk_net_settings' );
        submit_button( 'Generate new credentials', 'secondary' );

        echo '<p><input type="button" name="submitForm" id="submitForm" class="button" value="Generate new credentials"></p>';
        echo '</form>';
        //Modal page
        echo '<div id="consentModal" class="modal">
                <!-- Modal content -->
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <p>' . __('Are you sure to generate new credentials?','wp_desk_net') . '</p>
                    <div class="position-control-element"><input type="button" name="confirm" id="confirm" class="button button-primary" value="' . __('Confirm','wp_desk_net') . '">
                    <input type="button" name="cancel" id="cancel" class="button" value="' . __('Cancel','wp_desk_net') . '"></div>
                </div>

              </div>';
        echo '</div>';
    }

    /**
     * Perform template credential page
     *
     */
    function credential_page() {
        echo '<div class="wrap">';

        settings_errors();

        echo '<form action="options.php" method="post">';

        settings_fields( 'desk_net_authorization' );
        do_settings_sections( 'desk_net_authorization' );
        submit_button( 'Save' );

        echo '</form>';

        echo '</div>';
    }

    /**
     * Perform template status matching page
     *
     */
    function status_matching_page() {
        echo '<div class="wrap page-matching">';

        settings_errors();
        $statusList = get_option( 'wp_desk_net_desk-net-list-active-status' );

        if( ! empty ( $statusList ) && ! isset ( $statusList['error'] ) ) {
            echo '<form action="options.php" method="post">';

            settings_fields('wp_desk_net_status_matching');
            $this->custom_do_settings_sections('wp_desk_net_status_matching');
            submit_button('Save');

            echo '</form>';
        } else {
            echo'<h2>' . __( 'There is no connection from Desk-Net to WordPress. Code: 08', 'wp_desk_net' ) . '</h2>';
        }
        echo '</div>';
    }

    /**
     * Perform template category matching page
     *
     */
    function category_matching_page () {
        $platformID = get_option( 'wp_desk_net_platform_id' );
        $token = false;
        echo '<div class="wrap page-matching">';

        settings_errors();

        if ( ! empty ( get_option( 'wp_desk_net_user_password' ) )
            && ! empty( get_option( 'wp_desk_net_user_login' ) )
            && get_option( 'wp_desk_net_token' ) != 'not_valid') {
            $token = $this->checkValidateToken( get_option( 'wp_desk_net_user_login' ), get_option( 'wp_desk_net_user_password' ) );
        }

        if ( $token != false ) {
            if ( ! empty ( $platformID ) ) {
                $this->deskNetUpdateCategoryList();
                $categoryList = get_option('wp_desk_net_desk_net_category_list');

                if (!empty ($categoryList) && !isset ($categoryList['message'])) {
                    echo '<form action="options.php" method="post" autocomplete="off">';

                    settings_fields('wp_desk_net_category_matching');
                    $this->custom_do_settings_sections('wp_desk_net_category_matching');
                    submit_button('Save');

                    echo '</form>';
                } else {
                    echo '<h2>' . __('There is no connection from WordPress to Desk-Net. Code: 07', 'wp_desk_net') . '</h2>';
                }
            } else {
                echo'<h2>' . __( 'There is no connection from Desk-Net to WordPress. Code: 08', 'wp_desk_net' ) . '</h2>';
            }
        } else {
            echo '<h2>' . __('There is no connection from WordPress to Desk-Net. Code: 07', 'wp_desk_net') . '</h2>';
        }
        echo '</div>';
    }

    /**
     * Perform template content matching page
     *
     */
    function content_matching_page () {
        echo '<div class="wrap page-matching">';

        settings_errors();

        echo '<form action="options.php" method="post">';

        settings_fields( 'wp_desk_net_content_matching' );
        $this->custom_do_settings_sections( 'wp_desk_net_content_matching' );

        _e( 'Text will always be imported right into the post’s body.', 'wp_desk_net' );

        submit_button( 'Save' );

        echo '</form>';

        echo '</div>';

    }

    /**
     * Perform template permalinks page
     *
     */
    function permalinks_page() {

        echo '<div class="wrap">';

        settings_errors();

        echo '<form action="options.php" method="post">';

        settings_fields( 'wp_desk_net_permalinks' );
        do_settings_sections( 'wp_desk_net_permalinks' );
        submit_button( 'Save' );

        echo '</form>';
        echo '</div>';
    }

    /**
     * Perform settings fields for plugin pages
     *
     */
    function settings_init() {

        register_setting( 'desk_net_settings', 'wp_desk_net__settings', array( $this, 'generate_api_credentials' ) );
        register_setting( 'desk_net_authorization', 'wp_desk_net__authorization', array( $this, 'save_authorization' ) );
        register_setting( 'wp_desk_net_content_matching', 'wp_desk_net_content_list', array( $this, 'save_matching_settings' ) );
        register_setting( 'wp_desk_net_permalinks', 'wp_desk_net_id_in_permalink_option', array( $this, 'save_permalinks' ) );
        register_setting( 'wp_desk_net_status_matching', 'wp_desk_net_status_list', array( $this, 'save_matching_settings' ) );
        register_setting( 'wp_desk_net_category_matching', 'wp_desk_net_category_list', array( $this, 'save_matching_settings' ) );

        add_settings_section(
            'wp_desk_net_settings',
            __( 'WordPress Credentials', 'wp_desk_net' ),
            array( $this, 'settings_section_html' ),
            'desk_net_settings'
        );

        add_settings_field(
            'wp_desk_net_api_url',
            __( 'API URL', 'wp_desk_net' ),
            array( $this, 'api_url_html' ),
            'desk_net_settings',
            'wp_desk_net_settings'
        );

        add_settings_field(
            'wp_desk_net_api_key',
            __( 'API User', 'wp_desk_net' ),
            array( $this, 'api_key_input_html' ),
            'desk_net_settings',
            'wp_desk_net_settings'
        );

        add_settings_field(
            'wp_desk_net_api_secret',
            __( 'API Secret', 'wp_desk_net' ),
            array( $this, 'api_secret_input_html' ),
            'desk_net_settings',
            'wp_desk_net_settings'
        );

        // Desk-net Authorization
        add_settings_section(
            'wp_desk_net__authorization',
            __( 'Desk-Net Credentials', 'wp_desk_net' ),
            array( $this, 'authorization_section_html' ),
            'desk_net_authorization'
        );

        add_settings_field(
            'wp_desk_net_user_login',
            __( 'Desk-Net Login', 'wp_desk_net' ),
            array( $this, 'wp_desk_net_login_fidel_html' ),
            'desk_net_authorization',
            'wp_desk_net__authorization'
        );

        add_settings_field(
            'wp_desk_net_user_password',
            __( 'Desk-Net Password', 'wp_desk_net' ),
            array( $this, 'wp_desk_net_password_fidel_html' ),
            'desk_net_authorization',
            'wp_desk_net__authorization'
        );

        // Permalinks
        add_settings_section(
            'wp_desk_net_permalink',
            __( 'Desk-Net ID in URL', 'wp_desk_net' ),
            array( $this, 'wp_desk_net_permalink_section_html' ),
            'wp_desk_net_permalinks'
        );

        add_settings_field(
            'wp_desk_net_id_in_permalink',
            __( 'Desk-Net ID in URL', 'wp_desk_net' ),
            array( $this, 'wp_desk_net_id_in_permalink_html' ),
            'wp_desk_net_permalinks',
            'wp_desk_net_permalink',
            array(
                'Insert Desk-Net ID in URL'
            )
        );

        // Status Matching
        add_settings_section(
            'wp_desk_net_status_list',
            __( 'Status Matching', 'wp_desk_net' ),
            array( $this, 'status_matching_html' ),
            'wp_desk_net_status_matching'
        );

        add_settings_field(
            'wp_desk_net_desk_net_to_wp_status',
            __( 'Desk-Net to WordPress', 'wp_desk_net' ),
            array( $this, 'desk_net_to_wp_status_html' ),
            'wp_desk_net_status_matching',
            'wp_desk_net_status_list'
        );

        add_settings_field(
            'wp_desk_net_wp_to_desk_net_status',
            __( 'WordPress to Desk-Net', 'wp_desk_net' ),
            array( $this, 'wp_to_desk_net_status_html' ),
            'wp_desk_net_status_matching',
            'wp_desk_net_status_list'
        );

        //Content Matching
        add_settings_section(
            'wp_desk_net_content_list',
            __( 'Content import settings', 'wp_desk_net' ),
            array( $this, 'content_matching_html' ),
            'wp_desk_net_content_matching'
        );

        add_settings_field(
            'wp_desk_net_content_settings_list',
            __( 'Export setting per content type:', 'wp_desk_net' ),
            array( $this, 'content_setting_html' ),
            'wp_desk_net_content_matching',
            'wp_desk_net_content_list'
        );

        // Category Matching
        add_settings_section(
            'wp_desk_net_category_list',
            __( 'Category Matching', 'wp_desk_net' ),
            array( $this, 'category_matching_html' ),
            'wp_desk_net_category_matching'
        );

        add_settings_field(
            'wp_desk_net_desk_net_to_wp_category',
            __( 'Desk-Net to WordPress', 'wp_desk_net' ),
            array( $this, 'desk_net_to_wp_category_html' ),
            'wp_desk_net_category_matching',
            'wp_desk_net_category_list'
        );

        add_settings_field(
            'wp_desk_net_wp_to_desk_net_category',
            __( 'WordPress to Desk-Net', 'wp_desk_net' ),
            array( $this, 'wp_to_desk_net_category_html' ),
            'wp_desk_net_category_matching',
            'wp_desk_net_category_list'
        );
    }

    /**
     * Perform generate API credentials
     *
     */
    function generate_api_credentials() {
        update_option( 'wp_desk_net_api_key', uniqid( 'API_', true ) );
        update_option( 'wp_desk_net_api_secret', uniqid( '', true ) );

        add_settings_error(
            'wp_desk_net__settings',
            esc_attr( 'credentials_generated' ),
            __( 'New credentials successfully generated.' ),
            'updated'
        );
    }

    /**
     * Perform save authorization form
     *
     * @param array $args The fields form
     *
     */
    function save_authorization( $args ) {
        if ( $args != NULL ) {
            update_option( 'wp_desk_net_user_login', $args['wp_desk_net_user_login'] );
            update_option( 'wp_desk_net_user_password', $args['wp_desk_net_user_password'] );
            delete_option ( 'wp_desk_net_token' );
            $token = $this->checkValidateToken( $args['wp_desk_net_user_login'], $args['wp_desk_net_user_password']);
            if ( $token != false ) {
                $message = __( 'Connection successfully established.', 'wp_desk_net' );
                $type = 'updated';
            } else {
                $message = __( 'Connection could not be established.', 'wp_desk_net' );
                $type = 'error';
            }
            add_settings_error(
                'wp_desk_net__authorization',
                esc_attr( 'authorization_updated' ),
                $message,
                $type
            );
        }
    }

    /**
     * Perform save mathcing settings
     *
     * @param array $args The fields form
     *
     */
    function save_matching_settings ( $args ) {
        if ( $args != NULL ) {
            foreach ( $args as $key => $value ) {
                update_option( $key, $args[$key] );
            }
            add_settings_error(
                'save_matching_settings',
                esc_attr( 'matching_updated' ),
                __( 'Settings saved.' ),
                'updated'
            );
        }
    }

    /**
     * Perform content on page
     *
     */
    function settings_section_html() {
        _e( 'Use these credentials in Desk-Net on the Advanced Settings tab of the <a href="https://www.desk-net.com/objectsPage.htm" target="_blank">platform</a> you are connecting to this WordPress website.', 'wp_desk_net' );
    }

    /**
     * Perform content on page
     *
     */
    function status_matching_html() {
        _e( 'Use this page to match publication statuses in Desk-Net to those in WordPress and vice versa.', 'wp_desk_net' );
    }

    /**
     * Perform content on page
     *
     */
    function content_matching_html() {
        _e( 'If your Desk-Net users upload content such as pictures, video files, text etc. to Desk-Net such content can be imported into WordPress.</br></br>
            Here you can define if file based content such as picture or video files should be imported straight into a post’s body or if they should be made available in the Media section of your WordPress site only.</br></br>
            There will be no content import once a story has been published or if it has been scheduled for automatic publication at a pre-defined date/time.', 'wp_desk_net' );
    }

    /**
     * Perform content on page
     *
     */
    function category_matching_html() {
        _e( 'Use this page to match categories in Desk-Net to those in WordPress and vice versa.', 'wp_desk_net' );
    }

    /**
     * Perform template for fields Desk-Net to WordPress status
     *
     */
    function desk_net_to_wp_status_html() {
        $deskNetStatusList   = get_option( 'wp_desk_net_desk-net-list-active-status' );
        $postStatuses = get_post_statuses();

        foreach ( $deskNetStatusList as $key => $value ) {
            $deskNetToWP[$deskNetStatusList[$key]['id']]['name'] = $deskNetStatusList[$key]['name'];
        }

        $deskNetToWP['removed']['name'] = __( 'Deleted/removed', 'wp_desk_net' );

        foreach ( $postStatuses as $key => $value ) {
            $wpToDeskNet[$key]['name'] = $postStatuses[$key];
        }

        if ( empty ( get_option('wp_desk_net_status_desk_net_to_wp_5' ))) {
            update_option('wp_desk_net_status_desk_net_to_wp_5', 'publish' );
        }

        if ( ! empty ( $wpToDeskNet ) && ! empty ( $deskNetToWP ) ) {
            echo DeskNet_templateMatchingPage($deskNetToWP, $wpToDeskNet, 'status', '_desk_net_to_wp_', 'draft' );
        } else {
            _e("Sorry, we can't load statuses", 'wp_desk_net');
        }
    }

    /**
     * Perform template for fields WordPress to Desk-Net status
     *
     */
    function wp_to_desk_net_status_html() {
        $deskNetActiveStatusList   = get_option( 'wp_desk_net_desk-net-list-active-status' );
        $deskNetDeactivatedStatusList   = get_option( 'wp_desk_net_desk-net-list-deactivated-status' );
        $postStatuses = get_post_statuses();

        if ( ! empty ( $deskNetActiveStatusList ) ) {
            foreach ($deskNetActiveStatusList as $key => $value) {
                $deskNetToWP[$deskNetActiveStatusList[$key]['id']]['name'] = $deskNetActiveStatusList[$key]['name'];
            }
        }
        if ( ! empty ( $deskNetDeactivatedStatusList ) ) {
            foreach ($deskNetDeactivatedStatusList as $key => $value) {
                $deskNetToWP[$deskNetDeactivatedStatusList[$key]['id']]['name'] = $deskNetDeactivatedStatusList[$key]['name'];
            }
        }
        foreach ( $postStatuses as $key => $value ) {
            $wpToDeskNet[$key]['name'] = $postStatuses[$key];
        }

        if ( empty ( get_option('wp_desk_net_status_wp_to_desk_net_publish' ))) {
            update_option('wp_desk_net_status_wp_to_desk_net_publish', 5 );
        }

        if ( ! empty ( $wpToDeskNet ) && ! empty ( $deskNetToWP ) ) {
            echo DeskNet_templateMatchingPage( $wpToDeskNet, $deskNetToWP, 'status', '_wp_to_desk_net_', '1' );
        } else {
            _e("Sorry, we can't load statuses", 'wp_desk_net');
        }
    }

    /**
     * Perform template for fields Desk-Net to WordPress content
     *
     */
    function content_setting_html () {
        $elementMenuList['picture-files']['name'] = __( 'Picture files', 'wp_desk_net' );
        $elementMenuList['video-files']['name'] = __( 'Video files', 'wp_desk_net' );
        $elementMenuList['other-files']['name'] = __( 'Other files', 'wp_desk_net' );

        $menuValuesList['body-media']['name'] = __( 'Import into post body and Media area', 'wp_desk_net' );
        $menuValuesList['only-media']['name'] = __( 'Store in Media area only', 'wp_desk_net' );

        echo DeskNet_templateMatchingPage($elementMenuList, $menuValuesList, 'content', '_', 'body-media' );
    }

    /**
     * Perform template for fields Desk-Net to WordPress category
     *
     */
    function desk_net_to_wp_category_html() {
        $wpCategoryList = get_terms('category', 'orderby=namet&order=ASC&hide_empty=0');
        $wpCategoryList = json_decode(json_encode($wpCategoryList, JSON_UNESCAPED_UNICODE ),TRUE);
        $deskNetCategoryList = get_option( 'wp_desk_net_desk_net_category_list' );

        $wpToDeskNet['no_category']['name'] = 'No category';
        $wpToDeskNet['do_not_import']['name'] = 'Do not import';
        $deskNetToWP['no_category']['name'] = 'No category';

        foreach ( $wpCategoryList as $key => $value ) {
            $wpToDeskNet[$wpCategoryList[$key]['term_id']]['name'] = $wpCategoryList[$key]['name'];
            if ( $wpCategoryList[$key]['parent'] != 0 ) {
                $wpToDeskNet[$wpCategoryList[$key]['term_id']]['parent'] = $wpCategoryList[$key]['parent'];
            }
        }
        foreach ( $deskNetCategoryList as $key => $value ) {
            $deskNetToWP[$deskNetCategoryList[$key]['id']]['name'] = $deskNetCategoryList[$key]['name'];
            if ( isset( $deskNetCategoryList[$key]['category'] ) ) {
                $deskNetToWP[$deskNetCategoryList[$key]['id']]['parent'] = $deskNetCategoryList[$key]['category'];
            }
        }
        if ( ! empty ( $wpToDeskNet ) && ! empty ( $deskNetToWP ) ) {
            echo DeskNet_templateMatchingPage($deskNetToWP, $wpToDeskNet, 'category', '_desk_net_to_wp_', 'no_category' );
        } else {
            _e("Sorry, we can't load categories", 'wp_desk_net');
        }
    }

    /**
     * Perform template for fields WordPress to Desk-Net category
     *
     */
    function wp_to_desk_net_category_html() {
        $wpCategoryList = get_terms('category', 'orderby=namet&order=ASC&hide_empty=0');
        $wpCategoryList = json_decode(json_encode($wpCategoryList, JSON_UNESCAPED_UNICODE ),TRUE);
        $deskNetCategoryList = get_option( 'wp_desk_net_desk_net_category_list' );
        $deskNetToWP['No category']['name'] = 'No category';
        $deskNetToWP['Do not import']['name'] = 'Do not import';
        foreach ( $wpCategoryList as $key => $value ) {
            $wpToDeskNet[$wpCategoryList[$key]['term_id']]['name'] = $wpCategoryList[$key]['name'];
            if ( $wpCategoryList[$key]['parent'] != 0 ) {
                $wpToDeskNet[$wpCategoryList[$key]['term_id']]['parent'] = $wpCategoryList[$key]['parent'];
            }
        }
        foreach ( $deskNetCategoryList as $key => $value ) {
            $deskNetToWP[$deskNetCategoryList[$key]['id']]['name'] = $deskNetCategoryList[$key]['name'];
            if ( isset( $deskNetCategoryList[$key]['category'] ) ) {
                $deskNetToWP[$deskNetCategoryList[$key]['id']]['parent'] = $deskNetCategoryList[$key]['category'];
            }
        }
        if ( ! empty ( $wpToDeskNet ) && ! empty ( $deskNetToWP ) ) {
            echo DeskNet_templateMatchingPage($wpToDeskNet, $deskNetToWP, 'category', '_wp_to_desk_net_', 'No category' );
        } else {
            _e("Sorry, we can't load categories", 'wp_desk_net');
        }
    }

    /**
     * Perform content on page
     *
     */
    function api_url_html() {
        echo get_site_url() . '/wp-json/wp-desk-net/v1';
    }

    /**
     * Perform content on page
     *
     */
    function authorization_section_html() {
        _e( 'Enter here the API credentials of your Desk-Net account (not your personal Desk-Net user credentials). If you do not have these API credentials please request them from', 'wp_desk_net' );
        echo ' <a href="mailto:support@desk-net.com">support@desk-net.com</a>';
    }

    /**
     * Perform content on page
     *
     */
    function wp_desk_net_login_fidel_html() {
        echo '<input type="text" id="wp_desk_net_user_login" name="wp_desk_net__authorization[wp_desk_net_user_login]" value="' . get_option( 'wp_desk_net_user_login' ) . '" />';
    }

    /**
     * Perform content on page
     *
     */
    function wp_desk_net_password_fidel_html() {
        echo '<input type="password" id="wp_desk_net_user_password" name="wp_desk_net__authorization[wp_desk_net_user_password]" value="' . get_option( 'wp_desk_net_user_password' ) . '" autocomplete="new-password" />';
    }

    /**
     * Perform content on page
     *
     */
    function wp_desk_net_permalink_section_html() {
        _e( 'You can have this plugin automatically insert the Desk-Net ID into the URL of new posts.', 'wp_desk_net' );
    }

    /**
     * Perform template for fields WordPress permalink page
     *
     */
    function wp_desk_net_id_in_permalink_html( $args ) {
        $html = '<input type="checkbox" id="wp_desk_net_id_in_permalink" name="wp_desk_net_id_in_permalink_option[wp_desk_net_id_in_permalink]" ' . checked( 1, get_option( 'wp_desk_net_id_in_permalink' ), false ) . '/>';
        $html .= '<label for="wp_desk_net_id_in_permalink"> ' . $args[0] . '</label>';
        $this->statusUpload = false;
        echo $html;
    }

    /**
     * Perform save permalinks settings
     *
     * @param array $args The fields form
     *
     */
    function save_permalinks( $args ) {
        if ( !$this->statusUpload ) {
            if ($args != NULL) {
                update_option('wp_desk_net_id_in_permalink', 1);
            } else {
                update_option('wp_desk_net_id_in_permalink', 0);
            }
            $this->statusUpload = true;
        }
    }

    /**
     * Perform content on page
     *
     */
    function api_key_input_html() {
        echo get_option( 'wp_desk_net_api_key' );
    }

    /**
     * Perform content on page
     *
     */
    function api_secret_input_html() {
        echo get_option( 'wp_desk_net_api_secret' );
    }

    /**
     * Perform default value after init WP-Desk-Net plugin
     *
     */
    function wp_desk_net_activate() {
        add_option( 'wp_desk_net_api_key', uniqid( 'API_', true ) );
        add_option( 'wp_desk_net_api_secret', uniqid( '', true ) );

        update_option( 'wp_desk_net_id_in_permalink', 0 );
    }

    /**
     * Perform delete values from DB after deactivate plugin
     *
     */
    function wp_desk_net_deactivate() {
        $this->deleteAllOption();
    }

    /**
     * Perform delete fields with prefix from WordPress DB
     *
     * @param string $prefix The field prefix
     *
     */
    public function deleteAllOption ( $prefix = 'wp_desk_net_' ) {
        global $wpdb;

        $plugin_options = $wpdb->get_results( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '" . $prefix . "%'" );

        foreach ( $plugin_options as $option ) {
            delete_option( $option->option_name );
        }
    }

    /**
     * Perform Settings link on plugin page
     *
     * @param string $links
     *
     * @return array
     */
    function add_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=wp-desk-net' ) . '">' . __( 'Settings', 'wp_desk_net' ) . '</a>',
        );

        return array_merge( $links, $plugin_links );
    }

    /**
     * Perform additional text fields for post in WordPress
     *
     * @param object $post
     *
     */
    function desk_net_description( $post ) {

        $desk_net_description = esc_html( get_post_meta( $post->ID, 'desk_net_description', true ) );
        $desk_net_id = get_post_meta( $post->ID, 'story_id', true );

        if ( ! empty( $desk_net_description ) ) {
            echo '<br><strong class="desk-net-desc"><a href="' . self::DN_BASE_URL . '/mySchedulePage.htm?fragment=de' . $desk_net_id . '" target="_blank"><img src="' . plugin_dir_url( __FILE__ ) . '../img/favicon.ico" alt="Desk-Net" /></a>';
            if ( get_post_meta( $post->ID, 'wp_desk_net_remove_status', true) == 'removed' ) {
                echo __('Deleted/removed – ', 'wp_desk_net' ) . $desk_net_description;
            } else {
                echo $desk_net_description;
            }
            echo '</strong>';
        }

        if ( ! empty( $desk_net_id ) ) {
            echo '<div class="desk-net-id">';
            echo '<strong>' . __( 'Desk-Net ID: ', 'wp_desk_net' ) . $desk_net_id . '</strong><a href="' . self::DN_BASE_URL . '/mySchedulePage.htm?fragment=de' . $desk_net_id . '" target="_blank">' . __( 'View in Desk-Net ', 'wp_desk_net' ) . '</a></div>';
        }

        return;
    }

    /**
     * Perform generate custom template for Matching page
     *
     * @param object $page
     *
     */
    function custom_do_settings_sections( $page ) {
        global $wp_settings_sections, $wp_settings_fields;

        if ( !isset($wp_settings_sections) || !isset($wp_settings_sections[$page]) )
            return;

        foreach( (array) $wp_settings_sections[$page] as $section ) {
            echo "<h3>{$section['title']}</h3>\n";
            call_user_func($section['callback'], $section);
            if ( !isset($wp_settings_fields) ||
                !isset($wp_settings_fields[$page]) ||
                !isset($wp_settings_fields[$page][$section['id']]) )
                continue;
            echo '<div class="settings-form-wrapper">';
            $this->custom_do_settings_fields($page, $section['id']);
            echo '</div>';
        }
    }

    /**
     * Perform generate custom settings fields for Matching page
     *
     * @param object $page
     * @param object $section
     *
     */
    function custom_do_settings_fields($page, $section) {
        global $wp_settings_fields;

        if ( !isset($wp_settings_fields) ||
            !isset($wp_settings_fields[$page]) ||
            !isset($wp_settings_fields[$page][$section]) )
            return;

        foreach ( (array) $wp_settings_fields[$page][$section] as $field ) {
            echo '<div class="settings-form-row">';
            if ( !empty($field['args']['label_for']) )
                echo '<p class="label"><label for="' . $field['args']['label_for'] . '">' .
                    $field['title'] . '</label><br />';
            else
                echo '<p class="label">' . $field['title'] . '<br />';
            call_user_func($field['callback'], $field['args']);
            echo '</p></div>';
        }
    }

    /**
     * Perform Update Category List
     *
     * @return bool
     */
    public function  deskNetUpdateCategoryList() {
        $platformID = get_option( 'wp_desk_net_platform_id' );
        $saveCategoryListForPlatform = get_option( 'wp_desk_net_desk_net_category_list' );
        $requestMethod = new DeskNetRequestMethod();

        $categoryListForPlatform = json_decode( $requestMethod->get( self::DN_BASE_URL, 'categories/platform', $platformID ), true );
        if ( empty( $categoryListForPlatform ) || isset( $categoryListForPlatform['message'] ) ) {
            $response_data = array(
                'Category list for platform' => __( 'Is Empty', 'wp_desk_net' )
            );
            $this->log_error( $response_data );

            return false;
        }

        if ( ! empty ( $saveCategoryListForPlatform ) ) {
            $elementList = array();
            $wpCategoryList = get_terms('category', 'orderby=namet&order=ASC&hide_empty=0');
            $wpCategoryList = json_decode( json_encode( $wpCategoryList ), TRUE );
            foreach ( $wpCategoryList as $key => $value ) {
                array_push( $elementList, $wpCategoryList[$key]['term_id'] );
            }
            $deleteElements = new DeskNetDeleteItems();
            $deleteElements->shapeDeletedItems( $categoryListForPlatform, $saveCategoryListForPlatform, $elementList, 'category' );
        }

        update_option( 'wp_desk_net_desk_net_category_list', $categoryListForPlatform );
    }

    /**
     * Perform validate token
     *
     * @param string $login
     * @param string $password
     *
     * @return string
    */
    public function checkValidateToken ( $login, $password ) {
        $requestObj = new DeskNetRequestMethod ();
        $token = $requestObj->getToken( $login, $password );
        return $token;
    }

    /**
     * Perform delete filter and add query message param
     *
     * @param string $location
     *
     * @return string
     */
    public function add_notice_query_var( $location ) {
        remove_filter( 'redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
        return add_query_arg( array(
            'messageID' => $this->errorMessage,
            'warningID' => implode( '_', $this->warningMessage )
        ), $location );
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly