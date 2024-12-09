<?php
namespace ACLWcXeroSync\Services;

class ACLSyncService {
    /**
     * Attempts to fetch WooCommerce products and log debug information.
     */
    public static function sync_products() {
        error_log('Starting product sync...');
        echo "<div class='notice notice-info'><p>Starting WooCommerce product sync...</p></div>";

        try {
            // Step 1: Fetch WooCommerce Products
            error_log('Attempting to fetch products...');
            $products = ACLWCService::get_products();

            if (empty($products)) {
                error_log('No products fetched from WooCommerce.');
                echo "<div class='notice notice-warning'><p>No products found in WooCommerce.</p></div>";
                return;
            }

            // Step 2: Log and Display Products
            error_log('Products fetched successfully.');
            echo "<div class='notice notice-success'><p>Products fetched successfully.</p></div>";

            foreach ($products as $product) {
                $sku = isset($product['sku']) ? $product['sku'] : 'No SKU';
                $name = isset($product['name']) ? $product['name'] : 'Unnamed Product';
                error_log("Fetched Product: SKU={$sku}, Name={$name}");
                echo "<div class='notice notice-info'><p>Fetched Product: <strong>SKU:</strong> {$sku}, <strong>Name:</strong> {$name}</p></div>";
            }
        } catch (\Exception $e) {
            // Handle and log any exceptions
            error_log('Error during product fetch: ' . $e->getMessage());
            echo "<div class='notice notice-error'><p>Error during product fetch: {$e->getMessage()}</p></div>";
        }

        error_log('Finished product sync.');
        echo "<div class='notice notice-info'><p>Finished WooCommerce product sync.</p></div>";
    }
}
