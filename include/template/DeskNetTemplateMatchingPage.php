<?php

/**
 * Perform generation Template for Matching page
 *
 * array[key] array The key has ID or Slug value
 *  array[key][name] string The element Name
 *  array[key][parent] string The element Parent
 *
 * @param array $elementNameList
 * @param array $elementValueList
 * @param string $type The type element
 * @param string $sendDirection The direction for send elements
 * @param string $defaultValue The default value
 *
 * @return string
 */
function DeskNet_templateMatchingPage ( $elementNameList, $elementValueList, $type, $sendDirection, $defaultValue = '' )
{
    $html = '<table class="clear-padding matching-page ' . $type . '"><tbody>';

    if ( isset( $elementNameList ) && isset( $elementValueList ) ) {
        foreach ( $elementNameList as $key => $wpElement ) {
            if ( ! empty( get_option( "wp_desk_net_" . $type . $sendDirection . $key ) ) ) {
                $value = get_option( "wp_desk_net_" . $type . $sendDirection . $key );
            } else {
                $value = $defaultValue;
            }
            $html .= '<tr><td class="min-w142" ><strong>';
            if ( isset( $elementNameList[$key]['parent'] ) ) {
                $parentID = $elementNameList[$key]['parent'];
                foreach ( $elementNameList as $elementKey => $wpElement ) {
                    if ( $elementKey == $parentID ) {
                        $html .= $elementNameList[$elementKey]['name'] . '</strong> - <strong>';
                        break;
                    }
                }
            }

            $html .= $elementNameList[$key]['name'];
            $html .= '</strong></td><td class="text-arrow">&rarr;</td><td class="select-width ' . $type . '"><select name="wp_desk_net_' . $type . '_list[wp_desk_net_' . $type . $sendDirection . $key . ']">';
            foreach ($elementValueList as $keys => $element) {
                $elementValue = $keys;

                if ( isset($value) && $elementValue == $value ) {
                    $active = 'selected';
                } else {
                    $active = '';
                }
                if ( isset( $elementValueList[$keys]['parent'] ) ) {
                    $parentID = $elementValueList[$keys]['parent'];
                    foreach ( $elementValueList as $elementKey => $wpElement ) {
                        if ( $elementKey == $parentID ) {
                            $parentName = $elementValueList[$elementKey]['name'];
                            break;
                        }
                    }
                }
                if ( isset( $parentName ) && ! empty( $parentName ) ) {
                    $elementValue = $parentName . ' - ' . $elementValueList[$keys]['name'];
                    $parentName = '';
                } else {
                    $elementValue = $elementValueList[$keys]['name'];
                }
                $html .= '<option value="' . $keys . '"' . $active . '>' . $elementValue . '</option>';
            }
            $html .= '</select></td></tr>';
            $value = null;
        }
    } else {
        $html .= __("Sorry, we can't load $type", 'wp_desk_net');
    }
    $html .= '</tbody></table>';

    return $html;
}