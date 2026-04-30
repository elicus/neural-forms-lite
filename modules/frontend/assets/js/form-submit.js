/**
 * form-submit.js
 *
 * Handles Ajax form submission for all [neural-forms] forms on the page.
 * Vanilla JS - no framework, no build step, no external dependencies.
 *
 * Depends on:
 *   window.neuralFormsPublic.api.url   REST API base URL, injected by
 *                                   PublicForms::register_assets() via
 *                                   wp_add_inline_script() before this file.
 *
 * Expected DOM structure (produced by FormRenderer):
 *
 *   .neural-forms-form-wrap
 *     form.neural-forms-form[data-neural-forms-id][data-neural-forms-version][novalidate]
 *       .neural-forms-form__fields
 *         .neural-forms-form__field[data-neural-forms-field-id]   '-><- one per field
 *           input | textarea | select | ...
 *           p.neural-forms-form__field-error[role=alert][hidden]
 *         .neural-forms-form__field--honeypot[aria-hidden]   '-><- bot trap, never touched
 *       p.neural-forms-form__global-error[role=alert][hidden]
 *       div.neural-forms-form__captcha[data-neural-forms-captcha] '-><- present only when enabled
 *       div.neural-forms-form__submit-wrap
 *         button.neural-forms-form__submit[type=submit]
 *     .neural-forms-form__success[hidden]                    '-><- sibling of <form>
 */
( function () {
	'use strict';

	// Initialization

	/**
	 * Attach a submit handler to every .neural-forms-form on the current page.
	 * Safe to call as soon as the DOM is parsed.
	 */
	function init() {
		var forms = document.querySelectorAll( '.neural-forms-form' );
		for ( var i = 0; i < forms.length; i++ ) {
			initForm( forms[ i ] );
		}
	}

	/**
	 * Wire the submit event for a single form element.
	 * Also initialises conditional logic evaluation for this form.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initForm( form ) {
		form.addEventListener( 'submit', function ( e ) {
			handleSubmit( e, form );
		} );
		initConditional( form );
		initMultiStep( form );
	}

	// Submit handler

	/**
	 * Intercept form submission, POST data as JSON, handle the response.
	 *
	 * @param {Event}           e     The native submit event.
	 * @param {HTMLFormElement} form  The .neural-forms-form element.
	 */
	function handleSubmit( e, form ) {
		e.preventDefault();

		// Double-submit guard - neural-forms-form--submitting is applied by
		// setSubmitting(). If a fetch is already in-flight, do nothing.
		// Covers button double-click, rapid Enter-key presses, and any other
		// path that would fire a second submit event before the first resolves.
		if ( form.classList.contains( 'neural-forms-form--submitting' ) ) {
			return;
		}

		var formId = form.getAttribute( 'data-neural-forms-id' );
		if ( ! formId ) {
			// Misconfigured embed - no ID to submit to.
			return;
		}

		// Reset UI, disable form, then fire the request.
		clearErrors( form );
		setSubmitting( form, true );

		// ' CAPTCHA pre-submit check '
		// getCaptchaToken() returns:
		//   null  '->-> no CAPTCHA widget on this form '->-> skip entirely
		//   ''    '->-> widget present but the user hasn't completed it '->-> block
		//   str   '->-> valid token, include in payload
		var captchaToken = getCaptchaToken( form );
		if ( captchaToken === '' ) {
			showGlobalError( form, 'Please complete the CAPTCHA verification.' );
			setSubmitting( form, false );
			return;
		}

		// Strip any trailing slash from the base URL so concatenation is clean
		// regardless of whether rest_url() appends one or not.
		var baseUrl  = getApiUrl().replace( /\/+$/, '' );
		var endpoint = baseUrl + '/forms/' + formId + '/submit';

		// ' Dual-mode submission '
		// When the form contains file fields (data-neural-forms-has-files="1"),
		// send as multipart/form-data so file binaries are included.
		// All other forms use JSON for consistency with the existing server path.
		var hasFiles = form.getAttribute( 'data-neural-forms-has-files' ) === '1';

		var fetchOptions;
		if ( hasFiles ) {
			// Client-side size pre-check before building FormData.
			var sizeError = checkFileSizes( form );
			if ( sizeError ) {
				showGlobalError( form, sizeError );
				setSubmitting( form, false );
				return;
			}

			var fd = buildFormData( form, captchaToken );
			fetchOptions = {
				method: 'POST',
				body:   fd,
				// Do NOT set Content-Type - the browser sets it automatically with
				// the correct multipart boundary when given a FormData body.
			};
		} else {
			var payload = serializeForm( form );
			if ( captchaToken ) {
				payload.captcha_token = captchaToken;
			}
			fetchOptions = {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify( payload ),
			};
		}

		// ' Request timeout via AbortController '
		var controller = new AbortController();
		fetchOptions.signal = controller.signal;
		var timeoutId = setTimeout( function () { controller.abort(); }, 12000 );

		fetch( endpoint, fetchOptions )
			.then( function ( response ) {
				var status = response.status;

				// Parse the JSON body for all status codes.
				// .catch() handles non-JSON bodies (502 gateway errors, etc.)
				// so downstream handlers always receive a plain object.
				return response
					.json()
					.then( function ( body ) {
						return { status: status, body: body };
					} )
					.catch( function () {
						return { status: status, body: {} };
					} );
			} )
			.then( function ( result ) {
				switch ( result.status ) {

					// ' 201 Created '
					// Entry stored. Either redirect or show the inline message
					// based on the form's on_success setting.
					case 201:
						if ( ! redirectIfConfigured( form, 'success' ) ) {
							showSuccess( form );
						}
						break;

					// ' 422 Unprocessable Entity '
					// SubmissionValidator found errors. data.fields is a keyed
					// object: { fieldId: [{ code, message }, ...], ... }
					// Per-field validation errors are NOT routed through the
					// on_error redirect/message setting - the user needs to
					// see which inputs are invalid.
					case 422:
						var fields = (
							result.body &&
							result.body.data &&
							result.body.data.fields
						) ? result.body.data.fields : {};

						showFieldErrors( form, fields );
						break;

					// ' 429 Too Many Requests '
					// Rate-limit filter blocked the request.
					case 429:
						if ( ! redirectIfConfigured( form, 'error' ) ) {
							showGlobalError(
								form,
								errorMessage( form, 'Too many submissions. Please wait a moment before trying again.' )
							);
						}
						break;

					// ' 403 Forbidden '
					// Form is draft or archived.
					case 403:
						if ( ! redirectIfConfigured( form, 'error' ) ) {
							showGlobalError(
								form,
								errorMessage( form, 'This form is not currently available.' )
							);
						}
						break;

					// ' Everything else (4xx / 5xx / unexpected) '
					default:
						// Log the raw API response so the exact WP_Error code and
						// message are visible in the browser console during debugging.
						console.error(
							'[Neural Forms] Submission rejected (' + result.status + '):',
							( result.body && result.body.code    ) || 'unknown',
							'-',
							( result.body && result.body.message ) || '(no message)',
							result.body
						);
						if ( ! redirectIfConfigured( form, 'error' ) ) {
							showGlobalError(
								form,
								errorMessage(
									form,
									( result.body && result.body.message )
										|| 'Something went wrong. Please try again.'
								)
							);
						}
						break;
				}
			} )
			.catch( function ( err ) {
				if ( redirectIfConfigured( form, 'error' ) ) {
					return;
				}
				// AbortError means our timeout fired.
				if ( err && err.name === 'AbortError' ) {
					showGlobalError(
						form,
						errorMessage( form, 'Request timed out. Please try again.' )
					);
				} else {
					// fetch() itself rejected - network offline, DNS failure, CORS, etc.
					showGlobalError(
						form,
						errorMessage( form, 'Could not connect. Please check your connection and try again.' )
					);
				}
			} )
			.finally( function () {
				clearTimeout( timeoutId );

				// Re-enable the form on every path, including 201.
				// On success the form is hidden (display:none), so re-enabling
				// is a visual no-op but keeps DOM state consistent and allows
				// the page to remain predictable if JS later un-hides the form.
				setSubmitting( form, false );

				// Reset the CAPTCHA widget after every non-201 response so the
				// user can complete it again without refreshing the page.
				// On 201 the form itself is hidden so the reset is a no-op.
				resetCaptchaWidget( form );
			} );
	}

	// Conditional logic

	/**
	 * Set up conditional logic for a form.
	 *
	 * Runs the initial visibility pass immediately, then registers input/change
	 * listeners on the form element so every user interaction re-evaluates all
	 * conditional fields in one sweep.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initConditional( form ) {
		var conditionalFields = form.querySelectorAll( '[data-conditional]' );
		if ( conditionalFields.length === 0 ) {
			return;
		}

		// Initial pass - hide fields whose condition is already false on load.
		updateConditionalFields( form );

		// Re-evaluate on every input interaction.
		// Using the form element as the event target (event delegation) means we
		// don't need to re-wire listeners when fields are added dynamically.
		form.addEventListener( 'input',  function() { updateConditionalFields( form ); } );
		form.addEventListener( 'change', function() { updateConditionalFields( form ); } );
	}

	/**
	 * Evaluate all conditional fields and show/hide their wrappers.
	 *
	 * @param {HTMLFormElement} form
	 */
	function updateConditionalFields( form ) {
		var conditionalFields = form.querySelectorAll( '[data-conditional]' );
		for ( var i = 0; i < conditionalFields.length; i++ ) {
			var wrapper = conditionalFields[ i ];
			var rule;
			try {
				rule = JSON.parse( wrapper.getAttribute( 'data-conditional' ) );
			} catch ( parseErr ) {
				continue;
			}

			if ( ! rule || ! rule.field || ! rule.operator ) {
				continue;
			}

			var controlValue = getFieldValue( form, rule.field );
			var visible      = evaluateCondition( rule.operator, controlValue, rule.value || '' );

			if ( visible ) {
				wrapper.style.display = '';
			} else {
				wrapper.style.display = 'none';
				clearFieldValues( wrapper );
			}
		}
	}

	/**
	 * Read the current value of a field by its schema ID (the `name` attribute).
	 *
	 * Handles radio groups (returns checked value or ''), checkboxes (returns
	 * '1' or ''), and all other single-element controls (returns .value).
	 *
	 * @param  {HTMLFormElement} form
	 * @param  {string}          fieldId  Schema field ID matching the input's name.
	 * @return {string}
	 */
	function getFieldValue( form, fieldId ) {
		// Radio groups: find the checked button.
		var radios = form.querySelectorAll( '[name="' + fieldId + '"][type="radio"]' );
		if ( radios.length > 0 ) {
			for ( var r = 0; r < radios.length; r++ ) {
				if ( radios[ r ].checked ) {
					return radios[ r ].value;
				}
			}
			return '';
		}

		// Multi-option checkbox groups: name ends with [].
		var checkboxes = form.querySelectorAll( '[name="' + fieldId + '[]"][type="checkbox"]' );
		if ( checkboxes.length > 0 ) {
			var vals = [];
			for ( var c = 0; c < checkboxes.length; c++ ) {
				if ( checkboxes[ c ].checked ) {
					vals.push( checkboxes[ c ].value );
				}
			}
			return vals.join( ',' );
		}

		var el = form.querySelector( '[name="' + fieldId + '"]' );
		if ( ! el ) {
			return '';
		}

		if ( el.type === 'checkbox' ) {
			return el.checked ? '1' : '';
		}

		return el.value;
	}

	/**
	 * Evaluate a single condition rule.
	 *
	 * Mirrors the PHP evaluate_conditional() logic in SubmitEndpoint - both
	 * sides must behave identically so hidden fields stay hidden on the server.
	 *
	 * @param  {string} operator      One of: equals, not_equals, contains, empty, not_empty.
	 * @param  {string} controlValue  Current value of the controlling field.
	 * @param  {string} ruleValue     The value declared in the rule (may be empty).
	 * @return {boolean}              true = show the dependent field.
	 */
	function evaluateCondition( operator, controlValue, ruleValue ) {
		var v = String( controlValue );
		var r = String( ruleValue );

		switch ( operator ) {
			case 'equals':     return v === r;
			case 'not_equals': return v !== r;
			case 'contains':   return v.indexOf( r ) !== -1;
			case 'empty':      return v.trim() === '';
			case 'not_empty':  return v.trim() !== '';
			default:           return true;  // unknown operator - show by default
		}
	}

	/**
	 * Clear all input values inside a hidden field wrapper.
	 *
	 * Prevents stale data from being submitted when a field is hidden while
	 * already populated. Also handles file inputs (value reset via try/catch
	 * because some browsers block programmatic clearing of file inputs).
	 *
	 * @param {Element} wrapper  The .neural-forms-form__field wrapper element.
	 */
	function clearFieldValues( wrapper ) {
		var inputs = wrapper.querySelectorAll( 'input, textarea, select' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var el = inputs[ i ];
			if ( el.type === 'checkbox' || el.type === 'radio' ) {
				el.checked = false;
			} else if ( el.type === 'file' ) {
				try { el.value = ''; } catch ( fileErr ) { /* browsers may block this */ }
			} else {
				el.value = '';
			}
		}
	}

	/**
	 * Return true if the element is inside a hidden conditional field wrapper.
	 *
	 * Used by serializeForm() and buildFormData() to skip fields that are
	 * currently hidden - belt-and-suspenders in addition to clearFieldValues().
	 *
	 * @param  {Element}        el    A form control element.
	 * @param  {HTMLFormElement} form  The parent form.
	 * @return {boolean}
	 */
	function isInsideHiddenConditional( el, form ) {
		var node = el.parentElement;
		while ( node && node !== form ) {
			if ( node.hasAttribute( 'data-conditional' ) && node.style.display === 'none' ) {
				return true;
			}
			node = node.parentElement;
		}
		return false;
	}

	// Serialization

	/**
	 * Collect all named form controls into a plain { name: value } object.
	 *
	 * Type rules:
	 *   checkbox  '->-> integer 1 (checked) or 0 (unchecked). Matches what
	 *               SubmissionValidator::CHECKED_CHECKBOX_VALUES expects.
	 *   radio     '->-> string value of the checked option only; absent if none
	 *               checked, so the server's required check fires correctly.
	 *   file      '->-> skipped - files are not sent via the JSON path.
	 *   all other '->-> string .value property.
	 *
	 * The honeypot field (name="neural_forms_website") is included deliberately.
	 * Real users leave it empty; bots that fill it trigger spam_detected 400
	 * on the server (SubmitEndpoint Step 4b).
	 *
	 * @param  {HTMLFormElement} form
	 * @return {Object}
	 */
	function serializeForm( form ) {
		var data     = {};
		var elements = form.elements;

		for ( var i = 0; i < elements.length; i++ ) {
			var el = elements[ i ];

			// Skip unnamed controls, disabled controls, button types, file inputs,
			// and any control inside a hidden conditional field wrapper.
			if ( ! el.name || el.disabled || el.type === 'submit' || el.type === 'button' || el.type === 'file' ) {
				continue;
			}
			if ( isInsideHiddenConditional( el, form ) ) {
				continue;
			}

			if ( el.type === 'checkbox' && el.name.slice( -2 ) === '[]' ) {
				// Multi-option checkbox group (checkboxes type): collect checked values as array.
				var arrName = el.name.slice( 0, -2 );
				if ( ! data[ arrName ] ) {
					data[ arrName ] = [];
				}
				if ( el.checked ) {
					data[ arrName ].push( el.value );
				}
			} else if ( el.type === 'checkbox' ) {
				data[ el.name ] = el.checked ? 1 : 0;
			} else if ( el.type === 'radio' ) {
				// Write only when checked - leave the key absent for unchecked radios.
				if ( el.checked ) {
					data[ el.name ] = el.value;
				}
			} else {
				// text, email, tel, number, textarea, select, hidden, ...
				data[ el.name ] = el.value;
			}
		}

		return data;
	}

	/**
	 * Build a FormData object for multipart submission (file upload path).
	 *
	 * Text-like fields are appended as strings; file inputs append the
	 * selected File object when a file was chosen; checkboxes serialize as
	 * "1" or "0" to match the JSON path convention. The honeypot and captcha
	 * token are included where applicable.
	 *
	 * @param  {HTMLFormElement} form
	 * @param  {string|null}     captchaToken
	 * @return {FormData}
	 */
	function buildFormData( form, captchaToken ) {
		var fd       = new FormData();
		var elements = form.elements;

		for ( var i = 0; i < elements.length; i++ ) {
			var el = elements[ i ];

			if ( ! el.name || el.disabled || el.type === 'submit' || el.type === 'button' ) {
				continue;
			}
			if ( isInsideHiddenConditional( el, form ) ) {
				continue;
			}

			if ( el.type === 'file' ) {
				// Only append when a file was actually selected.
				if ( el.files && el.files.length > 0 ) {
					fd.append( el.name, el.files[ 0 ] );
				}
			} else if ( el.type === 'checkbox' && el.name.slice( -2 ) === '[]' ) {
				// Multi-option checkbox group: append only checked values.
				if ( el.checked ) {
					fd.append( el.name, el.value );
				}
			} else if ( el.type === 'checkbox' ) {
				fd.append( el.name, el.checked ? '1' : '0' );
			} else if ( el.type === 'radio' ) {
				if ( el.checked ) {
					fd.append( el.name, el.value );
				}
			} else {
				fd.append( el.name, el.value );
			}
		}

		if ( captchaToken ) {
			fd.append( 'captcha_token', captchaToken );
		}

		return fd;
	}

	/**
	 * Client-side file size pre-check.
	 *
	 * Reads the data-max-size attribute (bytes) set by FormRenderer on each
	 * file input and compares it against the selected file size. Returns an
	 * error message string on the first violation, or null when all files pass.
	 *
	 * This is a UX convenience only - the server re-validates sizes
	 * independently and is the authoritative gate.
	 *
	 * @param  {HTMLFormElement} form
	 * @return {string|null}
	 */
	function checkFileSizes( form ) {
		var inputs = form.querySelectorAll( 'input[type="file"]' );

		for ( var i = 0; i < inputs.length; i++ ) {
			var input   = inputs[ i ];
			var maxSize = parseInt( input.getAttribute( 'data-max-size' ), 10 );

			if ( isNaN( maxSize ) || ! input.files || input.files.length === 0 ) {
				continue;
			}

			var file = input.files[ 0 ];
			if ( file.size > maxSize ) {
				var maxMb = Math.round( maxSize / ( 1024 * 1024 ) );
				return 'The selected file exceeds the maximum allowed size of ' + maxMb + ' MB.';
			}
		}

		return null;
	}

	// UI state

	/**
	 * Reset all field-level and global error state before a new attempt.
	 *
	 * @param {HTMLFormElement} form
	 */
	function clearErrors( form ) {
		// Field error paragraphs - empty text, restore [hidden].
		var fieldErrors = form.querySelectorAll( '.neural-forms-form__field-error' );
		for ( var i = 0; i < fieldErrors.length; i++ ) {
			fieldErrors[ i ].textContent = '';
			fieldErrors[ i ].hidden      = true;
		}

		// Field wrappers - remove BEM error modifier (drives red-border CSS)
		// and clear aria-invalid on the contained input.
		var errorWrappers = form.querySelectorAll( '.neural-forms-form__field--has-error' );
		for ( var i = 0; i < errorWrappers.length; i++ ) {
			errorWrappers[ i ].classList.remove( 'neural-forms-form__field--has-error' );
			var invalidInput = errorWrappers[ i ].querySelector( '[aria-invalid]' );
			if ( invalidInput ) {
				invalidInput.removeAttribute( 'aria-invalid' );
			}
		}

		// Global error paragraph - empty text, restore [hidden].
		var globalError = form.querySelector( '.neural-forms-form__global-error' );
		if ( globalError ) {
			globalError.textContent = '';
			globalError.hidden      = true;
		}
	}

	/**
	 * Toggle the in-flight state.
	 *
	 * neural-forms-form--submitting on <form>: CSS hook to reduce opacity (but
	 * NOT block pointer-events - the button itself is disabled, and the class
	 * guard at the top of handleSubmit prevents double-submit).
	 *
	 * When entering the submitting state:
	 *   * The original button text is stashed in a data attribute.
	 *   * The button text is replaced with "Submitting..." and a CSS spinner.
	 * When leaving:
	 *   * The original text is restored from the data attribute.
	 *
	 * @param {HTMLFormElement} form
	 * @param {boolean}         isSubmitting
	 */
	function setSubmitting( form, isSubmitting ) {
		var btn = form.querySelector( '.neural-forms-form__submit' );

		if ( isSubmitting ) {
			form.classList.add( 'neural-forms-form--submitting' );
			if ( btn ) {
				btn.disabled = true;
				// Stash the original label so we can restore it later.
				if ( ! btn.hasAttribute( 'data-original-text' ) ) {
					btn.setAttribute( 'data-original-text', btn.textContent );
				}
				btn.innerHTML = '<span class="neural-forms-form__spinner" aria-hidden="true"></span> Submitting\u2026';
			}
		} else {
			form.classList.remove( 'neural-forms-form--submitting' );
			if ( btn ) {
				btn.disabled = false;
				var original = btn.getAttribute( 'data-original-text' );
				if ( original ) {
					btn.textContent = original;
				}
			}
		}
	}

	// Response handlers

	/**
	 * Outcome routing - per-form on_success / on_error setting.
	 *
	 * Reads data-on-{success|error}-mode + data-on-{success|error}-url from
	 * the form. When mode === 'redirect' and a non-empty URL is present,
	 * navigates the browser to that URL and returns true so the caller skips
	 * the inline UI. Returns false in every other case.
	 *
	 * @param  {HTMLFormElement} form
	 * @param  {string} kind  'success' or 'error'
	 * @return {boolean}      true '->-> caller should not render inline UI
	 */
	function redirectIfConfigured( form, kind ) {
		var modeAttr = 'data-on-' + kind + '-mode';
		var urlAttr  = 'data-on-' + kind + '-url';
		var mode     = form.getAttribute( modeAttr );
		var url      = form.getAttribute( urlAttr );
		if ( mode !== 'redirect' || ! url ) {
			return false;
		}
		// Use assign() rather than href = ... so the redirect appears in the
		// session history; lets the user back-navigate to the form page.
		try {
			window.location.assign( url );
		} catch ( navErr ) {
			window.location.href = url;
		}
		return true;
	}

	/**
	 * Resolve the visible error message - admin-supplied data-on-error-message
	 * wins over the default the caller passed in. Empty string falls through
	 * to the caller's default.
	 *
	 * @param  {HTMLFormElement} form
	 * @param  {string} fallback  Default copy if the admin did not customize.
	 * @return {string}
	 */
	function errorMessage( form, fallback ) {
		var custom = form.getAttribute( 'data-on-error-message' );
		return ( custom && custom.length > 0 ) ? custom : fallback;
	}

	/**
	 * 201 - Hide the form, reveal the success message.
	 *
	 * .neural-forms-form__success is a sibling of <form> inside .neural-forms-form-wrap,
	 * not a child, so setting form.hidden = true does not also hide it.
	 *
	 * @param {HTMLFormElement} form
	 */
	function showSuccess( form ) {
		form.hidden = true;

		var wrap = form.parentElement;
		if ( wrap ) {
			var success = wrap.querySelector( '.neural-forms-form__success' );
			if ( success ) {
				success.hidden = false;

				// Scroll the success message into view so the user sees it
				// immediately, especially on long forms where the submit button
				// may be below the fold.
				success.scrollIntoView( { behavior: 'smooth', block: 'center' } );

				// Move focus to the success container so screen readers announce
				// it. tabindex="-1" makes a non-interactive element focusable
				// programmatically without adding it to the tab order.
				if ( ! success.hasAttribute( 'tabindex' ) ) {
					success.setAttribute( 'tabindex', '-1' );
				}
				success.focus( { preventScroll: true } );

				// After a short delay, append a "Submit another response" link
				// so users can easily reset the form for a second submission.
				setTimeout( function () {
					if ( success.querySelector( '.neural-forms-form__reset-link' ) ) return;
					var link = document.createElement( 'button' );
					link.type      = 'button';
					link.className = 'neural-forms-form__reset-link';
					link.textContent = 'Submit another response';
					link.addEventListener( 'click', function () {
						form.reset();
						form.hidden    = false;
						success.hidden = true;
						// Remove the link so it doesn't stack on repeated submissions.
						if ( link.parentNode ) link.parentNode.removeChild( link );
						form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					} );
					success.appendChild( link );
				}, 2000 );
			}
		}
	}

	/**
	 * 422 - Display per-field error messages and scroll to the first one.
	 *
	 * The error <p> elements are pre-rendered by FormRenderer with [hidden]
	 * and empty text. This function sets their text and removes [hidden].
	 * Field wrappers receive .neural-forms-form__field--has-error for CSS red-border styling.
	 *
	 * Only the first error per field is shown - displaying a stack of errors
	 * per field would overwhelm the user. The server still sends all of them;
	 * the unused ones are silently discarded here.
	 *
	 * Scrolls to the first errored field in DOM order, not in the order the
	 * server returned them, so the user always sees the topmost visible error.
	 *
	 * @param {HTMLFormElement} form
	 * @param {Object}          fields  { fieldId: [{ code, message }, ...], ... }
	 */
	function showFieldErrors( form, fields ) {
		for ( var fieldId in fields ) {
			if ( ! Object.prototype.hasOwnProperty.call( fields, fieldId ) ) {
				continue;
			}

			var errors = fields[ fieldId ];
			if ( ! Array.isArray( errors ) || errors.length === 0 ) {
				continue;
			}

			var message = errors[ 0 ].message || 'This field has an error.';

			var wrapper = form.querySelector( '[data-neural-forms-field-id="' + fieldId + '"]' );
			if ( ! wrapper ) {
				// Field is in the server response but not in the DOM.
				// Possible if the schema was updated after the page was rendered.
				// Safe to skip - the global error below is not triggered here.
				continue;
			}

			var errorEl = wrapper.querySelector( '.neural-forms-form__field-error' );
			if ( errorEl ) {
				// textContent (not innerHTML) - error messages are plain text;
				// no HTML rendering needed or wanted.
				errorEl.textContent = message;
				errorEl.hidden      = false;
			}

			wrapper.classList.add( 'neural-forms-form__field--has-error' );

			// Mark the input as invalid for screen readers.
			var input = wrapper.querySelector( 'input, textarea, select' );
			if ( input ) {
				input.setAttribute( 'aria-invalid', 'true' );
			}
		}

		// querySelector returns the first match in document order, so this
		// scroll target is always the topmost visible error regardless of
		// the iteration order of the `fields` object above.
		var firstError = form.querySelector( '.neural-forms-form__field--has-error' );
		if ( firstError ) {
			firstError.scrollIntoView( { behavior: 'smooth', block: 'center' } );

			// Focus the first focusable input inside the errored field so
			// screen readers announce it and keyboard users can correct immediately.
			var focusTarget = firstError.querySelector( 'input, textarea, select' );
			if ( focusTarget && typeof focusTarget.focus === 'function' ) {
				// Delay focus slightly so the smooth scroll completes first;
				// immediate focus can fight with scrollIntoView in some browsers.
				setTimeout( function () { focusTarget.focus(); }, 350 );
			}
		}
	}

	/**
	 * Non-field errors (429, 403, 5xx, network) - populate the global error bar.
	 *
	 * @param {HTMLFormElement} form
	 * @param {string}          message  Plain text, displayed verbatim.
	 */
	function showGlobalError( form, message ) {
		var globalError = form.querySelector( '.neural-forms-form__global-error' );
		if ( globalError ) {
			globalError.textContent = message;
			globalError.hidden      = false;
		}
	}

	// Utilities

	/**
	 * Read the CAPTCHA token from the provider-injected hidden input.
	 *
	 * Returns:
	 *   null  - no .neural-forms-form__captcha widget found on this form.
	 *           Caller should skip CAPTCHA entirely.
	 *   ''    - widget found but the user has not yet completed the challenge.
	 *           Caller should block submission and show an error.
	 *   str   - token string, ready to include in the submission payload.
	 *
	 * Both Turnstile and reCAPTCHA v2 inject a hidden <input> or <textarea>
	 * into the form when the user completes the challenge:
	 *   Turnstile    '->-> input[name="cf-turnstile-response"]
	 *   reCAPTCHA v2 '->-> textarea[name="g-recaptcha-response"]
	 *
	 * data-neural-forms-captcha on the widget div stores the provider slug so we
	 * know which name to look for without checking the global SDK objects.
	 *
	 * @param  {HTMLFormElement} form
	 * @return {string|null}
	 */
	function getCaptchaToken( form ) {
		var widget = form.querySelector( '[data-neural-forms-captcha]' );
		if ( ! widget ) {
			return null;
		}

		var provider  = widget.getAttribute( 'data-neural-forms-captcha' );
		var inputName = null;

		if ( provider === 'turnstile' ) {
			inputName = 'cf-turnstile-response';
		} else if ( provider === 'recaptcha_v2' ) {
			inputName = 'g-recaptcha-response';
		}

		if ( ! inputName ) {
			return null;
		}

		var input = form.querySelector( '[name="' + inputName + '"]' );
		if ( ! input ) {
			// SDK hasn't injected the input yet - widget incomplete.
			return '';
		}

		return input.value ? input.value.trim() : '';
	}

	/**
	 * Reset the CAPTCHA widget after a failed submission so the user can
	 * complete the challenge again without refreshing the page.
	 *
	 * Turnstile: window.turnstile.reset(container) resets a specific widget.
	 * reCAPTCHA v2: window.grecaptcha.reset() resets the first widget on the
	 *               page. With one form per page this is always correct.
	 *               Multi-form pages with reCAPTCHA would need widget IDs,
	 *               which is out of scope for MVP.
	 *
	 * Both calls are guarded - if the SDK has not loaded or the widget div
	 * is absent, the reset is silently skipped.
	 *
	 * @param {HTMLFormElement} form
	 */
	function resetCaptchaWidget( form ) {
		var widget = form.querySelector( '[data-neural-forms-captcha]' );
		if ( ! widget ) {
			return;
		}

		var provider = widget.getAttribute( 'data-neural-forms-captcha' );

		if ( provider === 'turnstile' && window.turnstile ) {
			window.turnstile.reset( widget );
		} else if ( provider === 'recaptcha_v2' && window.grecaptcha ) {
			window.grecaptcha.reset();
		}
	}

	/**
	 * Return the REST API base URL from the PHP-injected config object.
	 * Returns an empty string if the object is missing (safe to concatenate;
	 * fetch will reject with a network error and showGlobalError will fire).
	 *
	 * @return {string}
	 */
	function getApiUrl() {
		return (
			window.neuralFormsPublic &&
			window.neuralFormsPublic.api &&
			window.neuralFormsPublic.api.url
		) || '';
	}

	// Multi-step navigation
	//
	// Forms with 2+ top-level step containers carry
	// data-neural-forms-multistep="1". Without JS every step renders
	// stacked and the submit button stays visible (graceful fallback).
	// With JS we hide all but the active step and swap nav button
	// visibility per index.

	/**
	 * Initialise multi-step navigation for a form, if applicable.
	 * @param {HTMLFormElement} form
	 */
	function initMultiStep( form ) {
		if ( form.getAttribute( 'data-neural-forms-multistep' ) !== '1' ) return;

		var steps = form.querySelectorAll( ':scope .neural-forms-form__step' );
		if ( steps.length < 2 ) return;

		var prevBtn = form.querySelector( '[data-step-action="prev"]' );
		var nextBtn = form.querySelector( '[data-step-action="next"]' );
		var submit  = form.querySelector( '.neural-forms-form__submit' );

		var current = 0;

		function show( index ) {
			for ( var i = 0; i < steps.length; i++ ) {
				if ( i === index ) {
					steps[ i ].classList.add( 'is-active' );
					steps[ i ].style.display = '';
				} else {
					steps[ i ].classList.remove( 'is-active' );
					steps[ i ].style.display = 'none';
				}
			}

			// Update nav button labels for the now-active step.
			updateNavLabels( steps[ index ] );

			if ( prevBtn ) prevBtn.style.display = ( index === 0 ) ? 'none' : '';
			if ( nextBtn ) nextBtn.style.display = ( index === steps.length - 1 ) ? 'none' : '';
			if ( submit )  submit.style.display  = ( index === steps.length - 1 ) ? '' : 'none';

			// Conditional logic visibility may depend on values in
			// previously visited steps - re-evaluate on every step
			// transition so newly visible fields are honoured.
			if ( typeof updateConditionalFields === 'function' ) {
				updateConditionalFields( form );
			}

			// Move focus to the active step's heading, or the step
			// container itself, so screen readers and keyboard users
			// don't lose context.
			var heading = steps[ index ].querySelector( '.neural-forms-form__step-title' );
			var target  = heading || steps[ index ];
			if ( target && typeof target.scrollIntoView === 'function' ) {
				try { target.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); } catch ( e ) { /* noop */ }
			}
		}

		function updateNavLabels( step ) {
			if ( ! step ) return;
			var nextLabel = step.getAttribute( 'data-step-button-next' );
			var prevLabel = step.getAttribute( 'data-step-button-previous' );
			if ( nextBtn && nextLabel ) nextBtn.textContent = nextLabel;
			if ( prevBtn && prevLabel ) prevBtn.textContent = prevLabel;
		}

		/**
		 * Validate the visible required fields inside the current step
		 * client-side. Returns true when the step can advance.
		 * Server validation remains the source of truth; this is UX-only.
		 */
		function validateStep( step ) {
			var inputs = step.querySelectorAll( 'input[required], select[required], textarea[required]' );
			var firstInvalid = null;
			for ( var i = 0; i < inputs.length; i++ ) {
				var el = inputs[ i ];

				// Skip fields whose wrapper is hidden by conditional logic.
				var wrapper = el.closest( '.neural-forms-form__field' );
				if ( wrapper && wrapper.hasAttribute( 'hidden' ) ) continue;
				if ( wrapper && wrapper.style.display === 'none' ) continue;

				var value = ( el.type === 'checkbox' || el.type === 'radio' ) ? el.checked : ( el.value || '' );
				if ( el.type === 'checkbox' && el.required && ! el.checked ) {
					if ( ! firstInvalid ) firstInvalid = el;
					continue;
				}
				if ( typeof value === 'string' && value.trim() === '' ) {
					if ( ! firstInvalid ) firstInvalid = el;
				}
			}
			if ( firstInvalid ) {
				try { firstInvalid.focus(); } catch ( e ) { /* noop */ }
				return false;
			}
			return true;
		}

		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! validateStep( steps[ current ] ) ) return;
				if ( current < steps.length - 1 ) {
					current += 1;
					show( current );
				}
			} );
		}

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( current > 0 ) {
					current -= 1;
					show( current );
				}
			} );
		}

		// Initial render: only step 0 visible, submit hidden, prev hidden.
		show( 0 );
	}

	// Bootstrap

	// wp_register_script( ..., true ) loads this file in the footer, after the
	// DOM is parsed, so readyState will almost always be 'complete' here.
	// The DOMContentLoaded branch is a safe fallback for edge cases (e.g. the
	// script somehow appearing in <head>).
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

}() );
