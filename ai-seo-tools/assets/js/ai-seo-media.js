/**
 * AI SEO Tools Media Library Script (Using wp.media API)
 */
(function($, _, wp) {
    'use strict';

    $(document).ready(function() {

        // --- Media Library List View Button Handler --- //
        // (Keep the existing logic for the list view)
        $('body').on('click', '.ai-seo-generate-alt-button', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $container = $button.closest('.ai-alt-text-status');
            var $spinner = $container.find('.spinner');
            var $resultSpan = $container.find('.ai-seo-alt-text-result');
            var attachmentId = $container.data('attachment-id');

            if ($button.is('.disabled')) {
                return; // Prevent multiple clicks
            }

            if (!attachmentId) {
                console.error('AI SEO Tools: Missing attachment ID (List View).');
                $resultSpan.text(aiSeoToolsMedia.error_text + ': Missing ID').css('color', 'red').show();
                return;
            }

            $button.addClass('disabled').prop('disabled', true);
            $spinner.css('display', 'inline-block');
            $resultSpan.hide().text('');

            $.ajax({
                url: aiSeoToolsMedia.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_seo_generate_single_alt',
                    nonce: aiSeoToolsMedia.nonce,
                    attachment_id: attachmentId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $container.empty().append('<span>' + escapeHtml(response.data.alt_text) + '</span>');
                    } else {
                        var errorMessage = response.data && response.data.message ? response.data.message : aiSeoToolsMedia.error_text;
                        $resultSpan.text(escapeHtml(errorMessage)).css('color', 'red').show();
                        $button.removeClass('disabled').prop('disabled', false);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('AI SEO Tools AJAX Error (List View):', textStatus, errorThrown, jqXHR.responseText);
                    var specificMessage = aiSeoToolsMedia.error_text + ': ' + textStatus;
                     if (jqXHR.responseText) {
                        try {
                            var errorResponse = JSON.parse(jqXHR.responseText);
                            if (errorResponse && errorResponse.data && errorResponse.data.message) {
                                specificMessage = escapeHtml(errorResponse.data.message);
                            }
                        } catch (e) { /* Ignore */ }
                    }
                    $resultSpan.text(specificMessage).css('color', 'red').show();
                    $button.removeClass('disabled').prop('disabled', false);
                },
                complete: function() {
                    $spinner.hide();
                }
            });
        });
        // --- End Media Library List View Button Handler --- //


        // --- Media Modal Button Injection using wp.media API --- //
        if (typeof wp !== 'undefined' && wp.media) {

            // Define our custom button view
            const AiSeoGenerateButton = wp.media.view.Button.extend({
                className: 'button button-secondary button-small ai-seo-generate-modal-button',
                // Keep spinner and text inside, status span will be added externally
                template: _.template('<span class="spinner ai-seo-modal-spinner" style="float: none; vertical-align: middle; margin-left: 5px; display: none;"></span><span class="ai-seo-button-text"></span>'),

                initialize: function() {
                    wp.media.view.Button.prototype.initialize.apply(this, arguments);
                    // Ensure we have access to the attachment model
                    this.model = this.options.attachmentModel; // Pass the model during instantiation
                     // Find the alt text input related to this button for easy access later
                    this.$altInput = this.options.$altInput;

                    this.bindHandlers();
                },

                 // Add elements to the button template for status/spinner
                render: function() {
                    wp.media.view.Button.prototype.render.apply(this, arguments);
                    // Use the template which now includes the text span
                    this.$el.html(this.template());
                    // Find the elements within the button
                    this.$spinner = this.$el.find('.ai-seo-modal-spinner');
                    // Status span is no longer inside the button
                    // this.$statusSpan = this.$el.find('.ai-seo-modal-status');
                    this.$buttonTextSpan = this.$el.find('.ai-seo-button-text');
                    // Set the actual button text
                    this.$buttonTextSpan.text(aiSeoToolsMedia.generating_text ? aiSeoToolsMedia.generating_text.replace('...', ' with AI') : 'Generate with AI');
                    return this;
                },

                bindHandlers: function() {
                    // Check if alt text exists initially and hide button if it does
                    if (this.model && this.model.get('alt')) {
                        this.$el.hide();
                    }
                    // Listen for changes on the alt text model property
                    if (this.model) {
                        this.listenTo(this.model, 'change:alt', this.toggleVisibility);
                    }
                     // Also listen to changes directly on the input field (might be updated externally)
                     if (this.$altInput) {
                        this.$altInput.on('input change', _.debounce(this.toggleVisibilityBasedOnInput.bind(this), 300));
                     }
                },

                // Hide button if alt text is added (either via model or input)
                toggleVisibility: function() {
                    if (this.model && this.model.get('alt')) {
                        this.$el.fadeOut();
                    } else {
                        this.$el.fadeIn();
                    }
                },
                toggleVisibilityBasedOnInput: function() {
                    if (this.$altInput && this.$altInput.val()) {
                        this.$el.fadeOut();
                    } else if (!this.model || !this.model.get('alt')) {
                         // Show only if model also has no alt text
                        this.$el.fadeIn();
                    }
                },

                // Handle the button click: Perform AJAX request
                click: function(e) {
                    e.preventDefault();
                    var attachmentId = this.model.id;

                    if (!attachmentId || !this.$altInput || this.$el.is('.disabled')) {
                        console.error('AI SEO Tools: Missing data for modal generation.', { id: attachmentId, input: this.$altInput });
                        return;
                    }

                    // Find the status span, which is now external
                    this.$statusSpan = this.$el.next('.ai-seo-modal-status');
                    if (!this.$statusSpan.length) {
                        console.error("AI SEO Tools: Could not find external status span next to button.");
                        // Optionally create it dynamically if missing?
                        return; // Exit if status span isn't found
                    }

                    // Show loading state
                    this.$el.addClass('disabled').prop('disabled', true);
                    this.$spinner.css('display', 'inline-block').addClass('is-active'); // Show spinner
                    this.$buttonTextSpan.css('display', 'none'); // Hide button text
                    this.$statusSpan.text('').css('color', ''); // Clear status

                    var self = this; // Reference to the button view for callbacks

                    $.ajax({
                        url: aiSeoToolsMedia.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ai_seo_generate_single_alt',
                            nonce: aiSeoToolsMedia.nonce,
                            attachment_id: attachmentId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success && response.data.alt_text) {
                                // Update the input value
                                self.$altInput.val(response.data.alt_text).trigger('change'); // Trigger change so WP saves it
                                // Update the model as well, although input change might handle this
                                self.model.set('alt', response.data.alt_text);

                                if (self.$statusSpan) self.$statusSpan.text('Generated!').css('color', 'green');
                                // Button will be hidden automatically by the 'change:alt' listener
                                // self.$el.hide(); // Hide button explicitly as well
                                setTimeout(function() {
                                     if (self.$statusSpan) self.$statusSpan.text('');
                                }, 3000);
                            } else {
                                var errorMessage = response.data && response.data.message ? response.data.message : aiSeoToolsMedia.error_text;
                                if (self.$statusSpan) self.$statusSpan.text(escapeHtml(errorMessage)).css('color', 'red');
                                if (self.$el) self.$el.removeClass('disabled').prop('disabled', false);
                                if (self.$spinner) self.$spinner.css('display', 'none').removeClass('is-active'); // Hide spinner on error
                                if (self.$buttonTextSpan) self.$buttonTextSpan.css('display', 'inline'); // Show button text on error
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error('AI SEO Tools Modal Generate AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                            var specificMessage = aiSeoToolsMedia.error_text;
                            if (jqXHR.responseText) {
                                try {
                                    var errorResponse = JSON.parse(jqXHR.responseText);
                                    if (errorResponse && errorResponse.data && errorResponse.data.message) {
                                        specificMessage = escapeHtml(errorResponse.data.message);
                                    }
                                } catch (e) { /* Ignore */ }
                            }
                             if (self.$statusSpan) self.$statusSpan.text(specificMessage + ' (' + textStatus + ')').css('color', 'red');
                            if (self.$el) self.$el.removeClass('disabled').prop('disabled', false);
                            if (self.$spinner) self.$spinner.css('display', 'none').removeClass('is-active'); // Hide spinner on error
                            if (self.$buttonTextSpan) self.$buttonTextSpan.css('display', 'inline'); // Show button text on error
                        },
                        complete: function() {
                             // Spinner hiding is now handled in success/error blocks, ensure it's hidden if needed
                             if (self.$spinner && self.$spinner.is(':visible')) {
                                 self.$spinner.css('display', 'none').removeClass('is-active');
                             }
                        }
                    });
                }
            });

            // Hook into the media frame rendering
            wp.media.events.on('editor:render', function(editor) {
                // This handles the sidebar in the main Media Library view
                // console.log('editor:render', editor);
                attachButtonToSidebar(editor);
            });

            // Need to handle different ways the sidebar might render/update
            var originalSidebar = wp.media.view.Attachment.Details.TwoColumn;
            if (originalSidebar) {
                 wp.media.view.Attachment.Details.TwoColumn = originalSidebar.extend({
                    render: function() {
                       // console.log('TwoColumn render');
                        originalSidebar.prototype.render.apply(this, arguments);
                        attachButtonLogic(this);
                    }
                });
            }
            var originalDetails = wp.media.view.Attachment.Details;
            if (originalDetails) {
                wp.media.view.Attachment.Details = originalDetails.extend({
                     render: function() {
                         // console.log('Details render');
                        originalDetails.prototype.render.apply(this, arguments);
                        attachButtonLogic(this);
                    }
                });
            }

            // Function to find the alt text field and inject the button
            function attachButtonLogic(viewInstance) {
                // Need the attachment model from the view
                var attachmentModel = viewInstance.model;
                if (!attachmentModel) return;

                 // Find the specific container for the alt text setting
                var $altSetting = viewInstance.$el.find('.setting[data-setting="alt"]');
                if (!$altSetting.length) return;

                // Find the textarea within it
                var $altTextarea = $altSetting.find('textarea');
                if (!$altTextarea.length) return;

                // Prevent duplicate injection for this specific view instance
                if ($altSetting.data('ai-seo-button-added')) return;
                $altSetting.data('ai-seo-button-added', true);

                // Create status span separately
                var $statusSpan = $('<span class="ai-seo-modal-status" style="margin-left: 10px; vertical-align: baseline;"></span>');

                // Instantiate and render the button, passing necessary options
                var aiButton = new AiSeoGenerateButton({
                    controller: viewInstance.controller, // Pass the controller
                    model: viewInstance.model, // Pass the model to the button itself (redundant?)
                    attachmentModel: attachmentModel, // Pass the attachment model explicitly
                    $altInput: $altTextarea // Pass the jQuery object for the alt input
                }).render();

                 // Insert the button after the textarea
                $altTextarea.after(aiButton.$el);
                 // Insert the status span after the button
                 aiButton.$el.after($statusSpan);
            }

            // Helper for editor:render event
             function attachButtonToSidebar(sidebarView) {
                if (sidebarView && sidebarView.details) {
                    // The actual details view might be nested
                    attachButtonLogic(sidebarView.details);
                }
             }

        } else {
            console.error("AI SEO Tools: wp.media object not found.");
        }
        // --- End Media Modal Button Injection --- //

    });

    // Basic HTML escaping function
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

})(jQuery, _, wp); // Pass _ and wp 