<?php

/**
 * @package DeskNetScanMediaFiles
 * @author  Desk-Net GmbH
 */

class DeskNetScanMediaFiles {

    function __construct() {}

    /**
     * Perform Get List FileID by name
     *
     * @param string $fileName The file name
     * @param string $fileType The file type
     * @param string $file The file for compare
     *
     * @return integer|boolean The result ID Files or false if file Not Found
     */
    public function scanMediaFiles ( $fileName, $fileType, $file ) {
        global $wpdb;

        $fileName = strval( $fileName );

        $querystr = "
          SELECT $wpdb->posts.ID 
            FROM $wpdb->posts
            WHERE $wpdb->posts.post_title = '$fileName'
            AND $wpdb->posts.post_type = 'attachment'
            AND $wpdb->posts.post_mime_type = '$fileType'
            ORDER BY $wpdb->posts.post_date DESC
          ";

        $fileList = $wpdb->get_results( $querystr, ARRAY_A );

        $response_data = array(
            'File List for Update by Name' => json_encode($fileList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );
        $this->log_error($response_data);

        if ( ! empty( $fileList ) ) {
            $fileID = $this->compareFileHash( $fileList, $file );
            return $fileID;
        }
        return false;
    }

    /**
     * Perform Get List FileID by name
     *
     * @param array $fileList The List with Files ID
     * @param string $file The file for compare
     *
     * @return integer|boolean The result ID Files or false if file Not Found
     */
    public function compareFileHash ( $fileList, $file ) {
        foreach ( $fileList as $key => $value ) {
            $response_data = array(
                'File ID Value' => $value["ID"],
                'File Get file' => json_encode(get_attached_file( $value["ID"] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            );
            $this->log_error($response_data);
            if ( get_attached_file( $value["ID"] ) !== false && md5_file( $file ) === md5_file( get_attached_file( $value["ID"] ) ) ) {
                return $value["ID"];
            }
        }

        return false;
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