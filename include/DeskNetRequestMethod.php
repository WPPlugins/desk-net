<?php
/**
 * @package DeskNetRequestMethod
 * @author  Desk-Net GmbH
 */

include_once 'WPDeskNetCore.php';
include_once 'DeskNetUpdateItems.php';

class DeskNetRequestMethod extends WPDeskNetCore {
	/**
	 * @var string Login
	 */
	protected $login;

	/**
	 * @var string Password
	 */
	protected $password;

	/**
	 * @var array Update Data
	 */
	protected $updateData;

	/**
	 * Class constructor
	 * @throws Exception
	 */
	function __construct() {
		$this->login    = get_option( 'wp_desk_net_user_login' );
		$this->password = get_option( 'wp_desk_net_user_password' );

		if ( ! function_exists( 'curl_init' ) ) {
			throw new Exception( 'CURL module not available! Pest requires CURL. See http://php.net/manual/en/book.curl.php' );
		}

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		if ( ! empty( $this->login ) && ! empty( $this->password )) {
			if ( ! empty( get_option( 'wp_desk_net_token' ) ) && get_option( 'wp_desk_net_token' ) != 'not_valid' ) {
				$this->token = get_option( 'wp_desk_net_token' );
			} else {
				$this->token = $this->getToken( $this->login, $this->password );
			}
			if ( $this->token != false && ! empty ( get_option( 'wp_desk_net_platform_id' ) )) {
				add_action('load-post.php', array( $this , 'loadPostPage' ), 7 );
				add_action('load-edit.php', array( $this , 'loadPostPage' ), 7 );
			} else {
				$this->errorMessage = 5;
			}
		} else {
			$this->errorMessage = 5;
			$this->token = false;
		}
		add_action( 'wp_print_scripts',  array( $this, 'disable_autosave' ));
		add_filter( 'redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
	}

	/**
	 * Perform detect load post page
	 */
	public function loadPostPage() {
		add_action('post_updated', array( $this , 'deskNetUpdateItem' ), 9, 3 );
	}

	/**
	 * Perform Disable AutoSave Draft
	 */
	public function disable_autosave() {
		wp_deregister_script('autosave');
	}

	/**
	 * Perform Update/Create Element on Desk-Net
	 *
	 * @param integer $post_ID
	 * @param object $post_after
	 * @param object $post_before
	 *
	 */
	public function deskNetUpdateItem( $post_ID, $post_after, $post_before = null ) {
		if ( $post_after->post_type == 'post' ) {
			if ('trash' === $post_after->post_status) {
				update_post_meta($post_ID, 'wp_desk_net_remove_status', 'removed');
				$publicationID = get_post_meta($post_ID, 'publications_id', true);
				$this->customRequest( null, self::DN_BASE_URL, 'elements/publication', 'DELETE', $publicationID);
				return;
			} elseif ( ! empty ( $post_before ) ) {
				if ( $post_after->post_content != $post_before->post_content ) {
					update_post_meta($post_ID, 'wp_desk_net_lock_update_post_content', true);
				}
				$post_after_time = strtotime( $post_after->post_date_gmt );
				$post_before_time = strtotime( $post_before->post_date_gmt );
				if ( ( $post_after_time != '0000-00-00 00:00:00' && $post_before_time == '0000-00-00 00:00:00' ) ||
					 ( $post_before_time != '0000-00-00 00:00:00' && $post_after_time != $post_before_time )) {
					update_post_meta( $post_ID, 'wp_desk_net_change_post_date', true );
				}
			}
			$editItem = new DeskNetUpdateItems();
			$editItem->deskNetUpdatePost( $post_ID, $post_after );
		} else {
			remove_filter( 'redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
		}
	}

	/**
	 * Perform update message in admin panel
	 * param messageID equal zero Hiding admin Notice
	 * @return string
	 */
	public function admin_notices() {
		if ( ! isset( $_GET['messageID'] ) || $_GET['messageID'] == 0 ) {
			return;
		}

		$messageList = array(
			1 => __( 'Story update successfully sent to Desk-Net', 'wp_desk_net' ),
			2 => __( 'Cannot update story in Desk-Net. The WordPress plugin could not find a corresponding story ID in Desk-Net. Code: 01', 'wp_desk_net' ),
			3 => __( 'Cannot update story in Desk-Net. There is no corresponding story in Desk-Net. Code: 02', 'wp_desk_net' ),
			4 => __( 'Cannot update the story in Desk-Net. Reason unknown. Please contact Desk-Net support at <a href="mailto:support@desk-net.com">support@desk-net.com</a>. Code: 03', 'wp_desk_net' ),
			5 => __( 'The Desk-Net API login credentials are not valid or have not been entered. Please check the settings on the page <a href="/wp-admin/admin.php?page=wp_desk_net_credential">Desk-Net Credentials</a> in the Desk-Net plugin. Code: 04', 'wp_desk_net' ),
			6 => __( 'Cannot create story in Desk-Net. Reason unknown. Code: 05', 'wp_desk_net' )
		);

		$warnings = array(
			1 => __( 'Not found this user in Desk-net', 'wp_desk_net' )
		);

		if ( isset( $_GET['warningID'] ) && ! empty( $_GET['warningID'] ) ) {
			$messageClass = 'notice-warning';
			$listMessage  = explode( '_', $_GET['warningID'] );
			foreach ( $listMessage as $key => $message ) {
				?>
				<div class="<?php echo $messageClass; ?> notice is-dismissible">
					<p><?php echo $warnings[ $listMessage[ $key ] ]; ?></p>
				</div>
				<?php
			}
		}

		if ( $_GET['messageID'] < 3 ) {
			$messageClass = 'updated';
		} else {
			$messageClass = 'notice-error';
		} ?>

		<div class="<?php echo $messageClass; ?> notice is-dismissible">
			<p><?php echo $messageList[ $_GET['messageID'] ]; ?></p>
		</div>

		<?php
	}

	/**
	 * Perform GET TOKEN
	 *
	 * @param string $login
	 * @param string $password
	 *
	 * @return string
	 */
	public function getToken( $login, $password ) {
		/**
		 * @var array request params
		 */
		$args = array(
			'sslverify' => false,
			'body' => [
				'grant_type'    => 'client_credentials',
				'client_id'     => $login,
				'client_secret' => $password,
			],
			'method' => 'POST',
		);

		//The HTTP POST request for get Token
		$obj_response = wp_remote_post( self::DN_BASE_URL . '/api/token', $args );
		$body_response = json_decode( $obj_response['body'], true );

		if ( $obj_response['response']['code'] != 200 ) {
			$response_data = array(
				'Request Get Token Status'  => $obj_response['response']['code']
			);
			$this->log_error( $response_data );
			update_option( 'wp_desk_net_token', 'not_valid' );
			return false;
		}

		//Update token option
		update_option( 'wp_desk_net_token', $body_response['access_token'] );

		return $body_response['access_token'];
	}

	/**
	 * Perform HTTP POST/DELETE request
	 *
	 * @param array $data The upload data
	 * @param string $url Base API url
	 * @param string $type The Desk-Net API method
	 * @param string $httpRequest Custom HTTP request
	 * @param string $recordId ID element
	 *
	 * @return string
	 */
	public function customRequest( $data, $url, $type, $httpRequest, $recordId = '') {
		$request_url = $url . "/api/v1_0_1/{$type}/{$recordId}";

		if ( $data !== null) {
			$data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			//Delete ampersand encode
			$data = str_replace('&amp;', '&', $data);

			$args = array(
				'headers' => [
					'Authorization' => "bearer $this->token",
					'Content-Length' => strlen($data),
					'Content-Type' => 'application/json;charset=UTF-8',
				],
				'body' => $data,
			);

			$response_data = array(
				'Action WP to DESK-NET data' => $data
			);
			$this->log_error( $response_data );
		} else {
			$args = array(
				'headers' => [
					'Authorization' => "bearer $this->token",
					'Content-Type' => 'application/json;charset=UTF-8',
				],
			);
		}

		$args['method'] = $httpRequest;

		$response = $this->sendRequest( $request_url, $args, $data );

		if ( ! ( $this->isJson( $response ) ) ) {
			$response_data = array(
				'message' => __( 'Get return invalid json', 'wp_desk_net' ),
			);
			$this->log_error( $response_data );
		}

		return $response;
	}

	/**
	 * Perform HTTP GET request
	 *
	 * @param string $url Base API url
	 * @param string $type The Desk-Net API method
	 * @param string $recordId ID element
	 *
	 * @return string
	 */
	public function get( $url, $type, $recordId = '' ) {
		$request_url = $url . "/api/v1_0_1/{$type}/{$recordId}";

		$args = array(
			'headers' => [
				'Authorization' => "bearer $this->token",
			],
			'method' => 'GET',
		);

		$response = $this->sendRequest( $request_url, $args );

		return $response;
	}

	/**
	 * Perform HTTP PUT request
	 *
	 * @param string $url Base API url
	 * @param integer $recordId ID element
	 *
	 * @return string
	 */
	public function put( $url, $recordId ) {
		$request_url = $url . "/api/v1_0_1/elements/{$recordId}";

		$args = array(
			'headers' => [
				'Authorization' => "bearer $this->token",
				'Content-Type' => 'application/json;charset=UTF-8',
			],
			'body' => $this->updateData,
			'method' => 'PUT',
		);

		$response_data = array(
			'ID RECORD' => $recordId
		);
		$this->log_error( $response_data );

		$response = $this->sendRequest( $request_url, $args );

		return $response;
	}

	/**
	 * Perform check JSON
	 *
	 * @param string $string
	 *
	 * @return boolean
	 */
	public function isJson( $string ) {
		json_decode( $string );

		return ( json_last_error() === 0 );
	}

	/**
	 * Perform send HTTP Request
	 *
	 * @param string $request_url The link for request
	 * @param array $args The params for request
	 * @param array $data The post data
	 * @param boolean $lastRequest The attempt send request
	 *
	 * @return string
	 */
	protected function sendRequest( $request_url, $args, $data = null, $lastRequest = null ) {
		$args['sslverify'] = false;

		$response = wp_remote_request( $request_url, $args );
		$httpCode = $response['response']['code'];

		if ( $httpCode == 401 ) {
			$this->token = $this->getToken( $this->login, $this->password );

			$response_data = array(
				'New token' => $this->token
			);
			$this->log_error( $response_data );
			$response = false;
			if ( $lastRequest != 'update_token' ) {
				$args['headers']['Authorization'] = "bearer $this->token";
				$response = $this->sendRequest( $request_url, $args, $data, 'update_token' );
			}
		} elseif ( $httpCode != 200 ) {
			$response_data = array(
				'Request Status'  => $httpCode,
				'Request message' => $response['body']
			);
			$this->log_error( $response_data );
			$data = json_decode( $data, true );
			$body_response = json_decode( $response['body'], true );
			$this->checkErrorMessage( $body_response, $data['publications'][0]['cms_id'] );
			$response = $response['body'];
		} else {
			$response = $response['body'];
		}

		return $response;
	}

	/**
	 * Perform check Error Message
	 *
	 * @param array $messageContent The list message
	 * @param string $postID The post ID in WordPress
	 *
	 * @return string
	 */
	public function checkErrorMessage ( $messageContent, $postID ) {
		if ( preg_match('/^Page with id \[\d.*\] was not found/' , $messageContent['message'], $categoryID ) ) {
			//Debugger
			$response_data = array(
				'Request error message' => 'Not valid Desk-Net category ID',
			);
			$this->log_error($response_data);
			$this->deskNetUpdateCategoryList();
			$post = get_post( $postID );
			$this->deskNetUpdateItem( $postID, $post );
		} elseif ( preg_match('/^User with id \[\d.*\] was not found/' , $messageContent['message'] )  ) {
			preg_match( '/\d.*\d/', $messageContent['message'], $deskNetUserID );
			$args = array(
				'meta_key'     => 'desk_net_author_id',
				'meta_value'   => $deskNetUserID[0],
			);
			$userData = get_users( $args );

			if ( empty( $userData[0]->data->ID ) ) {
				$this->errorMessage = 4;
				return;
			}

			delete_user_meta( $userData[0]->data->ID, 'desk_net_author_id', $deskNetUserID[0] );
			//Debugger
			$response_data = array(
				'Create second Request' => 'Not valid Desk-Net user ID',
				'Not valid user ID' => $deskNetUserID[0],
				"Clear field desk_net_author_id with value $deskNetUserID[0]" => 'Successful',
			);
			$this->log_error($response_data);
			$post = get_post( $postID );
			$this->deskNetUpdateItem( $postID, $post );
		} else {
			$this->errorMessage = 4;
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

new DeskNetRequestMethod();