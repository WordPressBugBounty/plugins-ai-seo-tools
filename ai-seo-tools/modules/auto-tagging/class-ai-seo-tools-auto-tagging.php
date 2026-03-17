<?php
/**
 * Handles automatic tagging of posts using AI.
 *
 * @package AI_SEO_Tools
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'AI_SEO_Tools_Auto_Tagging' ) ) {
    /**
     * Class AI_SEO_Tools_Auto_Tagging.
     */
    class AI_SEO_Tools_Auto_Tagging {

        // Constants for bulk processing
        private const BULK_OPTION_PENDING  = 'ai_seo_bulk_tags_pending_ids';
        private const BULK_OPTION_PROGRESS = 'ai_seo_bulk_tags_progress';
        private const CRON_HOOK           = 'ai_seo_tools_bulk_tags_cron';
        // Add constants for bulk append
        private const BULK_APPEND_PENDING   = 'ai_seo_bulk_append_tags_pending_ids';
        private const BULK_APPEND_PROGRESS  = 'ai_seo_bulk_append_tags_progress';
        private const CRON_APPEND_HOOK      = 'ai_seo_tools_bulk_append_tags_cron';
        // Add constants for bulk regenerate
        private const BULK_REGEN_PENDING    = 'ai_seo_bulk_regenerate_tags_pending_ids';
        private const BULK_REGEN_PROGRESS   = 'ai_seo_bulk_regenerate_tags_progress';
        private const CRON_REGEN_HOOK       = 'ai_seo_tools_bulk_regenerate_tags_cron';
        private const BATCH_SIZE          = 1; // Process 1 post per batch to respect rate limits

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
            // Add a column on the Posts list table.
            add_filter( 'manage_posts_columns', array( $this, 'add_tagging_column' ) );
            add_action( 'manage_posts_custom_column', array( $this, 'display_tagging_column' ), 10, 2 );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_list_scripts' ) );

            // AJAX handler for single post tagging.
            add_action( 'wp_ajax_ai_seo_generate_single_tags', array( $this, 'handle_ajax_generate_single_tags' ) );
            // AJAX handler to regenerate (replace) existing tags.
            add_action( 'wp_ajax_ai_seo_regenerate_single_tags', array( $this, 'handle_ajax_regenerate_single_tags' ) );

            // Bulk tagging AJAX hooks.
            add_action( 'wp_ajax_ai_seo_start_bulk_tags', array( $this, 'handle_ajax_start_bulk_tags' ) );
            add_action( 'wp_ajax_ai_seo_get_bulk_tags_status', array( $this, 'handle_ajax_get_bulk_tags_status' ) );
            add_action( 'wp_ajax_ai_seo_stop_bulk_tags', array( $this, 'handle_ajax_stop_bulk_tags' ) );
            add_action( self::CRON_HOOK, array( $this, 'process_bulk_tags_batch' ) );
            add_action( 'admin_notices', array( $this, 'display_bulk_process_notice' ) );

            // Bulk Append Tags Hooks
            add_action( 'wp_ajax_ai_seo_start_bulk_append_tags', array( $this, 'handle_ajax_start_bulk_append_tags' ) );
            add_action( 'wp_ajax_ai_seo_get_bulk_append_tags_status', array( $this, 'handle_ajax_get_bulk_append_tags_status' ) );
            add_action( 'wp_ajax_ai_seo_stop_bulk_append_tags', array( $this, 'handle_ajax_stop_bulk_append_tags' ) );
            add_action( self::CRON_APPEND_HOOK, array( $this, 'process_bulk_append_tags_batch' ) );
            // Bulk Regenerate Tags Hooks
            add_action( 'wp_ajax_ai_seo_start_bulk_regenerate_tags', array( $this, 'handle_ajax_start_bulk_regenerate_tags' ) );
            add_action( 'wp_ajax_ai_seo_get_bulk_regenerate_tags_status', array( $this, 'handle_ajax_get_bulk_regenerate_tags_status' ) );
            add_action( 'wp_ajax_ai_seo_stop_bulk_regenerate_tags', array( $this, 'handle_ajax_stop_bulk_regenerate_tags' ) );
            add_action( self::CRON_REGEN_HOOK, array( $this, 'process_bulk_regenerate_tags_batch' ) );

            // AJAX handler for clearing all tags on a single post.
            add_action( 'wp_ajax_ai_seo_clear_single_tags', array( $this, 'handle_ajax_clear_single_tags' ) );
            // AJAX handler to clear all tags for all posts.
            add_action( 'wp_ajax_ai_seo_clear_all_tags', array( $this, 'handle_ajax_clear_all_tags' ) );

            // Bulk Action: Clear Tags
            add_filter( 'bulk_actions-edit-post', array( $this, 'register_bulk_clear_tags_action' ), 10, 1 );
            add_filter( 'handle_bulk_actions-edit-post', array( $this, 'handle_bulk_clear_tags_bulk_action' ), 10, 3 );
            add_action( 'admin_notices', array( $this, 'bulk_clear_tags_admin_notice' ) );
        }

        /**
         * Enqueue scripts for the Posts list screen.
         *
         * @param string $hook Current admin page hook.
         */
        public function enqueue_post_list_scripts( string $hook ): void {
            // Only load on the Posts list view.
            if ( 'edit.php' !== $hook ) {
                return;
            }
            global $post_type;
            if ( 'post' !== $post_type ) {
                return;
            }

            wp_enqueue_script(
                'ai-seo-post-list',
                AI_SEO_TOOLS_URL . 'assets/js/ai-seo-post-list.js',
                array( 'jquery' ),
                AI_SEO_TOOLS_VERSION,
                true
            );

            wp_localize_script( 'ai-seo-post-list', 'aiSeoPostList', array_merge( array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'nonce'             => wp_create_nonce( 'ai_seo_generate_single_tags_nonce' ),
                'regenerate_nonce'  => wp_create_nonce( 'ai_seo_regenerate_single_tags_nonce' ),
                'clear_nonce'       => wp_create_nonce( 'ai_seo_clear_single_tags_nonce' ),
                'generating_text'   => esc_html__( 'Generating...', 'ai-seo-tools' ),
                'appending_text'    => esc_html__( 'Appending...', 'ai-seo-tools' ),
                'regenerating_text' => esc_html__( 'Regenerating...', 'ai-seo-tools' ),
                'clearing_text'     => esc_html__( 'Clearing...', 'ai-seo-tools' ),
                'append_text'       => esc_html__( 'Append Tags', 'ai-seo-tools' ),
                'regenerate_text'   => esc_html__( 'Regenerate Tags', 'ai-seo-tools' ),
                'clear_text'        => esc_html__( 'Clear Tags', 'ai-seo-tools' ),
                'error_text'        => esc_html__( 'Error', 'ai-seo-tools' ),
            ), array()) );
        }

        /**
         * Add a custom column for AI Tagging in the Posts list.
         *
         * @param array $columns Existing columns.
         * @return array Modified columns.
         */
        public function add_tagging_column( array $columns ): array {
            $new_columns = array();
            foreach ( $columns as $key => $title ) {
                if ( 'date' === $key ) {
                    $new_columns['ai_seo_tags'] = esc_html__( 'AI Tags', 'ai-seo-tools' );
                }
                $new_columns[ $key ] = $title;
            }
            if ( ! isset( $new_columns['ai_seo_tags'] ) ) {
                $new_columns['ai_seo_tags'] = esc_html__( 'AI Tags', 'ai-seo-tools' );
            }
            return $new_columns;
        }

        /**
         * Displays content for the AI Tags column.
         *
         * @param string $column_name The name of the column being displayed.
         * @param int    $post_id The ID of the current post.
         */
        public function display_tagging_column( string $column_name, int $post_id ): void {
            if ( 'ai_seo_tags' !== $column_name ) {
                return;
            }

            // Wrapper for status UI
            echo '<div class="ai-seo-tag-status" data-post-id="' . esc_attr( $post_id ) . '">';

            $tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

            if ( ! empty( $tags ) ) {
                // Show existing tags
                echo '<span>' . esc_html( implode( ', ', $tags ) ) . '</span> ';
                // Append button
                printf(
                    '<button type="button" class="button button-secondary button-small ai-seo-append-tags-button" data-post-id="%d">%s</button>',
                    esc_attr( $post_id ),
                    esc_html__( 'Append Tags', 'ai-seo-tools' )
                );
                // Regenerate button
                printf(
                    ' <button type="button" class="button button-secondary button-small ai-seo-regenerate-tags-button" data-post-id="%d">%s</button>',
                    esc_attr( $post_id ),
                    esc_html__( 'Regenerate Tags', 'ai-seo-tools' )
                );
                // Clear button
                printf(
                    ' <button type="button" class="button button-secondary button-small ai-seo-clear-tags-button" data-post-id="%d">%s</button>',
                    esc_attr( $post_id ),
                    esc_html__( 'Clear Tags', 'ai-seo-tools' )
                );
            } else {
                // Button to generate tags if none exist
                printf(
                    '<button type="button" class="button button-secondary button-small ai-seo-generate-tags-button" data-post-id="%d">%s</button>',
                    esc_attr( $post_id ),
                    esc_html__( 'Generate Tags', 'ai-seo-tools' )
                );
            }

            // Feedback elements (common)
            echo ' <span class="ai-seo-tags-result ai-seo-status-inline"></span>';
            echo '<span class="spinner ai-seo-spinner-inline"></span>';

            echo '</div>';
        }

        /**
         * Handle AJAX for single post tag generation.
         */
        public function handle_ajax_generate_single_tags(): void {
            check_ajax_referer( 'ai_seo_generate_single_tags_nonce', 'nonce' );

            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
            }

            $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
            if ( ! $post_id || ! get_post( $post_id ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Invalid post ID.', 'ai-seo-tools' ) ), 400 );
            }

            $result = $this->generate_tags_for_post( $post_id, true );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
            }

            wp_send_json_success( array( 'tags' => $result ) );
        }

        /**
         * Generate tags for a given post using OpenAI.
         *
         * @param int  $post_id The post ID.
         * @param bool $is_ajax Whether called via AJAX.
         * @param bool $replace_existing Whether to replace existing tags (true) or append (false).
         * @return string|bool|WP_Error Comma-separated tags on AJAX success, true on non-AJAX success, or WP_Error/string on failure.
         */
        public function generate_tags_for_post( int $post_id, bool $is_ajax = false, bool $replace_existing = false ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                $msg = esc_html__( 'Invalid post.', 'ai-seo-tools' );
                return $is_ajax ? new WP_Error( 'invalid_post', $msg ) : $msg;
            }

            // Retrieve settings.
            $options   = get_option( $this->option_name, array() );
            $api_key   = $options['openai_api_key'] ?? '';
            $max_tags  = $options['auto_tagging_max_tags'] ?? 5;
            $min_score = $options['auto_tagging_confidence_threshold'] ?? 0.75;
            $stop_list = $options['auto_tagging_stop_words'] ?? '';

            if ( empty( $api_key ) ) {
                $msg = esc_html__( 'OpenAI API key is missing.', 'ai-seo-tools' );
                return $is_ajax ? new WP_Error( 'missing_api_key', $msg ) : $msg;
            }

            $title   = $post->post_title;
            // Strip all HTML tags safely
            $content = wp_strip_all_tags( $post->post_content );

            // Build prompt.
            $prompt = sprintf(
                'Generate up to %d relevant tags for this WordPress post. Respond with a comma-separated list of tags based solely on the title and content. ',
                absint( $max_tags )
            );
            if ( $stop_list ) {
                $prompt .= 'Exclude these stop words: ' . esc_html( $stop_list ) . '. ';
            }
            $prompt .= 'Title: "' . esc_html( $title ) . '". Content: "' . esc_html( $content ) . '".';

            $payload = array(
                'model'    => $options['openai_model'] ?? AI_SEO_Tools_Settings::DEFAULT_MODEL,
                'messages' => array(
                    array(
                        'role'    => 'user',
                        'content' => $prompt,
                    ),
                ),
                'max_tokens' => 60,
            );

            $response = wp_remote_post(
                'https://api.openai.com/v1/chat/completions',
                array(
                    'headers'     => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'        => wp_json_encode( $payload ),
                    'timeout'     => 60,
                    'data_format' => 'body',
                )
            );

            if ( is_wp_error( $response ) ) {
                return $is_ajax
                    ? $response
                    : sprintf(
                        /* translators: %s is the network error message returned by WP_Error. */
                        esc_html__( 'Network error: %s', 'ai-seo-tools' ),
                        $response->get_error_message()
                    );
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
                $error_msg = $body['error']['message'] ?? esc_html__( 'API request failed.', 'ai-seo-tools' );
                return $is_ajax
                    ? new WP_Error( 'api_error', $error_msg, array( 'status' => $code ) )
                    : sprintf(
                        /* translators: 1: HTTP status code returned by the API; 2: detailed error message. */
                        esc_html__( 'API error (%1$d): %2$s', 'ai-seo-tools' ),
                        $code,
                        $error_msg
                    );
            }

            $raw = sanitize_text_field( trim( $body['choices'][0]['message']['content'] ) );
            $raw = trim( $raw, '., ' );
            $tags = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

            if ( empty( $tags ) ) {
                $msg = esc_html__( 'No tags generated.', 'ai-seo-tools' );
                return $is_ajax ? new WP_Error( 'empty_tags', $msg ) : $msg;
            }

            if ( $replace_existing ) {
                // Regenerate: replace all tags with up to $max_tags new tags
                $tags = array_slice( $tags, 0, $max_tags );
                wp_set_post_tags( $post_id, $tags, false );
            } else {
                // Append: preserve existing tags and add up to $max_tags new unique tags
                $existing_tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
                $existing_lc   = array_map( function( $t ) {
                    return mb_strtolower( trim( $t ) );
                }, $existing_tags );
                $to_add        = array();
                foreach ( $tags as $tag ) {
                    $tag_trim = trim( $tag );
                    $tag_lc   = mb_strtolower( $tag_trim );
                    if ( ! in_array( $tag_lc, $existing_lc, true ) ) {
                        $to_add[]      = $tag_trim;
                        $existing_lc[] = $tag_lc;
                    }
                    if ( count( $to_add ) >= $max_tags ) {
                        break;
                    }
                }
                if ( ! empty( $to_add ) ) {
                    // Append new tags to existing tags
                    wp_set_post_tags( $post_id, $to_add, true );
                }
            }

            if ( $is_ajax ) {
                // Return the current tags list to reflect appended or regenerated tags
                $current_tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
                return implode( ', ', $current_tags );
            }
            return true;
        }

        /**
         * Get statistics for post tagging.
         *
         * @return array{total:int,with_tags:int,without_tags:int,percent_with_tags:float,percent_without_tags:float}
         */
        public static function get_tagging_stats(): array {
            $cache_key = 'ai_seo_tools_tags_stats';
            $cached    = wp_cache_get( $cache_key, 'ai-seo-tools' );
            if ( false !== $cached ) {
                return $cached;
            }

            $all = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );
            $total = count( $all );
            $with  = 0;
            foreach ( $all as $pid ) {
                if ( ! empty( wp_get_post_tags( $pid ) ) ) {
                    $with++;
                }
            }
            $without = $total - $with;
            $percent_with  = $total > 0 ? ( $with / $total ) * 100 : 0;
            $percent_without = 100 - $percent_with;

            $stats = array(
                'total'                => $total,
                'with_tags'            => $with,
                'without_tags'         => $without,
                'percent_with_tags'    => $percent_with,
                'percent_without_tags' => $percent_without,
            );
            wp_cache_set( $cache_key, $stats, 'ai-seo-tools', 300 );
            return $stats;
        }

        /**
         * Get IDs of posts without tags.
         *
         * @return int[]
         */
        private function get_posts_without_tags(): array {
            $all = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );
            $pending = array();
            foreach ( $all as $pid ) {
                if ( empty( wp_get_post_tags( $pid ) ) ) {
                    $pending[] = $pid;
                }
            }
            return $pending;
        }

        /**
         * AJAX handler to start bulk tagging.
         */
        public function handle_ajax_start_bulk_tags(): void {
            check_ajax_referer( 'ai_seo_bulk_tags_start_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
            }

            $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
            // Clear existing cron jobs.
            wp_clear_scheduled_hook( self::CRON_HOOK );

            $pending_ids = $this->get_posts_without_tags();
            $total_missing = count( $pending_ids );
            if ( $limit > 0 && $limit < $total_missing ) {
                $pending_ids = array_slice( $pending_ids, 0, $limit );
                $total_to_process = $limit;
            } else {
                $total_to_process = $total_missing;
            }

            if ( empty( $pending_ids ) ) {
                update_option( self::BULK_OPTION_PROGRESS, array( 'status' => 'complete', 'total' => 0, 'processed' => 0 ), 'no' );
                delete_option( self::BULK_OPTION_PENDING );
                wp_send_json_success( array( 'message' => esc_html__( 'No posts found requiring tags.', 'ai-seo-tools' ), 'status' => 'complete' ) );
            }

            update_option( self::BULK_OPTION_PENDING, $pending_ids, 'no' );
            update_option( self::BULK_OPTION_PROGRESS, array(
                'status'    => 'running',
                'total'     => $total_to_process,
                'processed' => 0,
                'last_run'  => 0,
                'errors'    => array(),
            ), 'no' );

            // Schedule first batch.
            $options = get_option( $this->option_name, array() );
            $delay = $options['bulk_processing_delay'] ?? 20;
            wp_schedule_single_event( time() + $delay, self::CRON_HOOK );

            wp_send_json_success( array(
                'message'   => esc_html__( 'Bulk tagging process started.', 'ai-seo-tools' ),
                'status'    => 'running',
                'total'     => $total_to_process,
                'processed' => 0,
            ) );
        }

        /**
         * AJAX handler to get bulk tagging status.
         */
        public function handle_ajax_get_bulk_tags_status(): void {
            check_ajax_referer( 'ai_seo_bulk_tags_status_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
            }
            $progress = get_option( self::BULK_OPTION_PROGRESS, array( 'status' => 'idle', 'total' => 0, 'processed' => 0, 'errors' => array() ) );
            wp_send_json_success( $progress );
        }

        /**
         * AJAX handler to stop bulk tagging.
         */
        public function handle_ajax_stop_bulk_tags(): void {
            check_ajax_referer( 'ai_seo_bulk_tags_stop_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
            }
            wp_clear_scheduled_hook( self::CRON_HOOK );
            delete_option( self::BULK_OPTION_PENDING );
            $progress = get_option( self::BULK_OPTION_PROGRESS, array() );
            $progress['status'] = 'stopped';
            update_option( self::BULK_OPTION_PROGRESS, $progress, false );
            wp_send_json_success( array( 'message' => esc_html__( 'Bulk tagging stopped.', 'ai-seo-tools' ), 'status' => 'stopped' ) );
        }

        /**
         * Process a batch of posts for tagging via WP Cron.
         */
        public function process_bulk_tags_batch(): void {
            $pending_ids = get_option( self::BULK_OPTION_PENDING );
            $progress    = get_option( self::BULK_OPTION_PROGRESS, array() );

            if ( empty( $pending_ids ) || ! is_array( $pending_ids ) || ( $progress['status'] ?? '' ) !== 'running' ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
                $progress['status']     = 'error';
                $progress['last_error'] = 'Inconsistent state or no pending IDs.';
                update_option( self::BULK_OPTION_PROGRESS, $progress, false );
                return;
            }

            $batch_ids = array_slice( $pending_ids, 0, self::BATCH_SIZE );
            $processed_in_batch = 0;
            $batch_errors       = array();
            $batch_success_ids  = array();
            $batch_success_tags = [];

            // Run tagging for each post and capture the generated tag strings.
            foreach ( $batch_ids as $pid_raw ) {
                $pid = intval( $pid_raw );
                // Run as AJAX to get comma-separated tags; replace_existing = false for initial tagging
                $res = $this->generate_tags_for_post( $pid, true, false );
                if ( ! is_wp_error( $res ) ) {
                    $processed_in_batch++;
                    $batch_success_ids[] = $pid;
                    // Store returned tag list
                    $batch_success_tags[ $pid ] = is_string( $res ) ? $res : '';
                } else {
                    $batch_errors[ $pid ] = $res->get_error_message();
                }
            }

            // Update pending list.
            $remaining = array_slice( $pending_ids, self::BATCH_SIZE );
            update_option( self::BULK_OPTION_PENDING, $remaining, false );

            // Update progress and include recent generated tags.
            $progress['processed'] = ( $progress['processed'] ?? 0 ) + $processed_in_batch;
            $progress['last_run']  = time();
            $progress['errors']    = array_merge( $progress['errors'] ?? [], $batch_errors );
            $progress['recent_success_ids']  = $batch_success_ids;
            $progress['recent_success_tags'] = $batch_success_tags;

            if ( empty( $remaining ) ) {
                $progress['status'] = 'complete';
                delete_option( self::BULK_OPTION_PENDING );
            } else {
                // Schedule next batch.
                $options = get_option( $this->option_name, array() );
                $delay   = $options['bulk_processing_delay'] ?? 20;
                wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
            }

            update_option( self::BULK_OPTION_PROGRESS, $progress, false );
        }

        /**
         * Display an admin notice about bulk tagging status on relevant pages.
         */
        public function display_bulk_process_notice(): void {
            $notices = [];
            $progress_tag = get_option( self::BULK_OPTION_PROGRESS, [ 'status' => 'idle' ] );
            $progress_append = get_option( self::BULK_APPEND_PROGRESS, [ 'status' => 'idle' ] );
            $progress_regen = get_option( self::BULK_REGEN_PROGRESS, [ 'status' => 'idle' ] );

            if ( isset( $progress_tag['status'] ) && 'running' === $progress_tag['status'] ) {
                $processed = $progress_tag['processed'] ?? 0;
                $total = $progress_tag['total'] ?? 0;
                $notices[] = sprintf(
                    /* translators: 1: processed, 2: total */
                    esc_html__( 'AI SEO Tools: Bulk auto-tagging is running in the background (%1$d / %2$d processed).', 'ai-seo-tools' ),
                    $processed,
                    $total
                );
            }
            if ( isset( $progress_append['status'] ) && 'running' === $progress_append['status'] ) {
                $processed = $progress_append['processed'] ?? 0;
                $total = $progress_append['total'] ?? 0;
                $notices[] = sprintf(
                    /* translators: 1: processed, 2: total */
                    esc_html__( 'AI SEO Tools: Bulk append tags is running in the background (%1$d / %2$d processed).', 'ai-seo-tools' ),
                    $processed,
                    $total
                );
            }
            if ( isset( $progress_regen['status'] ) && 'running' === $progress_regen['status'] ) {
                $processed = $progress_regen['processed'] ?? 0;
                $total = $progress_regen['total'] ?? 0;
                $notices[] = sprintf(
                    /* translators: 1: processed, 2: total */
                    esc_html__( 'AI SEO Tools: Bulk regenerate tags is running in the background (%1$d / %2$d processed).', 'ai-seo-tools' ),
                    $processed,
                    $total
                );
            }
            $settings_url = admin_url( 'admin.php?page=ai-seo-tools-settings&tab=auto_tagging' );
            foreach ( $notices as $notice ) {
                echo '<div class="notice notice-info is-dismissible ai-seo-bulk-notice"><p>'
                    . esc_html( $notice ) . ' '
                    . '<a href="' . esc_url( $settings_url ) . '">'
                    /* translators: Link text for Auto-Tagging settings page in bulk process notices. */
                    . esc_html__( 'View Auto-Tagging Settings', 'ai-seo-tools' )
                    . '</a>'
                . '</p></div>';
            }
        }

        /**
         * AJAX handler to regenerate (replace) tags for a single post.
         */
        public function handle_ajax_regenerate_single_tags(): void {
            check_ajax_referer( 'ai_seo_regenerate_single_tags_nonce', 'nonce' );

            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
            }

            $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
            if ( ! $post_id || ! get_post( $post_id ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Invalid post ID.', 'ai-seo-tools' ) ), 400 );
            }

            $result = $this->generate_tags_for_post( $post_id, true, true ); // replace existing tags

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
            }

            wp_send_json_success( array( 'tags' => $result ) );
        }

        /**
         * Retrieves IDs of posts with tags.
         *
         * @return int[]
         */
        private function get_posts_with_tags(): array {
            $all = get_posts([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            $with = [];
            foreach ( $all as $pid ) {
                if ( ! empty( wp_get_post_tags( $pid ) ) ) {
                    $with[] = $pid;
                }
            }
            return $with;
        }

        /**
         * AJAX handler to start bulk append tags.
         */
        public function handle_ajax_start_bulk_append_tags(): void {
            check_ajax_referer( 'ai_seo_bulk_append_tags_start_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }
            $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
            wp_clear_scheduled_hook( self::CRON_APPEND_HOOK );
            $pending_ids = $this->get_posts_with_tags();
            $total = count( $pending_ids );
            if ( $limit > 0 && $limit < $total ) {
                $pending_ids = array_slice( $pending_ids, 0, $limit );
                $total = $limit;
            }
            if ( empty( $pending_ids ) ) {
                update_option( self::BULK_APPEND_PROGRESS, [ 'status'=>'complete','total'=>0,'processed'=>0 ], 'no' );
                delete_option( self::BULK_APPEND_PENDING );
                wp_send_json_success( [ 'message'=>esc_html__( 'No posts to append tags.', 'ai-seo-tools' ), 'status'=>'complete' ] );
            }
            update_option( self::BULK_APPEND_PENDING, $pending_ids, 'no' );
            update_option( self::BULK_APPEND_PROGRESS, [ 'status'=>'running','total'=>$total,'processed'=>0,'last_run'=>0,'errors'=>[] ], 'no' );
            $delay = get_option( $this->option_name )['bulk_processing_delay'] ?? 20;
            wp_schedule_single_event( time() + $delay, self::CRON_APPEND_HOOK );
            wp_send_json_success( [ 'message'=>esc_html__( 'Bulk append process started.', 'ai-seo-tools' ), 'status'=>'running','total'=>$total,'processed'=>0 ] );
        }

        /**
         * AJAX handler to get bulk append status.
         */
        public function handle_ajax_get_bulk_append_tags_status(): void {
            check_ajax_referer( 'ai_seo_bulk_append_tags_status_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }
            $progress = get_option( self::BULK_APPEND_PROGRESS, [ 'status'=>'idle','total'=>0,'processed'=>0,'errors'=>[] ] );
            wp_send_json_success( $progress );
        }

        /**
         * AJAX handler to stop bulk append.
         */
        public function handle_ajax_stop_bulk_append_tags(): void {
            check_ajax_referer( 'ai_seo_bulk_append_tags_stop_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }
            wp_clear_scheduled_hook( self::CRON_APPEND_HOOK );
            delete_option( self::BULK_APPEND_PENDING );
            $progress = get_option( self::BULK_APPEND_PROGRESS, [] );
            $progress['status'] = 'stopped';
            update_option( self::BULK_APPEND_PROGRESS, $progress, false );
            wp_send_json_success( [ 'message'=>esc_html__( 'Bulk append stopped.', 'ai-seo-tools' ), 'status'=>'stopped' ] );
        }

        /**
         * Processes a batch for bulk append tags.
         */
        public function process_bulk_append_tags_batch(): void {
            $pending = get_option( self::BULK_APPEND_PENDING );
            $progress = get_option( self::BULK_APPEND_PROGRESS );
            if ( empty($pending) || !is_array($pending) || ($progress['status']??'')!=='running' ) {
                wp_clear_scheduled_hook( self::CRON_APPEND_HOOK );
                update_option( self::BULK_APPEND_PROGRESS, array_merge($progress,['status'=>'error','last_error'=>'Inconsistent state']), false );
                return;
            }
            $batch = array_slice($pending, 0, self::BATCH_SIZE);
            $processed = 0;
            $errors = [];
            $success_ids = [];
            $success_tags = [];
            foreach ( $batch as $id ) {
                // Run with AJAX flag to get the comma-separated tag string
                $res = $this->generate_tags_for_post( (int) $id, true, false );
                if ( ! is_wp_error( $res ) ) {
                    $processed++;
                    $success_ids[]    = $id;
                    $success_tags[ $id ] = is_string( $res ) ? $res : '';
                } else {
                    $errors[ $id ] = $res->get_error_message();
                }
            }
            $remaining = array_slice($pending,self::BATCH_SIZE);
            update_option(self::BULK_APPEND_PENDING,$remaining,false);
            $progress['processed'] = ( $progress['processed'] ?? 0 ) + $processed;
            $progress['last_run']  = time();
            $progress['errors']    = array_merge( $progress['errors'] ?? [], $errors );
            $progress['recent_success_ids']  = $success_ids;
            $progress['recent_success_tags'] = $success_tags;
            if(empty($remaining)){ $progress['status']='complete'; delete_option(self::BULK_APPEND_PENDING); }
            else{ $delay=get_option($this->option_name)['bulk_processing_delay']??20; wp_schedule_single_event(time()+$delay,self::CRON_APPEND_HOOK); }
            update_option(self::BULK_APPEND_PROGRESS,$progress,false);
        }

        /**
         * AJAX handler to start bulk regenerate tags.
         */
        public function handle_ajax_start_bulk_regenerate_tags(): void {
            check_ajax_referer( 'ai_seo_bulk_regenerate_tags_start_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }
            $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
            wp_clear_scheduled_hook( self::CRON_REGEN_HOOK );
            $pending_ids = $this->get_posts_with_tags();
            $total = count( $pending_ids );
            if ( $limit > 0 && $limit < $total ) {
                $pending_ids = array_slice( $pending_ids, 0, $limit );
                $total = $limit;
            }
            if ( empty( $pending_ids ) ) {
                update_option( self::BULK_REGEN_PROGRESS, [ 'status'=>'complete','total'=>0,'processed'=>0 ], 'no' );
                delete_option( self::BULK_REGEN_PENDING );
                wp_send_json_success( [ 'message'=>esc_html__( 'No posts to regenerate tags.', 'ai-seo-tools' ), 'status'=>'complete' ] );
            }
            update_option( self::BULK_REGEN_PENDING, $pending_ids, 'no' );
            update_option( self::BULK_REGEN_PROGRESS, [ 'status'=>'running','total'=>$total,'processed'=>0,'last_run'=>0,'errors'=>[] ], 'no' );
            $delay = get_option( $this->option_name )['bulk_processing_delay'] ?? 20;
            wp_schedule_single_event( time() + $delay, self::CRON_REGEN_HOOK );
            wp_send_json_success( [ 'message'=>esc_html__( 'Bulk regenerate process started.', 'ai-seo-tools' ), 'status'=>'running','total'=>$total,'processed'=>0 ] );
        }

        /**
         * AJAX handler to get bulk regenerate status.
         */
        public function handle_ajax_get_bulk_regenerate_tags_status(): void {
            check_ajax_referer( 'ai_seo_bulk_regenerate_tags_status_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }
            $progress = get_option( self::BULK_REGEN_PROGRESS, [ 'status'=>'idle','total'=>0,'processed'=>0,'errors'=>[] ] );
            wp_send_json_success( $progress );
        }

        /**
         * AJAX handler to stop bulk regenerate.
         */
        public function handle_ajax_stop_bulk_regenerate_tags(): void {
            check_ajax_referer( 'ai_seo_bulk_regenerate_tags_stop_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
            }
            wp_clear_scheduled_hook( self::CRON_REGEN_HOOK );
            delete_option( self::BULK_REGEN_PENDING );
            $progress = get_option( self::BULK_REGEN_PROGRESS, [] );
            $progress['status'] = 'stopped';
            update_option( self::BULK_REGEN_PROGRESS, $progress, false );
            wp_send_json_success( [ 'message'=>esc_html__( 'Bulk regenerate stopped.', 'ai-seo-tools' ), 'status'=>'stopped' ] );
        }

        /**
         * Processes a batch for bulk regenerate tags.
         */
        public function process_bulk_regenerate_tags_batch(): void {
            $pending = get_option( self::BULK_REGEN_PENDING );
            $progress = get_option( self::BULK_REGEN_PROGRESS );
            if ( empty($pending) || !is_array($pending) || ($progress['status']??'')!=='running' ) {
                wp_clear_scheduled_hook( self::CRON_REGEN_HOOK );
                update_option( self::BULK_REGEN_PROGRESS, array_merge($progress,['status'=>'error','last_error'=>'Inconsistent state']), false );
                return;
            }
            $batch = array_slice( $pending, 0, self::BATCH_SIZE );
            $processed = 0;
            $errors = [];
            $success_ids = [];
            $success_tags = [];
            foreach ( $batch as $id ) {
                // Run with AJAX flag to retrieve regenerated tag string
                $res = $this->generate_tags_for_post( (int) $id, true, true );
                if ( ! is_wp_error( $res ) ) {
                    $processed++;
                    $success_ids[]    = $id;
                    $success_tags[ $id ] = is_string( $res ) ? $res : '';
                } else {
                    $errors[ $id ] = $res->get_error_message();
                }
            }
            $remaining = array_slice($pending,self::BATCH_SIZE);
            update_option(self::BULK_REGEN_PENDING,$remaining,false);
            $progress['processed']           = ( $progress['processed'] ?? 0 ) + $processed;
            $progress['last_run']            = time();
            $progress['errors']              = array_merge( $progress['errors'] ?? [], $errors );
            $progress['recent_success_ids']  = $success_ids;
            $progress['recent_success_tags'] = $success_tags;
            if(empty($remaining)){ $progress['status']='complete'; delete_option(self::BULK_REGEN_PENDING); }
            else{ $delay=get_option($this->option_name)['bulk_processing_delay']??20; wp_schedule_single_event(time()+$delay,self::CRON_REGEN_HOOK); }
            update_option(self::BULK_REGEN_PROGRESS,$progress,false);
        }

        /**
         * AJAX handler to clear all tags for a single post.
         */
        public function handle_ajax_clear_single_tags(): void {
            check_ajax_referer( 'ai_seo_clear_single_tags_nonce', 'nonce' );
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
            }
            $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
            if ( ! $post_id || ! get_post( $post_id ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Invalid post ID.', 'ai-seo-tools' ) ), 400 );
            }
            // Remove all tags
            wp_set_post_tags( $post_id, array(), false );
            wp_send_json_success( array( 'message' => esc_html__( 'All tags cleared.', 'ai-seo-tools' ) ) );
        }

        /**
         * AJAX handler to clear all tags for all posts.
         */
        public function handle_ajax_clear_all_tags(): void {
            // Verify nonce for clearing all tags
            check_ajax_referer( 'ai_seo_clear_all_tags_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
            }

            $posts = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );

            foreach ( $posts as $pid ) {
                wp_set_post_tags( $pid, array(), false );
            }

            $count = count( $posts );
            /* translators: %d is the number of posts whose tags were cleared */
            // Plain text message without HTML markup
            $message = sprintf(
                /* translators: %d is the number of posts cleared */
                _n( '%d post tags cleared.', '%d post tags cleared.', $count, 'ai-seo-tools' ),
                $count
            );
            wp_send_json_success( array( 'message' => $message ) );
        }

        /**
         * Register a bulk action to clear all tags from selected posts.
         *
         * @param array $bulk_actions Existing bulk actions.
         * @return array Modified bulk actions.
         */
        public function register_bulk_clear_tags_action( array $bulk_actions ): array {
            $bulk_actions['ai_seo_clear_tags'] = esc_html__( 'Clear Tags', 'ai-seo-tools' );
            return $bulk_actions;
        }

        /**
         * Handle the bulk action for clearing tags.
         *
         * @param string $redirect_to URL to redirect to.
         * @param string $action      The action name.
         * @param int[]  $post_ids    Array of selected post IDs.
         * @return string Modified redirect URL.
         */
        public function handle_bulk_clear_tags_bulk_action( string $redirect_to, string $action, array $post_ids ): string {
            // Verify nonce and permissions for bulk actions
            check_admin_referer( 'bulk-posts' );
            if ( ! current_user_can( 'manage_options' ) ) {
                return $redirect_to;
            }
            if ( 'ai_seo_clear_tags' !== $action ) {
                return $redirect_to;
            }
            foreach ( $post_ids as $pid ) {
                wp_set_post_tags( intval( $pid ), array(), false );
            }
            $count = count( $post_ids );
            $redirect_to = add_query_arg( 'ai_seo_tags_cleared', $count, $redirect_to );
            return $redirect_to;
        }

        /**
         * Show an admin notice after bulk clearing tags.
         */
        public function bulk_clear_tags_admin_notice(): void {
            // Only show to users with manage_options capability
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            // Only display when the GET parameter is present and greater than zero
            if ( ! isset( $_GET['ai_seo_tags_cleared'] ) ) {
                return;
            }

            // Check if this is a WordPress admin action with proper nonce
            // For bulk actions, WordPress includes the bulk action nonce
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bulk-posts' ) ) {
                return;
            }

            $count = intval( wp_unslash( $_GET['ai_seo_tags_cleared'] ) );
            if ( $count < 1 ) {
                return;
            }

            /* translators: %d is the number of posts whose tags were cleared */
            $message = sprintf(
                /* translators: %d is the number of posts cleared */
                _n( '%d post tags cleared.', '%d post tags cleared.', $count, 'ai-seo-tools' ),
                $count
            );
            wp_send_json_success( array( 'message' => $message ) );
        }

    }
} 