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

		// Register CPT
		add_action( 'init', [ $this, 'register_cpt' ] );

		// New AJAX handlers for Chat/History
		add_action( 'wp_ajax_elementor_ai_get_widgets', [ $this, 'ajax_get_widgets' ] );
		add_action( 'wp_ajax_elementor_ai_chat_submit', [ $this, 'ajax_chat_submit' ] );
		add_action( 'wp_ajax_elementor_ai_load_history', [ $this, 'ajax_load_history' ] );
	}

	public function register_cpt() {
		register_post_type( 'e_ai_widget', [
			'public' => false,
			'label' => 'AI Widgets',
			'supports' => [ 'title', 'custom-fields' ],
			'show_ui' => true,
			'show_in_menu' => false,
		] );
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

	public function ajax_get_widgets() {
		check_ajax_referer( 'elementor_ai_widget_generator_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Denied' );

		$args = [
			'post_type' => 'e_ai_widget',
			'posts_per_page' => -1,
			'post_status' => 'any'
		];
		$query = new WP_Query( $args );
		$widgets = [];
		foreach ( $query->posts as $post ) {
			$widgets[] = [
				'id' => $post->ID,
				'title' => $post->post_title,
				'date' => $post->post_date,
			];
		}
		wp_send_json_success( $widgets );
	}

	public function ajax_load_history() {
		check_ajax_referer( 'elementor_ai_widget_generator_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Denied' );

		$post_id = intval( $_POST['post_id'] );
		$history = get_post_meta( $post_id, '_ai_widget_history', true );
		if ( ! is_array( $history ) ) $history = [];

		wp_send_json_success( $history );
	}

	public function ajax_chat_submit() {
		check_ajax_referer( 'elementor_ai_widget_generator_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Denied' );

		$api_key = get_option( 'elementor_ai_api_key' );
		if ( empty( $api_key ) ) wp_send_json_error( 'API Key Missing' );

		$prompt = sanitize_text_field( $_POST['prompt'] );
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$history = [];
		$current_code = '';

		if ( $post_id ) {
			$history = get_post_meta( $post_id, '_ai_widget_history', true );
			if ( ! is_array( $history ) ) $history = [];
			$current_code = get_post_meta( $post_id, '_ai_widget_code', true );
			// Also need class name
			$class_name = get_post_meta( $post_id, '_ai_widget_class', true );
			if ( ! $class_name ) {
				// Fallback if missing
				$class_name = 'Elementor_AI_Widget_' . uniqid();
			}
		} else {
			// New Widget
			$class_name = 'Elementor_AI_Widget_' . uniqid();
			// Create Post
			$post_id = wp_insert_post( [
				'post_title' => $prompt, // Use first prompt as title initially
				'post_type' => 'e_ai_widget',
				'post_status' => 'publish'
			] );
			update_post_meta( $post_id, '_ai_widget_class', $class_name );
		}

		// Prepare LLM Context
		$messages = $this->prepare_llm_messages( $prompt, $history, $current_code, $class_name );

		// Call API
		$response_content = $this->call_openrouter_api( $messages, $api_key );
		if ( ! $response_content ) wp_send_json_error( 'API Error' );

		// Extract Code
		$new_code = $this->extract_code( $response_content );

		if ( empty( $new_code ) ) {
			// It might be just a chat message without code?
			// For now, we assume we always want code updates.
			// If no code block found, maybe just save the message.
			// But the goal is to generate widgets.
			// Let's assume failure if no code for now, or improve regex.
			wp_send_json_error( 'No code found in response.' );
		}

		// Validate
		if ( $this->is_valid_code( $new_code, $class_name ) ) {
			// Save to File
			$file_path = $this->widgets_dir . '/' . $class_name . '.php';
			file_put_contents( $file_path, $new_code );

			// Update Meta
			update_post_meta( $post_id, '_ai_widget_code', $new_code );

			// Update History
			$history[] = [ 'role' => 'user', 'content' => $prompt ];
			$history[] = [ 'role' => 'assistant', 'content' => 'Code updated successfully.' ]; // We don't save full code in history to save tokens, we save it in meta
			update_post_meta( $post_id, '_ai_widget_history', $history );

			wp_send_json_success( [
				'post_id' => $post_id,
				'history' => $history,
				'message' => 'Widget updated!'
			] );
		} else {
			wp_send_json_error( 'Generated code failed validation.' );
		}
	}

	private function prepare_llm_messages( $user_prompt, $history, $current_code, $class_name ) {
		$system_prompt = "You are an expert Elementor Widget developer.

		Task:
		- Maintain the PHP class: $class_name
		- Extend \Elementor\Widget_Base
		- Output ONLY valid PHP code for the entire class.
		- Do not use markdown blocks. Start with <?php.

		Context:
		- If code exists, you must modify it based on the user request.
		- If this is a new request, create the class from scratch.

		Current Code:
		$current_code
		";

		// We construct messages.
		// To save tokens, we might not send full history if it's long, but here we send it.
		// However, we stored 'Code updated' in history, which is not useful for the LLM.
		// The LLM needs the User prompts.
		// Better strategy:
		// 1. System Prompt (includes Current Code).
		// 2. New User Prompt.
		// We ignore old user prompts because 'Current Code' represents the sum of all previous prompts.
		// This is a 'State + Update' model rather than 'Chat History' model.

		return [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user', 'content' => $user_prompt ]
		];
	}

	private function call_openrouter_api( $messages, $api_key ) {
		$model = get_option( 'elementor_ai_model', 'x-ai/grok-code-fast-1' );

		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => site_url(),
				'X-Title'       => 'Elementor AI Widget Generator',
			],
			'body' => json_encode( [
				'model' => $model,
				'messages' => $messages,
			] ),
			'timeout' => 60,
		] );

		if ( is_wp_error( $response ) ) return false;

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['choices'][0]['message']['content'] ?? false;
	}

	private function extract_code( $content ) {
		$content = preg_replace( '/^```php/m', '', $content );
		$content = preg_replace( '/^```/m', '', $content );
		return trim( $content );
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
