<?php

namespace Elementor\Testing\Modules\GlobalClasses;

use Elementor\Core\Utils\Collection;
use Elementor\Modules\AtomicWidgets\Styles\Atomic_Styles_Manager;
use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
use Elementor\Modules\GlobalClasses\Atomic_Global_Styles;
use ElementorEditorTesting\Elementor_Test_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Test_Atomic_Global_Styles_Order extends Elementor_Test_Base {
	private $mock_atomic_styles_manager;

	public function setUp(): void {
		parent::setUp();

		$this->mock_atomic_styles_manager = $this->createMock( Atomic_Styles_Manager::class );

		remove_all_actions( 'elementor/atomic-widgets/styles/register' );
	}

	public function test_register_styles_respects_order() {
		// Arrange.
		$global_classes = new Atomic_Global_Styles();
		$global_classes->register_hooks();
		$context = is_preview() ? Global_Classes_Repository::CONTEXT_PREVIEW : Global_Classes_Repository::CONTEXT_FRONTEND;

		// We intentionally define items in one order (A, B) but 'order' in reverse (B, A).
		// Note: Using an array with keys ensures insertion order is preserved in PHP arrays.
		$items = [
			'item-a' => [
				'id' => 'item-a',
				'type' => 'class',
				'label' => 'Label A',
				'variants' => [],
			],
			'item-b' => [
				'id' => 'item-b',
				'type' => 'class',
				'label' => 'Label B',
				'variants' => [],
			],
		];

		$order = [ 'item-b', 'item-a' ];

		Global_Classes_Repository::make()->put( $items, $order );

		// Expected result should follow the 'order' array: B then A.
		// Atomic_Global_Styles transforms 'id' to match 'label'.
		$expected = [
			[
				'id' => 'Label B',
				'type' => 'class',
				'label' => 'Label B',
				'variants' => [],
			],
			[
				'id' => 'Label A',
				'type' => 'class',
				'label' => 'Label A',
				'variants' => [],
			],
		];

		$this->mock_atomic_styles_manager
			->expects( $this->once() )
			->method( 'register' )
			->with(
				[ Atomic_Global_Styles::STYLES_KEY, $context ],
				$this->callback( function ( $callback ) use ( $expected ) {
					// Execute the callback to get the styles
					$styles = $callback();

					// Use strict comparison to verify order.
					// Note: assertSame checks strict equality including order for indexed arrays.
					$this->assertSame( $expected, $styles, 'Styles should be registered in the order defined by the "order" property.' );

					return true;
				} )
			);

		// Act.
		do_action( 'elementor/atomic-widgets/styles/register', $this->mock_atomic_styles_manager );
	}
}
