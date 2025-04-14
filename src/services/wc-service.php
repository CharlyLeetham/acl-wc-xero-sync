<?php
namespace ACLWcXeroSync\Services;
use ACLWcXeroSync\Helpers\ACLXeroHelper;
use ACLWcXeroSync\Helpers\ACLXeroLogger;

class ACLWCService {
    /**
     * Fetches all published products from WooCommerce.
     *
     * @return array List of WooCommerce products.
     */
    public static function get_products( $offset = 0, $batch_size = null, $category_id = null, $supplier = '', $no_featured_image = false, $include_variations = false ) {
        ACLXeroLogger::log_message( "Starting get_products with supplier: '$supplier', no_featured_image: " . ( $no_featured_image ? 'true' : 'false' ) . ", include_variations: " . ( $include_variations ? 'true' : 'false' ), 'product_images' );
    
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
    
        ACLXeroLogger::log_message( "Query args: " . json_encode( $query ), 'product_images' );
    
        $product_ids = get_posts( $query );
        ACLXeroLogger::log_message( "Found " . count( $product_ids ) . " product IDs", 'product_images' );
    
        $products = [];
    
        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                ACLXeroLogger::log_message( "Product ID $product_id not found", 'product_images' );
                continue;
            }
    
            ACLXeroLogger::log_message( "Processing product ID $product_id, type: " . $product->get_type( ), 'product_images' );
    
            // Skip if filtering for no featured image and product has one
            if ( $no_featured_image && ( has_post_thumbnail( $product->get_id( ) ) || $product->get_image_id( ) ) ) {
                ACLXeroLogger::log_message( "Product ID $product_id skipped due to having featured image", 'product_images' );
                continue;
            }
    
            // Handle variations
            if ( $product->is_type( 'variation' ) ) {
                if ( $include_variations ) {
                    $products[] = [
                        'sku'         => $product->get_sku( ),
                        'description' => $product->get_name( ),
                        'supplier'    => self::get_supplier_term( $product ),
                    ];
                    ACLXeroLogger::log_message( "Added variation ID $product_id, SKU: " . $product->get_sku( ), 'product_images' );
                } else {
                    ACLXeroLogger::log_message( "Skipped variation ID $product_id as include_variations is false", 'product_images' );
                }
                continue;
            }
    
            // Include simple or variable parent products
            $products[] = [
                'sku'         => $product->get_sku( ),
                'description' => $product->get_name( ),
                'supplier'    => self::get_supplier_term( $product ),
            ];
            ACLXeroLogger::log_message( "Added " . ( $product->is_type( 'variable' ) ? 'variable' : 'simple' ) . " product ID $product_id, SKU: " . $product->get_sku( ), 'product_images' );
        }
    
        ACLXeroLogger::log_message( "Returning " . count( $products ) . " products", 'product_images' );
    
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
        $supplier = ! empty( $terms ) ? implode( ', ', $terms ) : '';
        ACLXeroLogger::log_message( "Supplier for product ID " . $product->get_id( ) . ": '$supplier'", 'product_images' );
        return $supplier;
    }
}