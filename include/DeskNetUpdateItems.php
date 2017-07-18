<?php

/**
 * @package DeskNetUpdateItems
 * @author  Desk-Net GmbH
 */

include_once 'DeskNetRequestMethod.php';

class DeskNetUpdateItems extends DeskNetRequestMethod {
    /**
     * @var string Not Found Category in Desk-Net
     */
    protected $notFoundCategoryInDeskNet = false;

    /**
     * Perform Update post
     *
     * @param integer $post_ID
     * @param object $post
     *
     * @return boolean
     */
    public function deskNetUpdatePost( $post_ID, $post ) {
        $story_id = get_post_meta( $post_ID, 'story_id', true );
        //Check update story on Desk-Net
        if ( $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE'
            || get_post_meta( $post_ID, 'wp_desk_net_remove_status', true ) == 'removed'
        ) {
            $response_data = array(
                'Update POST Status' => __( 'Do not Update POST on Desk-Net', 'wp_desk_net' ),
                'Update Remove status' => get_post_meta( $post_ID, 'wp_desk_net_remove_status', true )
            );
            $this->log_error( $response_data );
            //Hide admin Notice
            $this->errorMessage = 0;
            return false;
        } elseif ( empty( $story_id ) ) {
            $this->createPostOnDeskNet( $post_ID, $post );
            return false;
        };

        if ( empty( $story_id ) ) {
            $response_data = array(
                'message' => __( 'Empty story post id', 'wp_desk_net' )
            );
            $this->log_error( $response_data );
            $this->errorMessage = 2;

            return false;
        }
        //Get JSON with default value
        $storyData = $this->get( self::DN_BASE_URL, 'elements', $story_id );
        $storyData = json_decode( $storyData, true );

        if ( empty( $storyData ) || ! empty( $storyData['message'] ) ) {
            $response_data = array(
                'message' => __( 'Empty data json about story', 'wp_desk_net' )
            );
            $this->log_error( $response_data );
            $this->errorMessage = 3;

            return false;
        }

        $storyData = $this->updateJSONData($storyData, $post_ID);

        //Update with error
        if ( ! $storyData ) {
            return false;
        }

        //debugger
        $response_data = array(
            'Post ID'           => $post_ID,
            'JSON encode'       => json_encode( $storyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );
        $this->log_error( $response_data );

        $this->updateData = json_encode( $storyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK );
        //Encode Ampersand
        $this->updateData = str_replace( '&amp;', '&', $this->updateData );

        $this->put( self::DN_BASE_URL, $storyData['id'] );
    }

    /**
     * Perform Create Post on Desk-Net
     *
     * @param integer $post_ID
     * @param object $post
     *
     * @return boolean
     */
    public function createPostOnDeskNet( $post_ID, $post ){
        $post_data['title'] = $post->post_title;
        $publicationPosition = 0;
        $publicationsID = get_post_meta( $post_ID, 'publications_id', true );
        if ( ! empty ( $publicationsID ) ) {
            foreach ( $post_data['publications'] as $key => $value ) {
                if ( $post_data['publications'][$key]['id'] == $publicationsID ) {
                    $publicationPosition = $key;
                    break;
                }
            }
        }
        $post_data['publications'][$publicationPosition]['assignments'] = [true];
        $post_data['publications'][$publicationPosition]['url_to_published_content'] = $post->guid;
        $post_data['publications'][$publicationPosition]['url_to_content_in_cms'] = get_edit_post_link( $post_ID );

        $date = strtotime($post->post_date_gmt);
        $defaultTime = strtotime($post->post_date);
        $response_data = array(
            'Post status Created Post' => $post->post_status
        );
        $this->log_error( $response_data );
        if ( ( get_post_meta( $post_ID, 'wp_desk_net_change_post_date', true ) == true && $post->post_status != 'private' ) ||
            ( $post->post_status == 'publish' || $post->post_status == 'future' ) ) {
            if ( ! empty ( $defaultTime ) && date( 'H:i', $defaultTime ) == '23:59' &&
                ( $post->post_status == 'publish' || $post->post_status == 'future' ) ) {
                $modifiedDate = strtotime( $post->post_modified_gmt );
                $post_data['publications'][$publicationPosition]['single']['start']['time'] = date( 'H:i', $modifiedDate );
            } elseif ( ! empty ( $defaultTime ) && date( 'H:i', $defaultTime ) != '23:59' ) {
                $post_data['publications'][$publicationPosition]['single']['start']['time'] = date( 'H:i', $date );
            }
            $post_data['publications'][$publicationPosition]['single']['start']['date'] = date( 'Y-m-d', $date );
            update_post_meta( $post_ID, 'wp_desk_net_change_post_date', false );
        }

        //Select active Category ID
        $selectCategoryID = get_the_terms( $post_ID, 'category' );
        if ( count($selectCategoryID) > 1 ) {
            $selectCategoryID[0] = array_pop($selectCategoryID);
        }
        if ( ! empty( $selectCategoryID ) ) {
            $response_data = array(
                'Select Category on WP' => json_encode( $selectCategoryID, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            );
            $this->log_error( $response_data );
            $getSelectCategoryIDtoDeskNet = get_option( "wp_desk_net_category_wp_to_desk_net_" . $selectCategoryID[0]->term_id );

            if ( ! empty( $getSelectCategoryIDtoDeskNet ) && $getSelectCategoryIDtoDeskNet != 'Do not import'
                && $getSelectCategoryIDtoDeskNet != 'No category') {
                $post_data['publications'][$publicationPosition]['category'] = (int) $getSelectCategoryIDtoDeskNet;
            } elseif ( $getSelectCategoryIDtoDeskNet == 'Do not import' ) {
                $this->errorMessage = null;
                return false;
            } else {
                $response_data = array(
                    'Select Category ID for Desk-Net' => __( 'Not Found', 'wp_desk_net' )
                );
                $this->log_error( $response_data );

                $post_data['publications'][$publicationPosition]['platform'] = get_option( 'wp_desk_net_platform_id' );
            }
        } else {
            $post_data['publications'][$publicationPosition]['platform'] = get_option( 'wp_desk_net_platform_id' );
        }

        //Get Author info
        $authorID = get_user_meta(get_post_field('post_author', $post_ID), 'desk_net_author_id', true);
        if (!empty($authorID)) {
            $post_data['tasks'][0]['user'] = intval($authorID);
        } else {
            $user = get_userdata($post->post_author);
            $post_data['tasks'][0]['user']['name'] = $user->data->display_name;
            $post_data['tasks'][0]['user']['email'] = $user->data->user_email;
        }

        //Default value
        $post_data['tasks'][0]['format'] = 18;
        $post_data['tasks'][0]['confirmationStatus'] = -2;
        $post_data['publications'][$publicationPosition]['cms_id'] = $post_ID;

        // Update Status
        $statusID = $this->statusMatching(get_post_status($post_ID));
        $response_data = array(
            'Create Element WP to Desk-Net Status' => $statusID
        );
        $this->log_error($response_data);

        if ( !empty($statusID) && $statusID != 'No Status') {
            $post_data['publications'][$publicationPosition]['status'] = $statusID;
            $post_data['tasks'][0]['status'] = 1;
        } elseif ($statusID == 'No Status') {
            $post_data['tasks'][0]['status'] = 1;
            $post_data['publications'][$publicationPosition]['status'] = 1;
        }


        //Create element on Desk-Net
        $additionPostInfoFromDeskNet = $this->customRequest( $post_data, self::DN_BASE_URL, 'elements', 'POST' );
        $response_data = array(
            'AFTER CREATE WP TO DESK-NET RESPONSE'  => $additionPostInfoFromDeskNet,
        );
        $this->log_error( $response_data );
        $additionPostInfoFromDeskNet = json_decode( $additionPostInfoFromDeskNet, true);

        if ( ! empty( $additionPostInfoFromDeskNet['message'] )){
            $this->errorMessage = 6;
        } else {
            update_post_meta($post_ID, 'desk_net_description', html_entity_decode( mb_convert_encoding( $additionPostInfoFromDeskNet['title'], 'UTF-8' )));
            update_post_meta($post_ID, 'story_id', $additionPostInfoFromDeskNet['id']);
            update_post_meta( $post_ID, 'publications_id', $additionPostInfoFromDeskNet['publications'][0]['id'] );
        }
        add_filter( 'redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
    }

    /**
     * Perform Update Data before send to Desk-Net
     *
     * @param array $storyData
     * @param integer $post_ID
     *
     * @return array $storyData
     */
    public function updateJSONData( $storyData, $post_ID ) {
        $post = get_post( $post_ID );
        $platformID = get_option( 'wp_desk_net_platform_id' );
        $publicationPosition = 0;
        $publicationsID = get_post_meta( $post_ID, 'publications_id' );

        if ( ! empty ( $publicationsID ) && ! isset( $publicationsID->errors ) ) {
            foreach ( (array)$storyData['publications'] as $key => $value ) {
                if ( $storyData['publications'][$key]['id'] == $publicationsID ) {
                    $publicationPosition = $key;
                    break;
                }
            }
        }
        // Update elements
        $storyData['publications'][$publicationPosition]['url_to_published_content'] = get_permalink( $post_ID );
        $storyData['publications'][$publicationPosition]['url_to_content_in_cms'] = get_edit_post_link( $post_ID );
        $date = strtotime($post->post_date_gmt);
        $defaultTime = strtotime($post->post_date);
        //Check Time 23:59 and not send time to Desk-Net
        if ( ! empty ($defaultTime) &&
            ! empty ($date) &&
            date('H:i', $defaultTime) != '23:59' &&
            ( ( get_post_meta($post_ID, 'wp_desk_net_change_post_date', true) == true && $post->post_status != 'private' ) ||
                ( $post->post_status == 'publish' || $post->post_status == 'future' ))
        ) {
            $storyData['publications'][$publicationPosition]['single']['start']['date'] = date('Y-m-d', $date);
            $storyData['publications'][$publicationPosition]['single']['start']['time'] = date('H:i', $date);
            update_post_meta($post_ID, 'wp_desk_net_change_post_date', false);
        }

        if ( ! empty( $post_data['tasks'] ) ) {
            // Update Author
            $authorID = get_user_meta(get_post_field('post_author', $post_ID), 'desk_net_author_id', true);
            if (!empty($authorID)) {
                $storyData['tasks'][0]['user'] = intval($authorID);
            } else {
                unset ($storyData['tasks'][0]['user']);
                $user = get_userdata($post->post_author);

                $storyData['tasks'][0]['user']['name'] = $user->data->display_name;
                $storyData['tasks'][0]['user']['email'] = $user->data->user_email;
                //array_push( $this->warningMessage, 2 );
            }
        }

        // Update Status
        $statusID = $this->statusMatching( get_post_status( $post_ID ) );
        if ( ! empty( $statusID ) && $statusID != 'No Status') {
            $storyData['publications'][$publicationPosition]['status'] = $statusID;
            if ( ! empty( $post_data['tasks'] ) ) $storyData['tasks'][0]['status'] = 1;
        } elseif ($statusID == 'No Status') {
            if ( ! empty( $post_data['tasks'] ) ) $storyData['tasks'][0]['status'] = 1;
            $storyData['publications'][$publicationPosition]['status'] = 1;
        }

        $selectCategoryID = get_the_terms( $post_ID, 'category' );
        if ( count($selectCategoryID) > 1 ) {
            $selectCategoryID[0] = array_pop($selectCategoryID);
        }
        if ( ! empty( $selectCategoryID ) ) {
            $response_data = array(
                'Select Category on WP' => json_encode( $selectCategoryID, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            );
            $this->log_error( $response_data );
            $getSelectCategoryIDtoDeskNet = get_option( "wp_desk_net_category_wp_to_desk_net_" . $selectCategoryID[0]->term_id );
            if ( ! empty( $getSelectCategoryIDtoDeskNet ) && $getSelectCategoryIDtoDeskNet != 'Do not import'
                && $getSelectCategoryIDtoDeskNet != 'No category' ) {
                unset ( $storyData['publications'][$publicationPosition]['platform'] );
                $storyData['publications'][$publicationPosition]['category'] = (int) $getSelectCategoryIDtoDeskNet;
            } elseif ( $getSelectCategoryIDtoDeskNet == 'Do not import' ) {
                return false;
            } else {
                $response_data = array(
                    'Select Category ID for Desk-Net' => __( 'Not Found', 'wp_desk_net' )
                );
                $this->log_error( $response_data );
                unset ( $storyData['publications'][$publicationPosition]['category'] );
                $storyData['publications'][$publicationPosition]['platform'] = $platformID;
            }
        } else {
            unset ( $storyData['publications'][$publicationPosition]['category'] );
            $storyData['publications'][$publicationPosition]['platform'] = $platformID;
        }

        if ( empty( $storyData['id'] ) ) {
            $response_data = array(
                'ID post on Desk-Net' => __( 'Not found', 'wp_desk_net' )
            );
            $this->log_error( $response_data );
            $this->errorMessage = 3;

            return false;
        }

        return $storyData;
    }

    /**
     * Perform status Matching WP to Desk-Net
     *
     * @param string $wpStatus
     *
     * @return string
     */
    public function statusMatching ( $wpStatus ) {
        if ( $wpStatus == 'future' ) $wpStatus = 'publish';
        $deskNetStatus = get_option( "wp_desk_net_status_wp_to_desk_net_" . $wpStatus );

        if ( ! empty( $deskNetStatus ) && $deskNetStatus != 'No Status' ) {
            return $deskNetStatus;
        } else {
            return 'No Status';
        }
    }
}