(function($) {
    'use strict';

    /**
     * Helper to escape HTML to prevent injection.
     * @param {string} unsafe
     * @returns {string}
     */
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/\'/g, '&#039;');
    }

    // Single-post tag button handler for generate, append, regenerate
    $(document).on('click', '.ai-seo-generate-tags-button, .ai-seo-append-tags-button, .ai-seo-regenerate-tags-button', function(e) {
        e.preventDefault();
        var $button = $(this);
        var postId = $button.data('post-id');
        var $container = $button.closest('div');
        var $result = $container.find('.ai-seo-tags-result');
        var $spinner = $container.find('.spinner').first();

        if (! postId) {
            console.error('AI SEO Tools: Missing post ID.');
            $result.text(aiSeoPostList.error_text + ': Missing ID').css('color', 'red').show();
            return;
        }

        var isAppend = $button.is('.ai-seo-append-tags-button');
        var isRegenerate = $button.is('.ai-seo-regenerate-tags-button');
        var isGenerate = $button.is('.ai-seo-generate-tags-button');

        // Determine AJAX action and nonce
        var action = 'ai_seo_generate_single_tags';
        var nonce  = aiSeoPostList.nonce;
        if (isRegenerate) {
            action = 'ai_seo_regenerate_single_tags';
            nonce  = aiSeoPostList.regenerate_nonce;
        }

        // Set button text for feedback
        if (isRegenerate) {
            $button.text(aiSeoPostList.regenerating_text);
        } else if (isAppend) {
            $button.text(aiSeoPostList.appending_text);
        } else {
            $button.text(aiSeoPostList.generating_text);
        }

        // Disable button & show spinner
        $button.prop('disabled', true).addClass('disabled');
        $spinner.show();
        $result.hide().text('');

        $.ajax({
            url: aiSeoPostList.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: action,
                nonce: nonce,
                post_id: postId
            },
            success: function(response) {
                if (response.success && response.data.tags) {
                    var tags = escapeHtml(response.data.tags);
                    $result.text(tags).css('color', '').show();
                    // Hide generate or append button when done
                    if (isGenerate || isAppend) {
                        $button.hide();
                    }
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : aiSeoPostList.error_text;
                    $result.text(escapeHtml(msg)).css('color', 'red').show();
                }
            },
            error: function(jqXHR) {
                console.error('AI SEO Tools AJAX Error (Tags):', jqXHR.responseText);
                var msg = aiSeoPostList.error_text;
                try {
                    var err = JSON.parse(jqXHR.responseText);
                    if (err.data && err.data.message) {
                        msg = err.data.message;
                    }
                } catch (_) {}
                $result.text(escapeHtml(msg)).css('color', 'red').show();
            },
            complete: function() {
                // Restore original button text for regenerate only
                if (isRegenerate) {
                    $button.text(aiSeoPostList.regenerate_text || aiSeoPostList.regenerating_text.replace('ing', '')); // fallback
                }
                $spinner.hide();
                if (!isGenerate && !isAppend) {
                    $button.prop('disabled', false).removeClass('disabled');
                }
            }
        });
    });

    // Clear tags button handler
    $(document).on('click', '.ai-seo-clear-tags-button', function(e) {
        e.preventDefault();
        var $button   = $(this);
        var postId    = $button.data('post-id');
        var $container = $button.closest('div');
        var $result   = $container.find('.ai-seo-tags-result');
        var $spinner  = $container.find('.spinner').first();

        if (!postId) {
            console.error('AI SEO Tools: Missing post ID.');
            $result.text(aiSeoPostList.error_text + ': Missing ID').css('color', 'red').show();
            return;
        }

        // Indicate clearing
        $button.text(aiSeoPostList.clearing_text).prop('disabled', true);
        $spinner.show();
        $result.hide().text('');

        $.ajax({
            url: aiSeoPostList.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ai_seo_clear_single_tags',
                nonce: aiSeoPostList.clear_nonce,
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    // Remove existing tags display
                    $container.find('span').first().text('');
                    $result.text(response.data.message).css('color', 'green').show();
                    // Hide clear button
                    $button.hide();
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : aiSeoPostList.error_text;
                    $result.text(msg).css('color', 'red').show();
                }
            },
            error: function(jqXHR) {
                console.error('AI SEO Tools AJAX Error (Clear Tags):', jqXHR.responseText);
                var msg = aiSeoPostList.error_text;
                try {
                    var err = JSON.parse(jqXHR.responseText);
                    if (err.data && err.data.message) {
                        msg = err.data.message;
                    }
                } catch (_) {}
                $result.text(msg).css('color', 'red').show();
            },
            complete: function() {
                $spinner.hide();
                $button.text(aiSeoPostList.clear_text).prop('disabled', false);
            }
        });
    });

})(jQuery); 