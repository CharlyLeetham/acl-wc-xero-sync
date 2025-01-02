<?php
namespace ACLWcXeroSync\Services;

class ACLWCService {
    /**
     * Fetches all published products from WooCommerce.
     *
     * @return array List of WooCommerce products.
     */
    public static function get_products( $offset = 0, $batch_size = 50, $category_id = null ) {
        // Query WooCommerce products directly using WP_Query
        $query = [
            'post_type'   => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'fields'         => 'ids',
        ];

        if ($category_id) {
            $query['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                ),
            );
        } 
        
        $product_ids = get_posts( $query );
        return $product_ids;
        $products = [];
            
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $products[] = [
                    'id'          => $product_id,
                    'sku'         => $product->get_sku(),
                    'name'        => $product->get_name(),
                    'price'       => $product->get_price(),
                    'description' => $product->get_description(),
                ];
            }
        }
        return $products;
    }
}
