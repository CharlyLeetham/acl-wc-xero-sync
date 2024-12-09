<?php
namespace ACLWcXeroSync\Services;

class ACLWCService {
    /**
     * Fetches all published products from WooCommerce.
     *
     * @return array List of WooCommerce products.
     */
    public static function get_products() {
        // Query WooCommerce products directly using WP_Query
        $query = new \WP_Query([
            'post_type'   => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1, // Fetch all products
        ]);

        $products = [];
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product( $product_id );

                if ( $product ) {
                    $products[] = [
                        'id'          => $product_id,
                        'sku'         => $product->get_sku(),
                        'name'        => $product->get_name(),
                        'price'       => $product->get_price(),
                        'description' => $product->get_description(),
                    ];
                }
            }
        }

        // Reset global post data
        wp_reset_postdata();

        return $products;
    }
}
