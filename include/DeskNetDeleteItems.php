<?php

/**
 * @package DeskNetDeleteItems
 * @author  Desk-Net GmbH
 */

include_once 'WPDeskNetCore.php';

class DeskNetDeleteItems extends WPDeskNetCore {
    /**
     * Class constructor
     *
     * @throws Exception
     */
    function __construct() {
    }

    /**
     * Perform shape list of deleted items
     *
     * @param array $newItemsList
     * @param array $saveItemsList
     * @param array $wpItemsList
     *  array[key] string The key has ID or Slug element value
     * @param string $type
     */
    public function shapeDeletedItems ( $newItemsList, $saveItemsList, $wpItemsList, $type ) {
        $deleteElementList = array();

        if ( ! empty ( $saveItemsList ) ) {
            foreach ( $saveItemsList as $ID => $value ) {
                $inStock = false;
                foreach ( $newItemsList as $key => $content) {
                    if ($saveItemsList[ $ID ][ "id" ] == $newItemsList [ $key ][ "id" ]) {
                        $inStock = true;
                    }
                }
                if ( ! $inStock)  {
                    array_push( $deleteElementList, $saveItemsList[ $ID ]['id'] );
                }
            }
        }
        if ( ! empty( $deleteElementList ) ) {
            $deleteElementList = $this->checkSubItems( $deleteElementList );
            $this->deleteItems( $deleteElementList, $wpItemsList, $type );
        }
    }

    /**
     * Perform checking parent elements in the list of elements to be deleted
     *
     * @param array $deleteElementList
     *
     * @return array $deleteElementList
     */
    public function checkSubItems ( $deleteElementList ) {
        $saveCategoryListForPlatform = get_option( 'wp_desk_net_desk_net_category_list' );
        $underSubCategory = array();
        if ( ! empty ( $saveCategoryListForPlatform ) ) {
            foreach ( $saveCategoryListForPlatform as $key => $content ) {
                foreach ( $deleteElementList as $value ) {
                    if ( isset( $saveCategoryListForPlatform [ $key ][ "category" ] ) && $value == $saveCategoryListForPlatform [ $key ][ "category" ] ) {
                        array_push( $deleteElementList, $saveCategoryListForPlatform[ $key ]['id'] );
                        array_push( $underSubCategory, $saveCategoryListForPlatform[ $key ]['id'] );
                    }
                }
            }
        }
        if ( ! empty( $underSubCategory ) ) {
            $this->checkSubItems( $underSubCategory );
        } else {
            return $deleteElementList;
        }
    }

    /**
     * Perform deleted items and mapping category
     *
     * @param array $deleteElementList
     * @param array $wpItemsList
     * @param string $type
     *
     */
    public function deleteItems ( $deleteElementList, $wpItemsList, $type ) {
        if ( ! empty ( $wpItemsList ) && ! empty( $deleteElementList ) ) {
            foreach ( $deleteElementList as $content ) {
                foreach ( $wpItemsList as $value ) {
                    if ( $content == get_option( 'wp_desk_net_' . $type . '_wp_to_desk_net_' . $value ) ) {
                        update_option('wp_desk_net_' . $type . '_wp_to_desk_net_' . $value, 'No ' . $type );
                    }
                }
                delete_option( 'wp_desk_net_' . $type . '_desk_net_to_wp_' . $content );
            }
        }
    }
}