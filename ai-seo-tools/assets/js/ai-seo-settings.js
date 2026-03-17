/**
 * AI SEO Tools Settings Page Script
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        console.log('AI SEO Tools Settings JS loaded');

        var $refreshButton = $('#ai-seo-refresh-models-button');
        var $spinner = $('#ai-seo-refresh-models-spinner');
        var $statusSpan = $('#ai-seo-refresh-models-status');
        var $modelSelect = $('select[name="ai_seo_tools_options[openai_model]"]');

        $refreshButton.on('click', function() {
            if ($refreshButton.is('.disabled')) {
                return;
            }

            // Show loading state
            $refreshButton.addClass('disabled').prop('disabled', true);
            $spinner.css({ visibility: 'visible', display: 'inline-block' }).addClass('is-active');
            $statusSpan.text(aiSeoSettings.refreshing_text).css('color', '');
            $modelSelect.prop('disabled', true); // Disable select during refresh

            // AJAX request to refresh models
            $.ajax({
                url: aiSeoSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_seo_refresh_models',
                    nonce: aiSeoSettings.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data && response.data.models) {
                        // Success: Update the dropdown
                        var currentSelected = $modelSelect.val();
                        $modelSelect.empty(); // Clear existing options

                        $.each(response.data.models, function(modelId, modelLabel) {
                            var $option = $('<option></option>')
                                .val(modelId)
                                .text(modelLabel);
                            if (modelId === currentSelected) {
                                $option.prop('selected', true);
                            }
                            $modelSelect.append($option);
                        });

                        $statusSpan.text(aiSeoSettings.refreshed_text).css('color', 'green');
                        setTimeout(function() { $statusSpan.text(''); }, 3000); // Clear status after 3s

                    } else {
                        // Error from server (wp_send_json_error)
                        var errorMessage = response.data && response.data.message ? response.data.message : aiSeoSettings.error_text;
                        $statusSpan.text(escapeHtml(errorMessage)).css('color', 'red');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // AJAX request failed
                    var errorMessage = aiSeoSettings.error_text;
                     // Try to get a message from response
                     if (jqXHR.responseText) {
                        try {
                            var errorResponse = JSON.parse(jqXHR.responseText);
                            if (errorResponse && errorResponse.data && errorResponse.data.message) {
                                errorMessage = escapeHtml(errorResponse.data.message);
                            }
                        } catch (e) { /* Ignore parsing error */ }
                    }
                    $statusSpan.text(errorMessage + ' (' + textStatus + ')').css('color', 'red');
                },
                complete: function() {
                    // Always hide spinner and re-enable button/select
                    $spinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
                    $refreshButton.removeClass('disabled').prop('disabled', false);
                    $modelSelect.prop('disabled', false);
                }
            });
        });

        // === Bulk Alt Text Generation Logic === //
        var $bulkStartButton = $('#ai-seo-bulk-generate-button');
        var $bulkSpinner = $('#ai-seo-bulk-spinner');
        var $bulkProgressDiv = $('#ai-seo-bulk-progress');
        var $bulkProgressBar = $('#ai-seo-bulk-progress-bar');
        var $bulkProgressStatus = $('#ai-seo-bulk-progress-status');
        var $bulkStopButton = $('#ai-seo-bulk-stop-button');
        var $limitInput = $('#ai-seo-bulk-limit');
        var $runningNotice = $('#ai-seo-bulk-running-notice');
        var $errorListDiv = $('#ai-seo-bulk-error-list');
        var bulkStatusInterval = null; // To store the interval timer
        var processedIdsHistory = []; // Keep track of IDs already displayed in the list
        var currentBulkLimit = null; // Store the user-selected limit for the current bulk process

        // Function to update progress UI
        function updateBulkProgressUI(progress) {
            if (!progress) {
                $bulkStartButton.show().removeClass('disabled').prop('disabled', false);
                $bulkStopButton.hide();
                $bulkSpinner.removeClass('is-active');
                $bulkProgressDiv.hide();
                $limitInput.prop('disabled', false);
                if (bulkStatusInterval) {
                    clearInterval(bulkStatusInterval);
                    bulkStatusInterval = null;
                }
                $runningNotice.hide();
                return;
            }

            var processed = progress.processed || 0;
            var total = progress.total || 0;
            var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
            var statusText = '';
            var errorCount = progress.errors ? Object.keys(progress.errors).length : 0;

            $bulkProgressBar.css('width', percent + '%').text(percent + '%');

            if (progress.status === 'running') {
                $bulkStartButton.hide();
                $bulkStopButton.show().removeClass('disabled').prop('disabled', false);
                $bulkSpinner.css({ visibility: 'visible', display: 'inline-block' }).addClass('is-active');
                $bulkProgressDiv.show();
                $limitInput.prop('disabled', true);

                // Show the background notice on this page (top notice)
                var noticeMsg = aiSeoSettings.bulk_processing
                                     .replace('{processed}', processed)
                                     .replace('{total}', total)
                                     + "";

                $runningNotice.find('p').text(noticeMsg);
                $runningNotice.show();

                // Construct the status text for the progress area
                if (errorCount > 0) {
                    statusText = aiSeoSettings.bulk_processing_with_errors
                        .replace('{processed}', processed)
                        .replace('{total}', total)
                        .replace('{errors}', errorCount);
                } else {
                    statusText = aiSeoSettings.bulk_processing
                        .replace('{processed}', processed)
                        .replace('{total}', total);
                }
                // Add the background running message to the status line as well
                statusText += "<br/><small>" + aiSeoSettings.refreshing_text.replace('Refreshing...', 'Generation is running in the background. You can leave this page.') + "</small>";
            } else if (progress.status === 'complete') {
                $bulkStartButton.show().removeClass('disabled').prop('disabled', false);
                $bulkStopButton.hide();
                $bulkSpinner.removeClass('is-active');
                $bulkProgressDiv.show();
                $limitInput.prop('disabled', false);
                statusText = aiSeoSettings.bulk_complete;
                if (errorCount > 0) {
                    statusText += ' ' + aiSeoSettings.bulk_processing_with_errors
                        .replace('{processed}', processed)
                        .replace('{total}', total)
                        .replace('{errors}', errorCount);
                    $bulkProgressStatus.css('color', 'orange');
                } else {
                    $bulkProgressStatus.css('color', 'green');
                }
                if (bulkStatusInterval) { clearInterval(bulkStatusInterval); bulkStatusInterval = null; }
                $runningNotice.hide();
                currentBulkLimit = null; // Reset the stored limit when process ends
            } else if (progress.status === 'stopped') {
                $bulkStartButton.show().removeClass('disabled').prop('disabled', false);
                $bulkStopButton.hide();
                $bulkSpinner.removeClass('is-active');
                $bulkProgressDiv.show();
                $limitInput.prop('disabled', false);
                statusText = aiSeoSettings.bulk_stopped;
                $bulkProgressStatus.css('color', 'orange');
                if (bulkStatusInterval) { clearInterval(bulkStatusInterval); bulkStatusInterval = null; }
                $runningNotice.hide();
                // Reset progress bar to 0%
                $bulkProgressBar.css('width', '0%').text('0%');
            } else if (progress.status === 'error' || progress.status === 'stalled') {
                $bulkStartButton.show().removeClass('disabled').prop('disabled', false);
                $bulkStopButton.hide();
                $bulkSpinner.removeClass('is-active');
                $bulkProgressDiv.show();
                $limitInput.prop('disabled', false);
                statusText = progress.last_error || 'An unknown error occurred.';
                $bulkProgressStatus.css('color', 'red');
                if (bulkStatusInterval) { clearInterval(bulkStatusInterval); bulkStatusInterval = null; }
                $runningNotice.hide();
            } else {
                $bulkStartButton.show().removeClass('disabled').prop('disabled', false);
                $bulkStopButton.hide();
                $bulkSpinner.removeClass('is-active');
                $bulkProgressDiv.hide();
                $limitInput.prop('disabled', false);
                $bulkProgressStatus.text('').css('color', '');
                if (bulkStatusInterval) {
                    clearInterval(bulkStatusInterval);
                    bulkStatusInterval = null;
                }
                $runningNotice.hide();
            }

            $bulkProgressStatus.html(statusText);

            // Display errors if any
            if (errorCount > 0) {
                var $errorListUl = $errorListDiv.find('ul').empty(); // Clear previous errors
                $.each(progress.errors, function(id, message) {
                    // Sanitize message before adding to HTML
                    var safeMessage = escapeHtml(message);
                    $errorListUl.append('<li>ID ' + parseInt(id) + ': ' + safeMessage + '</li>');
                });
                $errorListDiv.show();
            } else {
                $errorListDiv.hide();
            }
        }

        // Helper: Extract bulk limit from status text if not available from backend
        function extractBulkLimitFromStatusText() {
            var statusText = $('#ai-seo-bulk-progress-status').text();
            // Match 'Processed X of Y' or 'Processed X of Y.'
            var match = statusText.match(/Processed\s+\d+\s+of\s+(\d+)/i);
            if (match && match[1]) {
                return parseInt(match[1], 10);
            }
            return null;
        }

        // Function to update the main statistics block
        function updateStatsDisplay(stats, userLimitFromStatus) {
            var $statsDiv = $('#ai-seo-alt-stats');
            if (!$statsDiv.length) return;

            var total = stats.total || 0;
            var withAlt = stats.with_alt || 0;
            var withoutAlt = stats.without_alt || 0;

            var content = '<h3>' + $('#ai-seo-alt-stats h3').text() + '</h3>'; // Keep title

            if (total > 0) {
                // Calculate with higher precision
                var rawPercentWith = (withAlt / total) * 100;
                var rawPercentWithout = 100 - rawPercentWith;
                // Format to 1 decimal place
                var percentWithFormatted = rawPercentWith.toFixed(1);
                var percentWithoutFormatted = rawPercentWithout.toFixed(1);

                content += '<ul>';
                content += '<li>' + 'Total Images in Media Library: ' + total + '</li>'; // Simple text, replace with localized if available
                content += '<li>' + 'Images with Alt Text: ' + withAlt + ' (' + percentWithFormatted + '%)</li>';
                content += '<li>' + 'Images without Alt Text: ' + withoutAlt + ' (' + percentWithoutFormatted + '%)</li>';
                content += '</ul>';
            } else {
                content += '<p>' + 'No images found in the Media Library.' + '</p>'; // Replace with localized string
            }
            $statsDiv.html(content);

             // Also update the bulk generation section controls based on new stats
             var $bulkControls = $('#ai-seo-bulk-controls');
             var $limitInput = $('#ai-seo-bulk-limit');
             var $bulkSection = $('#ai-seo-bulk-generate-section');

             if(withoutAlt > 0) {
                $limitInput.attr('max', withoutAlt);
                // If process is running (input is disabled), keep showing the user-selected limit from backend if available, otherwise parse from status text
                if ($limitInput.prop('disabled')) {
                    var limitToShow = null;
                    if (typeof userLimitFromStatus !== 'undefined' && userLimitFromStatus !== null) {
                        limitToShow = userLimitFromStatus;
                    } else {
                        limitToShow = extractBulkLimitFromStatusText();
                    }
                    if (limitToShow !== null) {
                        $limitInput.val(limitToShow);
                    }
                } else if (parseInt($limitInput.val(), 10) > withoutAlt) {
                    $limitInput.val(withoutAlt);
                }
                // Ensure controls are visible if they were hidden
                $bulkSection.find('p').first().text('Found ' + withoutAlt + ' images without alt text.'); // Update count text
                $bulkControls.show();
                $limitInput.closest('div').show();
             } else {
                 // Hide controls if no images are left
                 $bulkControls.hide();
                 $limitInput.closest('div').hide();
                 $bulkSection.find('p').first().text('All images in your Media Library already have alt text. Well done!');
             }
        }

        // Function to fetch and display details for newly processed images
        function fetchAndDisplayImageDetails(ids) {
            if (!ids || ids.length === 0) {
                return;
            }

            $.ajax({
                url: aiSeoSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_seo_get_image_details',
                    nonce: aiSeoSettings.get_details_nonce,
                    ids: ids
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        var $listContainer = $('#ai-seo-bulk-recent-list');
                        // Prepend items so newest appear first
                        $.each(ids.reverse(), function(index, id) { // Reverse to prepend in correct order
                             if (response.data[id]) {
                                var details = response.data[id];
                                // Construct the admin edit URL
                                var editUrl = 'upload.php?item=' + id; // Assuming admin context, otherwise use admin_url()
                                var $link = $('<a href="' + editUrl + '" target="_blank" style="display: flex; align-items: center; text-decoration: none; color: inherit;"></a>');
                                var $thumb = $('<img src="' + escapeHtml(details.thumb_url) + '" style="width: 40px; height: 40px; object-fit: cover; margin-right: 10px;">');
                                var $text = $('<span></span>').text(id + ': ' + escapeHtml(details.alt));
                                $link.append($thumb).append($text);
                                // Wrap the link in a div for styling and data attribute
                                var $listItem = $('<div class="processed-item" data-id="' + id + '" style="margin-bottom: 5px; padding: 5px; border-bottom: 1px solid #eee;"></div>');
                                $listItem.append($link);
                                $listContainer.prepend($listItem);
                             }
                        });

                        // Limit the list size to e.g., 10 items
                        while ($listContainer.children().length > 10) {
                            $listContainer.children().last().remove();
                        }
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                     // console.error('AI SEO Tools Image Details AJAX Error:', textStatus, errorThrown);
                }
            });
        }

        // Function to poll bulk status
        function pollBulkStatus() {
            $.ajax({
                url: aiSeoSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_seo_get_bulk_alt_status',
                    nonce: aiSeoSettings.bulk_status_nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var progressData = response.data;
                        updateBulkProgressUI(progressData);

                        // Check for newly processed images to display
                        if (progressData.recent_success_ids && progressData.recent_success_ids.length > 0) {
                            var newIds = [];
                            $.each(progressData.recent_success_ids, function(index, id) {
                                if (processedIdsHistory.indexOf(id) === -1) {
                                    newIds.push(id);
                                    processedIdsHistory.push(id); // Add to history
                                }
                            });
                             // Keep history manageable (e.g., last 50 IDs)
                             if (processedIdsHistory.length > 50) {
                                processedIdsHistory = processedIdsHistory.slice(-50);
                             }

                            if (newIds.length > 0) {
                                fetchAndDisplayImageDetails(newIds);
                            }
                        }

                        // Highlight currently processing item (if any)
                        $('#ai-seo-bulk-recent-list .processed-item').css('background-color', ''); // Clear previous highlight
                        if (progressData.current_id) {
                           $('#ai-seo-bulk-recent-list .processed-item[data-id="' + progressData.current_id + '"]').css('background-color', '#e0f0ff');
                        }

                        // Always update stats while running
                        if (progressData.status === 'running') {
                            fetchAndUpdateStats(progressData.user_limit);
                        }
                        // If completed, fetch and update main stats
                        if (progressData.status === 'complete' || progressData.status === 'stopped' || progressData.status === 'error') {
                            fetchAndUpdateStats();
                             if (bulkStatusInterval) { clearInterval(bulkStatusInterval); bulkStatusInterval = null; }
                        } else if (progressData.status !== 'running') {
                             // Stop polling if not running, complete, stopped or error
                              if (bulkStatusInterval) { clearInterval(bulkStatusInterval); bulkStatusInterval = null; }
                        }

                    } else {
                        // Failed to get status
                        // console.error('AI SEO Tools: Failed to get bulk status', response.data ? response.data.message : 'Unknown error');
                        $bulkProgressStatus.text(aiSeoSettings.bulk_status_error).css('color', 'red');
                        clearInterval(bulkStatusInterval);
                        bulkStatusInterval = null;
                         $bulkStartButton.removeClass('disabled').prop('disabled', false);
                         $bulkSpinner.removeClass('is-active');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // console.error('AI SEO Tools Bulk Status AJAX Error:', textStatus, errorThrown);
                    $bulkProgressStatus.text(aiSeoSettings.bulk_status_error + ' (' + textStatus + ')').css('color', 'red');
                    clearInterval(bulkStatusInterval);
                    bulkStatusInterval = null;
                    $bulkStartButton.removeClass('disabled').prop('disabled', false);
                    $bulkSpinner.removeClass('is-active');
                }
            });
        }

        // Function to fetch and update stats display
        function fetchAndUpdateStats(userLimitFromStatus) {
             $.ajax({
                url: aiSeoSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_seo_get_stats',
                    nonce: aiSeoSettings.get_stats_nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        updateStatsDisplay(response.data, userLimitFromStatus);
                    } else {
                        // console.error('AI SEO Tools: Failed to fetch stats', response.data ? response.data.message : 'Unknown error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                     // console.error('AI SEO Tools Stats Fetch AJAX Error:', textStatus, errorThrown);
                     // Optionally display an error message somewhere
                }
            });
        }

        // Attach click handler to the bulk start button
        $bulkStartButton.on('click', function() {
            if ($bulkStartButton.is('.disabled')) {
                return;
            }

            // Reset previous processed IDs and clear the recent posts list on new run
            processedIdsHistory = [];
            $('#ai-seo-bulk-recent-list').empty();

            // Store the user-selected limit for the current process
            currentBulkLimit = parseInt($limitInput.val(), 10);

            // Initial UI update
            $bulkStartButton.addClass('disabled').prop('disabled', true);
            $bulkSpinner.css({ visibility: 'visible', display: 'inline-block' }).addClass('is-active');
            $bulkProgressDiv.show();
            $bulkProgressBar.css('width', '0%').text('0%');
            $bulkProgressStatus.text(aiSeoSettings.refreshing_text).css('color', ''); // Use refreshing text initially

            // Start the bulk process via AJAX
            $.ajax({
                url: aiSeoSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_seo_start_bulk_alt',
                    nonce: aiSeoSettings.bulk_start_nonce,
                    limit: $limitInput.val() // Send the limit
                },
                dataType: 'json',
                success: function(response) {
                    // console.log('Bulk Start Response:', response); // <-- DEBUG: Log response
                    if (response.success) {
                        // Process started, begin polling for status
                        $bulkProgressStatus.text('Starting...');
                        updateBulkProgressUI(response.data); // Update with initial status if provided
                        if (!bulkStatusInterval && response.data.status === 'running') {
                            bulkStatusInterval = setInterval(pollBulkStatus, 5000); // Poll every 5 seconds
                        }
                    } else {
                        // Failed to start
                        $bulkProgressStatus.text(response.data && response.data.message ? escapeHtml(response.data.message) : aiSeoSettings.bulk_start_error).css('color', 'red');
                        $bulkStartButton.removeClass('disabled').prop('disabled', false);
                        $bulkSpinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
                        $bulkProgressDiv.hide();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // AJAX request failed to start
                    var errorMessage = aiSeoSettings.bulk_start_error;
                     if (jqXHR.responseText) {
                        try {
                            var errorResponse = JSON.parse(jqXHR.responseText);
                            if (errorResponse && errorResponse.data && errorResponse.data.message) {
                                errorMessage = escapeHtml(errorResponse.data.message);
                            }
                        } catch (e) { /* Ignore */ }
                    }
                    $bulkProgressStatus.text(errorMessage + ' (' + textStatus + ')').css('color', 'red');
                    $bulkStartButton.removeClass('disabled').prop('disabled', false);
                    $bulkSpinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
                    $bulkProgressDiv.hide();
                }
            });
        });

        // Attach click handler to the bulk stop button
        $bulkStopButton.on('click', function() {

            if ($bulkStopButton.is('.disabled')) {
                return;
            }

            // Initial UI update
            $bulkStartButton.addClass('disabled').prop('disabled', true);
            $bulkSpinner.css({ visibility: 'visible', display: 'inline-block' }).addClass('is-active');
            $bulkProgressDiv.show();
            $bulkProgressBar.css('width', '0%').text('0%');
            $bulkProgressStatus.text(aiSeoSettings.refreshing_text).css('color', ''); // Use refreshing text initially

            // Start the bulk process via AJAX
            $.ajax({
                url: aiSeoSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_seo_stop_bulk_alt',
                    nonce: aiSeoSettings.bulk_stop_nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Process started, begin polling for status
                        $bulkProgressStatus.text('Stopping...');
                        updateBulkProgressUI(response.data); // Update with initial status if provided
                        if (!bulkStatusInterval && response.data.status === 'stopped') {
                            bulkStatusInterval = setInterval(pollBulkStatus, 5000); // Poll every 5 seconds
                        }
                    } else {
                        // Failed to start
                        console.error('AI SEO Tools Bulk Stop AJAX Error:', textStatus, errorThrown);
                         var errorMessage = aiSeoSettings.bulk_stop_error;
                         if (jqXHR.responseText) {
                            try {
                                var errorResponse = JSON.parse(jqXHR.responseText);
                                if (errorResponse && errorResponse.data && errorResponse.data.message) {
                                    errorMessage = escapeHtml(errorResponse.data.message);
                                }
                            } catch (e) { /* Ignore */ }
                        }
                        $bulkProgressStatus.text(errorMessage + ' (' + textStatus + ')').css('color', 'red');
                        $bulkStartButton.removeClass('disabled').prop('disabled', false);
                        $bulkSpinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
                        $bulkProgressDiv.hide();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // AJAX request failed to start
                    console.error('AI SEO Tools Bulk Stop AJAX Error:', textStatus, errorThrown);
                     var errorMessage = aiSeoSettings.bulk_stop_error;
                     if (jqXHR.responseText) {
                        try {
                            var errorResponse = JSON.parse(jqXHR.responseText);
                            if (errorResponse && errorResponse.data && errorResponse.data.message) {
                                errorMessage = escapeHtml(errorResponse.data.message);
                            }
                        } catch (e) { /* Ignore */ }
                    }
                    $bulkProgressStatus.text(errorMessage + ' (' + textStatus + ')').css('color', 'red');
                    $bulkStartButton.removeClass('disabled').prop('disabled', false);
                    $bulkSpinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
                    $bulkProgressDiv.hide();
                }
            });
        });

        // Initial status check on page load in case a job was already running
        pollBulkStatus();

        // Clear the recent list on page load if no job is running
        $.ajax({
            url: aiSeoSettings.ajax_url,
            type: 'POST',
            data: { action: 'ai_seo_get_bulk_alt_status', nonce: aiSeoSettings.bulk_status_nonce },
            dataType: 'json',
            success: function(r) {
                if (r.success && r.data.status === 'running') {
                    if (!bulkStatusInterval) {
                        bulkStatusInterval = setInterval(pollBulkStatus, 5000);
                    }
                } else {
                    $('#ai-seo-bulk-recent-list').empty();
                    $('#ai-seo-bulk-running-notice').hide();
                    if (!r.success || (r.data.status !== 'error' && r.data.status !== 'stalled')) {
                        $('#ai-seo-bulk-error-list').hide();
                    }
                }
            }
        });

        // === Bulk Append Tags Logic ===
        var processedAppendIds = [];
        var $appendStart = $('#ai-seo-bulk-append-tags-start-button');
        var $appendStop  = $('#ai-seo-bulk-append-tags-stop-button');
        var $appendSpinner = $('#ai-seo-bulk-append-tags-spinner');
        var $appendProgressWrap = $('#ai-seo-bulk-append-tags-progress');
        var $appendBar       = $('#ai-seo-bulk-append-tags-progress-bar');
        var $appendStatus    = $('#ai-seo-bulk-append-tags-progress-status');
        var $appendErrorList = $('#ai-seo-bulk-append-tags-error-list ul');
        var appendInterval   = null;

        function pollBulkAppend() {
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_get_bulk_append_tags_status',
                    nonce: aiSeoSettings.bulk_append_tags_status_nonce
                },
                function(response) {
                    if (!response.success) {
                        return;
                    }
                    var progress = response.data;
                    var processed = progress.processed || 0;
                    var total = progress.total || 0;
                    var percent = total > 0 ? Math.round((processed / total) * 100) : 0;

                    $appendBar.css('width', percent + '%').text(percent + '%');
                    $appendStatus.text(
                        aiSeoSettings.bulk_tags_processing
                            .replace('{processed}', processed)
                            .replace('{total}', total)
                    );

                    // Show newly processed posts and their appended tags
                    if (progress.recent_success_ids) {
                        progress.recent_success_ids.forEach(function(id) {
                            if (processedAppendIds.indexOf(id) === -1) {
                                processedAppendIds.push(id);
                                // Build edit link for post
                                var editUrl = aiSeoSettings.ajax_url.replace('admin-ajax.php', 'post.php?post=') + id + '&action=edit';
                                // Get appended tags for this post
                                var tags = (progress.recent_success_tags && progress.recent_success_tags[id]) ? progress.recent_success_tags[id] : '';
                                var label = 'Post ID ' + id;
                                if (tags) {
                                    label += ': ' + escapeHtml(tags);
                                }
                                $('#ai-seo-bulk-append-tags-recent-list').prepend(
                                    '<div style="margin-bottom:5px;">' +
                                    '<a href="' + editUrl + '" target="_blank">' + label + '</a>' +
                                    '</div>'
                                );
                            }
                        });
                        var $list = $('#ai-seo-bulk-append-tags-recent-list');
                        while ($list.children().length > 10) {
                            $list.children().last().remove();
                        }
                    }

                    if (progress.status === 'complete') {
                        $appendStatus
                            .html('✅ Completed! All selected posts processed.')
                            .css('color', 'green');
                        $appendBar.css('width', '100%').text('100%');
                        $appendStop.hide();
                        $appendStart.show().prop('disabled', false);
                        if (progress.errors && Object.keys(progress.errors).length > 0) {
                            $appendStatus.append('<br><span style="color:orange;">Some posts had errors. See error list below.</span>');
                        }
                    }
                },
                'json'
            );
        }

        $appendStart.on('click', function() {
            // reset list on new run
            processedAppendIds = [];
            $('#ai-seo-bulk-append-tags-recent-list').empty();
            $appendStart.prop('disabled', true);
            $appendSpinner.css({ display: '', visibility: '' }).addClass('is-active');

            // Disable other tag bulk operations while append runs
            $('#ai-seo-bulk-tags-start-button, #ai-seo-bulk-tags-stop-button, #ai-seo-bulk-regenerate-tags-start-button, #ai-seo-bulk-regenerate-tags-stop-button').prop('disabled', true);
            // Disable Clear All Tags while a bulk process is running
            $('#ai-seo-clear-all-tags-button').prop('disabled', true);
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_start_bulk_append_tags',
                    nonce: aiSeoSettings.bulk_append_tags_start_nonce,
                    limit: $('#ai-seo-bulk-append-tags-limit').val()
                },
                function(response) {
                    $appendSpinner.removeClass('is-active').css({ display: '', visibility: '' });
                    if (response.success) {
                        $appendStart.hide();
                        $appendStop.show().prop('disabled', false);
                        $appendProgressWrap.show();
                        if (!appendInterval) {
                            appendInterval = setInterval(pollBulkAppend, 2000);
                        }
                    } else {
                        alert(response.data.message || aiSeoSettings.error_text);
                        $appendStart.prop('disabled', false);
                    }
                },
                'json'
            );
        });

        $appendStop.on('click', function() {
            $appendStop.prop('disabled', true);
            var $appendSpinner = $('#ai-seo-bulk-append-tags-spinner');
            $appendSpinner.css({ display: '', visibility: '' }).addClass('is-active');

            // Re-enable all bulk-tag operation buttons immediately after stop is clicked
            $('#ai-seo-bulk-tags-start-button, #ai-seo-bulk-tags-stop-button, #ai-seo-bulk-append-tags-start-button, #ai-seo-bulk-append-tags-stop-button, #ai-seo-bulk-regenerate-tags-start-button, #ai-seo-bulk-regenerate-tags-stop-button').prop('disabled', false);
            // Re-enable Clear All Tags when bulk processes stop
            $('#ai-seo-clear-all-tags-button').prop('disabled', false);
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_stop_bulk_append_tags',
                    nonce: aiSeoSettings.bulk_append_tags_stop_nonce
                },
                function(response) {
                    $appendSpinner.removeClass('is-active').css({ display: '', visibility: '' });
                    if (response.success) {
                        clearInterval(appendInterval);
                        appendInterval = null;
                        $appendStop.hide();
                        $appendStart.show().prop('disabled', false);
                    } else {
                        alert(response.data.message || aiSeoSettings.bulk_tags_stop_error);
                        $appendStop.prop('disabled', false);
                    }
                },
                'json'
            );
        });

        // === Bulk Regenerate Tags Logic ===
        var processedRegenIds = [];
        var $regenStart = $('#ai-seo-bulk-regenerate-tags-start-button');
        var $regenStop  = $('#ai-seo-bulk-regenerate-tags-stop-button');
        var $regenSpinner = $('#ai-seo-bulk-regenerate-tags-spinner');
        var $regenProgressWrap = $('#ai-seo-bulk-regenerate-tags-progress');
        var $regenBar       = $('#ai-seo-bulk-regenerate-tags-progress-bar');
        var $regenStatus    = $('#ai-seo-bulk-regenerate-tags-progress-status');
        var $regenLimit     = $('#ai-seo-bulk-regenerate-tags-limit');
        var $regenList      = $('#ai-seo-bulk-regenerate-tags-recent-list');
        var $regenErrorList = $('#ai-seo-bulk-regenerate-tags-error-list ul');
        var regenInterval   = null;

        function pollBulkRegenerate() {
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_get_bulk_regenerate_tags_status',
                    nonce: aiSeoSettings.bulk_regenerate_tags_status_nonce
                },
                function(response) {
                    if (!response.success) {
                        return;
                    }
                    var progress = response.data;
                    var processed = progress.processed || 0;
                    var total = progress.total || 0;
                    var percent = total > 0 ? Math.round((processed/total)*100) : 0;

                    $regenBar.css('width', percent+'%').text(percent+'%');
                    $regenStatus.text(
                        aiSeoSettings.bulk_tags_processing
                            .replace('{processed}', processed)
                            .replace('{total}', total)
                    );

                    // Show spinner while regenerate is running
                    if (progress.status === 'running') {
                        $regenSpinner.show().addClass('is-active');
                    }

                    // Show newly processed posts and regenerated tags
                    if (progress.recent_success_ids) {
                        progress.recent_success_ids.forEach(function(id) {
                            if (processedRegenIds.indexOf(id) === -1) {
                                processedRegenIds.push(id);
                                var editUrl = aiSeoSettings.ajax_url.replace('admin-ajax.php','post.php?post=') + id + '&action=edit';
                                // Append tags string if provided by server
                                var tags = progress.recent_success_tags && progress.recent_success_tags[id] ? progress.recent_success_tags[id] : '';
                                var label = 'Post ID ' + id;
                                if (tags) {
                                    label += ': ' + escapeHtml(tags);
                                }
                                $regenList.prepend('<div style="margin-bottom:5px;"><a href="'+editUrl+'" target="_blank">'+label+'</a></div>');
                            }
                        });
                        while ($regenList.children().length > 10) {
                            $regenList.children().last().remove();
                        }
                    }

                    if (progress.status === 'complete') {
                        $regenStatus
                            .html('✅ Completed! All selected posts processed.')
                            .css('color', 'green');
                        $regenBar.css('width', '100%').text('100%');
                        $regenStop.hide();
                        $regenStart.show().prop('disabled', false);
                        if (progress.errors && Object.keys(progress.errors).length > 0) {
                            $regenStatus.append('<br><span style="color:orange;">Some posts had errors. See error list below.</span>');
                        }
                        // Hide spinner when bulk regenerate completes
                        $regenSpinner.hide().removeClass('is-active');
                    }
                },
                'json'
            );
        }

        $regenStart.on('click', function() {
            // Reset list on new run
            processedRegenIds = [];
            $regenList.empty();
            $regenStart.prop('disabled', true);
            // Show spinner explicitly
            $regenSpinner.show().addClass('is-active');

            // Disable other tag bulk operations while regenerate runs
            $('#ai-seo-bulk-tags-start-button, #ai-seo-bulk-tags-stop-button, #ai-seo-bulk-append-tags-start-button, #ai-seo-bulk-append-tags-stop-button').prop('disabled', true);
            // Disable Clear All Tags while a bulk process is running
            $('#ai-seo-clear-all-tags-button').prop('disabled', true);
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_start_bulk_regenerate_tags',
                    nonce: aiSeoSettings.bulk_regenerate_tags_start_nonce,
                    limit: $regenLimit.val()
                },
                function(response) {
                    if (response.success) {
                        $regenStart.hide();
                        $regenStop.show().prop('disabled', false);
                        $regenProgressWrap.show();
                        if (!regenInterval) {
                            regenInterval = setInterval(pollBulkRegenerate, 2000);
                        }
                    } else {
                        // Hide spinner on error
                        $regenSpinner.hide().removeClass('is-active');
                        alert(response.data.message || aiSeoSettings.error_text);
                        $regenStart.prop('disabled', false);
                    }
                },
                'json'
            );
        });

        $regenStop.on('click', function() {
            $regenStop.prop('disabled', true);
            // Show spinner explicitly
            $regenSpinner.show().addClass('is-active');

            // Re-enable all bulk-tag operation buttons immediately after stop is clicked
            $('#ai-seo-bulk-tags-start-button, #ai-seo-bulk-tags-stop-button, #ai-seo-bulk-append-tags-start-button, #ai-seo-bulk-append-tags-stop-button, #ai-seo-bulk-regenerate-tags-start-button, #ai-seo-bulk-regenerate-tags-stop-button').prop('disabled', false);
            // Re-enable Clear All Tags when bulk processes stop
            $('#ai-seo-clear-all-tags-button').prop('disabled', false);
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_stop_bulk_regenerate_tags',
                    nonce: aiSeoSettings.bulk_regenerate_tags_stop_nonce
                },
                function(response) {
                    // Hide spinner explicitly
                    $regenSpinner.hide().removeClass('is-active');
                    if (response.success) {
                        clearInterval(regenInterval);
                        regenInterval = null;
                        $regenStop.hide();
                        $regenStart.show().prop('disabled', false);
                    } else {
                        alert(response.data.message || aiSeoSettings.error_text);
                        $regenStop.prop('disabled', false);
                    }
                },
                'json'
            );
        });

        // --- Auto-resume progress tracking on page load ---
        function checkAndResumeBulkTagging() {
            // Bulk Tagging resume
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_get_bulk_tags_status',
                    nonce: aiSeoSettings.bulk_tags_status_nonce
                },
                function(resp) {
                    if (resp.success && resp.data && resp.data.status === 'running') {
                        // Show/hide controls directly to avoid uninitialized vars
                        $('#ai-seo-bulk-tags-start-button').hide();
                        $('#ai-seo-bulk-tags-stop-button').show().prop('disabled', false);
                        $('#ai-seo-bulk-tags-progress').show();
                        // Disable append & regenerate buttons while tagging is running
                        $('#ai-seo-bulk-append-tags-start-button, #ai-seo-bulk-append-tags-stop-button, #ai-seo-bulk-regenerate-tags-start-button, #ai-seo-bulk-regenerate-tags-stop-button').prop('disabled', true);
                        // Disable Clear All Tags on resume since bulk tagging is running
                        $('#ai-seo-clear-all-tags-button').prop('disabled', true);
                        if (!tagInterval) {
                            tagInterval = setInterval(pollBulkTags, 2000);
                        }
                    }
                },
                'json'
            );
            // Bulk Append
            $.post(aiSeoSettings.ajax_url, {
                action: 'ai_seo_get_bulk_append_tags_status',
                nonce: aiSeoSettings.bulk_append_tags_status_nonce
            }, function(resp) {
                if (resp.success && resp.data && resp.data.status === 'running') {
                    $appendStart.hide();
                    $appendStop.show().prop('disabled', false);
                    $appendProgressWrap.show();
                    // Disable tagging & regenerate buttons while append is running
                    $('#ai-seo-bulk-tags-start-button, #ai-seo-bulk-tags-stop-button, #ai-seo-bulk-regenerate-tags-start-button, #ai-seo-bulk-regenerate-tags-stop-button').prop('disabled', true);
                    // Disable Clear All Tags on resume since bulk append is running
                    $('#ai-seo-clear-all-tags-button').prop('disabled', true);
                    if (!appendInterval) {
                        appendInterval = setInterval(pollBulkAppend, 2000);
                    }
                }
            });

            // Bulk Regenerate
            $.post(aiSeoSettings.ajax_url, {
                action: 'ai_seo_get_bulk_regenerate_tags_status',
                nonce: aiSeoSettings.bulk_regenerate_tags_status_nonce
            }, function(resp) {
                if (resp.success && resp.data && resp.data.status === 'running') {
                    $regenStart.hide();
                    $regenStop.show().prop('disabled', false);
                    $regenProgressWrap.show();
                    // Disable tagging & append buttons while regenerate is running
                    $('#ai-seo-bulk-tags-start-button, #ai-seo-bulk-tags-stop-button, #ai-seo-bulk-append-tags-start-button, #ai-seo-bulk-append-tags-stop-button').prop('disabled', true);
                    // Disable Clear All Tags on resume since bulk regenerate is running
                    $('#ai-seo-clear-all-tags-button').prop('disabled', true);
                    if (!regenInterval) {
                        regenInterval = setInterval(pollBulkRegenerate, 2000);
                    }
                }
            });
        }

        // Run on page load for bulk resume
        checkAndResumeBulkTagging();

        // === Auto-Tagging Bulk Tagging Logic ===
        var processedTagIds = [];
        var $tagStart       = $('#ai-seo-bulk-tags-start-button');
        var $tagStop        = $('#ai-seo-bulk-tags-stop-button');
        var $tagSpinner     = $('#ai-seo-bulk-tags-spinner');
        var $tagProgressWrap= $('#ai-seo-bulk-tags-progress');
        var $tagBar         = $('#ai-seo-bulk-tags-progress-bar');
        var $tagStatus      = $('#ai-seo-bulk-tags-progress-status');
        var $tagLimit       = $('#ai-seo-bulk-tags-limit');
        var $tagList        = $('#ai-seo-bulk-tags-recent-list');
        var $tagErrorList   = $('#ai-seo-bulk-tags-error-list ul');
        var tagInterval     = null;

        // Poll bulk tagging status
        function pollBulkTags() {
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_get_bulk_tags_status',
                    nonce: aiSeoSettings.bulk_tags_status_nonce
                },
                function(response) {
                    if (!response.success) return;
                    var progress  = response.data;
                    var processed = progress.processed || 0;
                    var total     = progress.total || 0;
                    var percent   = total > 0 ? Math.round((processed/total)*100) : 0;
                    $tagBar.css('width', percent + '%').text(percent + '%');
                    $tagStatus.text(
                        aiSeoSettings.bulk_tags_processing
                            .replace('{processed}', processed)
                            .replace('{total}', total)
                    );
                    // Show recent posts
                    if (progress.recent_success_ids) {
                        progress.recent_success_ids.forEach(function(id) {
                            if (processedTagIds.indexOf(id) === -1) {
                                processedTagIds.push(id);
                                var editUrl = aiSeoSettings.ajax_url.replace('admin-ajax.php','post.php?post=') + id + '&action=edit';
                                // Fetch generated tags string for this post
                                var rawTags = (progress.recent_success_tags && progress.recent_success_tags[id]) ? progress.recent_success_tags[id] : '';
                                var safeTags = escapeHtml(rawTags);
                                // Build link and append tags
                                var linkHtml = '<a href="' + editUrl + '" target="_blank">Post ' + id + '</a>';
                                var itemHtml = linkHtml + (safeTags ? ': ' + safeTags : '');
                                $tagList.prepend('<div style="margin-bottom:5px;">' + itemHtml + '</div>');
                            }
                        });
                        while ($tagList.children().length > 10) {
                            $tagList.children().last().remove();
                        }
                    }
                    if (progress.status === 'complete') {
                        $tagStatus.text(aiSeoSettings.bulk_tags_complete_msg).css('color','green');
                        $tagBar.css('width','100%').text('100%');
                        clearInterval(tagInterval); tagInterval = null;
                        $tagStop.hide(); $tagStart.show().prop('disabled', false);
                    } else if (progress.status === 'stopped') {
                        $tagStatus.text(aiSeoSettings.bulk_tags_stopped_msg).css('color','orange');
                        clearInterval(tagInterval); tagInterval = null;
                    }
                },
                'json'
            );
        }

        // Remove existing direct bindings for bulk tagging (if any) and add delegated handlers

        // Delegated handler for Start Bulk Tagging
        $(document).on('click', '#ai-seo-bulk-tags-start-button', function() {
            console.log('Bulk Tagging: Start button clicked');
            var $btn = $(this);
            // Reset list and disable button
            processedTagIds = [];
            $('#ai-seo-bulk-tags-recent-list').empty();
            $btn.prop('disabled', true);
            var $bulkTagsSpinner = $('#ai-seo-bulk-tags-spinner');
            // Ensure spinner is displayed and animated
            $bulkTagsSpinner.css({ visibility: 'visible', display: 'inline-block' }).addClass('is-active');

            // Disable other tag bulk operations while this runs
            $('#ai-seo-bulk-append-tags-start-button, #ai-seo-bulk-append-tags-stop-button, #ai-seo-bulk-regenerate-tags-start-button, #ai-seo-bulk-regenerate-tags-stop-button').prop('disabled', true);
            // Disable Clear All Tags while a bulk process is running
            $('#ai-seo-clear-all-tags-button').prop('disabled', true);
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_start_bulk_tags',
                    nonce: aiSeoSettings.bulk_tags_start_nonce,
                    limit: $('#ai-seo-bulk-tags-limit').val()
                },
                function(response) {
                    $bulkTagsSpinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
                    if (response.success) {
                        $('#ai-seo-bulk-tags-start-button').hide();
                        $('#ai-seo-bulk-tags-stop-button').show().prop('disabled', false);
                        $('#ai-seo-bulk-tags-progress').show();
                        if (!tagInterval) {
                            tagInterval = setInterval(pollBulkTags, 2000);
                        }
                    } else {
                        alert(response.data.message || aiSeoSettings.error_text);
                        $('#ai-seo-bulk-tags-start-button').prop('disabled', false);
                    }
                },
                'json'
            );
        });

        // Delegated handler for Stop Bulk Tagging
        $(document).on('click', '#ai-seo-bulk-tags-stop-button', function() {
            console.log('Bulk Tagging: Stop button clicked');
            var $btn = $(this);
            $btn.prop('disabled', true);
            var $bulkTagsSpinner = $('#ai-seo-bulk-tags-spinner');
            // Ensure spinner is displayed and animated
            $bulkTagsSpinner.css({ visibility: 'visible', display: 'inline-block' }).addClass('is-active');

            // Re-enable all bulk-tag operation buttons immediately after stop is clicked
            $('#ai-seo-bulk-tags-start-button, #ai-seo-bulk-tags-stop-button, #ai-seo-bulk-append-tags-start-button, #ai-seo-bulk-append-tags-stop-button, #ai-seo-bulk-regenerate-tags-start-button, #ai-seo-bulk-regenerate-tags-stop-button').prop('disabled', false);
            // Re-enable Clear All Tags when bulk processes stop
            $('#ai-seo-clear-all-tags-button').prop('disabled', false);
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_stop_bulk_tags',
                    nonce: aiSeoSettings.bulk_tags_stop_nonce
                },
                function(response) {
                    $bulkTagsSpinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
                    if (response.success) {
                        clearInterval(tagInterval);
                        tagInterval = null;
                        $('#ai-seo-bulk-tags-stop-button').hide();
                        $('#ai-seo-bulk-tags-start-button').show().prop('disabled', false);
                    } else {
                        alert(response.data.message || aiSeoSettings.error_text);
                        $('#ai-seo-bulk-tags-stop-button').prop('disabled', false);
                    }
                },
                'json'
            );
        });

        // Handler for Clear All Tags button
        $('#ai-seo-clear-all-tags-button').on('click', function() {
            if (! confirm(aiSeoSettings.confirm_clear_all_tags)) {
                return;
            }
            var $btn = $(this);
            var $spinner = $('#ai-seo-clear-all-tags-spinner');
            var $result = $('#ai-seo-clear-all-tags-result');
            $btn.prop('disabled', true);
            $spinner.css({ visibility: 'visible', display: 'inline-block' }).addClass('is-active');
            $result.text('');
            $.post(
                aiSeoSettings.ajax_url,
                {
                    action: 'ai_seo_clear_all_tags',
                    nonce: aiSeoSettings.clear_all_tags_nonce
                },
                function(response) {
                    if (response.success && response.data && response.data.message) {
                        $result.html(response.data.message);
                        // Reload the page to refresh stats and button states after clearing all tags
                        location.reload();
                    } else {
                        $result.html((response.data && response.data.message) ? response.data.message : aiSeoSettings.error_text);
                    }
                },
                'json'
            ).fail(function() {
                $result.text(aiSeoSettings.error_text).css('color', 'red');
            }).always(function() {
                $btn.prop('disabled', false);
                $spinner.css({ visibility: 'hidden', display: 'none' }).removeClass('is-active');
            });
        });

    });

    // Basic HTML escaping function (duplicate from media.js, consider moving to a common file later)
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    $(document).on('click', '.ai-seo-analyze-post:not([disabled])', function(){
        var btn = $(this);
        var postId = btn.data('post-id');
        var row = btn.closest('tr');
        btn.prop('disabled', true).text('Analyzing...');
        row.find('.ai-seo-apply-suggestion').prop('disabled', true);
        $.post(ajaxurl, {
            action: 'ai_seo_content_refresh_analyze',
            nonce: aiSeoSettings.nonce,
            post_id: postId
        }, function(resp){
            if(resp.success && resp.data && resp.data.suggestions){
                console.log('AI suggestions:', resp.data.suggestions); // Debug: show what AI returned
                var updatedContent = resp.data.suggestions.updated_content;
                var originalContent = row.data('original-content');
                if (!originalContent) {
                    var origEl = row.find('.ai-seo-original-content');
                    if (origEl.length) originalContent = origEl.text();
                }
                function getBlocks(html) {
                    return html.match(/<p[\s\S]*?<\/p>|<h2[\s\S]*?<\/h2>|<li[\s\S]*?<\/li>/gi) || [];
                }
                function diffWords(oldStr, newStr) {
                    var oldWords = oldStr.split(/(\s+)/);
                    var newWords = newStr.split(/(\s+)/);
                    var oldLen = oldWords.length, newLen = newWords.length;
                    var i = 0, j = 0;
                    var before = '', after = '';
                    while (i < oldLen || j < newLen) {
                        if (oldWords[i] === newWords[j]) {
                            before += oldWords[i] || '';
                            after += newWords[j] || '';
                            i++; j++;
                        } else if (newWords[j] && oldWords.indexOf(newWords[j], i) === -1) {
                            after += '<span style="background:#e6fbe8;color:#1a7f37;">' + (newWords[j] || '') + '</span>';
                            j++;
                        } else if (oldWords[i] && newWords.indexOf(oldWords[i], j) === -1) {
                            before += '<span style="background:#ffeaea;color:#c00;text-decoration:line-through;">' + (oldWords[i] || '') + '</span>';
                            i++;
                        } else {
                            before += '<span style="background:#ffeaea;color:#c00;text-decoration:line-through;">' + (oldWords[i] || '') + '</span>';
                            after += '<span style="background:#e6fbe8;color:#1a7f37;">' + (newWords[j] || '') + '</span>';
                            i++; j++;
                        }
                    }
                    return {before: before, after: after};
                }
                var html = '<div class="ai-seo-suggestions">';
                html += '<strong>AI Refreshed Content Preview:</strong>';
                if (originalContent) {
                    var origBlocks = getBlocks(originalContent);
                    var updBlocks = getBlocks(updatedContent);
                    var maxLen = Math.max(origBlocks.length, updBlocks.length);
                    html += '<div class="ai-seo-diff-table" style="display:flex;gap:24px;align-items:flex-start;">';
                    html += '<div style="flex:1;min-width:0;"><strong>Before</strong>';
                    for (var i = 0; i < maxLen; i++) {
                        var origBlock = origBlocks[i] || '';
                        var updBlock = updBlocks[i] || '';
                        var origText = origBlock.replace(/<[^>]+>/g, '');
                        var updText = updBlock.replace(/<[^>]+>/g, '');
                        var diff = diffWords(origText, updText);
                        html += '<div style="margin-bottom:8px;border-bottom:1px solid #f1f1f1;padding-bottom:4px;word-break:break-word;">' + diff.before + '</div>';
                    }
                    html += '</div>';
                    html += '<div style="flex:1;min-width:0;"><strong>After</strong>';
                    for (var i = 0; i < maxLen; i++) {
                        var origBlock = origBlocks[i] || '';
                        var updBlock = updBlocks[i] || '';
                        var origText = origBlock.replace(/<[^>]+>/g, '');
                        var updText = updBlock.replace(/<[^>]+>/g, '');
                        var diff = diffWords(origText, updText);
                        if (diff.after && diff.after.trim() !== '') {
                            html += '<div style="margin-bottom:8px;border-bottom:1px solid #f1f1f1;padding-bottom:4px;word-break:break-word;">' + diff.after + '</div>';
                        } else {
                            html += '<div style="margin-bottom:8px;border-bottom:1px solid #f1f1f1;padding-bottom:4px;color:#888;font-style:italic;">No AI output for this block</div>';
                        }
                    }
                    html += '</div></div>';
                } else {
                    html += '<div class="ai-seo-updated-content-preview" style="border:1px solid #eee; padding:10px; margin:10px 0; max-height:300px; overflow:auto; background:#fafbfc;">' + (updatedContent || '<span style=\'color:#888;font-style:italic;\'>No AI output</span>') + '</div>';
                }
                html += '<div class="ai-seo-updated-content" style="display:none">' + (updatedContent || '') + '</div>';
                html += '</div>';
                row.find('.ai-seo-suggestions').remove();
                row.after('<tr class="ai-seo-suggestions"><td colspan="5">'+html+'</td></tr>');
                row.find('.ai-seo-apply-suggestion').prop('disabled', false);
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Error getting suggestions.');
            }
        }).always(function(){
            btn.prop('disabled', false).text('Analyze');
        });
    });
    $(document).on('click', '.ai-seo-apply-suggestion:not([disabled])', function(){
        var btn = $(this);
        var postId = btn.data('post-id');
        // Find the suggestions row after this post's row
        var row = btn.closest('tr');
        var suggestionsRow = row.next('.ai-seo-suggestions');
        var updated_content = '';
        if (suggestionsRow.length) {
            // Try to find updated_content in a hidden field or data attribute (for now, fallback to intro if not present)
            var updatedContentEl = suggestionsRow.find('.ai-seo-updated-content');
            if (updatedContentEl.length) {
                updated_content = updatedContentEl.html();
            }
        }
        // If updated_content is not found, fallback to intro (for backward compatibility)
        if (!updated_content) {
            updated_content = row.find('.ai-seo-updated-content').html();
        }
        btn.prop('disabled', true).text('Applying...');
        $.post(ajaxurl, {
            action: 'ai_seo_content_refresh_apply',
            nonce: aiSeoSettings.nonce,
            post_id: postId,
            updated_content: updated_content
        }, function(resp){
            if(resp.success){
                if (suggestionsRow.length) suggestionsRow.remove();
                row.remove();
                alert('Suggestions applied!');
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Error applying suggestions.');
            }
        });
    });

})(jQuery); 

// Add language toggle and custom prompt logic
document.addEventListener('DOMContentLoaded', function() {
    var langCheckbox = document.getElementById('ai-seo-lang-enable-checkbox');
    var langCustomWrap = document.getElementById('ai-seo-lang-custom-wrap');
    if (langCheckbox && langCustomWrap) {
        // Set initial state
        if (langCheckbox.checked) {
            langCustomWrap.removeAttribute('hidden');
            langCustomWrap.style.display = 'block';
        } else {
            langCustomWrap.setAttribute('hidden', 'hidden');
            langCustomWrap.style.display = 'none';
        }
        
        langCheckbox.addEventListener('change', function() {
            if (langCheckbox.checked) {
                langCustomWrap.removeAttribute('hidden');
                langCustomWrap.style.display = 'block';
            } else {
                langCustomWrap.setAttribute('hidden', 'hidden');
                langCustomWrap.style.display = 'none';
            }
        });
    }
    var promptCheckbox = document.getElementById('ai-seo-prompt-enable-checkbox');
    var promptCustomWrap = document.getElementById('ai-seo-prompt-custom-wrap');
    if (promptCheckbox && promptCustomWrap) {
        // Set initial state
        if (promptCheckbox.checked) {
            promptCustomWrap.removeAttribute('hidden');
            promptCustomWrap.style.display = 'block';
        } else {
            promptCustomWrap.setAttribute('hidden', 'hidden');
            promptCustomWrap.style.display = 'none';
        }
        
        promptCheckbox.addEventListener('change', function() {
            if (promptCheckbox.checked) {
                promptCustomWrap.removeAttribute('hidden');
                promptCustomWrap.style.display = 'block';
            } else {
                promptCustomWrap.setAttribute('hidden', 'hidden');
                promptCustomWrap.style.display = 'none';
            }
        });
    }
    var restoreBtn = document.getElementById('ai-seo-restore-default-prompt');
    if (restoreBtn) {
        restoreBtn.addEventListener('click', function() {
            var textarea = document.querySelector('#ai-seo-prompt-custom-wrap textarea');
            if (textarea) {
                textarea.value = textarea.placeholder;
            }
        });
    }
});