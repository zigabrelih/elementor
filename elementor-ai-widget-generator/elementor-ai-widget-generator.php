<?php
/**
 * Plugin Name: Elementor AI Widget Generator
 * Description: An extension for Elementor to generate widgets using AI.
 * Version: 1.0.0
 * Author: Jules
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Elementor_AI_Widget_Generator {

	const VERSION = '1.0.0';
	private $widgets_dir;

	public function __construct() {
		$this->widgets_dir = wp_upload_dir()['basedir'] . '/elementor-ai-widgets';

		if ( ! file_exists( $this->widgets_dir ) ) {
			wp_mkdir_p( $this->widgets_dir );
		}

		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ] );
		add_action( 'wp_ajax_elementor_ai_generate_widget', [ $this, 'ajax_generate_widget' ] );

		// Load generated widgets
		add_action( 'elementor/widgets/register', [ $this, 'register_generated_widgets' ] );
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
				// Extract class name from file content or convention
				// For simplicity, we assume the class name matches the filename with some conversion
				// Or we rely on the file declaring a class and we find declared classes?
				// Better: We define a standard way to find the class.
				// Let's assume the file returns the class name or instance? No, standard is class declaration.

				$content = file_get_contents( $file );
				if ( preg_match( '/class\s+(\w+)\s+extends/', $content, $matches ) ) {
					$class_name = $matches[1];
					if ( class_exists( $class_name ) ) {
						$widgets_manager->register( new $class_name() );
					}
				}
			} catch ( \Throwable $e ) {
				// Log error, maybe delete file if it causes persistent issues?
				// For now, just ignore failed widgets so they don't crash editor
				error_log( 'Failed to load widget: ' . $file . ' - ' . $e->getMessage() );
			}
		}
	}

	public function ajax_generate_widget() {
		check_ajax_referer( 'elementor_ai_widget_generator_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$prompt = sanitize_text_field( $_POST['prompt'] );
		$widget_id = uniqid();
		$class_name = 'Elementor_AI_Widget_' . $widget_id;
		$file_path = $this->widgets_dir . '/' . $class_name . '.php';

		// Get code from LLM
		$widget_code = $this->get_llm_generated_code( $prompt, $class_name );

		if ( empty( $widget_code ) ) {
			wp_send_json_error( 'Failed to generate code.' );
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
		// Instructions for LLM
		$system_prompt = "You are an expert Elementor Widget developer.
		Create a complete PHP class for a new Elementor Widget.

		Requirements:
		1. Class name MUST be: $class_name
		2. Extend \Elementor\Widget_Base
		3. Implement get_name(), get_title(), get_icon(), get_categories()
		4. Implement register_controls() with useful controls based on user request.
		5. Implement render() to output HTML.
		6. Start the file with the opening <?php tag.
		7. Ensure code is secure and follows WP standards.

		User Request: $user_prompt
		";

		// MOCK LLM CALL
		// In a real scenario, this would call OpenAI or similar.
		// For this demo, we use a robust template and inject the user request slightly.

		return $this->mock_llm_logic( $class_name, $user_prompt );
	}

	private function mock_llm_logic( $class_name, $user_prompt ) {
		// A simple template that changes based on keywords in prompt
		$title = 'AI Widget';
		if ( strpos( $user_prompt, 'header' ) !== false ) $title = 'AI Header';
		if ( strpos( $user_prompt, 'button' ) !== false ) $title = 'AI Button';

		$render_content = "<h3>$title</h3><p>" . esc_html( $user_prompt ) . "</p>";

		// If user asks for color control
		$color_control = "";
		if ( strpos( $user_prompt, 'color' ) !== false ) {
			$color_control = "
		\$this->add_control(
			'color',
			[
				'label' => esc_html__( 'Color', 'elementor-ai-widget-generator' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} h3' => 'color: {{VALUE}}',
				],
			]
		);";
		}

		return "<?php
class $class_name extends \Elementor\Widget_Base {

	public function get_name() {
		return '" . strtolower( $class_name ) . "';
	}

	public function get_title() {
		return esc_html__( '$title', 'elementor-ai-widget-generator' );
	}

	public function get_icon() {
		return 'eicon-code';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	protected function register_controls() {
		\$this->start_controls_section(
			'content_section',
			[
				'label' => esc_html__( 'Content', 'elementor-ai-widget-generator' ),
				'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
			]
		);

		\$this->add_control(
			'title_text',
			[
				'label' => esc_html__( 'Title Text', 'elementor-ai-widget-generator' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( '$title', 'elementor-ai-widget-generator' ),
			]
		);
		$color_control

		\$this->end_controls_section();
	}

	protected function render() {
		\$settings = \$this->get_settings_for_display();
		?>
		<div class=\"ai-widget-content\">
			<h3><?php echo esc_html( \$settings['title_text'] ); ?></h3>
			<p>Original Prompt: " . esc_html( $user_prompt ) . "</p>
		</div>
		<?php
	}
}
";
	}

	private function is_valid_code( $code, $class_name ) {
		// 1. Basic syntax check using token_get_all is weak.
		// 2. We can try to eval() it in a sandbox? eval is dangerous.
		// 3. Write to temp file and try to include it.

		$temp_file = $this->widgets_dir . '/temp_' . uniqid() . '.php';
		file_put_contents( $temp_file, $code );

		try {
			// Check syntax lint if possible (requires exec)
			// $output = shell_exec("php -l $temp_file");

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
