jQuery(document).ready(function($) {
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
                        //console.log("Updating #log-content with:", response.data);
                        $('#log-content').text(response.data);
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
                    console.log("Updating #log-content with:", response);                          
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
        e.preventDefault();
        var filename = $(this).data('file');
        ACLWcXeroSync.downloadFile(filename);
    });

    // Default log display
    if (typeof defaultLog !== 'undefined' && defaultLog) {
        ACLWcXeroSync.displayLog(defaultLog);
    }
});