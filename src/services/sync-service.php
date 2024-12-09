<?php
/**
 * Orchestrates the syncing of WooCommerce products to Xero.
 */

namespace ACLWcXeroSync\Services;

class ACLSyncService {
    /**
     * Syncs WooCommerce products to Xero.
     */
    public static function sync_products() {
        $products = ACLWCService::get_products();
        foreach ( $products as $product ) {
            $item_data = self::map_product_to_xero_item( $product );
            ACLXeroService::create_or_update_item( $item_data );
        }
    }

    /**
     * Maps WooCommerce product data to Xero's item format.
     *
     * @param array $product The WooCommerce product data.
     * @return array The mapped Xero item data.
     */
    private static function map_product_to_xero_item( $product ) {
        return [
            'Code'        => $product['sku'] ?: $product['id'], // Use SKU or ID.
            'Name'        => $product['name'],
            'Description' => wp_strip_all_tags( $product['description'] ?? '' ),
            'PurchaseDetails' => [
                'UnitPrice' => floatval( $product['regular_price'] ?? 0 ),
            ],
            'SalesDetails' => [
                'UnitPrice' => floatval( $product['price'] ?? 0 ),
            ],
        ];
    }
}
