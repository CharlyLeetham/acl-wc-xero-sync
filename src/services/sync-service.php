<?php
namespace ACLWcXeroSync\Services;

class ACLSyncService {
    /**
     * Fetches WooCommerce products and logs their details.
     */
    public static function sync_products() {
        error_log('Starting WooCommerce product fetch...');

        try {
            // Step 1: Fetch WooCommerce Products
            $products = ACLWCService::get_products();

            if (empty($products)) {
                error_log('No products fetched from WooCommerce.');
                echo "<div class='notice notice-warning'><p>No products found in WooCommerce.</p></div>";
                return;
            }

            // Step 2: Log and Display Products
            foreach ($products as $product) {
                $sku = isset($product['sku']) ? $product['sku'] : 'No SKU';
                $name = isset($product['name']) ? $product['name'] : 'Unnamed Product';
                error_log("Product Fetched: SKU={$sku}, Name={$name}");
                echo "<div class='notice notice-info'><p>Product Fetched: <strong>SKU:</strong> {$sku}, <strong>Name:</strong> {$name}</p></div>";
            }

            error_log('WooCommerce product fetch completed successfully.');
        } catch (\Exception $e) {
            // Handle and log any exceptions
            error_log('Error fetching WooCommerce products: ' . $e->getMessage());
            echo "<div class='notice notice-error'><p>Error fetching WooCommerce products: {$e->getMessage()}</p></div>";
        }
    }
}
