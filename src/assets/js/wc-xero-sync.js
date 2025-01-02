jQuery(document).ready(function($) {
    var defaultLog = window.defaultLog || null;

    var ACLWcXeroSync = {
        displayLog: function(filename) {
            console.log("Filename 1: " + filename);
            $.ajax({
                url: aclWcXeroSyncAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'acl_get_log_content',
                    file: filename,
                    _ajax_nonce: aclWcXeroSyncAjax.nonce_get_log_content
                },
                success: function(response) {
                    if (response.success) {
                        console.log("Updating #log-content with:", response.data);
                        $('#log-content').text(response.data);
                        // Update the filename display
                        $('#current-filename').text(filename);                        
                    } else {
                        //console.error("Error from server:", response.data || response.message);                        
                        $('#log-content').text('Error loading log file: ' + (response.data || response.message));
                    }
                },
                error: function(xhr, status, error) {
                    $('#log-content').text('An error occurred while fetching the log content: ' + error);
                }
            });
        },

        downloadFile: function(filename) {
            $.ajax({
                url: aclWcXeroSyncAjax.ajax_url,
                type: 'GET',
                data: {
                    action: 'acl_download_file',
                    file: filename,
                    _ajax_nonce: aclWcXeroSyncAjax.nonce_download_file
                },
                xhrFields: {
                    responseType: 'text'
                },
                success: function(response, status, xhr) {   
                    console.log("Download Response:", response);                          
                    try {
                        if (response.success === false || response.success === undefined) {
                            console.log("Response part deux: " + (response.data.message || response.message));
                            $('#error-container').html('<div class="notice notice-error"><p>' + (response.data.message || response.message) + '</p></div>').show();
                            setTimeout(function() {
                                $('#error-container').hide();
                            }, 5000);
                        }
                    } catch (e) {
                        // If JSON parsing fails, we assume it's a file download
                        console.log("File download initiated");

                        // Here, you would implement the actual download logic:
                        var blob = new Blob([response], {type: xhr.getResponseHeader('Content-Type') || 'application/octet-stream'});
                        var link = document.createElement('a');
                        link.href = window.URL.createObjectURL(blob);
                        link.download = filename || 'download';
                        link.click();

                        // Clean up to release memory
                        window.URL.revokeObjectURL(link.href);                        
                    }
                },
                error: function(xhr, status, error) {
                    $('#error-container').html('<div class="notice notice-error"><p>An error occurred while trying to download the file. Status: ' + status + ', Error: ' + error + '</p></div>').show();
                }
            });
        }
    };

    // Event handlers
    $('.acl-delete-file').on('click', function(e) {
        e.preventDefault();
        var filename = $(this).data('file');
        if (confirm('Are you sure you want to delete ' + filename + '?')) {
            $.ajax({
                url: aclWcXeroSyncAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'acl_delete_csv',
                    file: filename,
                    _ajax_nonce: aclWcXeroSyncAjax.nonce_delete_csv
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
                url: aclWcXeroSyncAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'acl_delete_csv_multiple',
                    files: selectedFiles,
                    _ajax_nonce: aclWcXeroSyncAjax.nonce_delete_csv_multiple
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

    // New code for testing connection with Xero
    $('#test-xero-connection').on('click', function () {
        console.log ('HERE');
        $('#xero-test-connection-result').html('<p>Testing Connection...</p>');
        $.ajax({
            url: aclWcXeroSyncAjax.ajax_url, // This won't work in JS, see below for correction
            type: 'POST',
            data: { action: 'acl_xero_test_connection_ajax' },
            success: function (response) {                              
                $('#xero-test-connection-result').html(response);
            },
            error: function(xhr, status, error) {                              
                var errorMessage = xhr.status + ' ' + xhr.statusText + ': ' + error;
                $('#xero-test-connection-result').html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
            },
        });
    });

    // Functionality to toggle between password and text type using an eye icon
    $('.password-toggle-icon').on('click', function () {
        console.log ('here');
        var target = $(this).data('target');
        var input = $('#' + target);
        var currentType = input.attr('type');
        
        if (currentType === 'password') {
            input.attr('type', 'text');
            $(this).addClass('show');
        } else {
            input.attr('type', 'password');
            $(this).removeClass('show');
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

    $('.acl-display-file').on('click', function(e) {
        console.log ('display file' );
        e.preventDefault();
        var filename = $(this).data('file');
        ACLWcXeroSync.displayLog(filename);
    });

    $('.acl-download-file').on('click', function(e) {
        console.log('Download button pressed');
        e.preventDefault();
        var filename = $(this).data('file');
        ACLWcXeroSync.downloadFile(filename);
    });

    // Default log display
    if (defaultLog) {
       ACLWcXeroSync.displayLog(defaultLog);
    }

    function pollForStatus() {
        $.ajax({
            url: aclWcXeroSyncAjax.ajax_url,
            type: 'POST',
            data: {
                'action': 'acl_xero_sync_status_ajax',
                '_ajax_nonce': aclWcXeroSyncAjax.nonce_xero_sync_status_ajax // Ensure this nonce is localized
            },
            success: function(response) {
                if (response.success) {
                    var status = response.data;
                    $('#sync-results').append(`<p>Progress: ${status.progress}/${status.total}</p>`);
                    if (status.progress < status.total) {
                        setTimeout(pollForStatus, 1000); // Poll every second
                    }
                }
            },
            error: function() {
                console.error('Failed to get sync status');
            }
        });
    }

    // Add this new function to rebind events to dynamically added elements
    function bindEventHandlers() {
        // Display button - using event delegation
        $(document).off('click', '.acl-display-file').on('click', '.acl-display-file', function(e) {
            e.preventDefault();
            var filename = $(this).data('file');
            ACLWcXeroSync.displayLog(filename);
        });

        // Download button - using event delegation
        $(document).off('click', '.acl-download-file').on('click', '.acl-download-file', function(e) {
            e.preventDefault();
            var filename = $(this).data('file');
            ACLWcXeroSync.downloadFile(filename);
        });

        // Delete button - using event delegation
        $(document).off('click', '.acl-delete-file').on('click', '.acl-delete-file', function(e) {
            e.preventDefault();
            var filename = $(this).data('file');
            if (confirm('Are you sure you want to delete ' + filename + '?')) {
                $.ajax({
                    url: aclWcXeroSyncAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'acl_delete_csv',
                        file: filename,
                        _ajax_nonce: aclWcXeroSyncAjax.nonce_delete_csv
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('File deleted successfully!');
                            $(e.target).closest('li').remove();
                            // Update the CSV list after deletion
                            $.ajax({
                                url: aclWcXeroSyncAjax.ajax_url,
                                type: 'POST',
                                data: {
                                    action: 'acl_update_csv_display',
                                    _ajax_nonce: aclWcXeroSyncAjax.nonce_update_csv_display
                                },
                                success: function(csvResponse) {
                                    if (csvResponse.success) {
                                        $('#csv-file-container').html(csvResponse.data.html);
                                        // Rebind events after updating the list
                                        bindEventHandlers();
                                    }
                                }
                            });
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

        // Multiple file deletion - using event delegation
        $(document).off('click', '#delete-selected').on('click', '#delete-selected', function(e) {
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
                    url: aclWcXeroSyncAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'acl_delete_csv_multiple',
                        files: selectedFiles,
                        _ajax_nonce: aclWcXeroSyncAjax.nonce_delete_csv_multiple
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Selected files deleted successfully!');
                            // Remove all checked items
                            $('input[name="delete_files[]"]:checked').closest('li').remove();
                            // Update the CSV list after deletion
                            $.ajax({
                                url: aclWcXeroSyncAjax.ajax_url,
                                type: 'POST',
                                data: {
                                    action: 'acl_update_csv_display',
                                    _ajax_nonce: aclWcXeroSyncAjax.nonce_update_csv_display
                                },
                                success: function(csvResponse) {
                                    if (csvResponse.success) {
                                        $('#csv-file-container').html(csvResponse.data.html);
                                        // Rebind events after updating the list
                                        bindEventHandlers();
                                    }
                                }
                            });
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

        // Select All checkbox functionality - using event delegation
        $(document).off('click', '#select-all').on('click', '#select-all', function() {
            $('input[name="delete_files[]"]').prop('checked', this.checked);
        });

        // If all checkboxes are checked or unchecked, check or uncheck the "Select All" checkbox - using event delegation
        $(document).off('change', 'input[name="delete_files[]"]').on('change', 'input[name="delete_files[]"]', function() {
            if ($('input[name="delete_files[]"]').length === $('input[name="delete_files[]"]:checked').length) {
                $('#select-all').prop('checked', true);
            } else {
                $('#select-all').prop('checked', false);
            }
        });
    }

    // Bind initial event handlers
    bindEventHandlers();    
    
    // New code for sync functionality with loading indicator
    $('#start-sync').on('click', function(e) {
        e.preventDefault();
        var dryRun = $('#dry-run').is(':checked');
        var category_id = $('#category-select').val();
        var cogs = $('#cogs').val();
        var salesacct = $('#salesacct').val();
        var cogstaxtype = $('#cogs-tax-type').val();
        var salestaxtype = $('#sales-tax-type').val();       
        var $syncResults = $('#sync-results');
        var $csvUpdates = $('#csv-file-container'); // Assuming you have this div for CSV updates
        console.log (category_id);
    
        // Clear previous sync results, keep CSV updates
        $syncResults.html('<div class="notice notice-info"><p>Sync process is starting...</p><div id="sync-indicator" class="loader"></div></div>');
    
        var xhr = new XMLHttpRequest();
        xhr.open('POST', aclWcXeroSyncAjax.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                $('#sync-indicator').remove();
                // Append sync messages
                $syncResults.append(xhr.responseText);
    
                // Update CSV display
                $.ajax({
                    url: aclWcXeroSyncAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'acl_update_csv_display',
                        _ajax_nonce: aclWcXeroSyncAjax.nonce_update_csv_display
                    },
                    success: function(csvResponse) {
                        if (csvResponse.success) {
                            $csvUpdates.html(csvResponse.data.html);
                            if (csvResponse.data.defaultLog) {
                                ACLWcXeroSync.displayLog(csvResponse.data.defaultLog);
                            }
                        } else {
                            $csvUpdates.html('<div class="notice notice-error"><p>Failed to update CSV list: ' + csvResponse.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $csvUpdates.html('<div class="notice notice-error"><p>Error updating CSV list.</p></div>');
                    }
                });
            }
        };
    
        xhr.send('action=acl_xero_sync_products_ajax&sync_xero_products=1&dry_run=' + (dryRun ? '1' : '0') + 
         '&category_id=' + category_id + 
         '&cogs=' + cogs + 
         '&salesacct=' + salesacct + 
         '&cogstaxtype=' + cogstaxtype + 
         '&salestaxtype=' + salestaxtype + 
         '&_ajax_nonce=' + aclWcXeroSyncAjax.nonce_xero_sync_products_ajax);
    });
});