<?php
/**
 * Handles automatic alt text generation for images using AI.
 *
 * @package AI_SEO_Tools
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'AI_SEO_Tools_Alt_Text' ) ) {
	/**
	 * Class AI_SEO_Tools_Alt_Text.
	 */
	class AI_SEO_Tools_Alt_Text {

		// Constants for bulk processing
		private const BULK_OPTION_PENDING = 'ai_seo_bulk_alt_pending_ids';
		private const BULK_OPTION_PROGRESS = 'ai_seo_bulk_alt_progress';
		private const CRON_HOOK = 'ai_seo_tools_bulk_alt_cron';
		private const BATCH_SIZE = 1; // Process 1 image per batch to respect rate limits

		/**
		 * Option name where settings are stored.
		 *
		 * @var string
		 */
		private string $option_name = 'ai_seo_tools_options';

		/**
		 * Constructor.
		 * Hooks into WordPress actions.
		 */
		public function __construct() {
			// Hook for new attachments.
			add_action( 'add_attachment', array( $this, 'schedule_alt_text_generation' ) );
			add_action( 'ai_seo_generate_alt_text', array( $this, 'generate_alt_text_for_image' ), 10, 1 );

			// Hooks for Media Library integration.
			add_filter( 'manage_media_columns', array( $this, 'add_alt_text_column' ) );
			add_action( 'manage_media_custom_column', array( $this, 'display_alt_text_column' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_scripts' ) );

			// AJAX handler for manual generation.
			add_action( 'wp_ajax_ai_seo_generate_single_alt', array( $this, 'handle_ajax_generate_single_alt' ) );

			// --- Bulk Generation Hooks --- //
			add_action( 'wp_ajax_ai_seo_start_bulk_alt', array( $this, 'handle_ajax_start_bulk_alt' ) );
			add_action( 'wp_ajax_ai_seo_get_bulk_alt_status', array( $this, 'handle_ajax_get_bulk_alt_status' ) );
			add_action( 'wp_ajax_ai_seo_stop_bulk_alt', array( $this, 'handle_ajax_stop_bulk_alt' ) );
			add_action( 'wp_ajax_ai_seo_get_stats', array( $this, 'handle_ajax_get_stats' ) );
			add_action( 'wp_ajax_ai_seo_get_image_details', array( $this, 'handle_ajax_get_image_details' ) );
			add_action( self::CRON_HOOK, array( $this, 'process_bulk_alt_text_batch' ) );
			add_action( 'admin_notices', array( $this, 'display_bulk_process_notice' ) );
			// --- End Bulk Generation Hooks --- //
		}

		/**
		 * Enqueues scripts needed for the media library screen.
		 *
		 * @param string $hook The current admin page hook.
		 */
		public function enqueue_media_scripts( string $hook ): void {
			// Only load on the upload.php screen (Media Library List view).
			if ( 'upload.php' !== $hook ) {
				return;
			}

			wp_enqueue_script(
				'ai-seo-tools-media',
				AI_SEO_TOOLS_URL . 'assets/js/ai-seo-media.js', // We will create this file next.
				array( 'jquery' ),
				AI_SEO_TOOLS_VERSION,
				true // Load in footer.
			);

			// Pass data to JavaScript.
			wp_localize_script( 'ai-seo-tools-media', 'aiSeoToolsMedia', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ai_seo_generate_alt_nonce' ),
				'generating_text' => esc_html__( 'Generating...', 'ai-seo-tools' ),
				'error_text' => esc_html__( 'Error', 'ai-seo-tools' ),
			) );
		}

		/**
		 * Adds a custom column to the Media Library list view.
		 *
		 * @param array $columns Existing columns.
		 * @return array Modified columns.
		 */
		public function add_alt_text_column( array $columns ): array {
			// Add column before 'Date'.
			$new_columns = array();
			foreach ( $columns as $key => $title ) {
				if ( 'date' === $key ) {
					$new_columns['ai_seo_alt_text'] = esc_html__( 'AI Alt Text', 'ai-seo-tools' );
				}
				$new_columns[ $key ] = $title;
			}
			// If 'date' column wasn't found, add it at the end.
			if ( ! isset( $new_columns['ai_seo_alt_text'] ) ) {
			     $new_columns['ai_seo_alt_text'] = esc_html__( 'AI Alt Text', 'ai-seo-tools' );
			}
			return $new_columns;
		}

		/**
		 * Displays content for the custom alt text column.
		 *
		 * @param string $column_name The name of the column being displayed.
		 * @param int    $attachment_id The ID of the current attachment.
		 */
		public function display_alt_text_column( string $column_name, int $attachment_id ): void {
			if ( 'ai_seo_alt_text' !== $column_name ) {
				return;
			}

			// Only show for images.
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				echo '—'; // Not an image
				return;
			}

			$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

			echo '<div class="ai-alt-text-status" data-attachment-id="' . esc_attr( $attachment_id ) . '">';
			if ( ! empty( $alt_text ) ) {
				echo '<span>' . esc_html( $alt_text ) . '</span>';
			} else {
				// Button to generate alt text.
				printf(
					'<button type="button" class="button button-secondary button-small ai-seo-generate-alt-button">%s</button>',
					esc_html__( 'Generate', 'ai-seo-tools' )
				);
				echo '<span class="ai-seo-alt-text-result ai-seo-status-inline"></span>'; // For displaying results/errors
				echo '<span class="spinner ai-seo-spinner-inline"></span>';
			}
			echo '</div>';
		}

		/**
		 * Handles the AJAX request to generate alt text for a single image.
		 */
		public function handle_ajax_generate_single_alt(): void {
			check_ajax_referer( 'ai_seo_generate_alt_nonce', 'nonce' );

			if ( ! current_user_can( 'upload_files' ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
			}

			$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

			if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid attachment ID.', 'ai-seo-tools' ) ), 400 );
			}

			// Call the existing generation logic, but slightly refactored to return the result.
			$result = $this->generate_alt_text_for_image( $attachment_id, true ); // Pass true to indicate AJAX context.

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			} elseif ( $result === false ) {
                // Handle cases where generation failed silently within the function (e.g., API key missing)
                 wp_send_json_error( array( 'message' => esc_html__( 'Alt text generation failed. Check logs or API key.', 'ai-seo-tools' ) ), 500 );
            } elseif ( is_string( $result ) ) {
				// Success! Return the generated alt text.
				wp_send_json_success( array( 'alt_text' => $result ) );
			} else {
				// Unexpected result.
				wp_send_json_error( array( 'message' => esc_html__( 'An unexpected error occurred.', 'ai-seo-tools' ) ), 500 );
			}
		}

		/**
		 * Schedules a background job to generate alt text.
		 * Using WP Cron avoids slowing down the upload process.
		 *
		 * @param int $attachment_id The ID of the attachment just added.
		 */
		public function schedule_alt_text_generation( int $attachment_id ): void {
			// Check if the attachment is an image.
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return;
			}

			// Schedule a single event to run as soon as possible.
			wp_schedule_single_event( time(), 'ai_seo_generate_alt_text', array( $attachment_id ) );
		}

		/**
		 * Generates alt text for a given image attachment using an AI service.
		 *
		 * @param int  $attachment_id The ID of the attachment.
		 * @param bool $is_ajax Optional. Indicates if called via AJAX context. If true, returns result/error instead of void.
		 * @return bool|string|WP_Error Returns true on success (non-AJAX), generated alt text (string) on success (AJAX), specific error message (string) on failure (non-AJAX), or WP_Error on failure (AJAX).
		 */
		public function generate_alt_text_for_image( int $attachment_id, bool $is_ajax = false ) {
			// Verify it's an image.
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				$error_msg = esc_html__( 'Not an image.', 'ai-seo-tools' );
				return $is_ajax ? new WP_Error('invalid_attachment', $error_msg) : $error_msg;
			}

			// Check if alt text already exists (only if not forced, future enhancement).
			$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( ! empty( $existing_alt ) ) {
				// If called via AJAX, maybe return existing text or an indication it wasn't generated.
				// For now, we assume the button won't be shown if alt exists, so this path is mainly for the cron job.
				return $is_ajax ? $existing_alt : true;
			}

			// Retrieve OpenAI API key and language.
			$options = get_option( $this->option_name );
			$api_key = $options['openai_api_key'] ?? '';
			$custom_lang_enabled = !empty($options['alt_text_language_custom_enable']);
			$custom_lang = trim($options['alt_text_language_custom'] ?? '');
			$custom_prompt = trim($options['alt_text_custom_prompt'] ?? '');
			$custom_prompt_enabled = !empty($options['alt_text_custom_prompt_enable']);
			$default_prompt = 'Generate a concise, descriptive alt text for this image, suitable for SEO and accessibility. Focus on the main subject and action. Maximum 125 characters.';

			if ( empty( $api_key ) ) {
				$error_msg = esc_html__( 'OpenAI API key is missing.', 'ai-seo-tools' );
				return $is_ajax ? new WP_Error('missing_api_key', $error_msg) : $error_msg;
			}

			// Get image path instead of URL.
			$image_path = get_attached_file( $attachment_id );
			if ( ! $image_path || ! file_exists( $image_path ) ) {
				$error_msg = esc_html__( 'Could not retrieve image file path.', 'ai-seo-tools' );
				return $is_ajax ? new WP_Error('no_image_path', $error_msg) : $error_msg;
			}

			// Read image data.
			$image_data = file_get_contents( $image_path );
			if ( false === $image_data ) {
				$error_msg = esc_html__( 'Could not read image file.', 'ai-seo-tools' );
				return $is_ajax ? new WP_Error('read_image_failed', $error_msg) : $error_msg;
			}

			// Get MIME type.
			$file_info = wp_check_filetype( basename( $image_path ) );
			if ( ! $file_info || empty( $file_info['type'] ) ) {
				$error_msg = esc_html__( 'Could not determine image type.', 'ai-seo-tools' );
				return $is_ajax ? new WP_Error('mime_type_failed', $error_msg) : $error_msg;
			}
			$mime_type = $file_info['type'];

			// Encode image data in Base64.
			$base64_image = base64_encode( $image_data );

			// Create the data URI.
			$image_data_uri = "data:{$mime_type};base64,{$base64_image}";

			// --- OpenAI API Call --- //
			$api_endpoint = 'https://api.openai.com/v1/chat/completions';

			// Get the selected model from options, with a fallback.
			$selected_model = $options['openai_model'] ?? AI_SEO_Tools_Settings::DEFAULT_MODEL;

            // Get the selected detail level from options, with a fallback.
            $detail_level = $options['image_detail_level'] ?? 'low';

            // Build prompt: use custom if enabled and set, else default. Replace {LANGUAGE} if present.
            if ($custom_prompt_enabled && $custom_prompt !== '') {
                $prompt_text = $custom_prompt;
				if ($custom_lang_enabled && $custom_lang !== '') {
                    $prompt_text .= ' Use language: ' . $custom_lang;
                }
            } else {
                $prompt_text = $default_prompt;
                if ($custom_lang_enabled && $custom_lang !== '') {
                    $prompt_text .= ' Use language: ' . $custom_lang;
                }
            }

			$payload = array(
				'model' => $selected_model,
				'messages' => array(
					array(
						'role' => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $prompt_text
							),
							array(
								'type' => 'image_url',
								'image_url' => array(
									// Send image data as Base64 Data URI instead of URL
									'url' => $image_data_uri,
                                    'detail' => $detail_level // Add the detail level parameter
								)
							)
						)
					)
				),
				'max_tokens' => 50 // Limit the response length
			);

			$args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 60, // Increased timeout
				'method'  => 'POST',
				'data_format' => 'body',
			);

			$response = wp_remote_post( $api_endpoint, $args );

			// Handle the response.
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
				/* translators: %s: Error message returned from the network request. */
				return $is_ajax ? $response : sprintf(esc_html__('Network error: %s', 'ai-seo-tools'), $error_message);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded_body  = json_decode( $response_body, true );

			if ( $response_code !== 200 || ! isset( $decoded_body['choices'][0]['message']['content'] ) ) {
				$error_detail = "Code: {$response_code}. Body: " . substr($response_body, 0, 500); // Log part of the body
				$api_error_message = isset($decoded_body['error']['message']) ? $decoded_body['error']['message'] : esc_html__( 'API request failed or returned unexpected data.', 'ai-seo-tools' );
				/* translators: 1: HTTP response code, 2: API error message. */
				return $is_ajax ? new WP_Error('api_error', $api_error_message, array( 'status' => $response_code )) : sprintf(esc_html__('API error (%1$d): %2$s', 'ai-seo-tools'), $response_code, $api_error_message);
			}

			$generated_alt_text = $decoded_body['choices'][0]['message']['content'];
			// --- End API Call --- //

			// If the response starts with "I'm sorry" (case-insensitive, allow whitespace before), treat as error and do not save
			if (preg_match('/^\s*I\'m sorry/i', $generated_alt_text)) {
				$generated_alt_text = '';
			}

			// Sanitize and potentially trim the generated alt text.
			$sanitized_alt_text = sanitize_text_field( trim( $generated_alt_text ) );
			$sanitized_alt_text = trim( $sanitized_alt_text, '"' ); // Remove surrounding quotes
			$sanitized_alt_text = mb_substr( $sanitized_alt_text, 0, 125 ); // Enforce length limit

			// Update the image alt text meta data.
			if ( ! empty( $sanitized_alt_text ) ) {
				if ( update_post_meta( $attachment_id, '_wp_attachment_image_alt', $sanitized_alt_text ) ) {
					return $is_ajax ? $sanitized_alt_text : true;
				} else {
					$error_msg = esc_html__( 'Failed to save the generated alt text.', 'ai-seo-tools' );
					return $is_ajax ? new WP_Error('update_failed', $error_msg) : $error_msg;
				}
			} else {
				$error_msg = esc_html__( 'AI returned empty or invalid text.', 'ai-seo-tools' );
				return $is_ajax ? new WP_Error('empty_alt_text', $error_msg) : $error_msg;
			}

			// Should not be reached in normal flow due to checks above
			$error_msg = esc_html__( 'An unknown error occurred during generation.', 'ai-seo-tools' );
			return $is_ajax ? new WP_Error('unknown_error', $error_msg) : $error_msg;
		}

        /**
         * Retrieves statistics about image alt text in the Media Library.
         *
         * @return array{total: int, with_alt: int, without_alt: int} Statistics array.
         */
        public static function get_alt_text_stats(): array {
            $cache_key = 'ai_seo_tools_alt_text_stats';
            $cached = wp_cache_get( $cache_key, 'ai-seo-tools' );
            if ( false !== $cached ) {
                return $cached;
            }

            // Get all image attachments
            $all_images = get_posts([
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            $total_images = count($all_images);

            // Get images with non-empty alt text
            $with_alt = get_posts([
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Caching is used, and this is required for WordPress.org compliance.
                    [
                        'key'     => '_wp_attachment_image_alt',
                        'value'   => '',
                        'compare' => '!=',
                    ],
                ],
            ]);
            $with_alt_count = count($with_alt);
            $without_alt = $total_images - $with_alt_count;

            $result = [
                'total'       => $total_images,
                'with_alt'    => $with_alt_count,
                'without_alt' => $without_alt,
            ];
            wp_cache_set( $cache_key, $result, 'ai-seo-tools', 300 ); // Cache for 5 minutes
            return $result;
        }

        // ==============================================
        // == Bulk Generation Methods
        // ==============================================

        /**
         * AJAX handler to initiate the bulk generation process.
         */
        public function handle_ajax_start_bulk_alt(): void {
            check_ajax_referer( 'ai_seo_bulk_alt_start_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }

            // Get the limit from the request.
            $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;

            // Clear any previous cron jobs for safety
            wp_clear_scheduled_hook( self::CRON_HOOK );

            // Get IDs of images without alt text
            $pending_ids = $this->get_images_without_alt();

            // Apply the limit if specified and valid
            $total_missing = count($pending_ids);
            if ( $limit > 0 && $limit < $total_missing ) {
                $pending_ids = array_slice( $pending_ids, 0, $limit );
                $total_to_process = $limit;
            } else {
                $total_to_process = $total_missing; // Process all if limit is 0 or >= total missing
            }

            if ( empty( $pending_ids ) ) {
                update_option(self::BULK_OPTION_PROGRESS, ['status' => 'complete', 'total' => 0, 'processed' => 0]);
                delete_option(self::BULK_OPTION_PENDING);
                wp_send_json_success( ['message' => esc_html__( 'No images found requiring alt text.', 'ai-seo-tools'), 'status' => 'complete'] );
                return;
            }

            // Store pending IDs and initial progress
            update_option( self::BULK_OPTION_PENDING, $pending_ids, 'no' ); // 'no' means don't autoload
            update_option( self::BULK_OPTION_PROGRESS, [
                'status'    => 'running',
                'total'     => $total_to_process,
                'processed' => 0,
                'last_run'  => 0, // Timestamp of the last batch run
                'errors'    => [], // Store potential errors during bulk run
                'user_limit' => $limit > 0 ? $limit : $total_to_process // Store the original user-selected limit
            ], 'no' );

            // Schedule the first batch immediately
            wp_schedule_single_event( time(), self::CRON_HOOK );

            wp_send_json_success( [
                'message' => esc_html__( 'Bulk generation process started.', 'ai-seo-tools' ),
                'status'  => 'running',
                'total'   => $total_to_process,
                'processed' => 0
                ] );
        }

        /**
         * AJAX handler to get the current status of the bulk generation.
         */
        public function handle_ajax_get_bulk_alt_status(): void {
            check_ajax_referer( 'ai_seo_bulk_alt_status_nonce', 'nonce' ); // Use a specific nonce

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }

            $progress = get_option( self::BULK_OPTION_PROGRESS, ['status' => 'idle', 'total' => 0, 'processed' => 0, 'errors' => [], 'recent_success_ids' => [], 'current_id' => null] ); // Provide defaults

            // Check if cron seems stuck (e.g., no progress for 5 minutes)
            if ($progress['status'] === 'running' && isset($progress['last_run']) && $progress['last_run'] > 0 && (time() - $progress['last_run'] > 300)) {
                 // $progress['status'] = 'stalled'; // Optionally report stalled status
            }

            wp_send_json_success( $progress );
        }

        /**
         * AJAX handler to stop the bulk generation process.
         */
        public function handle_ajax_stop_bulk_alt(): void {
            check_ajax_referer( 'ai_seo_bulk_alt_stop_nonce', 'nonce' ); // Use a specific nonce

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }

            // Clear the scheduled hook
            wp_clear_scheduled_hook( self::CRON_HOOK );

            // Delete pending IDs
            delete_option( self::BULK_OPTION_PENDING );

            // Update status to stopped
            $progress = get_option( self::BULK_OPTION_PROGRESS, [] );
            $progress['status'] = 'stopped';
            update_option( self::BULK_OPTION_PROGRESS, $progress, false );

            wp_send_json_success( ['message' => esc_html__('Bulk generation stopped.', 'ai-seo-tools'), 'status' => 'stopped'] );
        }

        /**
         * Processes a batch of images for alt text generation via WP Cron.
         */
        public function process_bulk_alt_text_batch(): void {
            $pending_ids = get_option( self::BULK_OPTION_PENDING );
            $progress = get_option( self::BULK_OPTION_PROGRESS );

            // Safety checks
            if ( empty( $pending_ids ) || ! is_array( $pending_ids ) || ($progress['status'] ?? '') !== 'running' ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
                update_option(self::BULK_OPTION_PROGRESS, array_merge($progress ?? [], ['status' => 'error', 'last_error' => 'Inconsistent state or no pending IDs.']));
                return;
            }

            // Initialize recent successes if not present
            if (!isset($progress['recent_success_ids'])) {
                $progress['recent_success_ids'] = [];
            }

            // Take a batch of IDs from the beginning of the array
            $batch_ids = array_slice( $pending_ids, 0, self::BATCH_SIZE );

            $processed_in_batch = 0;
            $batch_errors = [];
            $batch_success_ids = [];

            foreach ( $batch_ids as $attachment_id_raw ) {
                $attachment_id = (int)$attachment_id_raw; // Ensure integer

                // Update currently processing ID
                $progress['current_id'] = $attachment_id;
                update_option( self::BULK_OPTION_PROGRESS, $progress, false ); // Save immediately to show current

                $current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
                if ( wp_attachment_is_image($attachment_id) && empty($current_alt) ) {
                    $result = $this->generate_alt_text_for_image( $attachment_id, false );

                    if ($result !== true) {
                        // Error: do not remove from queue, do not increment processed, schedule retry after 61 seconds and exit
                        $error_string = is_string($result) ? $result : 'Unknown error occurred';
                        $batch_errors[$attachment_id] = $error_string;

                        // Schedule retry after 61 seconds and exit
                        $plugin_options = get_option( 'ai_seo_tools_options', [] );
                        $delay = 61;
                        wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
                        // Save progress and exit early
                        $progress['errors'] = array_slice(array_merge($progress['errors'] ?? [], $batch_errors), -20);
                        $progress['current_id'] = null;
                        update_option( self::BULK_OPTION_PROGRESS, $progress, false );
                        return;
                    } else {
                        // Success
                        $batch_success_ids[] = $attachment_id;
                        $processed_in_batch++;
                    }
                } else {
                    // Not an image or already has alt
                    $processed_in_batch++;
                }
            }

            // Update the list of pending IDs by removing the processed batch
            $remaining_ids = array_slice( $pending_ids, $processed_in_batch );
            update_option( self::BULK_OPTION_PENDING, $remaining_ids, false );

            // Update progress
            $progress['processed'] += $processed_in_batch;
            $progress['last_run'] = time();
            $progress['current_id'] = null; // Clear current ID after batch

            // Add recent successes, keeping only the last 5
            $progress['recent_success_ids'] = array_slice(
                array_merge($batch_success_ids, $progress['recent_success_ids']),
                0,
                5 // Keep last 5 successful IDs
            );

            if (!empty($batch_errors)) {
                // Merge new errors with existing ones, possibly limiting total stored errors
                $progress['errors'] = array_slice(array_merge($progress['errors'] ?? [], $batch_errors), -20); // Keep last 20 errors
            }

            // Check if done
            if ( empty( $remaining_ids ) ) {
                $progress['status'] = 'complete';
                $progress['current_id'] = null;
                delete_option( self::BULK_OPTION_PENDING ); // Clean up pending IDs option
                wp_clear_scheduled_hook( self::CRON_HOOK ); // Unschedule the cron job
            } else {
                // Schedule the next batch with a user-defined delay
                $plugin_options = get_option( 'ai_seo_tools_options', [] );
                $delay = $plugin_options['bulk_processing_delay'] ?? 20; // Get delay from settings, default 20
                $delay = max(1, (int)$delay); // Ensure minimum 1 second
                wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
            }

            // Save the updated progress
            update_option( self::BULK_OPTION_PROGRESS, $progress, false );
        }

        /**
         * Helper function to get IDs of image attachments without alt text.
         *
         * @return int[] Array of attachment IDs.
         */
        private function get_images_without_alt(): array {
            $cache_key = 'ai_seo_tools_images_without_alt';
            $cached = wp_cache_get( $cache_key, 'ai-seo-tools' );
            if ( false !== $cached ) {
                return $cached;
            }

            $ids = get_posts([
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Caching is used, and this is required for WordPress.org compliance.
                    [
                        'relation' => 'OR',
                        [
                            'key'     => '_wp_attachment_image_alt',
                            'compare' => 'NOT EXISTS',
                        ],
                        [
                            'key'     => '_wp_attachment_image_alt',
                            'value'   => '',
                            'compare' => '=',
                        ],
                    ],
                ],
            ]);
            $result = array_map( 'intval', $ids );
            wp_cache_set( $cache_key, $result, 'ai-seo-tools', 300 ); // Cache for 5 minutes
            return $result;
        }

        /**
         * AJAX handler to get the current alt text stats.
         */
        public function handle_ajax_get_stats(): void {
            check_ajax_referer( 'ai_seo_get_stats_nonce', 'nonce' ); // Use a specific nonce

            if ( ! current_user_can( 'manage_options' ) ) {
                 wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }

            $stats = self::get_alt_text_stats();
            wp_send_json_success( $stats );
        }

        /**
         * AJAX handler to get details (thumbnail, alt text) for specific image IDs.
         */
        public function handle_ajax_get_image_details(): void {
            check_ajax_referer( 'ai_seo_get_image_details_nonce', 'nonce' ); // Use a specific nonce

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }

            $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];

            if ( empty( $ids ) ) {
                 wp_send_json_error( [ 'message' => esc_html__( 'No image IDs provided.', 'ai-seo-tools' ) ], 400 );
            }

            $details = [];
            foreach ( $ids as $id ) {
                if ( $image = wp_get_attachment_image_src( $id, 'thumbnail' ) ) { // Get thumbnail URL
                    $alt_text = get_post_meta( $id, '_wp_attachment_image_alt', true );
                    $details[ $id ] = [
                        'thumb_url' => $image[0],
                        'alt'       => $alt_text ?? '', // Ensure alt is always a string
                    ];
                }
            }

            wp_send_json_success( $details );
        }

        /**
         * Displays an admin notice if the bulk generation process is running.
         */
        public function display_bulk_process_notice(): void {
            // Only show notice to users who can manage options
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $progress = get_option( self::BULK_OPTION_PROGRESS );

            // Check if the status is exactly 'running'
            if ( ! empty( $progress ) && isset( $progress['status'] ) && $progress['status'] === 'running' ) {
                $processed = $progress['processed'] ?? 0;
                $total = $progress['total'] ?? 0;
                $message = sprintf(
					/* translators: 1: Number of processed images, 2: Total number of images. */
                    esc_html__( 'AI SEO Tools: Bulk alt text generation is currently running in the background (%1$d / %2$d processed).', 'ai-seo-tools' ),
                    $processed,
                    $total
                );

                echo '<div class="notice notice-info is-dismissible ai-seo-bulk-notice">'; // Added ai-seo-bulk-notice class
                echo '<p>' . esc_html( $message ) . '</p>';
                echo '</div>';
            }
        }

	}
} 