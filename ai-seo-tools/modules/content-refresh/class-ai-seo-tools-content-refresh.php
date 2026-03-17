<?php
/**
 * Handles AI-powered content refresh and SEO optimization for posts.
 *
 * @package AI_SEO_Tools
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'AI_SEO_Tools_Content_Refresh' ) ) {
	/**
	 * Class AI_SEO_Tools_Content_Refresh.
	 *
	 * Summary: Provides hooks and logic for analyzing and suggesting updates to existing posts using generative AI.
	 */
	class AI_SEO_Tools_Content_Refresh {
		/**
		 * Option name where settings are stored.
		 *
		 * @var string
		 */
		private string $option_name = 'ai_seo_tools_options';

		/**
		 * Constructor.
		 * Hooks into WordPress actions for future integration.
		 *
		 * Summary: Registers hooks for Gutenberg/classic editor integration and AJAX endpoints.
		 * Parameters: None
		 * Return Value: void
		 * Exceptions/Errors: None
		 */
		public function __construct() {
			// Placeholder: Add hooks for Gutenberg/classic editor integration.
			add_action( 'add_meta_boxes', array( $this, 'register_content_refresh_metabox' ) );
			// Placeholder: Register AJAX endpoints for content analysis and suggestions.
			add_action( 'wp_ajax_ai_seo_content_refresh_analyze', array( $this, 'handle_ajax_content_refresh_analyze' ) );
			add_action( 'wp_ajax_ai_seo_content_refresh_apply', array( $this, 'handle_ajax_content_refresh_apply' ) );
		}

		/**
		 * Registers a metabox in the post editor for content refresh suggestions.
		 *
		 * @return void
		 */
		public function register_content_refresh_metabox(): void {
			add_meta_box(
				'ai-seo-content-refresh',
				esc_html__( 'AI Content Refresh & SEO Optimizer', 'ai-seo-tools' ),
				array( $this, 'render_content_refresh_metabox' ),
				'post',
				'side',
				'default'
			);
		}

		/**
		 * Renders the content refresh metabox in the post editor.
		 *
		 * @return void
		 */
		public function render_content_refresh_metabox(): void {
			echo '<p>' . esc_html__( 'This tool will soon analyze your post and suggest AI-powered updates for outdated content, keywords, and meta descriptions.', 'ai-seo-tools' ) . '</p>';
			echo '<button type="button" class="button" disabled>' . esc_html__( 'Analyze Content (Coming Soon)', 'ai-seo-tools' ) . '</button>';
			$this->display_suggestions_placeholder();
		}

		/**
		 * Displays a placeholder for AI suggestions in the metabox.
		 *
		 * @return void
		 */
		private function display_suggestions_placeholder(): void {
			echo '<div class="ai-seo-content-suggestions">';
			echo '<strong>' . esc_html__('AI Suggestions:', 'ai-seo-tools') . '</strong>';
			echo '<ul class="ai-seo-muted-list">';
			echo '<li>' . esc_html__('Suggested intro/section updates will appear here.', 'ai-seo-tools') . '</li>';
			echo '<li>' . esc_html__('Keyword recommendations will appear here.', 'ai-seo-tools') . '</li>';
			echo '<li>' . esc_html__('Meta description suggestions will appear here.', 'ai-seo-tools') . '</li>';
			echo '</ul>';
			echo '<button type="button" class="button" disabled>' . esc_html__('Apply Suggestions (Coming Soon)', 'ai-seo-tools') . '</button>';
			echo '</div>';
		}

		/**
		 * Handles AJAX request for content analysis and suggestions.
		 *
		 * Summary: Calls OpenAI API with the post content and returns AI-powered suggestions for updated content, meta, and keywords.
		 *
		 * @return void
		 */
		public function handle_ajax_content_refresh_analyze(): void {
			check_ajax_referer( 'ai_seo_content_refresh_nonce', 'nonce' );
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
			}
			$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			$post = get_post($post_id);
			if (!$post) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid post.', 'ai-seo-tools' ) ), 400 );
			}
			$options = get_option( $this->option_name );
			$api_key = $options['openai_api_key'] ?? '';
			$model = $options['openai_model'] ?? 'gpt-4o';
			$max_tokens = isset($options['content_refresh_max_tokens']) ? max(500, intval($options['content_refresh_max_tokens'])) : 10000;
			$strength = isset($options['content_refresh_rewrite_strength']) ? $options['content_refresh_rewrite_strength'] : 'maximal';
			if ( empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'OpenAI API key is missing.', 'ai-seo-tools' ) ), 400 );
			}
			$content = $post->post_content;
			if ($strength === 'minimal') {
				$prompt = "You are an expert SEO content editor. Rewrite only outdated or inaccurate sections of the following blog post. Keep the rest of the text unchanged. Respond ONLY in valid JSON with this field: {\"updated_content\": \"...\"}. Do not include any explanation or text outside the JSON.\n\nHere is the post content:\n" . $content;
			} elseif ($strength === 'medium') {
				$prompt = "You are an expert SEO content editor. Paraphrase and improve clarity throughout the following blog post, but keep the original style and structure. Update any outdated information. Respond ONLY in valid JSON with this field: {\"updated_content\": \"...\"}. Do not include any explanation or text outside the JSON.\n\nHere is the post content:\n" . $content;
			} else { // maximal
				$prompt = "You are an expert SEO content editor. Rewrite the entire blog post to make it more engaging, modern, and unique. Paraphrase and reword as much as possible, improve clarity, and update any outdated information. Use dynamic, up-to-date language and avoid copying sentences verbatim. Make the text feel freshly written for 2024. Respond ONLY in valid JSON with this field: {\"updated_content\": \"...\"}. Do not include any explanation or text outside the JSON.\n\nHere is the post content:\n" . $content . "\n\n...Return the full, improved post content as HTML (use <p>, <h2>, <ul>, <li> etc). ...";
			}
			$payload = array(
				'model' => $model,
				'messages' => array(
					array(
						'role' => 'user',
						'content' => $prompt
					)
				),
				'max_tokens' => $max_tokens
			);
			$args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 90,
				'method'  => 'POST',
				'data_format' => 'body',
			);
			$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', $args );
			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
			}
			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded_body  = json_decode( $response_body, true );
			if ( $response_code !== 200 || ! isset( $decoded_body['choices'][0]['message']['content'] ) ) {
				$api_error_message = isset($decoded_body['error']['message']) ? $decoded_body['error']['message'] : esc_html__( 'API request failed or returned unexpected data.', 'ai-seo-tools' );
				wp_send_json_error( array( 'message' => $api_error_message ), $response_code );
			}
			$ai_content = $decoded_body['choices'][0]['message']['content'];
			// Normalize quotes
			$ai_content = str_replace(['"', '"'], '"', $ai_content);
			// Try to parse JSON from the AI response
			$suggestions = array('updated_content'=>'');
			if (preg_match('/\{.*\}/s', $ai_content, $matches)) {
				$json = $matches[0];
				$parsed = json_decode($json, true);
				if (is_array($parsed) && isset($parsed['updated_content'])) {
					$suggestions['updated_content'] = $parsed['updated_content'];
				}
			}
			// Fallback: try to extract updated_content manually
			if (empty($suggestions['updated_content'])) {
				// Try to extract the value between "updated_content": " and the closing quote
				if (preg_match('/"updated_content"\s*:\s*"(.*?)"\s*[,}]/s', $ai_content, $m)) {
					$val = $m[1];
					// Unescape escaped quotes and newlines
					$val = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $val);
					$val = stripcslashes($val);
					$suggestions['updated_content'] = $val;
				} else {
					// Try to remove leading {"updated_content": if present
					$val = preg_replace('/^\s*\{\s*"updated_content"\s*:\s*"/s', '', $ai_content);
					$val = preg_replace('/"\s*\}\s*$/s', '', $val);
					$val = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $val);
					$val = stripcslashes($val);
					$suggestions['updated_content'] = $val;
				}
			}
			// Clean up
			$suggestions['updated_content'] = trim($suggestions['updated_content']);
			$suggestions['updated_content'] = wp_kses_post($suggestions['updated_content']);
			wp_send_json_success( array( 'suggestions' => $suggestions ) );
		}

		/**
		 * Handles AJAX request to apply AI suggestions to the post.
		 *
		 * Summary: Updates post_content with the AI-updated HTML, and meta if provided.
		 *
		 * @return void
		 */
		public function handle_ajax_content_refresh_apply(): void {
			check_ajax_referer( 'ai_seo_content_refresh_nonce', 'nonce' );
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ), 403 );
			}
			$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			$post = get_post($post_id);
			if (!$post) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid post.', 'ai-seo-tools' ) ), 400 );
			}
			// Unsash and sanitize inputs
			$updated_content = isset($_POST['updated_content'])
				? wp_kses_post( wp_unslash( $_POST['updated_content'] ) )
				: '';
			$meta = isset($_POST['meta'])
				? sanitize_text_field( wp_unslash( $_POST['meta'] ) )
				: '';
			// Update post_content if provided
			if ($updated_content) {
				wp_update_post([
					'ID' => $post_id,
					'post_content' => $updated_content
				]);
			}
			// Update meta description if provided
			if ($meta) {
				update_post_meta($post_id, '_aioseo_description', $meta);
			}
			wp_send_json_success();
		}
	}
} 