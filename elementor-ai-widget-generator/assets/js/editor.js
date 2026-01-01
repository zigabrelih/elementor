jQuery( window ).on( 'elementor:init', function() {
	elementor.on( 'panel:init', function() {
		// Create the button
		var $button = jQuery( '<div id="elementor-ai-widget-generator-trigger" class="elementor-panel-footer-tool tooltip-target" data-tooltip="Generate AI Widget"><i class="eicon-code" aria-hidden="true"></i></div>' );

		// Append to footer
		jQuery( '#elementor-panel-footer-tools' ).prepend( $button );

		$button.on( 'click', function() {
			openAIWidgetModal();
		} );
	} );

	function openAIWidgetModal() {
		var modalId = 'elementor-ai-widget-generator-modal';

		// Check if modal already exists (to avoid duplicates)
		var existingModal = elementor.common.dialogsManager.getWidget( modalId );
		// getWidget usually returns undefined if not found or the widget instance.
		// However, Elementor's dialogsManager implementation might vary.
		// We'll trust createWidget handles ID collision or we create a new one each time.

		var modal = elementor.common.dialogsManager.createWidget( 'lightbox', {
			id: modalId,
			headerMessage: 'Generate New Widget with AI',
			message: `
				<div class="ai-widget-generator-content">
					<p>Describe the widget you want to create (e.g., "A pricing card with a toggle for monthly/yearly").</p>
					<textarea id="ai-widget-prompt" placeholder="Enter your instructions here..."></textarea>
					<div class="ai-actions">
						<button id="ai-widget-submit" class="elementor-button elementor-button-default">
							<i class="eicon-wand"></i> Generate Widget
						</button>
					</div>
					<div id="ai-widget-status"></div>
				</div>
			`,
			position: {
				my: 'center',
				at: 'center'
			},
			onReady: function() {
				var $submitBtn = this.getElements( 'message' ).find( '#ai-widget-submit' );
				var $prompt = this.getElements( 'message' ).find( '#ai-widget-prompt' );
				var $status = this.getElements( 'message' ).find( '#ai-widget-status' );

				$submitBtn.on( 'click', function() {
					var promptText = $prompt.val();
					if ( ! promptText ) {
						$status.html( '<span style="color:red;">Please enter a prompt.</span>' );
						return;
					}

					$status.html( '<i class="eicon-loading eicon-animation-spin"></i> Generating... This may take a moment.' );
					$submitBtn.prop( 'disabled', true );

					jQuery.ajax( {
						url: ElementorAIWidgetGeneratorConfig.ajaxUrl,
						type: 'POST',
						data: {
							action: 'elementor_ai_generate_widget',
							prompt: promptText,
							security: ElementorAIWidgetGeneratorConfig.nonce
						},
						success: function( response ) {
							if ( response.success ) {
								$status.html( '<span style="color:green;">Widget generated successfully! Reloading editor...</span>' );
								setTimeout( function() {
									location.reload();
								}, 1000 );
							} else {
								$status.html( '<span style="color:red;">Error: ' + response.data + '</span>' );
								$submitBtn.prop( 'disabled', false );
							}
						},
						error: function( xhr, status, error ) {
							$status.html( '<span style="color:red;">Server Error: ' + error + '</span>' );
							$submitBtn.prop( 'disabled', false );
						}
					} );
				} );
			}
		} );

		modal.show();
	}
} );
