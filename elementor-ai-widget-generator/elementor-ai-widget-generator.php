<?php
/**
 * Plugin Name: Elementor AI Widget Generator
 * Description: An extension for Elementor to generate widgets using AI (OpenRouter).
 * Version: 1.1.0
 * Author: Jules
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Elementor_AI_Widget_Generator {

	const VERSION = '1.1.0';
	private $widgets_dir;
	private $option_group = 'elementor_ai_widget_generator_settings';

	public function __construct() {
		$this->widgets_dir = wp_upload_dir()['basedir'] . '/elementor-ai-widgets';

		if ( ! file_exists( $this->widgets_dir ) ) {
			wp_mkdir_p( $this->widgets_dir );
		}

		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ] );
		add_action( 'wp_ajax_elementor_ai_generate_widget', [ $this, 'ajax_generate_widget' ] );

		// Load generated widgets
		add_action( 'elementor/widgets/register', [ $this, 'register_generated_widgets' ] );

		// Admin Settings
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_admin_menu() {
		add_options_page(
			'Elementor AI Widget Generator',
			'AI Widget Generator',
			'manage_options',
			'elementor-ai-widget-generator',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( $this->option_group, 'elementor_ai_api_key' );
		register_setting( $this->option_group, 'elementor_ai_model' );

		add_settings_section(
			'elementor_ai_main_section',
			'API Configuration',
			null,
			'elementor-ai-widget-generator'
		);

		add_settings_field(
			'elementor_ai_api_key',
			'OpenRouter API Key',
			[ $this, 'render_api_key_field' ],
			'elementor-ai-widget-generator',
			'elementor_ai_main_section'
		);

		add_settings_field(
			'elementor_ai_model',
			'AI Model',
			[ $this, 'render_model_field' ],
			'elementor-ai-widget-generator',
			'elementor_ai_main_section'
		);
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1>Elementor AI Widget Generator Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_group );
				do_settings_sections( 'elementor-ai-widget-generator' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_api_key_field() {
		$api_key = get_option( 'elementor_ai_api_key' );
		?>
		<input type="password" name="elementor_ai_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
		<p class="description">Enter your OpenRouter API Key.</p>
		<?php
	}

	public function render_model_field() {
		$selected_model = get_option( 'elementor_ai_model', 'x-ai/grok-code-fast-1' );
		$models = [
			'x-ai/grok-code-fast-1' => 'Grok Code Fast 1',
			'anthropic/claude-opus-4.5' => 'Claude Opus 4.5',
			'mistralai/devstral-2512:free' => 'Devstral 2512 (Free)',
			'anthropic/claude-sonnet-4.5' => 'Claude Sonnet 4.5',
			'minimax/minimax-m2' => 'Minimax M2',
			'google/gemini-3-flash-preview' => 'Gemini 3 Flash Preview',
			'openai/gpt-5.2' => 'GPT 5.2',
			'kwaipilot/kat-coder-pro:free' => 'Kat Coder Pro (Free)',
			'xiaomi/mimo-v2-flash-20251210' => 'Mimo V2 Flash 20251210'
		];
		?>
		<select name="elementor_ai_model">
			<?php foreach ( $models as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected_model, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function enqueue_editor_scripts() {
		wp_enqueue_script(
			'elementor-ai-widget-generator',
			plugins_url( 'assets/js/editor.js', __FILE__ ),
			[ 'elementor-editor', 'jquery' ],
			self::VERSION,
			true
		);

		wp_localize_script(
			'elementor-ai-widget-generator',
			'ElementorAIWidgetGeneratorConfig',
			[
				'nonce' => wp_create_nonce( 'elementor_ai_widget_generator_nonce' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			]
		);

		wp_enqueue_style(
			'elementor-ai-widget-generator',
			plugins_url( 'assets/css/editor.css', __FILE__ ),
			[],
			self::VERSION
		);
	}

	public function register_generated_widgets( $widgets_manager ) {
		foreach ( glob( $this->widgets_dir . '/*.php' ) as $file ) {
			try {
				include_once $file;

				$content = file_get_contents( $file );
				if ( preg_match( '/class\s+(\w+)\s+extends/', $content, $matches ) ) {
					$class_name = $matches[1];
					if ( class_exists( $class_name ) ) {
						$widgets_manager->register( new $class_name() );
					}
				}
			} catch ( \Throwable $e ) {
				error_log( 'Failed to load widget: ' . $file . ' - ' . $e->getMessage() );
			}
		}
	}

	public function ajax_generate_widget() {
		check_ajax_referer( 'elementor_ai_widget_generator_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$api_key = get_option( 'elementor_ai_api_key' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( 'API Key is missing. Please configure it in Settings.' );
		}

		$prompt = sanitize_text_field( $_POST['prompt'] );
		$widget_id = uniqid();
		$class_name = 'Elementor_AI_Widget_' . $widget_id;
		$file_path = $this->widgets_dir . '/' . $class_name . '.php';

		// Get code from LLM
		$widget_code = $this->get_llm_generated_code( $prompt, $class_name );

		if ( empty( $widget_code ) ) {
			wp_send_json_error( 'Failed to generate code from API.' );
		}

		// Sandbox: Test if code is valid
		if ( $this->is_valid_code( $widget_code, $class_name ) ) {
			file_put_contents( $file_path, $widget_code );
			wp_send_json_success( [ 'message' => 'Widget generated successfully!' ] );
		} else {
			wp_send_json_error( 'Generated code failed validation/syntax check.' );
		}
	}

	private function get_llm_generated_code( $user_prompt, $class_name ) {
		$model = get_option( 'elementor_ai_model', 'x-ai/grok-code-fast-1' );
		$api_key = get_option( 'elementor_ai_api_key' );

		// Instructions for LLM
		$system_prompt = "You are an expert Elementor Widget developer.
		Create a complete PHP class for a new Elementor Widget.

		Requirements:
		1. Class name MUST be: $class_name
		2. Extend \Elementor\Widget_Base
		3. Implement get_name(), get_title(), get_icon(), get_categories()
		4. Implement register_controls() with useful controls based on user request.
		5. Implement render() to output HTML.
		6. Output ONLY the PHP code. Do not include markdown code blocks (```php) if possible, or ensure they can be stripped.
		7. Start the file with the opening <?php tag.
		8. Ensure code is secure and follows WP standards.

		User Request: $user_prompt
		";

		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => site_url(),
				'X-Title'       => 'Elementor AI Widget Generator',
			],
			'body' => json_encode( [
				'model' => $model,
				'messages' => [
					[
						'role' => 'system',
						'content' => $system_prompt,
					],
					[
						'role' => 'user',
						'content' => $user_prompt,
					],
				],
			] ),
			'timeout' => 60,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'OpenRouter API Error: ' . $response->get_error_message() );
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$content = $data['choices'][0]['message']['content'];
			// Clean up code (strip markdown blocks if present)
			$content = preg_replace( '/^```php/m', '', $content );
			$content = preg_replace( '/^```/m', '', $content );
			return trim( $content );
		} else {
			error_log( 'OpenRouter API Invalid Response: ' . $body );
			return '';
		}
	}

	private function is_valid_code( $code, $class_name ) {
		// 1. Basic syntax check using token_get_all is weak.
		// 2. We can try to eval() it in a sandbox? eval is dangerous.
		// 3. Write to temp file and try to include it.

		$temp_file = $this->widgets_dir . '/temp_' . uniqid() . '.php';
		file_put_contents( $temp_file, $code );

		try {
			// Fallback: Try to include it.
			// Since we wrapped it in a class, including it shouldn't execute side effects immediately
			// except defining the class.
			include_once $temp_file;

			if ( ! class_exists( $class_name ) ) {
				throw new Exception( "Class $class_name not found after inclusion." );
			}

			// If we are here, it parsed correctly.
			// Clean up
			unlink( $temp_file );
			return true;
		} catch ( \Throwable $e ) {
			// Syntax error or other issue
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
			return false;
		}
	}
}

new Elementor_AI_Widget_Generator();
