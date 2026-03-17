<?php
/**
 * Handles the admin settings page for AI SEO Tools.
 *
 * @package AI_SEO_Tools
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'AI_SEO_Tools_Settings' ) ) {
	/**
	 * Class AI_SEO_Tools_Settings.
	 */
	class AI_SEO_Tools_Settings {

		/**
		 * Option group name.
		 *
		 * @var string
		 */
		private string $option_group = 'ai_seo_tools_options';

		/**
		 * Option name.
		 *
		 * @var string
		 */
		private string $option_name = 'ai_seo_tools_options';

		/**
		 * Settings page slug.
		 *
		 * @var string
		 */
		private string $settings_page_slug = 'ai-seo-tools-settings';

		/**
		 * Transient key for caching models.
		 *
		 * @var string
		 */
		private const MODELS_TRANSIENT_KEY = 'ai_seo_tools_models_cache';

        /**
         * Default list of known vision models if fetching fails.
         *
         * @var array
         */
        private array $default_vision_models = [
            'gpt-4o' => 'GPT-4o (Default)',
            'gpt-4-turbo' => 'GPT-4 Turbo (Default)',
        ];

		/**
		 * Default OpenAI model (used if nothing is selected or fetch fails badly).
		 *
		 * @var string
		 */
		public const DEFAULT_MODEL = 'gpt-4o'; // Fallback if not set, prefer newer

		/**
		 * Constructor.
		 * Hooks into WordPress admin actions.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
            // Action to clear transient when settings are updated.
            add_action( 'update_option_' . $this->option_name, array( $this, 'clear_models_cache' ), 10, 0 );
            // AJAX handler for refreshing models.
            add_action( 'wp_ajax_ai_seo_refresh_models', array( $this, 'handle_ajax_refresh_models' ) );
            // Enqueue scripts for settings page.
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_scripts' ) );
            // Enqueue styles for settings page.
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_styles' ) );
		}

        /**
         * Clears the cached list of models.
         */
        public function clear_models_cache(): void {
            delete_transient( self::MODELS_TRANSIENT_KEY );
        }

		/**
		 * Adds the settings page to the WordPress admin menu.
		 */
		public function add_settings_page(): void {

            // --- SVG Icon Logic --- //
            $icon_svg_path = AI_SEO_TOOLS_PATH . 'assets/images/menu-icon.svg';
            $menu_icon_uri = 'dashicons-search'; // Default fallback icon

            if ( file_exists( $icon_svg_path ) ) {
                $svg_content = file_get_contents( $icon_svg_path );
                if ( false !== $svg_content ) {
                    // Clean SVG (optional but recommended: remove comments, unnecessary attributes)
                    // Basic cleaning: remove newlines and excessive whitespace for data URI
                    $svg_content = preg_replace('/\s+/S', " ", $svg_content);
                    $svg_content = trim($svg_content);

                    // Encode as Base64
                    $base64_svg = base64_encode( $svg_content );
                    $menu_icon_uri = 'data:image/svg+xml;base64,' . $base64_svg;
                }
            }
            // --- End SVG Icon Logic --- //

			add_menu_page(
				esc_html__( 'AI SEO Tools Settings', 'ai-seo-tools' ), // Page Title (for browser tab and H1)
				esc_html__( 'AI SEO Tools', 'ai-seo-tools' ),       // Menu Title (the text in the sidebar)
				'manage_options',                                   // Capability required to see the menu
				$this->settings_page_slug,                          // Menu Slug (unique identifier, used in URL)
				array( $this, 'render_settings_page' ),             // Function that renders the page content
				$menu_icon_uri,                                     // Icon: Base64 encoded SVG or fallback Dashicon
				76                                                  // Position (lower number = higher up, 76 is below Tools)
			);
		}

		/**
		 * Registers the settings, section, and fields.
		 */
		public function register_settings(): void {
            // phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic -- All fields are fully sanitized in sanitize_options()
			register_setting(
				$this->option_group,
				$this->option_name,
				array( $this, 'sanitize_options' ) // phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic -- All fields are fully sanitized in sanitize_options()
			);

			add_settings_section(
				'ai_seo_tools_openai_section',
				esc_html__( 'OpenAI API Settings', 'ai-seo-tools' ),
				array( $this, 'render_openai_section' ),
				$this->settings_page_slug
			);

			add_settings_field(
				'openai_api_key',
				esc_html__( 'OpenAI API Key', 'ai-seo-tools' ),
				array( $this, 'render_api_key_field' ),
				$this->settings_page_slug,
				'ai_seo_tools_openai_section'
			);

			add_settings_field(
				'openai_model',
				esc_html__( 'OpenAI Model (Vision)', 'ai-seo-tools' ),
				array( $this, 'render_model_field' ),
				$this->settings_page_slug,
				'ai_seo_tools_openai_section'
			);

            // Add language selection field for alt text (customizable)
            add_settings_field(
                'alt_text_language_custom_enable',
                esc_html__( 'Custom Alt Text Language', 'ai-seo-tools' ),
                array( $this, 'render_alt_text_language_custom_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_openai_section'
            );

            add_settings_field(
                'alt_text_custom_prompt',
                esc_html__( 'Custom Alt Text Prompt', 'ai-seo-tools' ),
                array( $this, 'render_alt_text_custom_prompt_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_openai_section'
            );

            // --- Add Modules Section --- //
            add_settings_section(
                'ai_seo_tools_modules_section',
                esc_html__( 'Modules', 'ai-seo-tools' ),
                array( $this, 'render_modules_section' ),
                $this->settings_page_slug
            );

            add_settings_field(
                'enable_alt_text_module',
                esc_html__( 'Alt Text Generator for Images', 'ai-seo-tools' ),
                array( $this, 'render_enable_alt_text_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_modules_section'
            );

            add_settings_field(
                'enable_content_refresh_module',
                esc_html__( 'Content Refresh & SEO Optimizer', 'ai-seo-tools' ),
                array( $this, 'render_enable_content_refresh_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_modules_section'
            );

            // Add Auto Tagging module toggle
            add_settings_field(
                'enable_auto_tagging_module',
                esc_html__( 'Auto Tagging for Posts', 'ai-seo-tools' ),
                array( $this, 'render_enable_auto_tagging_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_modules_section'
            );

            add_settings_field(
                'content_refresh_max_tokens',
                esc_html__( 'Max Tokens for Content Refresh', 'ai-seo-tools' ),
                array( $this, 'render_content_refresh_max_tokens_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_openai_section'
            );

            add_settings_field(
                'content_refresh_rewrite_strength',
                esc_html__( 'Rewrite Strength', 'ai-seo-tools' ),
                array( $this, 'render_content_refresh_rewrite_strength_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_openai_section'
            );

            // --- Add Bulk Processing Settings Section --- //
            add_settings_section(
                'ai_seo_tools_bulk_section',
                esc_html__( 'Alt Text Bulk Processing Settings', 'ai-seo-tools' ),
                array( $this, 'render_bulk_section' ),
                $this->settings_page_slug
            );

            add_settings_field(
                'bulk_processing_delay',
                esc_html__( 'Delay Between Images', 'ai-seo-tools' ),
                array( $this, 'render_bulk_delay_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_bulk_section'
            );

            add_settings_field(
                'image_detail_level',
                esc_html__( 'Image Detail Level', 'ai-seo-tools' ),
                array( $this, 'render_detail_level_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_bulk_section'
            );

            // --- Auto Tagging Settings Section and Fields --- //
            add_settings_section(
                'ai_seo_tools_auto_tagging_section',
                esc_html__( 'Auto Tagging Settings', 'ai-seo-tools' ),
                array( $this, 'render_auto_tagging_settings_section' ),
                $this->settings_page_slug
            );

            add_settings_field(
                'auto_tagging_max_tags',
                esc_html__( 'Max Tags per Post', 'ai-seo-tools' ),
                array( $this, 'render_auto_tagging_max_tags_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_auto_tagging_section'
            );

            add_settings_field(
                'auto_tagging_confidence_threshold',
                esc_html__( 'Confidence Threshold', 'ai-seo-tools' ),
                array( $this, 'render_auto_tagging_confidence_threshold_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_auto_tagging_section'
            );

            add_settings_field(
                'auto_tagging_stop_words',
                esc_html__( 'Stop-Words List', 'ai-seo-tools' ),
                array( $this, 'render_auto_tagging_stop_words_field' ),
                $this->settings_page_slug,
                'ai_seo_tools_auto_tagging_section'
            );
            // --- End Bulk Processing Settings Section --- //
		}

		/**
		 * Sanitizes the option values before saving.
		 *
		 * @param array $input The input options.
		 * @return array The sanitized options.
		 */
		public function sanitize_options( array $input ): array {
			// Nonce verification for settings form
			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! $nonce || ! wp_verify_nonce( $nonce, $this->option_group . '-options' ) ) {
				wp_die( esc_html__( 'Security check failed. Please try again.', 'ai-seo-tools' ) );
			}

			$sanitized_input = array();
			$options = get_option( $this->option_name ); // Get existing options

			// Sanitize API Key.
            $new_api_key = isset( $input['openai_api_key'] ) ? sanitize_text_field( $input['openai_api_key'] ) : null;
            $old_api_key = $options['openai_api_key'] ?? null;

            if ( $new_api_key !== null ) {
                $sanitized_input['openai_api_key'] = $new_api_key;
                // If key changes, clear the model cache.
                if ($new_api_key !== $old_api_key) {
                    $this->clear_models_cache();
                }
            } elseif ( $old_api_key !== null) {
                $sanitized_input['openai_api_key'] = $old_api_key; // Keep existing if not set in input
            }

            // Fetch available models based on the *potentially new* API key for validation.
            $current_api_key = $sanitized_input['openai_api_key'] ?? null;
            $available_models = $this->get_available_models( $current_api_key ); // Pass key to fetcher

            // Sanitize Model Selection.
			if ( isset( $input['openai_model'] ) && array_key_exists( $input['openai_model'], $available_models ) ) {
				$sanitized_input['openai_model'] = $input['openai_model'];
			} else {
				// Fallback logic: Keep old if valid, otherwise use default.
				$old_model = $options['openai_model'] ?? self::DEFAULT_MODEL;
                if ( array_key_exists( $old_model, $available_models ) ) {
                    $sanitized_input['openai_model'] = $old_model;
                } else {
                    $sanitized_input['openai_model'] = self::DEFAULT_MODEL;
                    // If default isn't in the available list either, pick the first available one?
                    if (!empty($available_models) && !array_key_exists(self::DEFAULT_MODEL, $available_models)) {
                         // Use array_key_first for PHP 7.3+
                        if (function_exists('array_key_first')) {
                            $sanitized_input['openai_model'] = array_key_first($available_models);
                        } else {
                            // Fallback for older PHP: reset pointer and get key
                             reset($available_models);
                             $sanitized_input['openai_model'] = key($available_models);
                        }
                    }
                }
			}

            // Sanitize Module Enable/Disable Checkboxes
            $sanitized_input['enable_alt_text_module'] = ( isset( $input['enable_alt_text_module'] ) && $input['enable_alt_text_module'] === '1' ) ? '1' : '0';
            $sanitized_input['enable_content_refresh_module'] = ( isset( $input['enable_content_refresh_module'] ) && $input['enable_content_refresh_module'] === '1' ) ? '1' : '0';
            // Sanitize Auto Tagging module toggle
            $sanitized_input['enable_auto_tagging_module'] = ( isset( $input['enable_auto_tagging_module'] ) && $input['enable_auto_tagging_module'] === '1' ) ? '1' : '0';

            // Sanitize Bulk Processing Delay
            $sanitized_input['bulk_processing_delay'] = isset( $input['bulk_processing_delay'] ) ? absint( $input['bulk_processing_delay'] ) : 20; // Default 20 seconds
            // Ensure minimum delay (e.g., 1 second) to prevent issues
            if ($sanitized_input['bulk_processing_delay'] < 1) {
                 $sanitized_input['bulk_processing_delay'] = 1;
            }

            // Sanitize Detail Level
            $valid_details = ['low', 'high']; // Add 'auto'? OpenAI default is auto, but low/high give more control.
            $sanitized_input['image_detail_level'] = isset( $input['image_detail_level'] ) && in_array( $input['image_detail_level'], $valid_details, true ) ? sanitize_key($input['image_detail_level']) : 'low'; // Default 'low'

            // Sanitize custom alt text language
            $sanitized_input['alt_text_language_custom_enable'] = !empty($input['alt_text_language_custom_enable']) ? '1' : '';
            $sanitized_input['alt_text_language_custom'] = isset($input['alt_text_language_custom']) ? sanitize_text_field($input['alt_text_language_custom']) : '';

            // Sanitize custom alt text prompt enable
            $sanitized_input['alt_text_custom_prompt_enable'] = !empty($input['alt_text_custom_prompt_enable']) ? '1' : '';
            // Sanitize custom alt text prompt
            $sanitized_input['alt_text_custom_prompt'] = isset($input['alt_text_custom_prompt']) ? trim(wp_kses_post($input['alt_text_custom_prompt'])) : '';

            // Sanitize content refresh max tokens
            $max_tokens = isset($input['content_refresh_max_tokens']) ? intval($input['content_refresh_max_tokens']) : 10000;
            $max_tokens = max(500, $max_tokens);
            $sanitized_input['content_refresh_max_tokens'] = $max_tokens;

            // Sanitize content refresh rewrite strength
            $allowed_strengths = ['minimal', 'medium', 'maximal'];
            $strength = isset($input['content_refresh_rewrite_strength']) && in_array($input['content_refresh_rewrite_strength'], $allowed_strengths, true)
                ? $input['content_refresh_rewrite_strength'] : 'maximal';
            $sanitized_input['content_refresh_rewrite_strength'] = $strength;

            // Sanitize Auto Tagging Settings
            $max_tags = isset( $input['auto_tagging_max_tags'] ) ? absint( $input['auto_tagging_max_tags'] ) : 5;
            $max_tags = max( 1, min( 20, $max_tags ) );
            $sanitized_input['auto_tagging_max_tags'] = $max_tags;

            $threshold = isset( $input['auto_tagging_confidence_threshold'] ) ? floatval( $input['auto_tagging_confidence_threshold'] ) : 0.75;
            $threshold = max( 0, min( 1, $threshold ) );
            $sanitized_input['auto_tagging_confidence_threshold'] = $threshold;

            $sanitized_input['auto_tagging_stop_words'] = isset( $input['auto_tagging_stop_words'] ) ? sanitize_text_field( $input['auto_tagging_stop_words'] ) : '';

			return $sanitized_input;
		}

        /**
         * Fetches the list of models from OpenAI API.
         *
         * @param string|null $api_key The API key to use.
         * @return array|WP_Error Array of model_id => label on success, WP_Error on failure.
         */
        private function fetch_openai_models(?string $api_key)
        {
            if ( empty( $api_key ) ) {
                return new WP_Error( 'missing_key', esc_html__( 'API key is required to fetch models.', 'ai-seo-tools' ) );
            }

            $api_endpoint = 'https://api.openai.com/v1/models';
            $args = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'timeout' => 20, // Shorter timeout for model list
            ];

            $response = wp_remote_get( $api_endpoint, $args );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( $response_code !== 200 || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
                $error_message = isset($data['error']['message']) ? $data['error']['message'] : esc_html__('Invalid response from API.', 'ai-seo-tools');
                return new WP_Error( 'api_error', $error_message, [ 'status' => $response_code ] );
            }

            $models = [];
            foreach ( $data['data'] as $model ) {
                if ( isset( $model['id'] ) ) {
                    $label = $model['id'];
                    if (isset($model['owned_by'])) {
                        // $label .= ' (' . $model['owned_by'] . ')'; // Optional: Add owner info
                    }
                    $models[ $model['id'] ] = $label;
                }
            }

            // Sort models alphabetically by key (ID)
            ksort( $models );

            if (empty($models)) {
                 return new WP_Error( 'no_gpt_models', esc_html__( 'No models found via API.', 'ai-seo-tools' ) );
            }

            return $models;
        }

        /**
         * Gets available models, using cache if possible.
         *
         * @param string|null $api_key Optional API key (used if not cached).
         * @return array Available models [id => label]. Returns default list on failure.
         */
        private function get_available_models(?string $api_key = null): array
        {
            $cached_models = get_transient( self::MODELS_TRANSIENT_KEY );

            if ( false !== $cached_models && is_array($cached_models) ) {
                return $cached_models;
            }

            // If not cached, try fetching.
            if ( $api_key === null ) {
                 $options = get_option( $this->option_name );
                 $api_key = $options['openai_api_key'] ?? null;
            }

            $fetched_models = $this->fetch_openai_models( $api_key );

            if ( ! is_wp_error( $fetched_models ) && ! empty( $fetched_models ) ) {
                // Cache indefinitely (until manually cleared or API key changes).
                set_transient( self::MODELS_TRANSIENT_KEY, $fetched_models, 0 );
                return $fetched_models;
            } else {
                 // Fetch failed or no API key, return the hardcoded default list as a fallback.
                 return $this->default_vision_models;
            }
        }

		/**
		 * Renders the description for the OpenAI section.
		 */
		public function render_openai_section(): void {
			echo '<p>' . esc_html__( 'Enter your OpenAI API key and select the model for AI features.', 'ai-seo-tools' ) . '</p>';
		}

		/**
		 * Renders the API key input field.
		 */
		public function render_api_key_field(): void {
			$options = get_option( $this->option_name );
			$api_key = $options['openai_api_key'] ?? '';
			?>
			<input type='password' name='<?php echo esc_attr( $this->option_name ); ?>[openai_api_key]' value='<?php echo esc_attr( $api_key ); ?>' class='regular-text' autocomplete='off' />
             <p class="description">
                <?php 
                printf(
                     /* translators: %1$s and %2$s: HTML link tags for OpenAI Platform. */
                    esc_html__( 'Get your API key from the %1$sOpenAI Platform%2$s.', 'ai-seo-tools' ),
                    '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">',
                    '</a>'
                 ); ?>
                 <?php esc_html_e( 'Saving the key will attempt to fetch the available models.', 'ai-seo-tools' ); ?>
             </p>
             <div class="ai-seo-warning-box">
                <strong><?php esc_html_e('Important:', 'ai-seo-tools'); ?></strong>
                <?php esc_html_e('You must top up your OpenAI account balance by at least $5 for the API to work. Free accounts are not supported for processing images.', 'ai-seo-tools'); ?>
            </div>
            <div class="ai-seo-info-box">
                <span class="ai-seo-info-label"><?php esc_html_e('Info:', 'ai-seo-tools'); ?></span>
                <?php esc_html_e('With GPT-4o-mini, a $5 balance is enough for roughly 130,000–150,000 alt-text generations.', 'ai-seo-tools'); ?>
            </div>
            <div class="ai-seo-info-box">
                <span class="ai-seo-info-heading">Useful OpenAI Links:</span>
                <ul class="ai-seo-links-list">
                    <li><a href="https://platform.openai.com/settings/organization/billing/overview" target="_blank" rel="noopener noreferrer">Billing Overview</a></li>
                    <li><a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">API Keys</a></li>
                    <li><a href="https://platform.openai.com/account/usage" target="_blank" rel="noopener noreferrer">Usage Dashboard</a></li>
                    <li><a href="https://platform.openai.com/account/billing/limits" target="_blank" rel="noopener noreferrer">Rate Limits</a></li>
                    <li><a href="https://platform.openai.com/docs/guides/vision" target="_blank" rel="noopener noreferrer">Vision API Docs</a></li>
                </ul>
            </div>
			<?php
		}

		/**
		 * Renders the model selection dropdown field.
		 */
		public function render_model_field(): void {
			$options = get_option( $this->option_name );
			$selected_model = $options['openai_model'] ?? self::DEFAULT_MODEL;

            // Get models dynamically (will use cache if available).
            $available_models = $this->get_available_models();

            // Check if the currently selected model is still in the list, if not, maybe default?
            if ( ! empty( $available_models ) && ! array_key_exists( $selected_model, $available_models ) ) {
                // Try to use the default constant if it exists in the list
                if ( array_key_exists(self::DEFAULT_MODEL, $available_models)) {
                    $selected_model = self::DEFAULT_MODEL;
                } else {
                    // Otherwise, just select the first available model as a fallback
                     // Use array_key_first for PHP 7.3+
                    if (function_exists('array_key_first')) {
                        $selected_model = array_key_first($available_models);
                    } else {
                        // Fallback for older PHP: reset pointer and get key
                         reset($available_models);
                         $selected_model = key($available_models);
                    }
                }
            }

			?>
            <select name='<?php echo esc_attr( $this->option_name ); ?>[openai_model]' <?php disabled( empty( $available_models ) ); ?>>
				<?php if ( ! empty( $available_models ) ) : ?>
                    <?php foreach ( $available_models as $model_id => $model_label ) : ?>
                        <option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $selected_model, $model_id ); ?>>
                            <?php echo esc_html( $model_label ); ?>
                        </option>
                    <?php endforeach; ?>
                <?php else : ?>
                    <option value=""><?php esc_html_e( 'Could not fetch models. Check API key?', 'ai-seo-tools' ); ?></option>
                <?php endif; ?>
			</select>
            <button type="button" id="ai-seo-refresh-models-button" class="ai-seo-refresh-models-button button button-secondary">
                <?php esc_html_e( 'Refresh List', 'ai-seo-tools' ); ?>
            </button>
            <span id="ai-seo-refresh-models-spinner" class="spinner ai-seo-spinner-inline"></span>
            <span id="ai-seo-refresh-models-status" class="ai-seo-status-inline"></span>
            <p class="description">
                <?php esc_html_e( 'Select an available OpenAI model capable of processing images (for example, GPT-4o-mini or GPT-4.1-nano). Make sure the model supports image processing (Vision feature), otherwise it will not work. The list of models is cached indefinitely until manually refreshed.', 'ai-seo-tools' ); ?>
                <?php // Start a new PHP block specifically for the warning
                // Check if the fetched list is the default fallback list
                if ( $available_models === $this->default_vision_models ) {
                    $warning_message = esc_html__( 'Warning: Could not fetch models from API, showing default list. Please ensure API key is correct and saved.', 'ai-seo-tools' );
                    echo '<br/><span class="ai-seo-warning-text">' . esc_html( $warning_message ) . '</span>';
                }
               // End the warning PHP block ?> 
            </p>
            <?php
		}

		/**
		 * Renders the description for the Modules section.
		 */
		public function render_modules_section(): void {
			echo '<p>' . esc_html__( 'Enable or disable specific AI features.', 'ai-seo-tools' ) . '</p>';
		}

		/**
		 * Renders the checkbox for enabling the Alt Text module.
		 */
		public function render_enable_alt_text_field(): void {
			$options = get_option( $this->option_name );
			// Default to enabled ('1') if the option is not set yet.
			$is_enabled = ($options['enable_alt_text_module'] ?? '1') === '1';
			?>
			<label for="enable_alt_text_module">
				<input type='checkbox' id='enable_alt_text_module' name='<?php echo esc_attr( $this->option_name ); ?>[enable_alt_text_module]' value='1' <?php checked( $is_enabled, true ); ?> />
				<?php esc_html_e( 'Enable', 'ai-seo-tools' ); ?>
			</label>
			<?php
		}

		/**
		 * Renders the custom language input and enable checkbox for alt text.
		 */
		public function render_alt_text_language_custom_field(): void {
			$options = get_option( $this->option_name );
			$enabled = !empty($options['alt_text_language_custom_enable']);
			$custom_lang = $options['alt_text_language_custom'] ?? '';
			?>
			<div class="ai-seo-setting-group">
				<label class="ai-seo-setting-label">
					<input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[alt_text_language_custom_enable]" value="1" <?php checked($enabled, true); ?> id="ai-seo-lang-enable-checkbox" />
					<?php esc_html_e('Generate alt text in a non-English language', 'ai-seo-tools'); ?>
				</label>
				<div id="ai-seo-lang-custom-wrap" class="ai-seo-lang-custom-wrap"<?php if (!$enabled) echo ' hidden'; ?>>
					<input type="text" name="<?php echo esc_attr($this->option_name); ?>[alt_text_language_custom]" value="<?php echo esc_attr($custom_lang); ?>" placeholder="language name" class="ai-seo-input-small" />
					<p class="description"><?php esc_html_e('If enabled, the specified language will be used for alt text generation. Type your language name, for example: Spanish, French, German, Polish, Italian, Russian, Hindi, Arabian, Bengali, Portuguese, Indonesian, Urdu, etc.', 'ai-seo-tools'); ?></p>
				</div>
			</div>
			<?php
		}

		/**
		 * Renders the custom prompt textarea and restore default button for alt text.
		 */
		public function render_alt_text_custom_prompt_field(): void {
			$options = get_option( $this->option_name );
			$custom_prompt = $options['alt_text_custom_prompt'] ?? '';
			$default_prompt = 'Generate a concise, descriptive alt text for this image, suitable for SEO and accessibility. Focus on the main subject and action. Maximum 125 characters.';
			$enabled = !empty($options['alt_text_custom_prompt_enable']);
			?>
			<div class="ai-seo-setting-group">
				<label class="ai-seo-setting-label">
					<input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[alt_text_custom_prompt_enable]" value="1" <?php checked($enabled, true); ?> id="ai-seo-prompt-enable-checkbox" />
					<?php esc_html_e('Use custom prompt for alt text generation', 'ai-seo-tools'); ?>
				</label>
				<div id="ai-seo-prompt-custom-wrap" class="ai-seo-prompt-custom-wrap"<?php if (!$enabled) echo ' hidden'; ?>>
					<textarea name="<?php echo esc_attr($this->option_name); ?>[alt_text_custom_prompt]" rows="3" cols="60" class="ai-seo-textarea" placeholder="<?php echo esc_attr($default_prompt); ?>"><?php echo esc_textarea($custom_prompt); ?></textarea>
					<br />
					<button type="button" class="button" id="ai-seo-restore-default-prompt"><?php esc_html_e('Restore Default Prompt', 'ai-seo-tools'); ?></button>
					<p class="description ai-seo-description">
						<?php esc_html_e('You can customize the prompt sent to OpenAI for alt text generation.', 'ai-seo-tools'); ?>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Renders the checkbox for enabling the Content Refresh module.
		 *
		 * Summary: Outputs a checkbox to enable or disable the Content Refresh & SEO Optimizer module in the settings page.
		 *
		 * Parameters: None
		 * Return Value: void
		 * Exceptions/Errors: None
		 */
		public function render_enable_content_refresh_field(): void {
			$options = get_option( $this->option_name );
			$is_enabled = ($options['enable_content_refresh_module'] ?? '1') === '1';
			?>
			<label for="enable_content_refresh_module">
				<input type='checkbox' id='enable_content_refresh_module' name='<?php echo esc_attr( $this->option_name ); ?>[enable_content_refresh_module]' value='1' <?php checked( $is_enabled, true ); ?> />
				<?php esc_html_e( 'Enable', 'ai-seo-tools' ); ?>
			</label>
			<?php
		}

		/**
		 * Renders the content refresh max tokens input field.
		 */
		public function render_content_refresh_max_tokens_field(): void {
			$options = get_option( $this->option_name );
			$max_tokens = isset($options['content_refresh_max_tokens']) ? intval($options['content_refresh_max_tokens']) : 10000;
			echo '<input type="number" name="' . esc_attr($this->option_name) . '[content_refresh_max_tokens]" value="' . esc_attr($max_tokens) . '" min="500" step="100" class="ai-seo-input-narrow"> ';
			echo '<p class="description">' . esc_html__('Controls the maximum length of AI-generated content for one post. Higher values allow longer posts but may cost more and take longer. Default: 10000. No hard maximum, but very high values may be limited by the AI model.', 'ai-seo-tools') . '</p>';
		}

		/**
		 * Renders the content refresh rewrite strength select field.
		 */
		public function render_content_refresh_rewrite_strength_field(): void {
			$options = get_option( $this->option_name );
			$strength = isset($options['content_refresh_rewrite_strength']) ? $options['content_refresh_rewrite_strength'] : 'maximal';
			echo '<select name="' . esc_attr($this->option_name) . '[content_refresh_rewrite_strength]" class="ai-seo-select-narrow">';
			echo '<option value="minimal"' . selected($strength, 'minimal', false) . '>' . esc_html__('Minimal (only outdated)', 'ai-seo-tools') . '</option>';
			echo '<option value="medium"' . selected($strength, 'medium', false) . '>' . esc_html__('Medium (paraphrase, update)', 'ai-seo-tools') . '</option>';
			echo '<option value="maximal"' . selected($strength, 'maximal', false) . '>' . esc_html__('Maximal (fully rewrite, unique)', 'ai-seo-tools') . '</option>';
			echo '</select> ';
			echo '<p class="description">' . esc_html__('Controls how aggressively the AI rewrites your post. Default: Maximal.', 'ai-seo-tools') . '</p>';
		}

		/**
		 * Checks if the Alt Text Generator module is enabled.
		 *
		 * @return bool True if enabled, false otherwise.
		 */
		private function is_alt_text_module_enabled(): bool {
			$options = get_option($this->option_name);
			return ($options['enable_alt_text_module'] ?? '1') === '1';
		}

		/**
		 * Checks if the Content Refresh module is enabled.
		 *
		 * @return bool True if enabled, false otherwise.
		 */
		private function is_content_refresh_module_enabled(): bool {
			$options = get_option($this->option_name);
			return ($options['enable_content_refresh_module'] ?? '1') === '1';
		}

		/**
		 * Renders the main admin page HTML with tabs.
		 */
		public function render_settings_page(): void {
			// Generate nonce for tab navigation
			$tab_nonce = wp_create_nonce( 'ai_seo_tab_navigation' );
			
			// Determine the active tab with flexible nonce verification
			$active_tab = 'settings'; // default
			if ( isset( $_GET['tab'] ) ) {
				$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
				
				// Check different types of nonce depending on the context
				$nonce_provided = isset( $_GET['_wpnonce'] );
				$nonce_valid = false;
				
				if ( $nonce_provided ) {
					$provided_nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
					
					// Check for tab navigation nonce
					$tab_nonce_valid = wp_verify_nonce( $provided_nonce, 'ai_seo_tab_navigation' );
					
					// Check for content refresh nonce (for pagination/filtering)
					$content_refresh_nonce_valid = wp_verify_nonce( $provided_nonce, 'ai_seo_content_refresh_view' );
					
					// Accept either nonce type
					$nonce_valid = $tab_nonce_valid || $content_refresh_nonce_valid;
				}
				
				// Accept tab if no nonce provided (first load) OR if any valid nonce
				if ( ! $nonce_provided || $nonce_valid ) {
					// Validate the requested tab is one of the allowed values
					$allowed_tabs = [ 'settings', 'alt_text_generator', 'content_refresh', 'auto_tagging' ];
					if ( in_array( $requested_tab, $allowed_tabs, true ) ) {
						$active_tab = $requested_tab;
					}
				}
			}
			
			$show_alt_text_tab = $this->is_alt_text_module_enabled();
			$show_content_refresh_tab = $this->is_content_refresh_module_enabled();
			// Show auto-tagging tab if enabled
			$show_auto_tagging_tab = $this->is_auto_tagging_module_enabled();

			// If the current tab is hidden, fallback to settings
			if (
				($active_tab === 'alt_text_generator' && !$show_alt_text_tab) ||
				($active_tab === 'content_refresh' && !$show_content_refresh_tab) ||
				($active_tab === 'auto_tagging' && !$show_auto_tagging_tab)
			) {
				$active_tab = 'settings';
			}
			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

				<h2 class="nav-tab-wrapper">
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => $this->settings_page_slug, 'tab' => 'settings', '_wpnonce' => $tab_nonce ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e('Settings', 'ai-seo-tools'); ?>
					</a>
					<?php if ($show_alt_text_tab): ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => $this->settings_page_slug, 'tab' => 'alt_text_generator', '_wpnonce' => $tab_nonce ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab == 'alt_text_generator' ? 'nav-tab-active' : ''; ?>">
						 <?php esc_html_e('Alt Text Generator', 'ai-seo-tools'); ?>
					</a>
					<?php endif; ?>
					<?php if ($show_content_refresh_tab): ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => $this->settings_page_slug, 'tab' => 'content_refresh', '_wpnonce' => $tab_nonce ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab == 'content_refresh' ? 'nav-tab-active' : ''; ?>">
						 <?php esc_html_e('Content Refresh', 'ai-seo-tools'); ?>
					</a>
					<?php endif; ?>
					<?php if ($show_auto_tagging_tab): ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => $this->settings_page_slug, 'tab' => 'auto_tagging', '_wpnonce' => $tab_nonce ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab == 'auto_tagging' ? 'nav-tab-active' : ''; ?>">
						 <?php esc_html_e('Auto Tagging', 'ai-seo-tools'); ?>
					</a>
					<?php endif; ?>
				</h2>

				<div class="tab-content">
					<?php
					// Display content based on active tab.
					if ( $active_tab == 'settings' ) {
						$this->render_settings_tab_content();
					} elseif ( $active_tab == 'alt_text_generator' && $show_alt_text_tab ) {
						$this->render_alt_text_tab_content();
					} elseif ( $active_tab == 'content_refresh' && $show_content_refresh_tab ) {
						$this->render_content_refresh_tab_content();
					} elseif ( $active_tab == 'auto_tagging' && $show_auto_tagging_tab ) {
						$this->render_auto_tagging_tab_content();
					} else {
						// Default or fallback content if needed
						$this->render_settings_tab_content();
					}
					?>
				</div>

			</div>
			<?php
		}

		/**
		 * Renders the content for the Settings tab.
		 */
		private function render_settings_tab_content(): void {
			?>
			<form action='options.php' method='post'>
				<?php
				settings_fields( $this->option_group );
				// Only display sections relevant to the main settings tab
				do_settings_sections( $this->settings_page_slug ); // This will render OpenAI and Modules sections
				submit_button( esc_html__( 'Save Settings', 'ai-seo-tools' ) );
				?>
			</form>
			<?php
		}

		/**
		 * Renders the content for the Alt Text Generator tab.
		 */
		private function render_alt_text_tab_content(): void {
			// Explanatory block for the Alt Text Generator module
			echo '<div class="ai-seo-module-panel">';
			echo '<h2 class="ai-seo-module-title">What SEO problem does this solve?</h2>';
			echo '<p class="ai-seo-module-desc">Many site owners upload images without providing descriptive alt text or optimizing filenames, which hurts both accessibility and SEO. Over half of website homepages have images with missing alternative text, leaving visually impaired users in the dark and missing an SEO opportunity (search engines use alt text to understand images). Writing alt text for every image is tedious, and many people simply forget or don\'t know how to write a good description. This is a pain point for bloggers, e-commerce (lots of product images), and anyone mindful of SEO and accessibility compliance.</p>';
			echo '</div>';

			// Ensure the Alt Text module class is loaded if the module is active.
			if ( ! class_exists( 'AI_SEO_Tools_Alt_Text' ) ) {
				// Attempt to load it if it wasn't loaded automatically (e.g., module was just enabled)
				$module_file = AI_SEO_TOOLS_PATH . 'modules/alt-text/class-ai-seo-tools-alt-text.php';
				if (file_exists($module_file)) {
					require_once $module_file;
				} else {
					echo '<div class="error"><p>' . esc_html__('Alt Text module file not found. Cannot display statistics.', 'ai-seo-tools') . '</p></div>';
					return;
				}
				if ( ! class_exists( 'AI_SEO_Tools_Alt_Text' ) ) {
					 echo '<div class="error"><p>' . esc_html__('Alt Text module class not found after loading file. Cannot display statistics.', 'ai-seo-tools') . '</p></div>';
					 return;
				}
			}

			// Fetch statistics using the static method
			$stats = AI_SEO_Tools_Alt_Text::get_alt_text_stats();

			echo '<h2>' . esc_html__('Image Alt Text Statistics & Generation', 'ai-seo-tools') . '</h2>';

			// Add a placeholder for the background processing notice specifically on this page
			echo '<div id="ai-seo-bulk-running-notice" class="notice notice-info inline ai-seo-bulk-notice" hidden><p></p></div>';

			// Display Statistics
			echo '<div id="ai-seo-alt-stats">';
			echo '<h3>' . esc_html__('Current Status', 'ai-seo-tools') . '</h3>';
			if ( $stats['total'] > 0 ) {
				// Calculate percentages with higher precision
				$raw_percent_with_alt = ( $stats['with_alt'] / $stats['total'] ) * 100;
				$raw_percent_without_alt = 100 - $raw_percent_with_alt;

				// Format percentages to 1 decimal place
				$percent_with_alt_formatted = number_format( $raw_percent_with_alt, 1, '.', '' );
				$percent_without_alt_formatted = number_format( $raw_percent_without_alt, 1, '.', '' );

				echo '<ul>';
				echo '<li>' . sprintf( 
					/* translators: %d: Total number of images in the Media Library. */
					esc_html__( 'Total Images in Media Library: %d', 'ai-seo-tools' ), esc_html( $stats['total'] ) ) . '</li>';
				echo '<li>' . sprintf( 
					/* translators: 1: Number of images with alt text, 2: Percentage of images with alt text. */
					esc_html__( 'Images with Alt Text: %1$d (%2$s%%)', 'ai-seo-tools' ), esc_html( $stats['with_alt'] ), esc_html( $percent_with_alt_formatted ) ) . '</li>';
				echo '<li>' . sprintf( 
					/* translators: 1: Number of images without alt text, 2: Percentage of images without alt text. */
					esc_html__( 'Images without Alt Text: %1$d (%2$s%%)', 'ai-seo-tools' ), esc_html( $stats['without_alt'] ), esc_html( $percent_without_alt_formatted ) ) . '</li>';
				echo '</ul>';
			} else {
				echo '<p>' . esc_html__( 'No images found in the Media Library.', 'ai-seo-tools' ) . '</p>';
			}
			echo '</div>';

			echo '<hr>';

			// --- Bulk Generation Section --- //
			echo '<div id="ai-seo-bulk-generate-section">';
			echo '<h3>' . esc_html__('Bulk Generation', 'ai-seo-tools') . '</h3>';

			if ( $stats['without_alt'] > 0 ) {
				echo '<p>' . sprintf( 
					/* translators: %d: Number of images without alt text. */
					esc_html__( 'Found %d images without alt text.', 'ai-seo-tools' ), esc_html( $stats['without_alt'] ) ) . '</p>';

				// Limit Input
				echo '<div class="ai-seo-inline-section">';
				echo '<label for="ai-seo-bulk-limit">' . esc_html__( 'Process maximum:', 'ai-seo-tools' ) . ' </label>';
				echo '<input type="number" id="ai-seo-bulk-limit" name="ai_seo_bulk_limit" value="' . esc_attr( $stats['without_alt'] ) .'" min="1" max="' . esc_attr( $stats['without_alt'] ) .'" class="ai-seo-input-narrow"> ' . esc_html__( 'images', 'ai-seo-tools' );
				echo '</div>';

				// Buttons
				echo '<div id="ai-seo-bulk-controls">';
				echo '<button type="button" id="ai-seo-bulk-generate-button" class="button button-primary">' . esc_html__( 'Start Bulk Generation', 'ai-seo-tools' ) . '</button>';
				echo '<button type="button" id="ai-seo-bulk-stop-button" class="button button-secondary" hidden>' . esc_html__( 'Stop Generation', 'ai-seo-tools' ) . '</button>';
				echo '<span class="spinner ai-seo-spinner-inline" id="ai-seo-bulk-spinner"></span>';
				echo '</div>';

				// Progress Bar Area
				echo '<div id="ai-seo-bulk-progress" class="ai-seo-progress-wrapper" hidden>';
				echo '<div class="ai-seo-progress-container">';
				echo '<div id="ai-seo-bulk-progress-bar" class="ai-seo-progress-bar">0%</div>';
				echo '</div>';
				echo '<p id="ai-seo-bulk-progress-status"></p>'; // Status text like "Processed 5 of 100..."
				// Placeholder for recently processed images
				echo '<div id="ai-seo-bulk-recent-list" class="ai-seo-list-container"></div>';
				echo '</div>';

				// Placeholder for displaying errors
				echo '<div id="ai-seo-bulk-error-list" class="ai-seo-error-list" hidden>';
				echo '<h4>' . esc_html__('Errors Encountered:', 'ai-seo-tools') . '</h4>';
				echo '<ul class="ai-seo-list"></ul>';
				echo '</div>';

			} else if ( $stats['total'] > 0 ) {
				 echo '<p>' . esc_html__( 'All images in your Media Library already have alt text. Well done!', 'ai-seo-tools' ) . '</p>';
			}
			echo '</div>';
			// --- End Bulk Generation Section --- //
		}

		/**
		 * Renders the content for the Content Refresh tab.
		 *
		 * Summary: Outputs the admin UI content for the Content Refresh & SEO Optimizer module tab, including a table of outdated posts and summary stats.
		 *
		 * Parameters: None
		 * Return Value: void
		 * Exceptions/Errors: None
		 */
		private function render_content_refresh_tab_content(): void {
			// Verify nonce for GET parameters if they exist
			$nonce_verified = false;
			if ( isset( $_GET['_wpnonce'] ) ) {
				$nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ai_seo_content_refresh_view' );
			}

			// Pagination setup
			$per_page = 20;
			// Only use GET parameters if nonce is verified, otherwise use defaults
			if ( $nonce_verified ) {
				$paged = isset( $_GET['ai_seo_page'] ) ? max( 1, intval( wp_unslash( $_GET['ai_seo_page'] ) ) ) : 1;
				$score_filter = isset( $_GET['ai_seo_score'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_seo_score'] ) ) : '';
			} else {
				$paged = 1;
				$score_filter = '';
			}
			$offset = ($paged - 1) * $per_page;
			$score_options = ['' => __('All', 'ai-seo-tools'), 'High' => __('High', 'ai-seo-tools'), 'Medium' => __('Medium', 'ai-seo-tools'), 'Low' => __('Low', 'ai-seo-tools')];

			// --- Summary Stats --- //
			$args = array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			);
			$post_ids = get_posts($args);
			$total_posts = count($post_ids);
			$outdated_posts = 0;
			$outdated_ids = [];
			$now = time();
			$outdated_threshold = apply_filters('ai_seo_tools_outdated_months', 6) * MONTH_IN_SECONDS;
			$rows = [];
			foreach ($post_ids as $post_id) {
				$post = get_post($post_id);
				$last_updated = strtotime($post->post_modified_gmt);
				$age = $now - $last_updated;
				$is_outdated = $age > $outdated_threshold;
				$score = $is_outdated ? 'High' : 'Low';
				$word_count = str_word_count(wp_strip_all_tags($post->post_content));
				if ($is_outdated) {
					$outdated_posts++;
					$outdated_ids[] = $post_id;
				}
				$rows[] = [
					'id' => $post_id,
					'title' => get_the_title($post_id),
					'edit_link' => get_edit_post_link($post_id),
					'last_updated' => get_date_from_gmt($post->post_modified_gmt, 'Y-m-d'),
					'word_count' => $word_count,
					'score' => $score,
					'is_outdated' => $is_outdated,
				];
			}
			// Filter by Outdated Score if set
			if ($score_filter && in_array($score_filter, ['High','Medium','Low'], true)) {
				$rows = array_filter($rows, function($row) use ($score_filter) {
					return $row['score'] === $score_filter;
				});
				$rows = array_values($rows); // reindex
			}
			$filtered_total = count($rows);
			// Pagination slice
			$total_pages = max(1, ceil($filtered_total / $per_page));
			$rows_page = array_slice($rows, $offset, $per_page);

			echo '<div class="ai-seo-module-panel">';
			echo '<h2 class="ai-seo-module-title">What SEO problem does this solve?</h2>';
			echo '<p class="ai-seo-module-desc">Websites struggle to keep content up-to-date and SEO-friendly over time. Old blog posts can become outdated, hurting search rankings, but manually auditing and updating dozens or hundreds of posts is time-consuming. This module uses generative AI to analyze existing posts and suggest updates or rewrites for outdated sections, recommend low-competition keywords, and auto-generate meta descriptions or summaries. It provides a semi-automated content refresh process to keep posts relevant and improve rankings.</p>';
			echo '</div>';
			echo '<div class="ai-seo-module-panel">';
			echo '<h2 class="ai-seo-module-title">Content Refresh & SEO Optimizer</h2>';
			echo '<ul class="ai-seo-module-list">';
			echo '<li><strong>' . esc_html__('Total Posts:', 'ai-seo-tools') . '</strong> ' . esc_html($total_posts) . '</li>';
			echo '<li><strong>' . esc_html__('Outdated Posts:', 'ai-seo-tools') . '</strong> ' . esc_html($outdated_posts) . '</li>';
			echo '</ul>';
			echo '</div>';

			// Generate nonce for filter form and pagination
			$view_nonce = wp_create_nonce( 'ai_seo_content_refresh_view' );

			// --- Filter UI --- //
			echo '<form method="get" class="ai-seo-filter-form">';
			// Only preserve page and tab parameters to maintain navigation
			echo '<input type="hidden" name="page" value="' . esc_attr( $this->settings_page_slug ) . '" />';
			echo '<input type="hidden" name="tab" value="content_refresh" />';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $view_nonce ) . '" />';
			echo '<label for="ai-seo-score-filter" class="ai-seo-filter-label">' . esc_html__('Outdated Score:', 'ai-seo-tools') . '</label>';
			echo '<select id="ai-seo-score-filter" name="ai_seo_score" onchange="this.form.submit()" class="ai-seo-select-narrow">';
			foreach ($score_options as $val => $label) {
				echo '<option value="' . esc_attr($val) . '"' . selected($score_filter, $val, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
			echo '</form>';
			// --- Table --- //
			echo '<h3 class="ai-seo-section-title">' . esc_html__('All Posts', 'ai-seo-tools') . '</h3>';
			echo '<table class="widefat fixed striped ai-seo-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__('Title', 'ai-seo-tools') . '</th>';
			echo '<th>' . esc_html__('Last Updated', 'ai-seo-tools') . '</th>';
			echo '<th>' . esc_html__('Word Count', 'ai-seo-tools') . '</th>';
			echo '<th>' . esc_html__('Outdated Score', 'ai-seo-tools') . '</th>';
			echo '<th>' . esc_html__('Actions', 'ai-seo-tools') . '</th>';
			echo '</tr></thead><tbody>';
			foreach ($rows_page as $row) {
				$post = get_post($row['id']);
				$original_content = $post ? $post->post_content : '';
				echo '<tr>';
				echo '<td><div class="ai-seo-original-content">' . esc_html($original_content) . '</div><a href="' . esc_url($row['edit_link']) . '" target="_blank">' . esc_html($row['title']) . '</a></td>';
				echo '<td>' . esc_html($row['last_updated']) . '</td>';
				echo '<td>' . esc_html($row['word_count']) . '</td>';
				// Badge for Outdated Score
				$badge_color = $row['score'] === 'High' ? '#e74c3c' : '#27ae60';
				$badge_text_color = '#fff';
				$badge_class = $row['score'] === 'High' ? 'ai-seo-badge--high' : 'ai-seo-badge--low';
				echo '<td><span class="ai-seo-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $row['score'] ) . '</span></td>';
				echo '<td>';
				echo '<button type="button" class="button ai-seo-analyze-post" data-post-id="' . esc_attr($row['id']) . '">' . esc_html__('Analyze', 'ai-seo-tools') . '</button> ';
				echo '<button type="button" class="button ai-seo-apply-suggestion" data-post-id="' . esc_attr($row['id']) . '">' . esc_html__('Apply', 'ai-seo-tools') . '</button>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			// Pagination controls
			if ($total_pages > 1) {
				echo '<div class="ai-seo-pagination">';
				$base_url = remove_query_arg(['ai_seo_page']);
				$base_query_args = [
					'page' => $this->settings_page_slug,
					'tab' => 'content_refresh',
					'_wpnonce' => $view_nonce
				];
				if ( $score_filter ) {
					$base_query_args['ai_seo_score'] = $score_filter;
				}

				// Prev
				if ($paged > 1) {
					$query_args = $base_query_args;
					$query_args['ai_seo_page'] = $paged - 1;
					echo '<a href="' . esc_url(add_query_arg($query_args, admin_url('admin.php'))) . '" class="ai-seo-pagination-link">&laquo; ' . esc_html__('Prev', 'ai-seo-tools') . '</a>';
				}
				// Page numbers
				for ($i = 1; $i <= $total_pages; $i++) {
					$query_args = $base_query_args;
					$query_args['ai_seo_page'] = $i;
					$is_active = $i == $paged;
					$style = $is_active ? 'background:#0073aa;color:#fff;font-weight:bold;' : 'background:#f1f1f1;color:#0073aa;';
					echo '<a href="' . esc_url(add_query_arg($query_args, admin_url('admin.php'))) . '" class="ai-seo-pagination-link' . ($is_active ? ' ai-seo-pagination-link--active' : '') . '">' . esc_html( $i ) . '</a>';
				}
				// Next
				if ($paged < $total_pages) {
					$query_args = $base_query_args;
					$query_args['ai_seo_page'] = $paged + 1;
					echo '<a href="' . esc_url(add_query_arg($query_args, admin_url('admin.php'))) . '" class="ai-seo-pagination-link">' . esc_html__('Next', 'ai-seo-tools') . ' &raquo;</a>';
				}
				echo '</div>';
			}
			echo '<p class="ai-seo-note">' . esc_html__('Posts are considered outdated if last updated more than 6 months ago or missing a meta description. You can analyze and update any post with AI suggestions.', 'ai-seo-tools') . '</p>';
		}

		/**
		 * Renders the description for the Bulk Processing section.
		 */
		public function render_bulk_section(): void {
			echo '<p>' . esc_html__( 'Configure how the bulk generation process runs.', 'ai-seo-tools' ) . '</p>';
		}

		/**
		 * Renders the input field for bulk processing delay.
		 */
		public function render_bulk_delay_field(): void {
			$options = get_option( $this->option_name );
			$delay = $options['bulk_processing_delay'] ?? 20; // Default 20
			?>
			<input type='number' min="1" step="1" name='<?php echo esc_attr( $this->option_name ); ?>[bulk_processing_delay]' value='<?php echo esc_attr( $delay ); ?>' class='small-text' />
			<p class="description">
				<?php esc_html_e( 'Seconds to wait between processing each image during bulk generation. Helps avoid API rate limits. Minimum 1 second.', 'ai-seo-tools' ); ?>
			</p>
			<?php
		}

		/**
		 * Renders the select field for image detail level.
		 */
		public function render_detail_level_field(): void {
			$options = get_option( $this->option_name );
			$detail_level = $options['image_detail_level'] ?? 'low'; // Default 'low'
			?>
			<select name='<?php echo esc_attr( $this->option_name ); ?>[image_detail_level]'>
				<option value="low" <?php selected( $detail_level, 'low' ); ?>><?php echo esc_html__('Low', 'ai-seo-tools'); ?></option>
				<option value="high" <?php selected( $detail_level, 'high' ); ?>><?php echo esc_html__('High', 'ai-seo-tools'); ?></option>
			</select>
			 <p class="description">
				<?php esc_html_e( "Controls the detail level OpenAI uses to analyze images. 'Low' uses a fixed, lower token cost. 'High' uses more tokens based on image size (potentially more accurate analysis, but costs more). See OpenAI pricing for details.", 'ai-seo-tools' ); ?>
			</p>
			<?php
		}

		/**
		 * Enqueues scripts needed for the settings page.
		 *
		 * @param string $hook The current admin page hook.
		 */
		public function enqueue_settings_scripts( string $hook ): void {
			// Check if we are on our specific settings page.
			// Note: The hook for add_menu_page is 'toplevel_page_{menu_slug}'.
			if ( 'toplevel_page_' . $this->settings_page_slug !== $hook ) {
				return;
			}

			wp_enqueue_script(
				'ai-seo-tools-settings', // Handle for the script
				AI_SEO_TOOLS_URL . 'assets/js/ai-seo-settings.js', // Path to the new JS file
				array( 'jquery' ),
				AI_SEO_TOOLS_VERSION,
				true // Load in footer
			);

			// Localize script with necessary data.
			wp_localize_script( 'ai-seo-tools-settings', 'aiSeoSettings', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ai_seo_content_refresh_nonce' ),
				'bulk_start_nonce' => wp_create_nonce( 'ai_seo_bulk_alt_start_nonce' ),
				'bulk_status_nonce' => wp_create_nonce( 'ai_seo_bulk_alt_status_nonce' ),
				'bulk_stop_nonce' => wp_create_nonce( 'ai_seo_bulk_alt_stop_nonce' ),
				'get_stats_nonce' => wp_create_nonce( 'ai_seo_get_stats_nonce' ),
				'get_details_nonce' => wp_create_nonce( 'ai_seo_get_image_details_nonce' ),
				'refreshing_text' => esc_html__( 'Refreshing...', 'ai-seo-tools' ),
				'refreshed_text' => esc_html__( 'List updated.', 'ai-seo-tools' ),
				'error_text' => esc_html__( 'Error updating list.', 'ai-seo-tools' ),
				'bulk_start_error' => esc_html__( 'Error starting generation.', 'ai-seo-tools' ),
				'bulk_status_error' => esc_html__( 'Error fetching status.', 'ai-seo-tools' ),
				'bulk_stop_error' => esc_html__( 'Error stopping generation.', 'ai-seo-tools' ),
				'bulk_stopped' => esc_html__( 'Bulk generation stopped by user.', 'ai-seo-tools' ),
				'bulk_complete' => esc_html__( 'Bulk generation complete!', 'ai-seo-tools' ),
				'bulk_processing' => esc_html__( 'Processed {processed} of {total} images.', 'ai-seo-tools' ),
				'bulk_processing_with_errors' => esc_html__( 'Processed {processed} of {total}. Encountered {errors} errors (see logs).', 'ai-seo-tools' ),
				// Auto-Tagging nonces and messages
				'bulk_tags_start_nonce'   => wp_create_nonce( 'ai_seo_bulk_tags_start_nonce' ),
				'bulk_tags_status_nonce'  => wp_create_nonce( 'ai_seo_bulk_tags_status_nonce' ),
				'bulk_tags_stop_nonce'    => wp_create_nonce( 'ai_seo_bulk_tags_stop_nonce' ),
				'bulk_tags_processing'    => esc_html__( 'Processed {processed} of {total} posts.', 'ai-seo-tools' ),
				'bulk_tags_complete_msg'  => esc_html__( 'Bulk tagging complete!', 'ai-seo-tools' ),
				'bulk_tags_stopped_msg'   => esc_html__( 'Bulk tagging stopped by user.', 'ai-seo-tools' ),
				// Bulk Append nonces
				'bulk_append_tags_start_nonce'  => wp_create_nonce( 'ai_seo_bulk_append_tags_start_nonce' ),
				'bulk_append_tags_status_nonce' => wp_create_nonce( 'ai_seo_bulk_append_tags_status_nonce' ),
				'bulk_append_tags_stop_nonce'   => wp_create_nonce( 'ai_seo_bulk_append_tags_stop_nonce' ),
				// Bulk Regenerate nonces
				'bulk_regenerate_tags_start_nonce'  => wp_create_nonce( 'ai_seo_bulk_regenerate_tags_start_nonce' ),
				'bulk_regenerate_tags_status_nonce' => wp_create_nonce( 'ai_seo_bulk_regenerate_tags_status_nonce' ),
				'bulk_regenerate_tags_stop_nonce'   => wp_create_nonce( 'ai_seo_bulk_regenerate_tags_stop_nonce' ),
				// Nonce and confirmation for clearing all tags
				'clear_all_tags_nonce'              => wp_create_nonce( 'ai_seo_clear_all_tags_nonce' ),
				'confirm_clear_all_tags'            => esc_html__( 'Are you sure you want to clear all tags for all posts?', 'ai-seo-tools' ),
			) );
		}

		/**
		 * Handles the AJAX request to refresh the OpenAI models list.
		 */
		public function handle_ajax_refresh_models(): void {
			check_ajax_referer( 'ai_seo_refresh_models_nonce', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'ai-seo-tools' ) ], 403 );
			}

			// Get current API key
			$options = get_option( $this->option_name );
			$api_key = $options['openai_api_key'] ?? null;

			if ( empty( $api_key ) ) {
				wp_send_json_error( [ 'message' => esc_html__( 'API Key is not set.', 'ai-seo-tools' ) ], 400 );
			}

			// Clear the cache first
			$this->clear_models_cache();

			// Fetch fresh models
			$fetched_models = $this->fetch_openai_models( $api_key );

			if ( is_wp_error( $fetched_models ) ) {
				// Send back the WP_Error message
				wp_send_json_error( [ 'message' => $fetched_models->get_error_message() ], 500 );
			}

			if ( empty( $fetched_models ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'No models returned by API.', 'ai-seo-tools' ) ], 500 );
            }

            // Cache the newly fetched models
            set_transient( self::MODELS_TRANSIENT_KEY, $fetched_models, 0 );

            // Send success response with the models list
            wp_send_json_success( [ 'models' => $fetched_models ] );
		}

		/**
		 * Checks if the Auto Tagging module is enabled.
		 *
		 * @return bool True if enabled, false otherwise.
		 */
		private function is_auto_tagging_module_enabled(): bool {
			$options = get_option( $this->option_name );
			return ( $options['enable_auto_tagging_module'] ?? '1' ) === '1';
		}

		/**
		 * Renders the content for the Auto Tagging tab.
		 */
		private function render_auto_tagging_tab_content(): void {
			// Explanation block
			echo '<div class="ai-seo-module-panel">';
			echo '<h2 class="ai-seo-module-title">' . esc_html__( 'What SEO problem does this solve?', 'ai-seo-tools' ) . '</h2>';
			echo '<p class="ai-seo-module-desc">' . esc_html__( 'Automated tagging enriches metadata, improves internal linking, and reduces manual effort for editors. Generate semantically relevant tags based on post content.', 'ai-seo-tools' ) . '</p>';
			echo '</div>';

			// Ensure module class is loaded
			if ( ! class_exists( 'AI_SEO_Tools_Auto_Tagging' ) ) {
				$module_file = AI_SEO_TOOLS_PATH . 'modules/auto-tagging/class-ai-seo-tools-auto-tagging.php';
				if ( file_exists( $module_file ) ) {
					require_once $module_file;
				} else {
					echo '<div class="error"><p>' . esc_html__( 'Auto Tagging module file not found.', 'ai-seo-tools' ) . '</p></div>';
					return;
				}
			}

			$stats = AI_SEO_Tools_Auto_Tagging::get_tagging_stats();
			echo '<h2>' . esc_html__( 'Post Tagging Statistics & Generation', 'ai-seo-tools' ) . '</h2>';
			// Notice area for background processing of bulk tagging
			echo '<div id="ai-seo-bulk-tags-running-notice" class="notice notice-info inline ai-seo-bulk-notice" hidden><p></p></div>';
			echo '<div id="ai-seo-tagging-stats"><ul>';
			/* translators: %d is the total number of published posts. */
			echo '<li>' . sprintf( esc_html__( 'Total Published Posts: %d', 'ai-seo-tools' ), esc_html( $stats['total'] ) ) . '</li>';
			/* translators: 1: number of posts with tags; 2: percentage of posts with tags. */
			echo '<li>' . sprintf( esc_html__( 'Posts with Tags: %1$d (%2$s%%)', 'ai-seo-tools' ), esc_html( $stats['with_tags'] ), esc_html( number_format( $stats['percent_with_tags'], 1 ) ) ) . '</li>';
			/* translators: 1: number of posts without tags; 2: percentage of posts without tags. */
			echo '<li>' . sprintf( esc_html__( 'Posts without Tags: %1$d (%2$s%%)', 'ai-seo-tools' ), esc_html( $stats['without_tags'] ), esc_html( number_format( $stats['percent_without_tags'], 1 ) ) ) . '</li>';
			echo '</ul></div>';
			echo '<hr>';

			// Bulk Tagging Section
			echo '<div id="ai-seo-tagging-bulk-section">';
			echo '<h3>' . esc_html__( 'Bulk Tagging', 'ai-seo-tools' ) . '</h3>';
			if ( $stats['without_tags'] > 0 ) {
				/* translators: %d is the number of posts without tags. */
				echo '<p>' . sprintf( esc_html__( 'Found %d posts without tags.', 'ai-seo-tools' ), esc_html( $stats['without_tags'] ) ) . '</p>';
				echo '<div class="ai-seo-inline-section">';
				echo '<label for="ai-seo-bulk-tags-limit">' . esc_html__( 'Process maximum:', 'ai-seo-tools' ) . '</label> ';
				echo '<input type="number" id="ai-seo-bulk-tags-limit" value="' . esc_attr( $stats['without_tags'] ) . '" min="1" max="' . esc_attr( $stats['without_tags'] ) . '" class="ai-seo-input-narrow"> ' . esc_html__( 'posts', 'ai-seo-tools' ) . ' ';
				echo '</div>';
				echo '<div id="ai-seo-bulk-tags-controls">';
				echo '<button type="button" id="ai-seo-bulk-tags-start-button" class="button button-primary">' . esc_html__( 'Start Bulk Tagging', 'ai-seo-tools' ) . '</button> ';
				echo '<button type="button" id="ai-seo-bulk-tags-stop-button" class="button button-secondary" hidden>' . esc_html__( 'Stop Tagging', 'ai-seo-tools' ) . '</button> ';
				echo '<span class="spinner ai-seo-spinner-inline" id="ai-seo-bulk-tags-spinner"></span>';
				echo '</div>';
				echo '<div id="ai-seo-bulk-tags-progress" class="ai-seo-progress-wrapper" hidden>';
				echo '<div class="ai-seo-progress-container">';
				echo '<div id="ai-seo-bulk-tags-progress-bar" class="ai-seo-progress-bar">0%</div>';
				echo '</div>';
				echo '<p id="ai-seo-bulk-tags-progress-status"></p>';
				echo '<div id="ai-seo-bulk-tags-recent-list" class="ai-seo-list-container"></div>';
				echo '</div>';
				echo '<div id="ai-seo-bulk-tags-error-list" class="ai-seo-error-list" hidden>';
				echo '<h4>' . esc_html__( 'Errors Encountered:', 'ai-seo-tools' ) . '</h4><ul class="ai-seo-list"></ul>';
				echo '</div>';
			} elseif ( $stats['total'] > 0 ) {
				echo '<p>' . esc_html__( 'All published posts already have tags.', 'ai-seo-tools' ) . '</p>';
			}
			echo '</div>';

			// Bulk Append Tags Section
			echo '<div id="ai-seo-bulk-append-tags-running-notice" class="notice notice-info inline ai-seo-bulk-notice" hidden><p></p></div>';
			echo '<div id="ai-seo-bulk-append-section" class="ai-seo-section">';
			echo '<h3>' . esc_html__( 'Bulk Append Tags', 'ai-seo-tools' ) . '</h3>';
			if ( $stats['with_tags'] > 0 ) {
				/* translators: %d is the number of posts with existing tags. */
				echo '<p>' . sprintf( esc_html__( 'Found %d posts with existing tags.', 'ai-seo-tools' ), esc_html( $stats['with_tags'] ) ) . '</p>';
				echo '<div class="ai-seo-inline-section">';
				echo '<label for="ai-seo-bulk-append-tags-limit">' . esc_html__( 'Process maximum:', 'ai-seo-tools' ) . '</label> ';
				echo '<input type="number" id="ai-seo-bulk-append-tags-limit" value="' . esc_attr( $stats['with_tags'] ) . '" min="1" max="' . esc_attr( $stats['with_tags'] ) . '" class="ai-seo-input-narrow"> ' . esc_html__( 'posts', 'ai-seo-tools' );
				echo '</div>';
				echo '<div id="ai-seo-bulk-append-tags-controls">';
				echo '<button type="button" id="ai-seo-bulk-append-tags-start-button" class="button button-primary">' . esc_html__( 'Start Bulk Append', 'ai-seo-tools' ) . '</button> ';
				echo '<button type="button" id="ai-seo-bulk-append-tags-stop-button" class="button button-secondary" hidden>' . esc_html__( 'Stop Append', 'ai-seo-tools' ) . '</button> ';
				echo '<span class="spinner ai-seo-spinner-inline" id="ai-seo-bulk-append-tags-spinner"></span>';
				echo '</div>';
				echo '<div id="ai-seo-bulk-append-tags-progress" class="ai-seo-progress-wrapper" hidden>';
				echo '<div class="ai-seo-progress-container">';
				echo '<div id="ai-seo-bulk-append-tags-progress-bar" class="ai-seo-progress-bar">0%</div>';
				echo '</div>';
				echo '<p id="ai-seo-bulk-append-tags-progress-status"></p>';
				echo '<div id="ai-seo-bulk-append-tags-recent-list" class="ai-seo-list-container"></div>';
				echo '</div>';
				echo '<div id="ai-seo-bulk-append-tags-error-list" class="ai-seo-error-list" hidden>';
				echo '<h4>' . esc_html__( 'Append Errors Encountered:', 'ai-seo-tools' ) . '</h4><ul class="ai-seo-list"></ul>';
				echo '</div>';
			} elseif ( $stats['total'] > 0 ) {
				echo '<p>' . esc_html__( 'No posts eligible for append (no posts with existing tags).', 'ai-seo-tools' ) . '</p>';
			}
			echo '</div>';

			// Bulk Regenerate Tags Section
			echo '<div id="ai-seo-bulk-regenerate-tags-running-notice" class="notice notice-info inline ai-seo-bulk-notice" hidden><p></p></div>';
			echo '<div id="ai-seo-bulk-regenerate-section" class="ai-seo-section">';
			echo '<h3>' . esc_html__( 'Bulk Regenerate Tags', 'ai-seo-tools' ) . '</h3>';
			if ( $stats['with_tags'] > 0 ) {
				/* translators: %d is the number of posts with existing tags. */
				echo '<p>' . sprintf( esc_html__( 'Found %d posts with existing tags.', 'ai-seo-tools' ), esc_html( $stats['with_tags'] ) ) . '</p>';
				echo '<div class="ai-seo-inline-section">';
				echo '<label for="ai-seo-bulk-regenerate-tags-limit">' . esc_html__( 'Process maximum:', 'ai-seo-tools' ) . '</label> ';
				echo '<input type="number" id="ai-seo-bulk-regenerate-tags-limit" value="' . esc_attr( $stats['with_tags'] ) . '" min="1" max="' . esc_attr( $stats['with_tags'] ) . '" class="ai-seo-input-narrow"> ' . esc_html__( 'posts', 'ai-seo-tools' );
				echo '</div>';
				echo '<div id="ai-seo-bulk-regenerate-tags-controls">';
				echo '<button type="button" id="ai-seo-bulk-regenerate-tags-start-button" class="button button-primary">' . esc_html__( 'Start Bulk Regenerate', 'ai-seo-tools' ) . '</button> ';
				echo '<button type="button" id="ai-seo-bulk-regenerate-tags-stop-button" class="button button-secondary" hidden>' . esc_html__( 'Stop Regenerate', 'ai-seo-tools' ) . '</button> ';
				echo '<span class="spinner ai-seo-spinner-inline" id="ai-seo-bulk-regenerate-tags-spinner"></span>';
				echo '</div>';
				echo '<div id="ai-seo-bulk-regenerate-tags-progress" class="ai-seo-progress-wrapper" hidden>';
				echo '<div class="ai-seo-progress-container">';
				echo '<div id="ai-seo-bulk-regenerate-tags-progress-bar" class="ai-seo-progress-bar">0%</div>';
				echo '</div>';
				echo '<p id="ai-seo-bulk-regenerate-tags-progress-status"></p>';
				echo '<div id="ai-seo-bulk-regenerate-tags-recent-list" class="ai-seo-list-container"></div>';
				echo '</div>';
				echo '<div id="ai-seo-bulk-regenerate-tags-error-list" class="ai-seo-error-list" hidden>';
				echo '<h4>' . esc_html__( 'Regenerate Errors Encountered:', 'ai-seo-tools' ) . '</h4><ul class="ai-seo-list"></ul>';
				echo '</div>';
			} else {
				echo '<p>' . esc_html__( 'No posts found for regeneration.', 'ai-seo-tools' ) . '</p>';
			}
			echo '</div>';

			// Clear All Tags section
			echo '<hr class="ai-seo-separator">';
			echo '<div id="ai-seo-clear-all-tags-section" class="ai-seo-section">';
			echo '<h3>' . esc_html__( 'Clear All Tags', 'ai-seo-tools' ) . '</h3>';
			echo '<p>' . esc_html__( 'This will remove all tags from all published posts. This action cannot be undone.', 'ai-seo-tools' ) . '</p>';
			echo '<button type="button" id="ai-seo-clear-all-tags-button" class="button button-secondary">' . esc_html__( 'Clear All Tags', 'ai-seo-tools' ) . '</button> ';
			echo '<span class="spinner ai-seo-spinner-inline" id="ai-seo-clear-all-tags-spinner"></span>';
			echo '<span id="ai-seo-clear-all-tags-result" class="ai-seo-status-inline-large"></span>';
			echo '</div>';  
		}

		/**
		 * Renders descriptive text for the Auto Tagging section.
		 */
		public function render_auto_tagging_settings_section(): void {
			echo '<p>' . esc_html__( 'Configure default settings for the Auto Tagging module.', 'ai-seo-tools' ) . '</p>';
		}

		/**
		 * Renders the Max Tags per Post field.
		 */
		public function render_auto_tagging_max_tags_field(): void {
			$options = get_option( $this->option_name );
			$value = $options['auto_tagging_max_tags'] ?? 5;
			?>
			<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[auto_tagging_max_tags]" value="<?php echo esc_attr( $value ); ?>" min="1" max="20" class="small-text" />
			<p class="description"><?php esc_html_e( 'Maximum number of tags to generate per post (1-20).', 'ai-seo-tools' ); ?></p>
			<?php
		}

		/**
		 * Renders the Confidence Threshold field.
		 */
		public function render_auto_tagging_confidence_threshold_field(): void {
			$options = get_option( $this->option_name );
			$value = $options['auto_tagging_confidence_threshold'] ?? 0.75;
			?>
			<input type="number" step="0.01" name="<?php echo esc_attr( $this->option_name ); ?>[auto_tagging_confidence_threshold]" value="<?php echo esc_attr( $value ); ?>" min="0" max="1" class="small-text" />
			<p class="description"><?php esc_html_e( 'Confidence threshold for tag relevance (0.00-1.00).', 'ai-seo-tools' ); ?></p>
			<?php
		}

		/**
		 * Renders the Stop-Words List field.
		 */
		public function render_auto_tagging_stop_words_field(): void {
			$options = get_option( $this->option_name );
			$value = $options['auto_tagging_stop_words'] ?? '';
			?>
			<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[auto_tagging_stop_words]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
			<p class="description"><?php esc_html_e( 'Comma-separated list of words to exclude from generated tags.', 'ai-seo-tools' ); ?></p>
			<?php
		}

		/**
		 * Renders the checkbox for enabling the Auto Tagging module.
		 */
		public function render_enable_auto_tagging_field(): void {
			$options = get_option( $this->option_name );
			$is_enabled = ( $options['enable_auto_tagging_module'] ?? '1' ) === '1';
			?>
			<label for="enable_auto_tagging_module">
				<input type='checkbox' id='enable_auto_tagging_module' name='<?php echo esc_attr( $this->option_name ); ?>[enable_auto_tagging_module]' value='1' <?php checked( $is_enabled, true ); ?> />
				<?php esc_html_e( 'Enable', 'ai-seo-tools' ); ?>
			</label>
			<?php
		}

        /**
         * Enqueue admin styles for the settings page.
         */
        public function enqueue_settings_styles($hook) {
            if ($hook === 'toplevel_page_ai-seo-tools-settings' || strpos($hook, 'ai-seo-tools-settings') !== false) {
                wp_enqueue_style(
                    'ai-seo-settings-css',
                    AI_SEO_TOOLS_URL . 'admin/assets/css/ai-seo-settings.css',
                    array(),
                    AI_SEO_TOOLS_VERSION
                );
            }
        }
	}
} 