<?php
/**
 * Admin class
 *
 * @package     WP_Store_locator
 * @subpackage  Classes/Admin
 * @copyright   Copyright (c) 2013, Tijmen Smit
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
*/

if ( ! defined( 'ABSPATH' ) ) exit;

if ( !class_exists( 'WPSL_Admin' ) ) {
    /**
     * Handle the backend of the store locator
     *
     * @since 1.0
     */
	class WPSL_Admin extends WP_Store_locator {
        
        /**
         * Holds the store data
         *
         * @var array
         * @since 1.0
         */
        public $store_data;
		
        /**
         * Class constructor
         */
		function __construct() {
			add_action( 'init', array( $this, 'output_buffer' ) );
			add_action( 'admin_init', array( $this, 'admin_init' ) );
			add_action( 'wp_loaded', array( $this, 'init' ) );
            add_action( 'wp_ajax_delete_store', array( $this, 'delete_store_ajax' ) );
            
            $this->settings = $this->get_settings();
		}
		
		public function output_buffer() {
			ob_start();
		}
		
        /**
         * Register a callback function for the settings page
         *
         * @since 1.0
         * @return void
         */
		public function admin_init() {
		 	register_setting( 'wpsl_settings', 'wpsl_settings', array( $this, 'sanitize_settings' ) );
		}
		
        /**
         * Add the admin menu and enqueue the admin scripts
         *
         * @since 1.0
         * @return void
         */
		public function init() {
			add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );	
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );	
		}
		
        /**
         * Add the menu pages
         *
         * @since 1.0
         * @return void
         */
		public function create_admin_menu() {	
			add_menu_page( 'Store Locator', __( 'Store Locator', 'wpsl' ), __( 'manage_options', 'wpsl' ), 'wpsl_store_editor', array( $this, 'manage_stores' ), plugins_url( 'img/store-locator-icon.png', dirname( __FILE__ ) ) );
            add_submenu_page( 'wpsl_store_editor', __( 'Manage Stores', 'wpsl' ), __( 'Manage Stores', 'wpsl' ), 'manage_options', 'wpsl_store_editor', array( $this, 'manage_stores' ) );
			add_submenu_page( 'wpsl_store_editor', __( 'Add Store', 'wpsl' ), __( 'Add Store', 'wpsl' ), 'manage_options', 'wpsl_add_store', array( $this, 'add_store' ) );
            add_submenu_page( 'wpsl_store_editor', __( 'Settings', 'wpsl' ), __( 'Settings', 'wpsl' ), 'manage_options', 'wpsl_settings', array( $this, 'show_settings' ) );	
			add_submenu_page( 'wpsl_store_editor', __( 'FAQ', 'wpsl' ), __( 'FAQ', 'wpsl' ), 'manage_options', 'wpsl_faq', array( $this, 'show_faq' ) );
		}
        
        /**
         * Load the add store template
         *
         * @since 1.0
         * @return void
         */  
        public function add_store() {
            $this->store_actions();
            require_once( WPSL_PLUGIN_DIR . 'admin/templates/add-store.php' ); 
        }
                
        /**
         * If a store form is submitted, process the store data
         *
         * @since 1.0
         * @return void
         */  
        public function store_actions() {
			if ( isset( $_REQUEST['wpsl_actions'] ) ) {
				$this->handle_store_data();
			} 
        }

        /**
         * Handle the different actions for the store.
         * 
         * Based on the action value, either show the store overview list or the edit store template
         *
         * @since 1.0
         * @return void
         */    
		public function manage_stores() {

            $this->store_actions();
            
            /* Check which store template to show */
            switch ( $_GET['action'] ) {
                case 'edit_store':
                    require_once( WPSL_PLUGIN_DIR . 'admin/templates/edit-store.php' );
                    break;
                default:
                    require_once( WPSL_PLUGIN_DIR . 'admin/templates/stores-overview.php' );
                    break;
            } 
		}
                
        /**
         * Process new store data
         *
         * @since 1.0
         * @return void
         */
        public function handle_store_data() {
            
            global $wpdb;
						
			if ( !current_user_can( 'manage_options' ) )
				die( '-1' );
		
			check_admin_referer( 'wpsl_' . $_POST['wpsl_actions'] );
            
            $this->store_data = $this->validate_store_data();

			if ( $this->store_data ) {
				$latlng = $this->validate_latlng( $this->store_data['lat'], $this->store_data['lng'] );
				
				/* If we don't have a valid latlng value, we geocode the supplied address to get one */
				if ( !$latlng ) {
					$reponse = $this->geocode_location();
                    $this->store_data['country']     = $reponse['country']['long_name'];
                    $this->store_data['country-iso'] = $reponse['country']['short_name'];
                    $this->store_data['latlng']      = $reponse['latlng'];
				} else {
                    $this->store_data['country-iso'] = $_POST['wpsl']['country-iso'];
                    $this->store_data['latlng']      = $latlng;
				}

                if ( $_POST['wpsl_actions'] == 'add_new_store' )
                    $this->add_new_store(); 

                if ( $_POST['wpsl_actions'] == 'update_store' )
                    $this->update_store(); 
            }
        }
        
        /**
         * Delete a single store
         * 
         * This is called from the store overview page when a user clicks the delete button
         *
         * @since 1.0
         * @return json Either fail or success
         */
        public function delete_store_ajax() {
            
            global $wpdb;
            
            $store_id = absint( $_POST['store_id'] );

            if ( !current_user_can( 'manage_options' ) )
                die( '-1' );
            
            check_ajax_referer( 'wpsl_delete_nonce_'.$store_id );
                
            $result = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->wpsl_stores WHERE wpsl_id = %d", $store_id ) );

            if ( $result === false ) {
                wp_send_json_error();
            } else {
                wp_send_json_success();
            } 
        }
       
        /**
         * Update store details
         * 
         * @since 1.0
         * @param array $store_data The updated store data
         * @return void
         */
        public function update_store() {
           
            global $wpdb;
						
            $result = $wpdb->query( 
                            $wpdb->prepare( 
                                    "
                                    UPDATE $wpdb->wpsl_stores
                                    SET store = %s, street = %s, city = %s, state = %s, zip = %s, country = %s, country_iso = %s, lat = %s, lng = %s, description = %s, phone = %s, fax = %s, url = %s, email = %s, hours = %s, thumb_id = %d, active = %d 
                                    WHERE wpsl_id = %d",
                                    $this->store_data['store'],
                                    $this->store_data['street'],
                                    $this->store_data['city'],
                                    $this->store_data['state'],
                                    strtoupper ( $this->store_data['zip'] ),
                                    $this->store_data['country'],
                                    $this->store_data['country-iso'],
                                    $this->store_data['latlng']['lat'],
                                    $this->store_data['latlng']['lng'],
                                    $this->store_data['desc'],
                                    $this->store_data['phone'],
                                    $this->store_data['fax'],
                                    $this->store_data['url'],
                                    $this->store_data['email'],
                                    $this->store_data['hours'],
                                    $this->store_data['thumb-id'],
                                    $this->store_data['active'],
                                    $_GET['store_id']
                                  )
                            );	
            
            if ( $result === false ) {
                $state = 'error';
                $msg = __( 'There was a problem updating the store details, please try again.', 'wpsl' );
            } else {
                $_POST = array();
                $state = 'updated';
                $msg = __( 'Store details updated.', 'wpsl' );
            } 
        
            add_settings_error ( 'update-store', esc_attr( 'update-store' ), $msg, $state );
		}
		
        /**
         * Add a new store to the db
         * 
         * @since 1.0
         * @param array $store_data The submitted store data
         * @return void
         */
		public function add_new_store() {

            global $wpdb;
						
            $result = $wpdb->query( 
                            $wpdb->prepare( 
                                    "
                                    INSERT INTO $wpdb->wpsl_stores
                                    (store, street, city, state, zip, country, country_iso, lat, lng, description, phone, fax, url, email, hours, thumb_id)
                                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d)
                                    ", 
                                    $this->store_data['store'],
                                    $this->store_data['street'],
                                    $this->store_data['city'],
                                    $this->store_data['state'],
                                    strtoupper ( $this->store_data['zip'] ),
                                    $this->store_data['country'],
                                    $this->store_data['country-iso'],
                                    $this->store_data['latlng']['lat'],
                                    $this->store_data['latlng']['lng'],
                                    $this->store_data['desc'],
                                    $this->store_data['phone'],
                                    $this->store_data['fax'],
                                    $this->store_data['url'],
                                    $this->store_data['email'],
                                    $this->store_data['hours'],
                                    $this->store_data['thumb-id']
                                    )
                               );
             
            if ( $result === false ) {
                $state = 'error';
                $msg = __( 'There was a problem saving the new store details, please try again.', 'wpsl' );
            } else {
                $_POST = array();
                $state = 'updated';
                $msg = __( 'Store succesfully added.', 'wpsl' );
            } 
        
            add_settings_error ( 'add-store', esc_attr( 'add-store' ), $msg, $state );  
		}
		
        /**
         * Get a single value from the default settings
         * 
         * @since 1.0
         * @param string $setting The value that should be restored
         * @return string the default setting value
         */
        public function get_default_setting( $setting ) {
			return $this->default_settings[$setting];
		}
        
        /**
         * Validate the submitted store data
         * 
         * @since 1.0
         * @return mixed array|void $store_data the submitted store data if not empty, otherwise nothing
         */
		public function validate_store_data() {
            
			$store_data = $_POST['wpsl'];
			
			if ( empty( $store_data['store'] ) || ( empty( $store_data['street'] ) ) || ( empty( $store_data['city'] ) ) || ( empty( $store_data['zip'] ) ) || ( empty( $store_data['country'] ) )  ) {	
                add_settings_error ( 'validate-store', esc_attr( 'validate-store' ), __( 'Please fill in all the required fields.', 'wpsl' ), 'error' );  				
			} else {
				return $store_data;
			}
		}
        
        /**
         * Get the data for a single store
         * 
         * @since 1.0
         * @param string $store_id The id for a single store
         * @return array $result The store details
         */
        public function get_store_data( $store_id ) {
            
             global $wpdb;

             $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->wpsl_stores WHERE wpsl_id = %d", $store_id ), ARRAY_A );		

             return $result;
        }
        
        /**
         * Show the settings template
         *
         * @since 1.0
         * @return void
         */
        public function show_settings() {
			require 'templates/map-settings.php';
		}
        
        /**
         * Show the faq template
         *
         * @since 1.0
         * @return void
         */
        public function show_faq() {
           require 'templates/faq.php'; 
        }
        
        /**
         * Handle the different validation errors for the plugin settings
         * 
         * @since 1.0
         * @param string $error_type Contains the type of validation error that occured
         * @return void
         */
		private function settings_error( $error_type ) {
            
			switch ( $error_type ) {
				case 'max_results':
					$error_msg = __( 'The max results field cannot be empty, the default value has been restored.', 'wpsl' );	
					break;
				case 'search_radius':
					$error_msg = __( 'The search radius field cannot be empty, the default value has been restored.', 'wpsl' );	
					break;	
				case 'label_missing':
					$error_msg = __( 'One of the label fields was left empty, the default value for that field has been restored.', 'wpsl' );
					break;
			}
			
			add_settings_error ( 'setting-errors', esc_attr( 'settings_fail' ), $error_msg, 'error' );
		}
		
		/**
         * Sanitize the submitted plugin settings
         * 
         * @since 1.0
         * @return array $output The setting values
         */
		public function sanitize_settings() {
            
			$map_types = array( 
                'roadmap', 
                'satellite', 
                'hybrid', 
                'terrain' 
            );
            $unit_values = array( 
                'px', 
                '%' 
            );
            $distance_units = array( 
                'km', 
                'mi' 
            );
		
			$output['api_key']      = sanitize_text_field( $_POST['wpsl_api']['key'] );
			$output['api_language'] = wp_filter_nohtml_kses( $_POST['wpsl_api']['language'] );
			$output['api_region']   = wp_filter_nohtml_kses( $_POST['wpsl_api']['region'] );
			
			if ( in_array( $_POST['wpsl_search']['distance_unit'], $distance_units ) ) {
				$output['distance_unit'] = $_POST['wpsl_search']['distance_unit'];
			} else {
				$output['distance_unit'] = $this->get_default_setting( 'distance_unit' );
			}
			
			/* Check for a valid max results value, otherwise we use the default */
			if ( !empty( $_POST['wpsl_search']['max_results'] ) ) {
				$output['max_results'] = sanitize_text_field( $_POST['wpsl_search']['max_results'] );
			} else {
				$this->settings_error( 'max_results' );
				$output['max_results'] = $this->get_default_setting( 'max_results' );
			}
			
			/* See if a search radius value exist, otherwise we use the default */
			if ( !empty( $_POST['wpsl_search']['radius'] ) ) {
				$output['search_radius'] = sanitize_text_field( $_POST['wpsl_search']['radius'] );
			} else {
				$this->settings_error( 'search_radius' );
				$output['search_radius'] = $this->get_default_setting( 'search_radius' );
			}
			
			$output['marker_bounce'] = isset( $_POST['wpsl_map']['marker_bounce'] ) ? 1 : 0;	
			
			/* Check if we have a valid zoom level, it has to be between 1 or 12. If not set it to the default of 3 */
			if ( $_POST['wpsl_map']['zoom_level'] >= 1 || $_POST['wpsl_map']['zoom_level'] <= 12 ) {
				$output['zoom_level'] = $_POST['wpsl_map']['zoom_level'];
			} else {
				$output['zoom_level'] = $this->get_default_setting( 'zoom_level' );	
			}	
			
			$output['zoom_name'] = sanitize_text_field( $_POST['wpsl_map']['zoom_name'] );
			
			/* If no location name is set to zoom to we also empty the latlng values from the hidden input field */
			if ( empty( $output['zoom_name'] ) ) {
				$output['zoom_latlng'] = '';
			} else {
				$output['zoom_latlng'] = sanitize_text_field( $_POST['wpsl_map']['zoom_latlng'] );
			}

			/* Check if we have a valid map type */
			if ( in_array( $_POST['wpsl_map']['type'], $map_types ) ) {
				$output['map_type'] = $_POST['wpsl_map']['type'];
			} else {
				$output['map_type'] = $this->get_default_setting( 'map_type' );
			}
             
			$output['streetview'] 		= isset( $_POST['wpsl_map']['streetview'] ) ? 1 : 0;
            $output['pan_controls'] 	= isset( $_POST['wpsl_map']['pan_controls'] ) ? 1 : 0;	
			$output['control_position'] = ( $_POST['wpsl_map']['control_position']  == 'left' )  ? 'left' : 'right';	
			$output['control_style']    = ( $_POST['wpsl_map']['control_style'] == 'small' ) ? 'small' : 'large';
			$output['auto_locate'] 		= isset( $_POST['wpsl_map']['auto_locate'] ) ? 1 : 0;
            
 			/* Check the height value of the map */
			if ( absint( $_POST['wpsl_design']['height_value'] ) ) {
				$output['height'] = $_POST['wpsl_design']['height_value'];
			} else {
				$output['height'] = $this->get_default_setting( 'height' );
			}
            
            /* Check the max-width of the infowindow */
			if ( absint( $_POST['wpsl_design']['infowindow_width'] ) ) {
				$output['infowindow_width'] = $_POST['wpsl_design']['infowindow_width'];
			} else {
				$output['infowindow_width'] = $this->get_default_setting( 'infowindow_width' );
			}
            
             /* Check the width for the search field */
			if ( absint( $_POST['wpsl_design']['search_width'] ) ) {
				$output['search_width'] = $_POST['wpsl_design']['search_width'];
			} else {
				$output['search_width'] = $this->get_default_setting( 'search_width' );
			}
            
             /* Check the width for labels */
			if ( absint( $_POST['wpsl_design']['label_width'] ) ) {
				$output['label_width'] = $_POST['wpsl_design']['label_width'];
			} else {
				$output['label_width'] = $this->get_default_setting( 'label_width' );
			}
			
            $output['results_dropdown'] = isset( $_POST['wpsl_design']['design_results'] ) ? 1 : 0;          
            
            $output['start_marker'] 	= wp_filter_nohtml_kses( $_POST['wpsl_map']['start_marker'] );
            $output['store_marker'] 	= wp_filter_nohtml_kses( $_POST['wpsl_map']['store_marker'] );
			
			$missing_labels = false;
			$required_labels = array( 
				'search', 
				'search_btn', 
				'preloader', 
				'radius', 
				'no_results', 
				'results', 
				'directions', 
				'error', 
				'phone', 
				'fax', 
				'hours', 
                'start',
				'limit' 
			);
			
			/**
             * Labels can never be empty, so we make sure they always contain data. 
             * If they are empty, we use the default value 
             */
			foreach ( $required_labels as $k => $label ) {
				if ( !empty( $_POST['wpsl_label'][$label] ) ) {
					$output[$label.'_label'] = sanitize_text_field( $_POST['wpsl_label'][$label] );
				} else {
					$output[$label.'_label'] = $this->get_default_setting( $label.'_label' );
					$missing_labels = true;
				}
			}
			
			if ( $missing_labels ) { 
				$this->settings_error( 'label_missing' );
			}

			return $output;
		}
		
        /** 
         * Validate the latlng values
         * 
         * @since 1.0
         * @param string $lat The latitude value
         * @param string $lng The longitude value
         * @return array|boolean $latlng The validated latlng values or false if it fails
         */
		public function validate_latlng( $lat, $lng ) {

			if ( is_numeric( $lat ) || ( is_numeric( $lng ) ) )  {
				$latlng = array( 
					"lat" => $lat,
					"lng" => $lng
				);
					
				return $latlng;
			} else {
				return false;	
			}
		}
			
        /** 
         * Geocode the store location
         * 
         * @since 1.0
         * @return array|void $response Contains the country name in the selected language, and the latlng values
         */
		public function geocode_location() {
            
			$geocode_response = $this->get_latlng();

			switch ( $geocode_response['status'] ) {
				case 'OK':
                    $response = array (
                        "country" => $this->filter_country_name( $geocode_response ),
                        "latlng"  => $geocode_response['results'][0]['geometry']['location']
                    );
                    
					return $response;
					break;
				case 'ZERO_RESULTS':
                    $msg = __( 'The Google Geocoding API returned no results for the store location. Please change the location and try again.', 'wpsl' );
					break;	
				case 'OVER_QUERY_LIMIT':
                    $msg = __( 'You have reached the daily allowed geocoding limit, you can read more <a href="https://developers.google.com/maps/documentation/geocoding/#Limits">here</a>.', 'wpsl' );
					break;	
				default:
                    $msg = __( 'The Google Geocoding API failed to return valid data, please try again later.', 'wpsl' );
					break;				
			}
            
            if ( !empty( $msg ) ) {
                add_settings_error ( 'geocode', esc_attr( 'geocode' ), $msg, 'error' );  				
            }
		}

        /** 
         * Make the API call to Google to geocode the address
         * 
         * @since 1.0
         * @return array $response The geocode response
         */
		public function get_latlng() {
            
            $address = $this->store_data["street"].','.$this->store_data["city"].','.$this->store_data["zip"].','.$this->store_data["country"];
			$url = "http://maps.googleapis.com/maps/api/geocode/json?address=".urlencode( $address )."&sensor=false&language=".$this->settings['api_language'];

			if ( extension_loaded( "curl" ) && function_exists( "curl_init" ) ) {
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $ch, CURLOPT_URL, $url ) ;
				$result = curl_exec( $ch );
				curl_close( $ch );
			} else {
				$result = file_get_contents( $url );
			}
			
			$response = json_decode( $result, true );
            
			return $response;
		}
        
        /** 
         * Filter out the country names from the geocode response
         * 
         * Take out both the full country name, and the short country code (2 letters)
         * 
         * @since 1.0
         * @param array $response The fulle API geocode response
         * @return array $country_name Both the full and short country names
         */
        public function filter_country_name( $response ) {

            $length = count( $response[results][0][address_components] );
            
            /* Loop over the address components untill we find the country,political part */
            for ( $i = 0; $i < $length; $i++ ) {
                $address_component = $response[results][0][address_components][$i][types];

                if ( $address_component[0] == 'country' && $address_component[1] == 'political' ) {
                    $country_name['long_name'] = $response[results][0][address_components][$i][long_name];
                    $country_name['short_name'] = $response[results][0][address_components][$i][short_name];
                    
                    break;
                }
            }

            return $country_name;
        }
        
        /**
         * Show the options of the start and store markers
         *
         * @since 1.0
         * @return string $marker_list The complete list of available and selected markers
         */
        public function show_marker_options() {
            
            $marker_list;
            $marker_images = $this->get_available_markers();
            $marker_locations = array( 
                "start", 
                "store" 
            );

            foreach ( $marker_locations as $location ) {
                if ( $location == 'start' ) {
                    $marker_list .= __( 'Start location marker:', 'wpsl' );
                } else  {
                    $marker_list .= __( 'Store location marker:', 'wpsl' ); 
                }

                if ( !empty( $marker_images ) ) {
                    $marker_list .= '<ul class="wpsl-marker-list">';

                    foreach ( $marker_images as $marker_img ) {
                        $marker_list .= $this->create_marker_html( $marker_img, $location );
                    }

                    $marker_list .= '</ul>';
                }
            }
            
            return $marker_list;
        }
        
        /**
         * Load the markers that can be used on the map
         *
         * @since 1.0
         * @return array $marker_images A list of all the available markers
         */
        public function get_available_markers() {
            
            $dir = WPSL_PLUGIN_DIR . 'img/markers/';

            if ( is_dir( $dir ) ) {
                if ( $dh = opendir( $dir ) ) {
                    while ( false !== ( $file = readdir( $dh ) ) ) {
                        if ( $file == '.' || $file == '..' || ( strpos( $file, '@2x' ) !== false ) ) continue;
                        $marker_images[] = $file;
                    }

                    closedir( $dh );
                }
            }
            
            return $marker_images;
        }
        
        /**
         * Create the html output for the marker list that is shown on the settings page
         * 
         * There are two markers lists, one where the user can set the marker for the start point 
         * and one where a marker can be set for the store. We also check if the marker img is identical
         * to the name in the option field. If so we set it to checked
         *
         * @since 1.0
         * @param string $marker_img The filename of the marker
         * @param string $location Eiter contains "start" or "store"
         * @return string $marker_list A list of all the available markers
         */
        public function create_marker_html( $marker_img, $location ) {
            
            $marker_list = '';
            
            if ( $this->settings[$location.'_marker'] == $marker_img ) {
                $checked = 'checked="checked"';
                $css_class = 'class="wpsl-active-marker"';
            } else {
                $checked = '';
                $css_class = '';
            }

            $marker_list .= '<li ' . $css_class . '>';
            $marker_list .= '<img src="' . WPSL_URL . 'img/markers/' . $marker_img . '" />';
            $marker_list .= '<input ' . $checked . ' type="radio" name="wpsl_map[' . $location . '_marker]"  value="' . $marker_img . '" />';
            $marker_list .= '</li>';
            
            return $marker_list;
        }
		
        /**
         * Create a dropdown where users can select the distance unit (km or miles), 
         * and set the active value to selected.
         *
         * @since 1.0
         * @return string $dropdown The html for the distance option list
         */
		public function show_distance_units() {
            
			$items = array( 'km', 'mi' );
			$dropdown = '<select id="wpsl-distance-unit" name="wpsl_search[distance_unit]">';
			
			foreach ( $items as $item => $value ) {
				$selected = ( $this->settings['distance_unit'] == $value ) ? 'selected="selected"' : '';
				$dropdown .= "<option value='$value' $selected>" . $value . "</option>";
			}
			
			$dropdown .= "</select>";
			
			return $dropdown;			
		}
		
		/**
         * Create a dropdown where users can select the used map type
         *
         * @since 1.0
         * @return string $dropdown The html for the map option list
         */
		public function show_map_types() {
            
			$items = array( 
                'roadmap', 
                'satellite', 
                'hybrid', 
                'terrain' 
            );
			$dropdown = '<select id="wpsl-map-type" name="wpsl_map[type]">';
			
			foreach ( $items as $item => $value ) {
				$selected = ( $this->settings['map_type'] == $value ) ? 'selected="selected"' : '';
				$dropdown .= "<option value='$value' $selected>" . ucfirst( $value ) . "</option>";
			}
			
			$dropdown .= "</select>";
			
			return $dropdown;
		}

        /**
         * Create the dropdown to select the zoom level
         *
         * @since 1.0
         * @return string $dropdown The html for the zoom level list
         */
		public function show_zoom_levels() {
            
			$dropdown = '<select id="wpsl-zoom-level" name="wpsl_map[zoom_level]">';
			
			for ( $i = 1; $i < 13; $i++ ) {
				$selected = ( $this->settings['zoom_level'] == $i ) ? 'selected="selected"' : '';
				
				switch ( $i ) {
					case '1':
						$zoom_desc = '- World view';
						break;
					case '3':
						$zoom_desc = '- Default';
						break;
					case '12':
						$zoom_desc = '- Roadmap view';
						break;	
					default:
						$zoom_desc = '';		
				}
		
				$dropdown .= "<option value='$i' $selected>$i $zoom_desc</option>";	
			}
				
			$dropdown .= "</select>";
				
			return $dropdown;
		}
		
       /**
         * Options for the language and region list
         *
         * @since 1.0
         * @param string $list The request list type
         * @return string|void $option_list The html for the selected list, or nothing if the $list contains invalud values
         */
		public function get_api_option_list( $list ) {
			
			switch ( $list ) {
				case 'language':	
					$api_option_list = array ( 	
						__('Select your language', 'wpsl')    => '',
						__('English', 'wpsl')                 => 'en',
						__('Arabic', 'wpsl')                  => 'ar',
						__('Basque', 'wpsl')                  => 'eu',
						__('Bulgarian', 'wpsl')               => 'bg',
						__('Bengali', 'wpsl')                 => 'bn',
						__('Catalan', 'wpsl')                 => 'ca',
						__('Czech', 'wpsl')                   => 'cs',
						__('Danish', 'wpsl')                  => 'da',
						__('German', 'wpsl')                  => 'de',
						__('Greek', 'wpsl')                   => 'el',
						__('English (Australian)', 'wpsl')    => 'en-AU',
						__('English (Great Britain)', 'wpsl') => 'en-GB',
						__('Spanish', 'wpsl')                 => 'es',
						__('Farsi', 'wpsl')                   => 'fa',
						__('Finnish', 'wpsl')                 => 'fi',
						__('Filipino', 'wpsl')                => 'fil',
						__('French', 'wpsl')                  => 'fr',
						__('Galician', 'wpsl')                => 'gl',
						__('Gujarati', 'wpsl')                => 'gu',
						__('Hindi', 'wpsl')                   => 'hi',
						__('Croatian', 'wpsl')                => 'hr',
						__('Hungarian', 'wpsl')               => 'hu',
						__('Indonesian', 'wpsl')              => 'id',
						__('Italian', 'wpsl')                 => 'it',
						__('Hebrew', 'wpsl')                  => 'iw',
						__('Japanese', 'wpsl')                => 'ja',
						__('Kannada', 'wpsl')                 => 'kn',
						__('Korean', 'wpsl')                  => 'ko',
						__('Lithuanian', 'wpsl')              => 'lt',
						__('Latvian', 'wpsl')                 => 'lv',
						__('Malayalam', 'wpsl')               => 'ml',
						__('Marathi', 'wpsl')                 => 'mr',
						__('Dutch', 'wpsl')                   => 'nl',
						__('Norwegian', 'wpsl')               => 'no',
						__('Norwegian Nynorsk', 'wpsl')       => 'nn',
						__('Polish', 'wpsl')                  => 'pl',
						__('Portuguese', 'wpsl')              => 'pt',
						__('Portuguese (Brazil)', 'wpsl')     => 'pt-BR',
						__('Portuguese (Portugal)', 'wpsl')   => 'pt-PT',
						__('Romanian', 'wpsl')                => 'ro',
						__('Russian', 'wpsl')                 => 'ru',
						__('Slovak', 'wpsl')                  => 'sk',
						__('Slovenian', 'wpsl')               => 'sl',
						__('Serbian', 'wpsl')                 => 'sr',
						__('Swedish', 'wpsl')                 => 'sv',
						__('Tagalog', 'wpsl')                 => 'tl',
						__('Tamil', 'wpsl')                   => 'ta',
						__('Telugu', 'wpsl')                  => 'te',
						__('Thai', 'wpsl')                    => 'th',
						__('Turkish', 'wpsl')                 => 'tr',
						__('Ukrainian', 'wpsl')               => 'uk',
						__('Vietnamese', 'wpsl')              => 'vi',
						__('Chinese (Simplified)', 'wpsl')    => 'zh-CN',
						__('Chinese (Traditional)' ,'wpsl')   => 'zh-TW'
				);	
					break;			
				case 'region':
					$api_option_list = array (   
						__('Select your region', '')                   => '',
						__('Afghanistan', 'wpsl')                      => 'af',
						__('Albania', 'wpsl')                          => 'al',
						__('Algeria', 'wpsl')                          => 'dz',
						__('American Samoa', 'wpsl')                   => 'as',
						__('Andorra', 'wpsl')                          => 'ad',
						__('Anguilla', 'wpsl')                         => 'ai',
						__('Angola', 'wpsl')                           => 'ao',
						__('Antigua and Barbuda', 'wpsl')              => 'ag',
						__('Argentina', 'wpsl')                        => 'ar',
						__('Armenia', 'wpsl')                          => 'am',
						__('Aruba', 'wpsl')                            => 'aw',
						__('Australia', 'wpsl')                        => 'au',
						__('Austria', 'wpsl')                          => 'at',
						__('Azerbaijan', 'wpsl')                       => 'az',
						__('Bahamas', 'wpsl')                          => 'bs',
						__('Bahrain', 'wpsl')                          => 'bh',
						__('Bangladesh', 'wpsl')                       => 'bd',
						__('Barbados', 'wpsl')                         => 'bb',
						__('Belarus', 'wpsl')                          => 'by',
						__('Belgium', 'wpsl')                          => 'be',
						__('Belize', 'wpsl')                           => 'bz',
						__('Benin', 'wpsl')                            => 'bj',
						__('Bermuda', 'wpsl')                          => 'bm',
						__('Bhutan', 'wpsl')                           => 'bt',
						__('Bolivia', 'wpsl')                          => 'bo',
						__('Bosnia and Herzegovina', 'wpsl')           => 'ba',
						__('Botswana', 'wpsl')                         => 'bw',
						__('Brazil', 'wpsl')                           => 'br',
						__('British Indian Ocean Territory', 'wpsl')   => 'io',
						__('Brunei', 'wpsl')                           => 'bn',
						__('Bulgaria', 'wpsl')                         => 'bg',
						__('Burkina Faso', 'wpsl')                     => 'bf',
						__('Burundi', 'wpsl')                          => 'bi',
						__('Cambodia', 'wpsl')                         => 'kh',
						__('Cameroon', 'wpsl')                         => 'cm',
						__('Canada', 'wpsl')                           => 'ca',
						__('Cape Verde', 'wpsl')                       => 'cv',
						__('Cayman Islands', 'wpsl')                   => 'ky',
						__('Central African Republic', 'wpsl')         => 'cf',
						__('Chad', 'wpsl')                             => 'td',
						__('Chile', 'wpsl')                            => 'cl',
						__('China', 'wpsl')                            => 'cn',
						__('Christmas Island', 'wpsl')                 => 'cx',
						__('Cocos Islands', 'wpsl')                    => 'cc',
						__('Colombia', 'wpsl')                         => 'co',
						__('Comoros', 'wpsl')                          => 'km',
						__('Congo', 'wpsl')                            => 'cg',
						__('Costa Rica', 'wpsl')                       => 'cr',
						__('Côte d\'Ivoire', 'wpsl')                   => 'ci',
						__('Croatia', 'wpsl')                          => 'hr',
						__('Cuba', 'wpsl')                             => 'cu',
						__('Czech Republic', 'wpsl')                   => 'cz',
						__('Denmark', 'wpsl')                          => 'dk',
						__('Djibouti', 'wpsl')                         => 'dj',
						__('Democratic Republic of the Congo', 'wpsl') => 'cd',
						__('Dominica', 'wpsl')                         => 'dm',
						__('Dominican Republic', 'wpsl')               => 'do',
						__('Ecuador', 'wpsl')                          => 'ec',
						__('Egypt', 'wpsl')                            => 'eg',
						__('El Salvador', 'wpsl')                      => 'sv',
						__('Equatorial Guinea', 'wpsl')                => 'gq',
						__('Eritrea', 'wpsl')                          => 'er',
						__('Estonia', 'wpsl')                          => 'ee',
						__('Ethiopia', 'wpsl')                         => 'et',
						__('Fiji', 'wpsl')                             => 'fj',
						__('Finland', 'wpsl')                          => 'fi',
						__('France', 'wpsl')                           => 'fr',
						__('French Guiana', 'wpsl')                    => 'gf',
						__('Gabon', 'wpsl')                            => 'ga',
						__('Gambia', 'wpsl')                           => 'gm',
						__('Germany', 'wpsl')                          => 'de',
						__('Ghana', 'wpsl')                            => 'gh',
						__('Greenland', 'wpsl')                        => 'gl',
						__('Greece', 'wpsl')                           => 'gr',
						__('Grenada', 'wpsl')                          => 'gd',
						__('Guam', 'wpsl')                             => 'gu',
						__('Guadeloupe', 'wpsl')                       => 'gp',
						__('Guatemala', 'wpsl')                        => 'gt',
						__('Guinea', 'wpsl')                           => 'gn',
						__('Guinea-Bissau', 'wpsl')                    => 'gw',
						__('Haiti', 'wpsl')                            => 'ht',
						__('Honduras', 'wpsl')                         => 'hn',
						__('Hong Kong', 'wpsl')                        => 'hk',
						__('Hungary', 'wpsl')                          => 'hu',
						__('Iceland', 'wpsl')                          => 'is',
						__('India', 'wpsl')                            => 'in',
						__('Indonesia', 'wpsl')                        => 'id',
						__('Iran', 'wpsl')                             => 'ir',
						__('Iraq', 'wpsl')                             => 'iq',
						__('Ireland', 'wpsl')                          => 'ie',
						__('Israel', 'wpsl')                           => 'il',
						__('Italy', 'wpsl')                            => 'it',
						__('Jamaica', 'wpsl')                          => 'jm',
						__('Japan', 'wpsl')                            => 'jp',
						__('Jordan', 'wpsl')                           => 'jo',
						__('Kazakhstan', 'wpsl')                       => 'kz',
						__('Kenya', 'wpsl')                            => 'ke',
						__('Kuwait', 'wpsl')                           => 'kw',
						__('Kyrgyzstan', 'wpsl')                       => 'kg',
						__('Laos', 'wpsl')                             => 'la',
						__('Latvia', 'wpsl')                           => 'lv',
						__('Lebanon', 'wpsl')                          => 'lb',
						__('Lesotho', 'wpsl')                          => 'ls',
						__('Liberia', 'wpsl')                          => 'lr',
						__('Libya', 'wpsl')                            => 'ly',
						__('Liechtenstein', 'wpsl')                    => 'li',
						__('Lithuania', 'wpsl')                        => 'lt',
						__('Luxembourg', 'wpsl')                       => 'lu',
						__('Macau', 'wpsl')                            => 'mo',
						__('Macedonia', 'wpsl')                        => 'mk',
						__('Madagascar', 'wpsl')                       => 'mg',
						__('Malawi', 'wpsl')                           => 'mw',
						__('Malaysia ', 'wpsl')                        => 'my',
						__('Mali', 'wpsl')                             => 'ml',
						__('Marshall Islands', 'wpsl')                 => 'mh',
						__('Martinique', 'wpsl')                       => 'il',
						__('Mauritania', 'wpsl')                       => 'mr',
						__('Mauritius', 'wpsl')                        => 'mu',
						__('Mexico', 'wpsl')                           => 'mx',
						__('Micronesia', 'wpsl')                       => 'fm',
						__('Moldova', 'wpsl')                          => 'md',
						__('Monaco' ,'wpsl')                           => 'mc',
						__('Mongolia', 'wpsl')                         => 'mn',
						__('Montenegro', 'wpsl')                       => 'me',
						__('Montserrat', 'wpsl')                       => 'ms',
						__('Morocco', 'wpsl')                          => 'ma',
						__('Mozambique', 'wpsl')                       => 'mz',
						__('Myanmar', 'wpsl')                          => 'mm',
						__('Namibia', 'wpsl')                          => 'na',
						__('Nauru', 'wpsl')                            => 'nr',
						__('Nepal', 'wpsl')                            => 'np',
						__('Netherlands', 'wpsl')                      => 'nl',
						__('Netherlands Antilles', 'wpsl')             => 'an',
						__('New Zealand', 'wpsl')                      => 'nz',
						__('Nicaragua', 'wpsl')                        => 'ni',
						__('Niger', 'wpsl')                            => 'ne',
						__('Nigeria', 'wpsl')                          => 'ng',
						__('Niue', 'wpsl')                             => 'nu',
						__('Northern Mariana Islands', 'wpsl')         => 'mp',
						__('Norway', 'wpsl')                           => 'no',
						__('Oman', 'wpsl')                             => 'om',
						__('Pakistan', 'wpsl')                         => 'pk',
						__('Panama' ,'wpsl')                           => 'pa',
						__('Papua New Guinea', 'wpsl')                 => 'pg',
						__('Paraguay' ,'wpsl')                         => 'py',
						__('Peru', 'wpsl')                             => 'pe',
						__('Philippines', 'wpsl')                      => 'ph',
						__('Pitcairn Islands', 'wpsl')                 => 'pn',
						__('Poland', 'wpsl')                           => 'pl',
						__('Portugal', 'wpsl')                         => 'pt',
						__('Qatar', 'wpsl')                            => 'qa',
						__('Reunion', 'wpsl')                          => 're',
						__('Romania', 'wpsl')                          => 'ro',
						__('Russia', 'wpsl')                           => 'ru',
						__('Rwanda', 'wpsl')                           => 'rw',
						__('Saint Helena', 'wpsl')                     => 'sh',
						__('Saint Kitts and Nevis', 'wpsl')            => 'kn',
						__('Saint Vincent and the Grenadines', 'wpsl') => 'vc',
						__('Saint Lucia', 'wpsl')                      => 'lc',
						__('Samoa', 'wpsl')                            => 'ws',
						__('San Marino', 'wpsl')                       => 'sm',
						__('São Tomé and Príncipe', 'wpsl')            => 'st',
						__('Saudi Arabia', 'wpsl')                     => 'sa',
						__('Senegal', 'wpsl')                          => 'sn',
						__('Serbia', 'wpsl')                           => 'rs',
						__('Seychelles', 'wpsl')                       => 'sc',
						__('Sierra Leone', 'wpsl')                     => 'sl',
						__('Singapore', 'wpsl')                        => 'sg',
						__('Slovakia', 'wpsl')                         => 'si',
						__('Solomon Islands', 'wpsl')                  => 'sb',
						__('Somalia', 'wpsl')                          => 'so',
						__('South Africa', 'wpsl')                     => 'za',
						__('South Korea', 'wpsl')                      => 'kr',
						__('Spain', 'wpsl')                            => 'es',
						__('Sri Lanka', 'wpsl')                        => 'lk',
						__('Sudan', 'wpsl')                            => 'sd',
						__('Swaziland', 'wpsl')                        => 'sz',
						__('Sweden', 'wpsl')                           => 'se',
						__('Switzerland', 'wpsl')                      => 'ch',	
						__('Syria', 'wpsl')                            => 'sy',
						__('Taiwan', 'wpsl')                           => 'tw',
						__('Tajikistan', 'wpsl')                       => 'tj',
						__('Tanzania', 'wpsl')                         => 'tz',
						__('Thailand', 'wpsl')                         => 'th',
						__('Timor-Leste', 'wpsl')                      => 'tl',
						__('Tokelau' ,'wpsl')                          => 'tk',
						__('Togo', 'wpsl')                             => 'tg',
						__('Tonga', 'wpsl')                            => 'to',
						__('Trinidad and Tobago', 'wpsl')              => 'tt',
						__('Tunisia', 'wpsl')                          => 'tn',
						__('Turkey', 'wpsl')                           => 'tr',
						__('Turkmenistan', 'wpsl')                     => 'tm',
						__('Tuvalu', 'wpsl')                           => 'tv',
						__('Uganda', 'wpsl')                           => 'ug',
						__('Ukraine', 'wpsl')                          => 'ua',
						__('United Arab Emirates', 'wpsl')             => 'ae',
						__('United Kingdom', 'wpsl')                   => 'gb',
						__('United States', 'wpsl')                    => 'us',
						__('Uruguay', 'wpsl')                          => 'uy',
						__('Uzbekistan', 'wpsl')                       => 'uz',
						__('Wallis Futuna', 'wpsl')                    => 'wf',
						__('Venezuela', 'wpsl')                        => 've',
						__('Vietnam', 'wpsl')                          => 'vn',
						__('Yemen', 'wpsl')                            => 'ye',
						__('Zambia' ,'wpsl')                           => 'zm',
						__('Zimbabwe', 'wpsl')                         => 'zw'	
				);				
			}
			
			/* Make sure we have a array with a value */			
			if ( !empty( $api_option_list ) && ( is_array( $api_option_list ) ) ) {
				$i = 0;
				
				foreach ( $api_option_list as $api_option_key => $api_option_value ) {  
				
					/* If no option value exist, set the first one as the selected */
					if ( ( $i == 0 ) && ( empty( $this->settings['api_'.$list] ) ) ) {
						$selected = 'selected="selected"';
					} else {
						$selected = ( $this->settings['api_'.$list] == $api_option_value ) ? 'selected="selected"' : '';
					}
					
					$option_list .= '<option value="' . esc_attr( $api_option_value ) . '"' . $selected . '> ' . esc_html( $api_option_key ) . '</option>';
					$i++;
				}
												
				return $option_list;				
			}

		}
        
        /**
         * Check if we can use a font for the plugin icon
         *
         * @since 1.0
         * @param $location The location
         * @return void
         */
        private function check_icon_font_usage( $location ) {
                        
            global $wp_version;

            if ( ( version_compare( $wp_version, '3.8', '>=' ) == TRUE ) ) {
                wp_enqueue_style( 'wpsl-admin-css-38', plugins_url( '/css/style-3.8.css', __FILE__ ), false );
            } 
        }
        
        /**
         * Add the required admin script
         *
         * @since 1.0
         * @return void
         */
		public function admin_scripts() {	
			wp_enqueue_media();
            wp_enqueue_script( 'jquery-ui-dialog' );
            wp_enqueue_style( 'jquery-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/themes/smoothness/jquery-ui.css' );
			wp_enqueue_style( 'wpsl-admin-css', plugins_url( '/css/style.css', __FILE__ ), false );
            $this->check_icon_font_usage( 'footer' );
			wp_enqueue_script( 'wpsl-gmap', ( "//maps.google.com/maps/api/js?sensor=false&libraries=places&language=" . $this->settings['api_language'] ), false ); // we set the language here to make sure the geocode response returns the country name in the correct language
			wp_enqueue_script( 'wpsl-admin-js', plugins_url( '/js/wpsl-admin.js', __FILE__ ), array( 'jquery' ), false );				
            wp_enqueue_script( 'wpsl-queue', plugins_url( '/js/ajax-queue.js', __FILE__ ), array( 'jquery' ), false ); 
            wp_enqueue_script( 'wpsl-retina', plugins_url( '/js/retina-1.1.0.js', __FILE__ ), array( 'jquery' ), false ); 
        }
	}
	
	new WPSL_Admin;
}