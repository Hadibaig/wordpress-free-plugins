/* global jQuery, hs3daciData */
jQuery( function ( $ ) {
	'use strict';

	var generatedData = {};

	try {
		var raw = $( '#hs3daci-generated-data' ).text();
		generatedData = raw ? JSON.parse( raw ) : {};
	} catch ( e ) {
		generatedData = {};
	}

	/**
	 * Generate button: kicks off a batched generation run and polls/loops
	 * through batches, updating a progress bar, until the run is complete.
	 * On multilingual sites, either generates one selected language or
	 * loops through all of them sequentially.
	 */
	$( '#hs3daci-generate-btn' ).on( 'click', function () {
		var $btn = $( this );
		var $status = $( '#hs3daci-generate-status' );
		var $progressWrap = $( '#hs3daci-progress-wrap' );
		var $progressBar = $( '#hs3daci-progress-bar' );
		var $progressText = $( '#hs3daci-progress-text' );
		var $langSelect = $( '#hs3daci-language-select' );

		var languagesToRun = [];
		if ( $langSelect.length ) {
			var selected = $langSelect.val();
			if ( selected ) {
				languagesToRun = [ selected ];
			} else {
				// "All languages": collect every option except the blank "All languages" one.
				$langSelect.find( 'option' ).each( function () {
					if ( $( this ).val() ) {
						languagesToRun.push( $( this ).val() );
					}
				} );
			}
		} else {
			languagesToRun = [ '' ]; // Single-language site: one run, no lang_code.
		}

		$btn.prop( 'disabled', true );
		$status.text( '' );
		$progressWrap.show();

		runLanguageQueue( languagesToRun, 0, $btn, $progressWrap, $progressBar, $progressText, $status );
	} );

	/**
	 * Runs generation for one language, then moves to the next in the list
	 * once complete. With a single-language site, languages is [ '' ] and
	 * this runs exactly once.
	 *
	 * @param {Array} languages List of language codes to process in order.
	 * @param {number} index Current position in the list.
	 * @param {jQuery} $btn Generate button.
	 * @param {jQuery} $progressWrap Progress bar container.
	 * @param {jQuery} $progressBar Progress bar fill element.
	 * @param {jQuery} $progressText Progress label element.
	 * @param {jQuery} $status Status text element.
	 */
	function runLanguageQueue( languages, index, $btn, $progressWrap, $progressBar, $progressText, $status ) {
		if ( index >= languages.length ) {
			$btn.prop( 'disabled', false );
			$status.text( 'Done!' );
			setTimeout( function () {
				$progressWrap.hide();
				$status.text( '' );
			}, 1500 );
			return;
		}

		var langCode = languages[ index ];
		var langLabel = langCode ? langCode.toUpperCase() : '';

		$progressBar.css( 'width', '0%' );
		$progressText.text( langLabel ? ( 'Starting ' + langLabel + '...' ) : 'Starting...' );

		$.post( hs3daciData.ajaxUrl, {
			action: 'hs3daci_generate_start',
			nonce: hs3daciData.nonce,
			lang_code: langCode,
			include_meta_description: $( '#hs3daci-include-meta' ).is( ':checked' )
		} ).done( function ( startResponse ) {
			if ( ! startResponse.success ) {
				var msg = ( startResponse.data && startResponse.data.message ) ? startResponse.data.message : 'Unknown error.';
				$status.text( 'Error: ' + msg );
				$btn.prop( 'disabled', false );
				$progressWrap.hide();
				return;
			}

			var total = startResponse.data.total || 0;

			if ( 0 === total ) {
				// Nothing to generate for this language; move to the next one.
				runLanguageQueue( languages, index + 1, $btn, $progressWrap, $progressBar, $progressText, $status );
				return;
			}

			runBatch( total, langCode, langLabel, function () {
				runLanguageQueue( languages, index + 1, $btn, $progressWrap, $progressBar, $progressText, $status );
			}, $btn, $progressBar, $progressText, $status );
		} ).fail( function () {
			$status.text( 'Request failed. Please try again.' );
			$btn.prop( 'disabled', false );
			$progressWrap.hide();
		} );
	}

	/**
	 * Recursively processes one batch at a time for the current language
	 * and updates the progress UI, calling onComplete once the server
	 * reports the run for this language is done.
	 *
	 * @param {number} total Total items in the queue (for percentage calc).
	 * @param {string} langCode Language code being processed (empty for single-language sites).
	 * @param {string} langLabel Human-readable label for status text.
	 * @param {Function} onComplete Called once this language's run finishes.
	 * @param {jQuery} $btn Generate button.
	 * @param {jQuery} $progressBar Progress bar fill element.
	 * @param {jQuery} $progressText Progress label element.
	 * @param {jQuery} $status Status text element.
	 */
	/**
	 * Recursively processes one batch at a time for the current language
	 * and updates the progress UI, calling onComplete once the server
	 * reports the run for this language is done.
	 * Auto-retries up to 3 times on transient server failures (e.g. timeout
	 * on a busy shared host) before giving up and showing an error.
	 *
	 * @param {number}   total      Total items in the queue (for percentage calc).
	 * @param {string}   langCode   Language code being processed.
	 * @param {string}   langLabel  Human-readable label for status text.
	 * @param {Function} onComplete Called once this language's run finishes.
	 * @param {jQuery}   $btn Generate button.
	 * @param {jQuery}   $progressBar Progress bar fill element.
	 * @param {jQuery}   $progressText Progress label element.
	 * @param {jQuery}   $status Status text element.
	 * @param {number}   retries    Remaining retry attempts (default 3).
	 */
	function runBatch( total, langCode, langLabel, onComplete, $btn, $progressBar, $progressText, $status, retries ) {
		if ( retries === undefined ) { retries = 3; }

		$.post( hs3daciData.ajaxUrl, {
			action: 'hs3daci_generate_batch',
			nonce: hs3daciData.nonce,
			lang_code: langCode
		} ).done( function ( response ) {
			if ( ! response.success ) {
				var msg = ( response.data && response.data.message ) ? response.data.message : 'Unknown error.';
				$status.text( 'Error: ' + msg );
				$btn.prop( 'disabled', false );
				return;
			}

			var processed = response.data.processed || 0;
			var pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 100;
			var prefix = langLabel ? ( langLabel + ': ' ) : '';

			$progressBar.css( 'width', pct + '%' );
			$progressText.text( prefix + processed + ' / ' + total + ' items processed' );

			if ( response.data.done ) {
				if ( ! langCode ) {
					generatedData = {
						llms_txt: response.data.llms_txt || '',
						llms_full_txt: response.data.llms_full_txt || '',
						generated_at: response.data.generated_at || ''
					};
					$( '#hs3daci-last-generated' ).text( 'Last generated: ' + generatedData.generated_at );
				}

				onComplete();
			} else {
				// Reset retries on each successful batch response.
				runBatch( total, langCode, langLabel, onComplete, $btn, $progressBar, $progressText, $status, 3 );
			}
		} ).fail( function () {
			if ( retries > 0 ) {
				// Transient server error — wait 2 seconds and retry automatically.
				$status.text( 'Retrying... (' + retries + ' attempts remaining)' );
				setTimeout( function () {
					runBatch( total, langCode, langLabel, onComplete, $btn, $progressBar, $progressText, $status, retries - 1 );
				}, 2000 );
			} else {
				$status.text( 'Request failed after 3 retries. Click Generate to resume from where it stopped.' );
				$btn.prop( 'disabled', false );
			}
		} );
	}

	/**
	 * View buttons: open the modal with the requested file content.
	 */
	$( '.hs3daci-view-btn' ).on( 'click', function () {
		var target = $( this ).data( 'target' );
		var content = generatedData[ target ] || '';
		var title = ( 'llms_full_txt' === target ) ? 'llms-full.txt' : 'llms.txt';

		if ( ! content ) {
			content = 'Nothing generated yet. Click "Generate Files" first.';
		}

		$( '#hs3daci-modal-title' ).text( title );
		$( '#hs3daci-modal-body' ).val( content );
		$( '#hs3daci-modal' ).show();
	} );

	$( '.hs3daci-modal-close' ).on( 'click', function () {
		$( '#hs3daci-modal' ).hide();
	} );

	$( '#hs3daci-modal' ).on( 'click', function ( e ) {
		if ( e.target === this ) {
			$( this ).hide();
		}
	} );

	/**
	 * Clear Analytics Data: wipes the logs table after confirmation.
	 */
	$( '#hs3daci-clear-logs-btn' ).on( 'click', function () {
		if ( ! window.confirm( 'This will permanently delete all recorded analytics data. Continue?' ) ) {
			return;
		}

		var $btn = $( this );
		var $status = $( '#hs3daci-clear-logs-status' );

		$btn.prop( 'disabled', true );
		$status.text( 'Clearing...' );

		$.post( hs3daciData.ajaxUrl, {
			action: 'hs3daci_clear_logs',
			nonce: hs3daciData.nonce
		} ).done( function ( response ) {
			if ( response.success ) {
				$status.text( 'Cleared.' );
				setTimeout( function () {
					window.location.reload();
				}, 600 );
			} else {
				$status.text( 'Error clearing data.' );
			}
		} ).fail( function () {
			$status.text( 'Request failed.' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	/**
	 * Verify Live File: checks for physical file conflicts and fetches the
	 * actual public URL (cache-busted) to compare against what's stored,
	 * then renders a plain-language explanation of the result.
	 */
	$( '.hs3daci-verify-btn' ).on( 'click', function () {
		var fileType = $( this ).data( 'file-type' );
		var $btn = $( this );
		var $result = $( '.hs3daci-verify-result[data-file-type="' + fileType + '"]' );

		$btn.prop( 'disabled', true );
		$result.removeClass( 'hs3daci-verify-ok hs3daci-verify-warn hs3daci-verify-error' ).html( '<p>Checking...</p>' ).show();

		$.post( hs3daciData.ajaxUrl, {
			action: 'hs3daci_verify_live',
			nonce: hs3daciData.nonce,
			file_type: fileType
		} ).done( function ( response ) {
			$btn.prop( 'disabled', false );

			if ( ! response.success ) {
				var msg = ( response.data && response.data.message ) ? response.data.message : 'Could not run the check.';
				$result.addClass( 'hs3daci-verify-error' ).html( '<p>' + escapeHtml( msg ) + '</p>' );
				return;
			}

			renderVerifyResult( fileType, response.data, $result );
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$result.addClass( 'hs3daci-verify-error' ).html( '<p>Request failed. Please try again.</p>' );
		} );
	} );

	/**
	 * Builds the plain-language verification message based on diagnostic results.
	 *
	 * @param {string} fileType 'llms' or 'llms-full'.
	 * @param {Object} data Response data: physical, live.
	 * @param {jQuery} $result Container to render into.
	 */
	function renderVerifyResult( fileType, data, $result ) {
		var physicalKey = ( 'llms-full' === fileType ) ? 'llms_full' : 'llms';
		var hasPhysicalConflict = data.physical && data.physical[ physicalKey ];
		var fileName = ( 'llms-full' === fileType ) ? 'llms-full.txt' : 'llms.txt';

		if ( hasPhysicalConflict ) {
			$result.addClass( 'hs3daci-verify-warn' ).html(
				'<p><strong>A physical ' + fileName + ' file exists in your site\'s root folder.</strong> ' +
				'Static files are served before WordPress loads, so that file is overriding what this plugin generates, ' +
				'regardless of caching. Delete or rename that file via FTP/File Manager (look for ' + fileName + ' in your site\'s root directory, the same folder as wp-config.php), then check again.</p>'
			);
			return;
		}

		if ( null === data.live.matches ) {
			$result.addClass( 'hs3daci-verify-error' ).html(
				'<p>Could not fetch the live URL to compare (' + escapeHtml( data.live.error || 'unknown error' ) + '). ' +
				'Try opening ' + escapeHtml( data.live.url ) + ' directly in your browser.</p>'
			);
			return;
		}

		if ( data.live.matches ) {
			$result.addClass( 'hs3daci-verify-ok' ).html(
				'<p><strong>All clear.</strong> The live URL matches what this plugin generated. No caching or conflict issues detected.</p>'
			);
			return;
		}

		$result.addClass( 'hs3daci-verify-warn' ).html(
			'<p><strong>The live URL does not match the generated content yet.</strong> This is almost always a caching issue: ' +
			'your host, a CDN (e.g. Cloudflare), or a caching plugin is serving an old cached copy of this URL. ' +
			'Try clearing your site/host cache, then check again. If you just disabled another plugin\'s llms.txt feature ' +
			'(e.g. Yoast SEO), this is expected until that cache clears.</p>' +
			'<p class="hs3daci-verify-excerpt">Live content preview: <code>' + escapeHtml( data.live.live_excerpt || '(empty)' ) + '</code></p>'
		);
	}

	/**
	 * Post type "Manage individual items": loads the list of posts for that
	 * post type via AJAX (once) and renders checkboxes for exclusion.
	 */
	$( '.hs3daci-pt-expand' ).on( 'click', function () {
		var postType = $( this ).data( 'post-type' );
		var $container = $( '.hs3daci-posttype-items[data-post-type="' + postType + '"]' );

		if ( $container.is( ':visible' ) ) {
			$container.slideUp();
			return;
		}

		$container.slideDown();

		if ( $container.data( 'loaded' ) ) {
			return;
		}

		$.post( hs3daciData.ajaxUrl, {
			action: 'hs3daci_get_posts',
			nonce: hs3daciData.nonce,
			post_type: postType
		} ).done( function ( response ) {
			if ( ! response.success ) {
				var message = ( response.data && response.data.message ) ? response.data.message : 'Error loading items.';
				$container.html( '<p>' + message + '</p>' );
				return;
			}

			var items = response.data.items || [];

			if ( ! items.length ) {
				$container.html( '<p>No published items found.</p>' );
				$container.data( 'loaded', true );
				return;
			}

			var $hiddenInput = getExcludedInput( postType );
			var excludedIds = ( $hiddenInput.val() || '' )
				.split( ',' )
				.filter( function ( v ) {
					return v !== '';
				} );

			var html = '<ul class="hs3daci-item-list">';
			items.forEach( function ( item ) {
				var isExcluded = excludedIds.indexOf( String( item.id ) ) !== -1;
				var checked = isExcluded ? '' : 'checked';
				html += '<li><label><input type="checkbox" class="hs3daci-item-checkbox" data-id="' + item.id + '" ' + checked + '> ' + escapeHtml( item.title ) + '</label></li>';
			} );
			html += '</ul>';

			$container.html( html ).data( 'loaded', true );

			$container.on( 'change', '.hs3daci-item-checkbox', function () {
				updateExcludedIds( postType );
			} );
		} ).fail( function () {
			$container.html( '<p>Request failed. Please try again.</p>' );
		} );
	} );

	/**
	 * Returns the hidden input that stores excluded post IDs for a post type.
	 *
	 * @param {string} postType Post type slug.
	 * @return {jQuery}
	 */
	function getExcludedInput( postType ) {
		return $( 'input.hs3daci-excluded-ids[name="post_types[' + postType + '][excluded_ids]"]' );
	}

	/**
	 * Recomputes the comma-separated list of excluded IDs from unchecked checkboxes.
	 *
	 * @param {string} postType Post type slug.
	 */
	function updateExcludedIds( postType ) {
		var $container = $( '.hs3daci-posttype-items[data-post-type="' + postType + '"]' );
		var $hiddenInput = getExcludedInput( postType );

		var excluded = [];
		$container.find( '.hs3daci-item-checkbox' ).each( function () {
			if ( ! $( this ).is( ':checked' ) ) {
				excluded.push( $( this ).data( 'id' ) );
			}
		} );

		$hiddenInput.val( excluded.join( ',' ) );
	}

	/**
	 * Minimal HTML escaping for titles rendered into checkbox labels.
	 *
	 * @param {string} str Raw string.
	 * @return {string}
	 */
	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}
} );
