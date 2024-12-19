<?php
namespace ACLWcXeroSync\Helpers;
use ACLWcXeroSync\Services\ACLSyncService;
use ACLWcXeroSync\Admin\ACLProductSyncPage;
use ACLWcXeroSync\Helpers\ACLXeroLoggers;

class ACLXeroHelper {

    /**
     * Initializes the Xero client using Consumer Key and Secret.
     *
     * @param string $client_id The Consumer Key.
     * @param string $client_secret The Consumer Secret.
     * @return \XeroPHP\Application
     */    

    public static function initialize_xero_client() {
        ACLXeroLogger::log_message('Initializing Xero client.', 'xero_auth');
    
        try {
            // Retrieve stored credentials
            $accessToken = get_option('xero_access_token');
            $refreshToken = get_option('xero_refresh_token');
            $tenantId = get_option('xero_tenant_id');
            $tokenExpires = get_option('xero_token_expires', 0);
    
            // Validate credentials
            if (!$accessToken || !$refreshToken || !$tenantId) {
                throw new \Exception("Missing Xero credentials. Please reauthorize.");
            }
    
            // Refresh token if expired
            if (time() > $tokenExpires) {
                ACLXeroLogger::log_message('Access token expired. Attempting to refresh...', 'xero_auth');
                //$accessToken = self::refresh_access_token($refreshToken);
                $clientId = get_option('acl_xero_consumer_key');
                $clientSecret = get_option('acl_xero_consumer_secret');
    
                if (!$clientId || !$clientSecret) {
                    throw new \Exception("Xero Client ID or Secret is missing. Please configure your settings.");
                }
    
                $provider = new \Calcinai\OAuth2\Client\Provider\Xero([
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                ]);
    
                try {
                    $newAccessToken = $provider->getAccessToken('refresh_token', [
                        'refresh_token' => $refreshToken,
                    ]);
    
                    // Update stored credentials
                    $accessToken = $newAccessToken->getToken();
                    update_option('xero_access_token', $accessToken);
                    update_option('xero_refresh_token', $newAccessToken->getRefreshToken());
                    update_option('xero_token_expires', time() + $newAccessToken->getExpires());
    
                    ACLXeroLogger::log_message('Tokens refreshed successfully.', 'xero_auth');
                } catch (\Exception $e) {
                    ACLXeroLogger::log_message('Token refresh failed: ' . $e->getMessage(), 'xero_auth');
                    throw new \Exception("Failed to refresh the Xero access token. Please reauthorize.");
                }                
            }
    
            // Initialize the Xero client
            $xero = new \XeroPHP\Application($accessToken, $tenantId);
    
            // Test client connection
            try {
                $xero->load('Accounting\\Organisation')->execute();
            } catch (\XeroPHP\Remote\Exception $e) {
                // Handle 401 Unauthorized error gracefully
                if (strpos($e->getMessage(), '401 Unauthorized') !== false) {
                    ACLXeroLogger::log_message("Unauthorized access detected: " . $e->getMessage(), 'xero_auth');
                    throw new \Exception("Access token is invalid. Please reauthorize the Xero connection.");
                }
    
                // Re-throw other exceptions
                throw new \Exception("Failed to verify Xero connection: " . $e->getMessage());
            }
    
            ACLXeroLogger::log_message("Xero client initialized successfully with Tenant ID: $tenantId", 'xero_auth');
            return $xero;
    
        } catch (\Exception $e) {
            ACLXeroLogger::log_message("Error initializing Xero client: " . $e->getMessage(), 'xero_auth');
            return new \WP_Error('initialization_error', 'Error initializing Xero client: ' . $e->getMessage());                      
        }
    }
    
    public static function csv_file($filename, $message) {

        /* Check to see if folder for csv's exist. If not create it */

        $upload_dir = WP_CONTENT_DIR . '/uploads/';
        $folder_name = 'acl-wc-xero-sync';
        $folder_path = $upload_dir . $folder_name;
        
        if (!is_dir($folder_path)) {
            if (mkdir($folder_path, 0755, true)) {
                ACLXeroLogger::log_message("Create directory $folder_path", 'product_sync');
            } else {
                // Handle the error, e.g., log it
                ACLXeroLogger::log_message("Failed to create directory $folder_path", 'product_sync');
            }
        } 

        $csv_file = $folder_path .'/'. $filename;

        // File locking
        $fp = fopen($csv_file, 'a'); // Open file in append mode
        if ($fp === false) {
            ACLXeroLogger::log_message("Failed to open $csv_file for appending", 'product_sync');
            return;
        }

        if (flock($fp, LOCK_EX)) { // Attempt to acquire an exclusive lock
            if (!file_exists($csv_file)) {
                // Write the header
                fwrite($fp, "SKU,Xero Price,WC Price\n");
                ACLXeroLogger::log_message("Created $csv_file", 'product_sync');
            }
            // Write the message
            fwrite($fp, $message . "\n");
            ACLXeroLogger::log_message("Wrote line.", 'product_sync');            
            flock($fp, LOCK_UN); // Release the lock
        } else {
            ACLXeroLogger::log_message("Failed to acquire lock for $csv_file", 'product_sync');
        }
        fclose($fp);
    } 


    

    public static function display_csv() {   
        // Display list of CSV files in specified directory
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        if (is_dir($folder_path)) {
            $files = glob($folder_path . '/*.csv');
        
            // Sort files in reverse chronological order
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            echo "<h3>CSV Files:</h3>";
            if ($files === false || empty($files)) {
                // No files found or glob failed
                echo "<p>There are no sync files to display.</p>";
            } else {                
                echo "<ul>";
                echo "<li><input type='checkbox' id='select-all' name='select-all' value='all'> <label for='select-all'>Select All</label></li>";            
                foreach ($files as $file) {
                    $filename = basename($file);
                    echo "<li><input type='checkbox' name='delete_files[]' value='" . esc_attr($filename) . "'> {$filename} ";
                    echo "<a href='" . wp_nonce_url(admin_url('admin-ajax.php?action=acl_download_file&file=' . urlencode($filename)), 'download_file') . "' class='button acl-download-file'>Download</a>";
                    echo "<button class='button acl-delete-file' data-file='" . esc_attr($filename) . "'>Delete</button></li>";
                }
                echo "</ul>";
                echo "<button id='delete-selected' class='button'>Delete Selected</button>";
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Single file deletion
                    $('.acl-delete-file').on('click', function(e) {
                        e.preventDefault();
                        var filename = $(this).data('file');
                        if (confirm('Are you sure you want to delete ' + filename + '?')) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'acl_delete_csv',
                                    file: filename,
                                    _ajax_nonce: '<?php echo wp_create_nonce('delete_csv'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('File deleted successfully!');
                                        $(e.target).closest('li').remove();
                                    } else {
                                        alert('Error: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('An error occurred: ' + error);
                                }
                            });
                        }
                    });

                    // Multiple file deletion
                    $('#delete-selected').on('click', function(e) {
                        e.preventDefault();
                        var selectedFiles = $('input[name="delete_files[]"]:checked').map(function() {
                            return $(this).val();
                        }).get();
                        
                        if (selectedFiles.length === 0) {
                            alert('Please select at least one file to delete.');
                            return;
                        }

                        if (confirm('Are you sure you want to delete these ' + selectedFiles.length + ' files?')) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'acl_delete_csv_multiple',
                                    files: selectedFiles,
                                    _ajax_nonce: '<?php echo wp_create_nonce('delete_csv_multiple'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('Selected files deleted successfully!');
                                        // Remove all checked items
                                        $('input[name="delete_files[]"]:checked').closest('li').remove();
                                    } else {
                                        alert('Error: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('An error occurred: ' + error);
                                }
                            });
                        }
                    });

                    // Select All checkbox functionality
                    $('#select-all').on('click', function() {
                        $('input[name="delete_files[]"]').prop('checked', this.checked);
                    });

                    // If all checkboxes are checked or unchecked, check or uncheck the "Select All" checkbox
                    $('input[name="delete_files[]"]').on('change', function() {
                        if ($('input[name="delete_files[]"]').length === $('input[name="delete_files[]"]:checked').length) {
                            $('#select-all').prop('checked', true);
                        } else {
                            $('#select-all').prop('checked', false);
                        }
                    });                
                });
                </script>
                <?php 
            }              
        } else {
            echo "<div class='notice notice-warning'><p>The 'acl-wc-xero-sync' folder does not exist.</p></div>";
        }        
    }

    public static function update_csv_display() {
        ob_start();
        self::display_csv();
        $content = ob_get_clean();
        wp_send_json_success($content);
        wp_die();
    }    

    public static function handle_file_download() {

        if (!check_ajax_referer('download_csv', false, false)) {

            echo json_encode(array('success' => false, 'message' => '<div class="notice notice-error">Nonce verification failed. Please try again or refresh the page.</div>'));
            wp_die();
        }
        
        $file = $_GET['file'];
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        $file_path = $folder_path . '/' . $file;
    
        if (file_exists($file_path)) {
            $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $content_type = ($file_extension === 'csv') ? 'text/csv' : 'text/plain'; // Set content type based on file extension
    
            header('Content-Type: ' . $content_type);
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($file_path));
            readfile($file_path);
        } else {
            wp_die('File not found.');
        }
        exit;
    } 
    
    public static function handle_delete_csv() {
        check_ajax_referer('delete_csv');

        $file = sanitize_file_name($_POST['file']);
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        $file_path = $folder_path . '/' . $file;

        if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'csv') {
            if (unlink($file_path)) {
                wp_send_json_success('File deleted successfully.');
            } else {
                wp_send_json_error('Failed to delete the file.');
            }
        } else {
            wp_send_json_error('File not found or not a CSV.');
        }
        wp_die();
    }
    
    public static function handle_delete_csv_multiple() {
        check_ajax_referer('delete_csv_multiple');

        $files = $_POST['files'] ?? [];
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        $deleted_files = [];
        $error_files = [];

        foreach ($files as $file) {
            $file = sanitize_file_name($file);
            $file_path = $folder_path . '/' . $file;
            
            if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'csv') {
                if (unlink($file_path)) {
                    $deleted_files[] = $file;
                } else {
                    $error_files[] = $file;
                }
            } else {
                $error_files[] = $file; // File doesn't exist or not a CSV
            }
        }

        if (empty($error_files)) {
            wp_send_json_success('Files deleted successfully: ' . implode(', ', $deleted_files));
        } else {
            $error_message = 'Some files could not be deleted: ' . implode(', ', $error_files);
            if (!empty($deleted_files)) {
                $error_message .= '. Successfully deleted: ' . implode(', ', $deleted_files);
            }
            wp_send_json_error($error_message);
        }

        wp_die();
    } 
    
    
    // Display the log files

    public static function display_logs() {   
        // Display list of CSV files in specified directory
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        if (is_dir($folder_path)) {
            $files = glob($folder_path . '/*.log');
        
            // Sort files in reverse chronological order
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            if ($files === false || empty($files)) {
                // No files found or glob failed
                echo "<p>There are no log files to display.</p>";
            } else {                
                echo "<ul>";
                echo "<li><input type='checkbox' id='select-all' name='select-all' value='all'> <label for='select-all'>Select All</label></li>";            
                foreach ($files as $file) {
                    $filename = basename($file);
                    echo "<li><input type='checkbox' name='delete_files[]' value='" . esc_attr($filename) . "'> {$filename} ";
                    echo "<button class='button acl-display-file' data-file='" . esc_attr($filename) . "'>Display</button>";                    
                    echo "<a href='" . wp_nonce_url(admin_url('admin-ajax.php?action=acl_download_file&file=' . urlencode($filename)), 'download_file') . "' class='button acl-download-file'>Download</a>";
                    echo "<button class='button acl-delete-file' data-file='" . esc_attr($filename) . "'>Delete</button></li>";
                }
                echo "</ul>";
                echo "<button id='delete-selected' class='button'>Delete Selected</button>";
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {


                    var ACLWcXeroSync = {
                        displayLog: function(filename) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'get_log_content',
                                    file: filename,
                                    _ajax_nonce: '<?php echo wp_create_nonce('get_log_content'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $('#log-content').text(response.data);
                                    } else {
                                        $('#log-content').text('Error loading log file: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    $('#log-content').text('An error occurred while fetching the log content: ' + error);
                                }
                            });
                        }
                    };

                    // Display the default log content when the page loads
                    var defaultLog = '<?php echo esc_js(basename($files[0] ?? '')); ?>';
                    if (defaultLog) {
                        console.log(defaultLog);
                        ACLWcXeroSync.displayLog(defaultLog);
                    }

                    // Single file deletion
                    $('.acl-delete-file').on('click', function(e) {
                        e.preventDefault();
                        var filename = $(this).data('file');
                        if (confirm('Are you sure you want to delete ' + filename + '?')) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'acl_delete_csv',
                                    file: filename,
                                    _ajax_nonce: '<?php echo wp_create_nonce('delete_csv'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('File deleted successfully!');
                                        $(e.target).closest('li').remove();
                                    } else {
                                        alert('Error: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('An error occurred: ' + error);
                                }
                            });
                        }
                    });

                    // Multiple file deletion
                    $('#delete-selected').on('click', function(e) {
                        e.preventDefault();
                        var selectedFiles = $('input[name="delete_files[]"]:checked').map(function() {
                            return $(this).val();
                        }).get();
                        
                        if (selectedFiles.length === 0) {
                            alert('Please select at least one file to delete.');
                            return;
                        }

                        if (confirm('Are you sure you want to delete these ' + selectedFiles.length + ' files?')) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'acl_delete_csv_multiple',
                                    files: selectedFiles,
                                    _ajax_nonce: '<?php echo wp_create_nonce('delete_csv_multiple'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('Selected files deleted successfully!');
                                        // Remove all checked items
                                        $('input[name="delete_files[]"]:checked').closest('li').remove();
                                    } else {
                                        alert('Error: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('An error occurred: ' + error);
                                }
                            });
                        }
                    });

                    // Select All checkbox functionality
                    $('#select-all').on('click', function() {
                        $('input[name="delete_files[]"]').prop('checked', this.checked);
                    });

                    // If all checkboxes are checked or unchecked, check or uncheck the "Select All" checkbox
                    $('input[name="delete_files[]"]').on('change', function() {
                        if ($('input[name="delete_files[]"]').length === $('input[name="delete_files[]"]:checked').length) {
                            $('#select-all').prop('checked', true);
                        } else {
                            $('#select-all').prop('checked', false);
                        }
                    });

                    // Display log file content when "Display" button is clicked
                    $('.acl-display-file').on('click', function(e) {
                        e.preventDefault();
                        var filename = $(this).data('file');
                        console.log(filename);
                        ACLWcXeroSync.displayLog(filename);
                    });
                });
                </script>
            <?php 
            }              
        } else {
            echo "<div class='notice notice-warning'><p>The 'acl-wc-xero-sync' folder does not exist.</p></div>";
        }        
    } 


    //Display the contents of the log file
    
    public static function get_log_content() {
        check_ajax_referer('get_log_content', '_ajax_nonce');

        $upload_dir = WP_CONTENT_DIR . '/uploads/';
        $folder_name = 'acl-wc-xero-sync';
        $filename = sanitize_file_name($_POST['file']);        
        $log_file = $upload_dir . $folder_name . '/' . $filename;
    
        if (file_exists($log_file)) {
            $content = file_get_contents($log_file);
            // We'll limit the log to the last 1000 lines to prevent memory issues with large logs
            $lines = explode("\n", $content);
            $limited_content = implode("\n", array_slice($lines, -1000));
            wp_send_json_success($limited_content);
        } else {
            return "Log file not found.";
        }
    }
        


    /**
     * Handles the AJAX request for testing the Xero connection.
     */
    public static function handle_test_connection() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
    
        //ob_start();
        $xero = ACLXeroHelper::initialize_xero_client();

        // Check for errors
        if (is_wp_error($xero)) {
            echo "<div class='notice notice-error'><p>".$xero->get_error_message()."</p></div>"; // Display the error message
            wp_die(); // Stop further execution
        }
        
        if (!empty($xero)) {
            echo "<div class='notice notice-info'><p>Xero client initialized successfully with Tenant ID: ".get_option('xero_tenant_id')."</p></div>"; // Echo the captured output
        } else {
            echo "No output from sync process.";
        }
        wp_die(); // This is required to end the AJAX call properly
    }    

    public static function handle_sync_ajax() {
        ACLXeroLogger::log_message("Entering sync ajax", 'product_sync');
       
        // Check if the user has permission to perform this action
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
    
        ob_start(); // Start output buffering
        ACLSyncService::sync_products();
        $output = ob_get_clean(); // Capture the output
        
        if (!empty($output)) {
            echo $output; // Echo the captured output
        } else {
            echo "<div class='notice notice-info'><p>No output from sync process.</p>";
        }
        wp_die(); // This is required to end the AJAX call properly
    }

}