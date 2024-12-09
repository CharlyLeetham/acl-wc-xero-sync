<?php
/**
 * Handles interactions with the WooCommerce REST API.
 */

namespace ACLWcXeroSync\Services;

class ACLWCService {
    /**
     * Fetches all products from WooCommerce.
     *
     * @return array List of WooCommerce products.
     */
    public static function get_products() {
        $consumer_key = 'your_consumer_key';      // Replace with your key.
        $consumer_secret = 'your_consumer_secret'; // Replace with your secret.
        $store_url = 'https://yourstore.com/wp-json/wc/v3/products';

        $response = wp_remote_get( $store_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret ),
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            error_log( 'ACL WC API Error: ' . $response->get_error_message() );
            return [];
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
    }
}
