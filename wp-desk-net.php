<?php
/*
Plugin Name: Desk-Net
Plugin URI: http://support.desk-net.com/hc/en-us/articles/115003386247
Description: Desk-Net integration with WordPress
Version: 1.0.1
Author: Desk-Net GmbH
Author URI: http://www.desk-net.com
*/

require_once 'include/WPDeskNetCore.php';
require_once 'include/DeskNetDeleteItems.php';
require_once 'include/DeskNetScanMediaFiles.php';
require_once 'include/DeskNetRequestMethod.php';

class WPDeskNet extends WPDeskNetCore {

	public $desk_net_permalinks = 1;

    /**
     * @var string post content
     */
    protected $postContent = '';

	public function __construct() {
        $this->desk_net_permalinks = get_option( 'wp_desk_net_id_in_permalink' );

        add_filter( 'query', array( $this, 'filter_query' ), 10, 1 );
        add_action( 'init', array( $this, 'desk_net_init' ) );
		add_action( 'rest_api_init', array( $this, 'api_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'desc_net_css_js' ) );

		if ( ! is_admin() && $this->desk_net_permalinks != 0 ) {
			add_action( 'wp', array( $this, 'desk_net_redirect' ) );
			add_filter( 'post_link', array( $this, 'desk_net_post_link' ), 10, 3 );
		}

		parent::__construct();
	}

    /**
     * Perform decode special chars
     *
     * @param string $query
     * @return string $query
     */
    public function filter_query( $query ) {
        $query = html_entity_decode( $query );
        return $query;
    }

    /**
     * Perform initialize default settings in plugin
     *
     */
	public function desk_net_init() {

		if ( $this->desk_net_permalinks != 0 ) {

			add_rewrite_rule( '([0-9]+)/(.?.+?)?/?$', 'index.php?page=&post_type=post&name=$matches[2]&desk_net_id=$matches[1]', 'top' );

			add_filter( 'query_vars', function ( $vars ) {
				$vars[] = 'desk_net_id';

				return $vars;
			} );
		}

		flush_rewrite_rules();
	}

    /**
     * Perform redirect page in WordPress
     *
     */
	public function desk_net_redirect() {

		if ( ! is_single() ) {
			return;
		}

		global $post;

		$url = $this->desk_net_post_link( $post->guid, $post );

		if ( $url != $post->guid ) {
			wp_redirect( $url, 301 );
			exit;
		}
	}

    /**
     * Perform API hooks
     *
     */
	public function api_hooks() {

		$namespace = 'wp-desk-net/v1';

		register_rest_route( $namespace, '/oauth2/token', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'auth' )
		) );

        register_rest_route( $namespace, '/oauth/token', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'auth' )
		) );

		register_rest_route( $namespace, '/publication', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'publication' )
		) );

		register_rest_route( $namespace, '/publication/(?P<post_id>\d+)', array(
            'methods'  => 'PUT',
			'callback' => array( $this, 'publication' ),
		) );

        register_rest_route( $namespace, '/statuses', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'statuses' )
		) );

        register_rest_route( $namespace, '/files', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'files' )
		) );

        register_rest_route( $namespace, '/files/(?P<cms_id>\d+)', array(
			'methods'  => 'PUT',
			'callback' => array( $this, 'files' )
		) );

		register_rest_route( $namespace, '/publication/(?P<post_id>\d+)', array(
			'methods'  => 'DELETE',
			'callback' => array( $this, 'publication' )
		) );

	}

    /**
     * Perform Update|Delete|Create post on WordPress by request from Desk-Net
     *
     * @param WP_REST_Request $data The data from Desk-Net
     *
     * @return object $response
     */
	public function publication( WP_REST_Request $data ) {
        $headers = $data->get_headers();
		$response_data = array(
            "Header params" => json_encode($headers),
			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD']
		);
		$this->log_error( $response_data );

		if ( ! $this->check_token() ) {
			$response_data = array(
				'message' => __( 'Wrong token', 'wp_desk_net' )
			);
			$this->log_error( $response_data );
			$response = new WP_REST_Response( $response_data, 401 );

			return $response;
		}

        if ( $data['post_id'] != null && get_post_meta( $data['post_id'], 'wp_desk_net_remove_status', true ) == 'removed' ) {
            $response = new WP_REST_Response( '', 200 );

            return $response;
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'DELETE' ) {
            if ( empty( $data['post_id'] ) || ! is_numeric( $data['post_id'] ) ) {
                $response_data = array(
                    'message' => __( 'Wrong post ID', 'wp_desk_net' )
                );
                $this->log_error( $response_data );
                $response = new WP_REST_Response( $response_data, 406 );

                return $response;
            }

            $response_data = array(
                'Post ID - DELETE items' => $data['post_id']
            );
            $this->log_error( $response_data );

            update_post_meta( $data['post_id'], 'wp_desk_net_remove_status', 'removed' );

            //Status Matching
            $matchingStatusRemove = get_option('wp_desk_net_status_desk_net_to_wp_removed');

            if ( isset( $matchingStatusRemove ) ) {
                $updatePostFields = array(
                    'ID'           => $data['post_id'],
                    'post_status'   => $matchingStatusRemove,
                );

                wp_update_post( $updatePostFields );
            }

            $response = new WP_REST_Response( '', 200 );

            return $response;
        }

		if ( $_SERVER['REQUEST_METHOD'] === 'PUT' ) {
			if ( empty( $data['post_id'] ) || ! is_numeric( $data['post_id'] ) ) {
				$response_data = array(
					'message' => __( 'Wrong post ID', 'wp_desk_net' )
				);
				$this->log_error( $response_data );
				$response = new WP_REST_Response( $response_data, 406 );

				return $response;
			}
		}

        $json_request = $data->get_json_params();
        //debugger
		$response_data = array(
			'JSON DATA' => json_encode( $json_request )
		);
		$this->log_error( $response_data );
		if ( empty( $json_request ) ) {
			$response_data = array(
				'message' => __( 'Error: Empty json request', 'wp_desk_net' )
			);
			$this->log_error( $response_data );
			$response = new WP_REST_Response( $response_data, 400 );

			return $response;
		}

        $json_request['post_id'] = $data['post_id'];

		$response = $this->create_post( $json_request );

		return $response;
	}

    /**
     * Perform Update Status list
     *
     * @param WP_REST_Request $data
     *
     */
	 public function statuses( WP_REST_Request $data ) {
        $json_request = $data->get_json_params();
        $saveStatusesList = get_option( 'wp_desk_net_desk-net-list-active-status' );
        $response_data = array(
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
            'Desk-Net Status list' => json_encode( $json_request )
        );
        $this->log_error( $response_data );
        //Update Platform ID
        if ( isset( $json_request['platform'] ) ) {
            update_option( 'wp_desk_net_platform_id', $json_request['platform']);
        }

        $json_request = $this->checkTriggersExportStatus( $json_request );

        if ( ! empty ( $saveStatusesList ) ) {
            $wpStatusList = get_post_statuses();
            $deleteElements = new DeskNetDeleteItems();
            $deleteElements->shapeDeletedItems( $json_request['activeStatuses'], $saveStatusesList, $wpStatusList, 'status' );
        }
        if ( ! empty ( $json_request['activeStatuses'] ) ) {
            update_option('wp_desk_net_desk-net-list-active-status', $json_request['activeStatuses']);
            update_option('wp_desk_net_status_wp_to_desk_net_publish', '5');
            update_option('wp_desk_net_status_desk_net_to_wp_5', 'publish');
        }
        if ( ! empty ( $json_request['deactivatedStatuses'] ) ) {
            update_option('wp_desk_net_desk-net-list-deactivated-status', $json_request['deactivatedStatuses']);
        }
    }

    /**
     * Perform Download Attachments
     *
     * @param WP_REST_Request $data
     *
     * @return array|object
     */
    public function files( WP_REST_Request $data ) {
        if ( ! $this->check_token() ) {
            $response_data = array(
                'message' => __( 'Wrong token', 'wp_desk_net' )
            );
            $this->log_error( $response_data );
            $response = new WP_REST_Response( $response_data, 401 );

            return $response;
        }

        $binaryFileString = $data->get_param('file');

        $basicFilename = $data->get_param( 'filename' );
        $sanitizeFilename = $this->_sanitizeFileName( $basicFilename );
        $response_data = array(
            "Basic FileName" => $basicFilename,
            "Update FileName" => $sanitizeFilename,
        );
        $this->log_error( $response_data );
        $wp_filetype = wp_check_filetype( $sanitizeFilename );
        $max_upload_size = wp_max_upload_size();
        if ( ! $max_upload_size ) {
            $max_upload_size = 0;
        }

        if ( ( $max_upload_size < $data->get_header( 'content_length' )) || ! $wp_filetype['ext']  ) {
            $response_data = array(
                "File is very big or it's type unsupported WP" => 'Not upload ' . $sanitizeFilename,
            );
            $this->log_error( $response_data );
            $response['id'] = 1;

            return $response;
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'PUT' && !empty($data['cms_id'])) {
            $response['id'] = $data['cms_id'];
            if ( $data['cms_id'] == 1) {
                if ( isset( get_post( $data['cms_id'])->post_title ) && get_post( $data['cms_id'] )->post_title == $basicFilename ) {
                    $response['cmsEditLink'] = admin_url("upload.php?item=" . $data['cms_id']);
                    $response['cmsOpenLink'] = wp_get_attachment_url($data['cms_id']);
                } else {
                    $response_data = array(
                        "Not update file because: file very big or it's type unsupported WP" => 'Not update ' . $basicFilename,
                    );
                    $this->log_error( $response_data );
                }
            } else {
                $response['cmsEditLink'] = admin_url("upload.php?item=" . $data['cms_id']);
                $response['cmsOpenLink'] = wp_get_attachment_url($data['cms_id']);
            }
            return $response;
        }

        $info = wp_upload_bits( $sanitizeFilename, null, $binaryFileString);
        $file = $info['file'];

        $response_data = array(
            'File Info' => json_encode( $info ),
            'File Upload Dir' => $info['url'],
            'File Exist' => $sanitizeFilename,
            'Info' => json_encode( $info ),
        );
        $this->log_error( $response_data );

        $type = wp_check_filetype( $file, null );

        //Scan Media lib by filename/type/md5hash
        $scanFiles = new DeskNetScanMediaFiles( );
        $postTitle = preg_replace( '/\.[^.]+$/', '', $basicFilename );
        $attach_id = $scanFiles->scanMediaFiles( $postTitle, $type['type'], $file );

        if ( $attach_id === false ) {

            $response = $this->loadToMediaLib( $file, $type['type'], $info['url'], $basicFilename );

            $response_data = array(
                'Response Create File' => json_encode($response ),
            );
            $this->log_error($response_data);

            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                if (empty($data['post_id']) || !is_numeric($data['post_id'])) {
                    $response_data = array(
                        'message' => __('Wrong file ID', 'wp_desk_net')
                    );
                    $this->log_error($response_data);
                    $response = new WP_REST_Response($response_data, 406);

                    return $response;
                }

            }
        } else {
            unlink( $info['file'] );
            $response['id'] = $attach_id;
            $response['cmsEditLink'] = admin_url("upload.php?item=$attach_id");
            $response['cmsOpenLink'] = $info['url'];
        }

        if ( $response != false ) {
            return $response;
        } else {
            $response = new WP_REST_Response( '', 500 );
            return $response;
        }
    }

    /**
     * The replace space in fileName
     *
     * @param string $filename The file name
     *
     * @return string $filename
     */
    public function _sanitizeFileName( $filename ) {
        $filename_raw = $filename;
        $special_chars = array("?", "[", "]", "/", "\\", "=", "<", ">", ":", ";", ",", "'", "\"", "&", "$", "#", "*", "(", ")", "|", "~", "`", "!", "{", "}", "%", "+", chr(0));
        /**
         * Filters the list of characters to remove from a filename.
         *
         * @param array  $special_chars Characters to remove.
         * @param string $filename_raw  Filename as it was passed into _sanitizeFileName().
         */
        $special_chars = apply_filters( 'sanitize_file_name_chars', $special_chars, $filename_raw );
        $filename = preg_replace( "#\x{00a0}#siu", ' ', $filename );
        $filename = str_replace( $special_chars, '', $filename );
        $filename = str_replace( array( '%20', '+' ), '_', $filename );
        $filename = preg_replace( '/[\r\n\t ]+/', '_', $filename );
        $filename = trim( $filename, '.-_' );

        return $filename;
    }

    /**
     * The detect content mapping
     *
     * @param string $fileID The ID on file in media
     * @param string $formatID The ID for task format
     * @param string $content The content for text field
     *
     * @return array $response
     */
    public function checkContentMatching ( $fileID, $formatID, $content = '' ) {
        $formatsList = get_option( 'wp_desk_net_formats_list' );
        $contentMatchingValue = 'body-media';
        $file = 'content';

        if ( ! $formatsList ) {
            $requestObject = new DeskNetRequestMethod();
            $formatsList = $requestObject->get( self::DN_BASE_URL, 'formats' );
            update_option( 'wp_desk_net_formats_list', $formatsList );
        }
        $formatsList = json_decode( $formatsList, true);
        $formatType = $this->getFormatType ( $formatsList, $formatID );

        switch ( $formatType ) {
            case 1:
                $fileFormat = 'text';
                break;
            case 2:
                $fileFormat = 'picture';
                break;
            case 3:
                $fileFormat = 'video';
                break;
            case 4:
                $fileFormat = 'other';
                break;
            case 5:
                $fileFormat = 'other';
                break;
            default: $fileFormat = 'default';
        }

        if ( $fileFormat !== 'text' ) {
            $file = get_attached_file( $fileID );
        }

        if ( $fileFormat !== 'default' && $fileFormat !== 'text' ) {
            $contentMatchingValue = get_option("wp_desk_net_content_" . $fileFormat . "-files");
        }

        $response_data = array(
            'Format Type' => $formatType,
            'Format ID' => $formatID,
            'Сontent Matching Value' => json_encode( !$contentMatchingValue ),
        );
        $this->log_error( $response_data );

        if ( ! $contentMatchingValue || $contentMatchingValue == 'body-media' ) {
            return $this->insertFileToContent( $file, $fileID, $content );
        } else {
            return $this->postContent;
        }
    }

    /**
     * Get format type
     *
     * @param array $formatsList The formats list with types
     * @param string $formatID The attach file format ID
     * @param boolean $attemptStatus The attach file format ID
     *
     * @return string $formatType
     */
    public function getFormatType ( $formatsList, $formatID, $attemptStatus = false ) {
        $formatType = false;
        if ( ! empty ( $formatsList ) ) {
            foreach ( $formatsList as $value ) {
                if ( $value['id'] == $formatID ) {
                    $formatType = $value['type'];
                }
            }
        }

        if ( ! $formatType && $attemptStatus === true ) {
            $requestObject = new DeskNetRequestMethod();
            $formatsList = $requestObject->get( self::DN_BASE_URL, 'formats' );
            update_option( 'wp_desk_net_formats_list', $formatsList );
            $formatType = $this->getFormatType( $formatsList, $formatID, true );
        }

        return $formatType;
    }

    /**
     * The attach content
     *
     * @param string $file The link on file
     * @param string $fileType The type of file
     * @param string $url The file url
     * @param string $filename The file name
     *
     * @return array $response
     */
    public function loadToMediaLib( $file, $fileType, $url, $filename ) {
        $attachment = array(
            'guid'           => $url,
            'post_mime_type' => $fileType,
            'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $response_data = array(
            'File Type' => json_encode( $fileType ),
            'File Name' => $filename,
            'File URL' => $url,
            'File Attachment params' => json_encode( $attachment ),
        );
        $this->log_error( $response_data );

        $attach_id = wp_insert_attachment( $attachment, $file );

        $response['id'] = $attach_id;
        $response['cmsEditLink'] = admin_url("upload.php?item=$attach_id");
        $response['cmsOpenLink'] = $url;

        //debug
        $response_data = array(
            'File ID' => $attach_id,
            'File cmsEditLink' => $response['cmsEditLink'],
            'File cmsOpenLink' => $response['cmsOpenLink'],
        );
        $this->log_error($response_data);

        // Generate the metadata for the attachment, and update the database record.
        if ( substr_count( $fileType, 'image' ) > 0 ) {
            require_once( ABSPATH . 'wp-admin/includes/image.php' );

            $attach_data = wp_generate_attachment_metadata($attach_id, $file);
            $response_data = array(
                'File Attachment' => json_encode( $attach_data ),
            );
            $this->log_error($response_data);

            wp_update_attachment_metadata($attach_id, $attach_data);
        }

        return $response;
    }

    /**
     * The insert content to body
     *
     * @param string $file The file url
     * @param string $fileID The file ID
     * @param string $content The content for text field
     *
     * @return array $response
     */
    public function insertFileToContent ( $file, $fileID, $content ) {
        if ( ! $this->scanPostContentOnFilesFromDN( $fileID ) ) {
            $this->postContent .= $this->generateShortCode( $file, $fileID, $content );
        }

        return $this->postContent;
    }

    /**
     * Perform detect shortcode supported file type
     *
     * @param $fileType string The type of file
     *
     * @return string
     */
    public function checkSupportedShortCodeFileType ( $fileType ) {
        $supportedShortCodeType = array('mp4', 'm4v', 'webm', 'ogv', 'wmv', 'flv','mp3', 'm4a', 'ogg', 'wav', 'wma');

        foreach ( $supportedShortCodeType as $value ) {
            if ( $fileType['ext'] == $value ) {
                return 'shortCode';
            }
        }

        return null;
    }

    /**
     * The scan post content on files/text from Desk-Net
     *
     * @param string $fileID The file ID
     *
     * @return string $response
     */
    public function scanPostContentOnFilesFromDN ( $fileID ) {
        if ( ! empty ( $this->postContent ) ) {
            $searchResult =  preg_match( "/\\bdn\\-file\\-id\\-$fileID+[\\'|\\\"|\\:|\\s]/", $this->postContent, $oldContent );
            $response_data = array(
                "Update Task with ID $fileID" => json_encode( $searchResult )
            );
            $this->log_error( $response_data );
            if ( ! $searchResult ) {
                return false;
            } else {
                return true;
            }
        } else {
            return false;
        }
    }

    /**
     * The insert content to body
     *
     * @param string $file The file url
     * @param string $fileID The file ID
     * @param string $content The content for text field
     *
     * @return string $response
     */
    public function generateShortCode( $file, $fileID, $content ) {
        if ( $file !== 'content') {
            $fileType = wp_check_filetype( $file, null );
            $supportShortCode = $this->checkSupportedShortCodeFileType( $fileType );
            preg_match('/^\w*/', $fileType['type'], $type);

            if ( ! empty ( $supportShortCode )) {
                $contentTemplate = $supportShortCode;
            } else {
                $contentTemplate = $type[0];
            }
        } else{
            $contentTemplate = $file;
        }

        switch ( $contentTemplate ) {
            case 'shortCode':
                $fileUrl = wp_make_link_relative( wp_get_attachment_url( $fileID ) );
                $shortCode = "<p>[$type[0] dn-file-id-$fileID:\"$fileID\" src=\"$fileUrl\"][/$type[0]]</p>";
                break;
            case 'image':
                $imageProperty = wp_get_attachment_image_src( $fileID, 'large');
                $fileUrl = wp_make_link_relative( $imageProperty[0] );
                $shortCode = "<p><img class=\"dn-file-id-$fileID\" src=\"$fileUrl\" alt=\"\" width=\"$imageProperty[1]\" height=\"$imageProperty[2]\"/></p>";
                break;
            case 'content':
                $shortCode = "<p class=\"dn-file-id-$fileID\">$content</p>";
                break;
            default:
                $file = get_post( $fileID );
                $fileName = $file->post_title;
                $fileUrl = wp_make_link_relative( wp_get_attachment_url( $fileID ) );
                $shortCode = "<p><a class=\"dn-file-id-$fileID\" href='$fileUrl'>$fileName</a></p>";
        }
        $response_data = array(
            'Generate ShortCode' => $shortCode,
            'ShortCode type' => $contentTemplate
        );
        $this->log_error( $response_data );
        return $shortCode;
    }

    /**
     * Perform edit post data
     *
     * @param array $json_request The post data
     *
     * @return array|object
     */
	public function post_data( $json_request ) {

		if ( empty( $json_request['description'] ) ) {
			$response_data = array(
				'message' => __( 'Error: Story description is empty', 'wp_desk_net' )
			);
			$this->log_error( $response_data );
			$response = new WP_REST_Response( $response_data, 428 );

			return $response;
		}
		$title = preg_replace('/[\r\n\t ]+/', ' ', $json_request['description']);

        // Update Status
		$wpStatusName = get_option( 'wp_desk_net_status_desk_net_to_wp_' . $json_request['publication']['status']['id'] );
		$wpStatusSlug = $this->statusMatching( $wpStatusName );

		$response_data = array(
			'Desk-Net STATUS' => $wpStatusSlug
		);
		$this->log_error( $response_data );

		if ( empty ( $wpStatusSlug )) $wpStatusSlug = 'draft';

		$post_data = array(
			'post_title'  => $title,
			'post_status' => $wpStatusSlug,
		);

		if ( isset( $json_request['post_id'] ) ) {
			$post_data['ID'] = $json_request['post_id'];
		}
        $response_data = array(
            'Valid Date FROM DN' => json_encode( $json_request )
        );
        $this->log_error( $response_data );
		if ( ! empty( $json_request['publication']['single']['start'] ) ) {

			$date          = $json_request['publication']['single']['start'];
			$response_data = array(
				'Valid Date' => $this->validate_date( $date )
			);
			$this->log_error( $response_data );
			$valid_date = $this->validate_date( $date );

			if ( ! $valid_date ) {
				$response_data = array(
					'message' => __( 'Error: wrong data format', 'wp_desk_net' )
				);
				$this->log_error( $response_data );
				$response = new WP_REST_Response( $response_data, 428 );

				return $response;
			}

			if ( $valid_date ) {
				$post_data['post_date_gmt'] = $valid_date;
			}
		}

		return $post_data;
	}

	/**
	 * Perform get slug for post status
	 *
	 * @param string $wpStatusName
	 *
	 * @return string
	 */
	public function statusMatching( $wpStatusName ) {
        $postStatuses = get_post_statuses();

        if ( ! empty($wpStatusName) && $wpStatusName != 'No Status') {
            foreach ( $postStatuses as $key => $wpStatus ){
                if ( $key == $wpStatusName ) return $key;
            }
        } else {
            return 'draft';
        }
	}

    /**
     * Perform Create post on WP from DN
     *
     * @param array $json_request The post params
     *
     * @return WP_REST_Response|boolean
     */
	public function create_post( $json_request ) {
        if ( $this->activePostCategory( $json_request, true ) ) {
            $response = new WP_REST_Response( '', 200 );
            return $response;
        }
        $lockPostContent = false;
		$post_data = $this->post_data( $json_request );

		if ( empty( $post_data['post_title'] ) ) {
            $response_data = array(
                'JSON data from DN' => json_encode( $post_data )
            );
			$this->log_error( $response_data );
			$response = new WP_REST_Response( $post_data, 428 );

			return $response;
		}
        //Check Status create/update post on WP
        if ( ! empty( $json_request['publication']['category']['id'] ) ) {
            $categoryID = $json_request['publication']['category']['id'];
            if (get_option('wp_desk_net_category_desk_net_to_wp_' . $categoryID) == 'Do not import') {
                return false;
            }
        }

		// Set author if it exists
		if ( ! empty( $json_request['tasks'] ) ) {

			foreach ( $json_request['tasks'] as $task ) {

				if ( ! empty ( $task['format']['name'] ) ) {

					if ( ! empty( $task['assignee'] ) ) {

						$user_id = $this->valid_user( $task['assignee']['name'] );

						if ( $user_id ) {
							$post_data['post_author'] = $user_id;
							//Save author ID
							update_user_meta( $user_id, 'desk_net_author_id', $task['assignee']['id'] );
                            break;
						}
					}
				}
			}
		}
        $response_data = array(
            'Validate Create Date Method' => $_SERVER['REQUEST_METHOD'],
            'Insert or Update JSON data' => json_encode( $post_data )
        );
        $this->log_error( $response_data );

        $post_data['ID'] = ( isset( $post_data['ID'] ) ) ? $post_data['ID'] : '';

        if ( ! empty ( $post_data['ID'] ) ) {
            $content_post = get_post( $post_data['ID'] );
            $this->postContent = $content_post->post_content;
            $lockPostContent = get_post_meta( $post_data['ID'], 'wp_desk_net_lock_update_post_content', true );
        }

        if( ! empty ( $json_request['tasks'] ) && $lockPostContent != true
            && get_post_status( $json_request['post_id'] ) != 'publish'
            && get_post_status( $json_request['post_id'] ) != 'future' ) {

            foreach ( $json_request['tasks'] as $value ) {
                if ( ! empty( $value['files'] ) ) {
                    foreach ( $value['files'] as $file ) {
                        $basicFilename = $file['filename'];
                        $basicFilename = preg_replace('/\.[^.]+$/', '', $basicFilename);
                        if (isset(get_post($file['cmsId'])->post_title) && get_post($file['cmsId'])->post_title == $basicFilename) {

                            $post_data['post_content'] = $this->checkContentMatching($file['cmsId'], $value['format']['id'], '', $this->_sanitizeFileName($file['filename']));
                            //Add task ID for element
                            update_post_meta($file['cmsId'], 'wp_desk_net_file_id', $value['id']);
                        }
                    }
                } elseif ( ! empty ( $value['content'] ) ) {
                    $post_data['post_content'] = $this->checkContentMatching( $value['id'], $value['format']['id'], $value['content'] );
                }
            }
            if ( ! empty( $post_data['post_content'] )) {
                $response_data = array(
                    'The adding new post content' => $post_data['post_content'],
                );
                $this->log_error( $response_data );
            }
        } else {
            $response_data = array(
                'The post content' => 'Not update',
            );
            $this->log_error( $response_data );
        }
        //Full title form Desk-Net
        $deskNetTitle = $post_data['post_title'];

		if ( $_SERVER['REQUEST_METHOD'] === 'PUT' ) {

			update_post_meta( $post_data['ID'], 'desk_net_description', html_entity_decode( $post_data['post_title'] ));

			unset( $post_data['post_title'] );
            if ( ! empty( $post_data['post_date_gmt'] )) {
                $date = strtotime($post_data['post_date_gmt']);
                $post_data['post_date_gmt'] = date('Y-m-d H:i:s', $date);

                $add_timezone = strtotime($post_data['post_date_gmt']) + get_option('gmt_offset') * 60 * 60;
                $post_data['post_date'] = date('Y-m-d H:i:s', $add_timezone);
            } else {
                $date = strtotime(get_the_date( 'Y-m-d H:i:s', $post_data['ID'] ));
                $post_data['post_date_gmt'] =  get_gmt_from_date( $date, 'Y-m-d H:i:s');
                $post_data['post_date'] = date('Y-m-d H:i:s', $date);
            }
            $post_data = wp_slash( $post_data );
			$post_id = wp_update_post( $post_data );
            $response_data = array(
                'Update Data' => json_encode( $post_data )
            );
            $this->log_error( $response_data );
		} else {
            $post_data = wp_slash( $post_data );
            if( strlen( $post_data['post_title'] ) > 77 ) {
                $post_data['post_title'] = mb_substr( $post_data['post_title'], 0, 77 ) . '...';
            }
            $response_data = array(
                'Insert post Data' => json_encode($post_data)
            );
            $this->log_error( $response_data );
            $post_id = wp_insert_post( $post_data, true );
		}

		//debugger
		$response_data = array(
			'METHOD UPDATE DATA' => $_SERVER['REQUEST_METHOD'],
			'Post ID'            => json_encode( $post_id )
		);
		$this->log_error( $response_data );

		//Update Active Category
		$this->activePostCategory( $json_request, false, $post_id );

		if ( isset( $deskNetTitle ) ) {
			update_post_meta( $post_id, 'desk_net_description', html_entity_decode( $deskNetTitle ));
		}

		if ( isset( $post_id ) && $json_request['id'] ) {
			update_post_meta( $post_id, 'story_id', $json_request['id'] );
		}

		if ( isset( $post_id ) && isset( $json_request['publication']['id'] ) ) {
			update_post_meta( $post_id, 'publications_id', $json_request['publication']['id'] );
		}

		$post = get_post( $post_id );
        $postEditLink = get_edit_post_link( $post_id );

        if ( empty ( $postEditLink ) ) {
            $postEditLink = admin_url( ) . "post.php?post=$post_id&action=edit";
        }

		$response_data = array(
			'id'  => $post_id,
			'cmsEditLink' => $postEditLink,
            'cmsOpenLink' => $post->guid
		);

		$this->log_error( $response_data );
		$response = new WP_REST_Response( $response_data, 201 );

		return $response;
	}

    /**
     * Perform update Active Category for post on WP from DN
     *
     * @param array $json_request
     * @param integer $post_id
     * @param boolean $detectImport The detect Category 'Do Not Import'
     *
     * @return string
     */
	public function activePostCategory ( $json_request, $detectImport = false, $post_id = null ) {
        if ( ! empty( $json_request['publication']['category']['id'] ) &&
            isset( $json_request['publication']['category']['name'] ) ) {
            $categoryID = $json_request['publication']['category']['id'];

            if ( $detectImport ) {
                return $this->checkDoNotImport( get_option( 'wp_desk_net_category_desk_net_to_wp_' . $categoryID ) );
            }

            $args = array(
                'taxonomy'   => 'category',
                'hide_empty' => false,
            );
            $categoryList = get_terms( $args );

            $response_data = array(
                'Desk-Net to WP Direction' => get_option( 'wp_desk_net_category_desk_net_to_wp_' . $categoryID )
            );
            $this->log_error( $response_data );
            $mappingCategory = false;
            foreach ( $categoryList as $key => $value ) {
                if ( get_option( 'wp_desk_net_category_desk_net_to_wp_' . $categoryID ) == $categoryList[ $key ]->term_id ) {
                    wp_set_post_categories( $post_id, $categoryList[ $key ]->term_id, false );
                    $mappingCategory = true;
                }
            }
            if ( ! $mappingCategory ) wp_set_post_categories( $post_id );
        } elseif ( ! isset( $json_request['publication']['category']['name'] ) && ! empty( get_option('wp_desk_net_category_desk_net_to_wp_no_category') ) ) {
            $noCategory  = get_option('wp_desk_net_category_desk_net_to_wp_no_category');
            if ( $detectImport ) {
                return $this->checkDoNotImport( $noCategory );
            }

            if ( $noCategory != 'no_category' ) {
                wp_set_post_categories( $post_id, $noCategory, false );
            } elseif( $noCategory == 'no_category' ) {
                wp_set_post_terms( $post_id, array(), 'category', false );
            }
        }
    }

    /**
     * Perform scan value on 'do_not_import' or 'Do not import'
     *
     * @param string $value
     *
     * @return boolean
     */
    public function checkDoNotImport ( $value ) {
        $response_data = array(
            'Category Matching value' => $value,
        );
        $this->log_error( $response_data );
        if ( $value == 'do_not_import' || $value == 'Do not import' ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Perform validate user from Desk-Net by email
     *
     * @param string $email The email Desk-Net user
     *
     * @return boolean|integer $response
     */
	public function valid_user( $email ) {

		include_once( ABSPATH . 'wp-includes/pluggable.php' );

		$user = get_user_by( 'email', $email );

		if ( $user ) {
			return $user->ID;
		}

		return false;
	}

    /**
     * Perform validate date on empty value from Desk-Net
     *
     * @return boolean|string $response
     */
	public function validate_date( $date_arr ) {

		if ( empty( $date_arr['date'] ) ) {
			return false;
		}

		$format = 'Y-m-d H:i';

        if ( isset( $date_arr['time'] ) && ! empty($date_arr['time'] ) ) {
            $date = $date_arr['date'] . ' ' .  $date_arr['time'];
        } else {
            $defaultDate = strtotime( $date_arr['date'] . ' ' . '23:59' );
            $defaultDate = date( $format, $defaultDate );
		    $date = strtotime( $defaultDate ) - get_option( 'gmt_offset' ) * 60 * 60; // The default Time create post if time on Desk-Net is empty
		    $date = date( $format, $date );
        }

        $response_data = array(
            'Send Time Create Post DN to WP' => $date,
        );
        $this->log_error( $response_data );

		$check_date = DateTime::createFromFormat( $format, $date );

		if ( array_sum( $check_date->getLastErrors() ) ) {
			return false;
		}

		return $check_date->format( 'Y-m-d H:i:s' );
	}

    /**
     * Perform authorization by API
     *
     * @return object $response
     */
	public function auth() {
		if ( ! empty( $_POST['api_key'] ) &&
		     ! empty( $_POST['api_secret'] ) &&
		     $_POST['api_key'] == get_option( 'wp_desk_net_api_key' ) &&
		     $_POST['api_secret'] == get_option( 'wp_desk_net_api_secret' )
		) {
			$response_data = array(
				'access_token' => $this->generate_token(),
				'token_type'   => 'bearer',
				'expires_in'   => 943199
			);
			$this->log_error( $response_data );
			$response = new WP_REST_Response( $response_data, 200 );
			$response->header( 'Access-Control-Allow-Origin', apply_filters( 'wp_desk_net_access_control_allow_origin', '*' ) );

		} else {
			$response_data = array(
				'message' => __( 'Wrong credentials', 'wp_desk_net' )
			);
			$this->log_error( $response_data );
			$response = new WP_REST_Response( $response_data, 401 );
		}

		return $response;
	}

    /**
     * Perform join style to page "WordPress Credentials"
     *
     */
	public function desc_net_css_js() {
		wp_enqueue_style( 'style-name', plugin_dir_url( __FILE__ ) . 'css/desk_net.css' );
        wp_enqueue_script('jquery');
        wp_enqueue_script('js-name', plugin_dir_url( __FILE__ ) . 'js/desk_net.js', array ( 'jquery' ) );
	}

    /**
     * Perform update activate and deactivate status list. Update active statuses by triggersExport
     *
     * @param array $statusList The default status list
     *
     * @return array $statusList The refreshed status list
     */
	public function checkTriggersExportStatus( $statusList ) {
        if ( empty ( $statusList['deactivatedStatuses'] ) ) $statusList['deactivatedStatuses'] =  array();
        foreach ( $statusList['activeStatuses'] as $key => $value ) {
            if ( $statusList['activeStatuses'][$key]['triggersExport'] == false ) {
                array_unshift( $statusList['deactivatedStatuses'], $statusList['activeStatuses'][$key] );
                unset( $statusList['activeStatuses'][$key] );
            }
        }
        return $statusList;
    }

    /**
     * Perform generate post link
     *
     * @param string $url The post url
     * @param object $post The post
     * @param boolean $leavename The post name
     *
     * @return string
     */
	public function desk_net_post_link( $url, $post, $leavename = false ) {

		$desk_net_id = get_query_var( 'desk_net_id' );

		if ( $post->post_type == 'post' && empty( $desk_net_id ) ) {

			$story_id = get_post_meta( $post->ID, 'story_id' );

			if ( ! empty( $story_id[0] ) ) {

				$base_url = get_site_url();

				$url = $base_url . '/' . $story_id[0] . '/' . $post->post_name . '/';
			}
		}

		return $url;
	}

    /**
     * Perform generate WP token
     *
     * @return array $statusList
     */
	public function generate_token() {

		$characters       = '0123456789abcdefghijklmnopqrstuvwxyz';
		$charactersLength = strlen( $characters );
		$lengths          = array( 8, 4, 4, 4, 12 );

		$token = '';

		foreach ( $lengths as $key => $length ) {
			for ( $i = 0; $i < $length; $i ++ ) {
				$token .= $characters[ rand( 0, $charactersLength - 1 ) ];
			}

			if ( $key != 4 ) {
				$token .= '-';
			}
		}

		update_option( 'wp_desk_net_auth_token', $token );

		return $token;
	}

    /**
     * Perform validate token
     *
     * @return boolean
     */
	public function check_token() {
		$token       = 'Bearer ' . get_option( 'wp_desk_net_auth_token' );
		$all_headers = getallheaders();

		$all_headers = array_change_key_case( $all_headers, CASE_LOWER );

		if ( ! array_key_exists( 'authorization', $all_headers ) ) {
            $response_data = array(
                'message' => __( 'Empty authorization', 'wp_desk_net' )
            );
            $this->log_error( $response_data );
			return false;
		}

		$token_for_check = $all_headers['authorization'];

		if ( ! empty( $token ) && ! empty( $token_for_check ) && $token == $token_for_check ) {
            $response_data = array(
                'message' => __( 'Valid token', 'wp_desk_net' )
            );
            $this->log_error( $response_data );
			return true;
		} else {
			return false;
		}
	}

    /**
     * Perform print plugin log
     *
     * @param array $msg_arr The list message
     *
     * @return boolean
     */
	public function log_error( $msg_arr ) {

		if ( WP_DEBUG ) {
			foreach ( $msg_arr as $key => $message ) {
				error_log( date( '[Y-m-d H:i]' ) . '[Desk-Net][' . $key . '] ' . $message );
			}
		}
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

new WPDeskNet;