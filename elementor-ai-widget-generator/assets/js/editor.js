jQuery( window ).on( 'elementor:init', function() {
	elementor.on( 'panel:init', function() {
		// Create the button
		var $button = jQuery( '<div id="elementor-ai-widget-generator-trigger" class="elementor-panel-footer-tool tooltip-target" data-tooltip="Manage AI Widgets"><i class="eicon-code" aria-hidden="true"></i></div>' );

		// Append to footer
		jQuery( '#elementor-panel-footer-tools' ).prepend( $button );

		$button.on( 'click', function() {
			openAIWidgetManager();
		} );
	} );

	function openAIWidgetManager() {
		var modalId = 'elementor-ai-widget-manager-modal';
		var existingModal = elementor.common.dialogsManager.getWidget( modalId );
		if ( existingModal ) { existingModal.destroy(); } // Always recreate to be safe

		var modal = elementor.common.dialogsManager.createWidget( 'lightbox', {
			id: modalId,
			headerMessage: 'AI Widget Manager',
			message: `
				<div class="ai-widget-manager">
					<div id="ai-widget-list-view">
						<button id="ai-create-new-btn" class="elementor-button elementor-button-default"><i class="eicon-plus"></i> Create New Widget</button>
						<div class="ai-widget-list-container">
							<p>Loading widgets...</p>
						</div>
					</div>
					<div id="ai-widget-chat-view" style="display:none;">
						<div class="ai-chat-header">
							<button id="ai-back-btn" class="elementor-button elementor-button-default"><i class="eicon-arrow-left"></i> Back</button>
							<span id="ai-chat-title">New Widget</span>
							<button id="ai-reload-btn" class="elementor-button elementor-button-success" style="display:none;">Reload & Preview</button>
						</div>
						<div id="ai-chat-history"></div>
						<div class="ai-chat-input-area">
							<textarea id="ai-chat-prompt" placeholder="Describe changes..."></textarea>
							<button id="ai-chat-send" class="elementor-button elementor-button-primary"><i class="eicon-send"></i></button>
						</div>
					</div>
				</div>
			`,
			position: { my: 'center', at: 'center' },
			onReady: function() {
				var self = this;
				var $listView = self.getElements('message').find('#ai-widget-list-view');
				var $chatView = self.getElements('message').find('#ai-widget-chat-view');
				var $listContainer = self.getElements('message').find('.ai-widget-list-container');
				var $history = self.getElements('message').find('#ai-chat-history');
				var $prompt = self.getElements('message').find('#ai-chat-prompt');
				var $sendBtn = self.getElements('message').find('#ai-chat-send');
				var currentPostId = 0;

				// Load List
				loadWidgetList();

				// Event Handlers
				self.getElements('message').find('#ai-create-new-btn').on('click', function() {
					startChat( 0, 'New Widget' );
				});

				self.getElements('message').find('#ai-back-btn').on('click', function() {
					$chatView.hide();
					$listView.show();
					loadWidgetList();
				});

				self.getElements('message').find('#ai-reload-btn').on('click', function() {
					location.reload();
				});

				$sendBtn.on( 'click', function() {
					sendMessage();
				});

				// Functions
				function loadWidgetList() {
					$listContainer.html( '<i class="eicon-loading eicon-animation-spin"></i>' );
					jQuery.ajax({
						url: ElementorAIWidgetGeneratorConfig.ajaxUrl,
						type: 'POST',
						data: {
							action: 'elementor_ai_get_widgets',
							security: ElementorAIWidgetGeneratorConfig.nonce
						},
						success: function( res ) {
							if ( res.success ) {
								renderList( res.data );
							} else {
								$listContainer.text( 'Error loading widgets.' );
							}
						}
					});
				}

				function renderList( widgets ) {
					if ( widgets.length === 0 ) {
						$listContainer.html( '<p>No widgets found. Create one!</p>' );
						return;
					}
					var html = '<ul class="ai-widget-list">';
					widgets.forEach( function( w ) {
						html += '<li data-id="' + w.id + '" data-title="' + w.title + '">' +
								'<strong>' + w.title + '</strong> <small>' + w.date + '</small>' +
								'<button class="edit-ai-widget elementor-button elementor-button-default elementor-button-sm">Edit</button>' +
								'</li>';
					});
					html += '</ul>';
					$listContainer.html( html );

					$listContainer.find('.edit-ai-widget').on('click', function() {
						var $li = jQuery(this).closest('li');
						startChat( $li.data('id'), $li.data('title') );
					});
				}

				function startChat( id, title ) {
					currentPostId = id;
					$listView.hide();
					$chatView.show();
					self.getElements('message').find('#ai-chat-title').text( title );
					$history.html( '' );
					self.getElements('message').find('#ai-reload-btn').hide();

					if ( id !== 0 ) {
						loadHistory( id );
					} else {
						appendMessage( 'system', 'Hello! Describe the widget you want to create.' );
					}
				}

				function loadHistory( id ) {
					$history.html( '<i class="eicon-loading eicon-animation-spin"></i>' );
					jQuery.ajax({
						url: ElementorAIWidgetGeneratorConfig.ajaxUrl,
						type: 'POST',
						data: {
							action: 'elementor_ai_load_history',
							post_id: id,
							security: ElementorAIWidgetGeneratorConfig.nonce
						},
						success: function( res ) {
							$history.empty();
							if ( res.success && res.data.length > 0 ) {
								res.data.forEach( function( msg ) {
									appendMessage( msg.role, msg.content );
								});
							} else {
								appendMessage( 'system', 'No history yet.' );
							}
						}
					});
				}

				function sendMessage() {
					var text = $prompt.val();
					if ( ! text ) return;

					appendMessage( 'user', text );
					$prompt.val('');
					$sendBtn.prop('disabled', true);
					appendMessage( 'system', '<i class="eicon-loading eicon-animation-spin"></i> Generating code...' );

					jQuery.ajax({
						url: ElementorAIWidgetGeneratorConfig.ajaxUrl,
						type: 'POST',
						data: {
							action: 'elementor_ai_chat_submit',
							prompt: text,
							post_id: currentPostId,
							security: ElementorAIWidgetGeneratorConfig.nonce
						},
						success: function( res ) {
							$history.find('div:last-child').remove(); // Remove loading
							$sendBtn.prop('disabled', false);
							if ( res.success ) {
								currentPostId = res.data.post_id;
								appendMessage( 'assistant', 'Widget updated successfully!' );
								self.getElements('message').find('#ai-reload-btn').show();
							} else {
								appendMessage( 'system', 'Error: ' + res.data );
							}
						},
						error: function() {
							$history.find('div:last-child').remove();
							$sendBtn.prop('disabled', false);
							appendMessage( 'system', 'Server Error.' );
						}
					});
				}

				function appendMessage( role, text ) {
					var cls = role === 'user' ? 'ai-msg-user' : 'ai-msg-system';
					if ( role === 'assistant' ) cls = 'ai-msg-assistant';
					$history.append( '<div class="ai-msg ' + cls + '">' + text + '</div>' );
					$history.scrollTop( $history[0].scrollHeight );
				}
			}
		});

		modal.show();
	}
} );
