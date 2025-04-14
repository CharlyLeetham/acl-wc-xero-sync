<?php
namespace ACLWcXeroSync\Services;

class ACLWCService {
    /**
     * Fetches all published products from WooCommerce.
     *
     * @return array List of WooCommerce products.
     */
    public static function get_products( $offset = 0, $batch_size = null, $category_id = null, $supplier = '', $no_featured_image = false, $include_variations = false ) {
        // Query WooCommerce products and variations if needed
        $post_types = $include_variations ? array( 'product', 'product_variation' ) : array( 'product' );
        $query = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,            
            'fields'         => 'ids',
        ];

        // Only set posts_per_page if batch_size is specified
        if ( ! is_null( $batch_size ) && $batch_size > 0 ) {
            $query['posts_per_page'] = $batch_size;
            $query['offset'] = $offset;
        }
        
    
        // Add category filter if provided
        if ( $category_id ) {
            $query['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category_id,
            ];
        }

        // Add supplier filter if provided
        if ( ! empty( $supplier ) ) {
            $query['tax_query'][] = [
                'taxonomy' => 'pa_supplier',
                'field'    => 'slug',
                'terms'    => sanitize_title( $supplier ),
            ];
        }

        // Ensure tax_query relation is AND if multiple filters
        if ( ! empty( $query['tax_query'] ) && count( $query['tax_query'] ) > 1 ) {
            $query['tax_query']['relation'] = 'AND';
        }
    
        $product_ids = get_posts( $query );
        $products = [];
    
        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            // Skip if filtering for no featured image and product has one
            if ( $no_featured_image && ( has_post_thumbnail( $product->get_id( ) ) || $product->get_image_id( ) ) ) {
                continue;
            }

            // Handle variations based on $include_variations
            if ( $product->is_type( 'variation' ) ) {
                if ( $include_variations ) {
                    $products[] = [
                        'sku'         => $product->get_sku( ),
                        'description' => $product->get_name( ),
                        'supplier'    => self::get_supplier_term( $product ),
                    ];
                }
                continue;
            }

            // Include simple or variable parent products
            if ( ! $include_variations && $product->is_type( 'variable' ) ) {
                // Variable parent only for non-variations list
                $products[] = [
                    'sku'         => $product->get_sku( ),
                    'description' => $product->get_name( ),
                    'supplier'    => self::get_supplier_term( $product ),
                ];
            } elseif ( ! $product->is_type( 'variable' ) ) {
                // Simple products always included
                $products[] = [
                    'sku'         => $product->get_sku( ),
                    'description' => $product->get_name( ),
                    'supplier'    => self::get_supplier_term( $product ),
                ];
            }
        }

        return $products;  
    } 

    /**
     * Gets the supplier term for a product.
     *
     * @param WC_Product $product The WooCommerce product object.
     * @return string The supplier term name(s) or empty string if none.
     */
    private static function get_supplier_term( $product ) {
        $terms = wc_get_product_terms( $product->get_id( ), 'pa_supplier', array( 'fields' => 'names' ) );
        return ! empty( $terms ) ? implode( ', ', $terms ) : '';
    }
}