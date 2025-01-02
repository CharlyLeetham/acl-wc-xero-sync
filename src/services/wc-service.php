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
        $query = new \WP_Query([
            'post_type'   => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'fields'         => 'id',
        ]);

        if ($category_id) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                ),
            );
        } 
        
        $product_ids = get_posts($args);
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
