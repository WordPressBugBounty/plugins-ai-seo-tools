=== AI SEO Tools ===
Contributors: kingaddons, alxrlov, olgadev
Tags: seo, ai, alt text, images, accessibility
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 2.0.3
Requires PHP: 8.0
License: GPL v3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI SEO Tools uses AI to automatically improve your site's SEO, including generating image alt text, content refresh and auto tagging.

== Description ==
AI SEO Tools leverages generative AI to automate and enhance your WordPress SEO. Features include:
* Automatic image alt text generation.
* Content Refresh & SEO Optimizer: Analyze and refresh old posts, suggest AI-powered updates, keywords, and meta descriptions.
* Auto Tagging for Posts: Automatically generate semantically relevant tags.
* Bulk Alt Text Generation: Generate alt text for multiple images in bulk with configurable delay and detail level.
* Bulk Tagging: Process multiple posts for auto-tagging in bulk.
* Bulk Append Tags: Append new AI-generated tags to posts with existing tags.
* Bulk Regenerate Tags: Regenerate tags for posts to keep metadata fresh.
* Custom Alt Text Language: Generate alt text in any specified language (e.g., Spanish, French).
* Custom Alt Text Prompt: Customize the AI prompt for alt text generation.
* Dynamic OpenAI Model Selection: Choose vision models, refresh the model list, and cache results.

Enjoy all AI features at OpenAI cost, with no additional fees from us!

### Automatic Image Alt Text Generator

Summary: This module automatically generates descriptive alt text for your Media Library images using AI, improving accessibility and SEO.

Return Value: The generated alt text is saved to each image's ALT attribute.

Examples:
* Enable the Alt Text Generator module in Settings -> AI SEO Tools.
* Visit the Alt Text Generator tab to view statistics and generate alt text for one or all images.

### Content Refresh & SEO Optimizer

Summary: This module uses generative AI to analyze your existing posts and suggest updates or rewrites for outdated sections, recommend low-competition keywords, and auto-generate meta descriptions or summaries. It helps keep your content up-to-date and SEO-friendly, saving hours of manual editing and improving your site's search rankings.

Return Value: AI-powered content suggestions for your posts.

Examples:
- Enable the module in the plugin settings.
- Visit the Content Refresh tab for more information and future controls.

### Auto Tagging

Summary: Automatically generate semantically relevant tags for your posts using AI to enhance metadata and internal linking.

Return Value: AI-generated tags applied to each post.

Examples:
* Enable the Auto Tagging module in Settings -> AI SEO Tools.
* Visit the Auto Tagging tab to bulk tag your published posts.

### Bulk Processing Overview

Summary: Perform bulk operations for alt text generation, tagging, appending tags, and regenerating tags with progress feedback.

Settings:
* Bulk Processing Delay: Seconds to wait between API calls to avoid rate limits.
* Image Detail Level: Controls granularity of analysis ('low' or 'high').

Examples:
* In the Alt Text Generator tab, click 'Start Bulk Generation' to process multiple images.
* In the Auto Tagging tab, click 'Start Bulk Tagging', 'Start Bulk Append', or 'Start Bulk Regenerate' as needed.

### Customization

Summary: Customize alt text language, prompt, and OpenAI model selection for fine-tuned AI behavior.

Settings:
* Custom Alt Text Language: Generate alt text in any specified language.
* Custom Alt Text Prompt: Provide a custom prompt for alt text generation.
* Dynamic OpenAI Model Selection: Choose the model and refresh the available list.

Examples:
* Check 'Generate alt text in a non-English language' and enter 'German'.
* Click the 'Refresh List' button next to the Model selection in Settings to update available models. 

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/ai-seo-tools` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the Settings->AI SEO Tools screen to configure the plugin.

== Frequently Asked Questions ==
= Does this plugin require an OpenAI API key? =
Yes, you must provide your own OpenAI API key.

== External services ==
This plugin connects to the OpenAI API (https://api.openai.com) to generate AI-powered content for alt text, content refresh suggestions, and post tagging.
- What data is sent: It sends your image metadata (for alt text), post content (for suggestions), and any custom prompts or language preferences you have configured.
- When: Data is sent when you manually generate alt text, initiate bulk generation, analyze content refresh, or generate/append/regenerate tags.
- Why: AI processing is performed by OpenAI models to provide advanced SEO and accessibility enhancements.
- Service provider: OpenAI Inc.
  - Terms of Use: https://openai.com/policies/terms-of-use
  - Privacy Policy: https://openai.com/policies/privacy-policy

== Changelog ==
= 2.0.2 =
* Initial public release.