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
		initWelcomeScreen( form );
		initMultiStep( form );
		initRepeaters( form );
		initPhonePickers( form );
		initRandomizeOptions( form );
		// Snapshot the SSR poll-results markup AFTER initMultiStep so any
		// step-driven mutations are captured. Must run before the first
		// submit so the "always" restoration path has the original bars
		// to fall back to.
		snapshotPollResults( form );
		// Quiz placeholders are always SSR-empty + hidden, but we
		// snapshot them anyway so the reset path is symmetric with
		// poll's restore semantics.
		snapshotQuizResults( form );
	}

	// Randomize options (quiz + poll)

	/**
	 * Shuffle the option rows of every quiz / poll fieldset flagged by
	 * FormRenderer (`data-quiz-randomize` / `data-poll-randomize`).
	 *
	 * The server already shuffles once as a no-JS fallback; this runs
	 * per page load so visitors behind a page cache still get their own
	 * order. Rows are moved as whole <label> nodes, so the radio, its id
	 * / for pairing, `checked` and `required` all travel with the row.
	 * Quiz feedback and poll results are keyed by field id + value, never
	 * by position, so reordering is safe.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initRandomizeOptions( form ) {
		var groups = form.querySelectorAll(
			'[data-quiz-randomize="1"] .neural-forms-form__quiz-options,' +
			'[data-poll-randomize="1"] .neural-forms-form__poll-options'
		);
		for ( var g = 0; g < groups.length; g++ ) {
			var group = groups[ g ];
			var rows  = Array.prototype.slice.call( group.children );
			// Fisher–Yates.
			for ( var i = rows.length - 1; i > 0; i-- ) {
				var j   = Math.floor( Math.random() * ( i + 1 ) );
				var tmp = rows[ i ];
				rows[ i ] = rows[ j ];
				rows[ j ] = tmp;
			}
			for ( var r = 0; r < rows.length; r++ ) {
				group.appendChild( rows[ r ] );
			}
		}
	}

	// Repeater add / remove + reindex

	/**
	 * Wire delegated click handlers for every repeater on the form.
	 *
	 * - Add: clone the <template> for the target repeater, substitute
	 *   __INDEX__ / __INDEX_1__ across name / id / for /
	 *   aria-describedby attributes, append to the <ol>.
	 * - Remove: drop the parent <li>, then reindex the remaining
	 *   items so name / id / "Item N" header stay contiguous.
	 *
	 * After every mutation, refresh the add + remove button
	 * visibility against data-min / data-max.
	 */
	/**
	 * Welcome Screen reveal handler.
	 *
	 * Renderer places the welcome screen markup as the FIRST child
	 * inside .neural-forms-form-wrap (a sibling of <form>) and sets
	 * data-neural-forms-has-welcome on the wrap. Without JS, both the
	 * welcome screen AND the form body are visible (graceful fallback
	 * — visitor can still submit). With JS, the welcome screen takes
	 * over and the form body stays hidden via the CSS rule that
	 * targets [data-neural-forms-has-welcome] until Start is clicked.
	 *
	 * On Start click: drop the data flag (CSS reveals the form),
	 * remove the welcome screen, focus the first input or jump
	 * multi-step forms to step 1.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initWelcomeScreen( form ) {
		var wrap = form.closest( '.neural-forms-form-wrap' );
		if ( ! wrap || wrap.getAttribute( 'data-neural-forms-has-welcome' ) !== '1' ) return;
		var welcome = wrap.querySelector( '.neural-forms-form__welcome' );
		var btn     = welcome ? welcome.querySelector( '[data-neural-forms-welcome-start="1"]' ) : null;
		if ( ! welcome || ! btn ) {
			// Defensive: if something stripped the markup, clear the
			// flag so the form remains usable.
			wrap.removeAttribute( 'data-neural-forms-has-welcome' );
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			wrap.removeAttribute( 'data-neural-forms-has-welcome' );
			wrap.classList.add( 'is-started' );
			// Hide via inline display:none so a re-init doesn't bind
			// the click twice if the form is somehow re-mounted.
			welcome.style.display = 'none';

			// Multi-step: ensure step 0 is the active step. initMultiStep
			// already runs show(0) on init, but the welcome screen click
			// is the natural "begin" moment — re-asserting it covers
			// any path where another script may have advanced the step.
			var nextBtn  = form.querySelector( '[data-step-action="next"]' );
			var prevBtn  = form.querySelector( '[data-step-action="prev"]' );
			var firstStep = form.querySelector( '.neural-forms-form__step' );
			if ( firstStep ) {
				// Focus the step's heading or first input.
				var focusTarget = firstStep.querySelector( '.neural-forms-form__step-title' )
					|| firstStep.querySelector( 'input, select, textarea' );
				if ( focusTarget && typeof focusTarget.focus === 'function' ) {
					try { focusTarget.focus( { preventScroll: false } ); } catch ( _ ) { focusTarget.focus(); }
				}
			} else {
				// Single-step form — focus the first real input.
				var first = form.querySelector( 'input:not([type="hidden"]), select, textarea' );
				if ( first && typeof first.focus === 'function' ) {
					try { first.focus(); } catch ( _ ) { /* noop */ }
				}
			}

			// nextBtn / prevBtn references kept above for symmetry with
			// the multi-step init; nothing to mutate here unless the
			// welcome screen also reset progress, which isn't part of
			// the current scope. Reserved for future "Restart" flow.
			void nextBtn; void prevBtn;
		} );
	}

	// ─── Phone country-code picker ──────────────────────────────────

	/**
	 * Country list for the phone picker. Each entry: [iso, name, dial].
	 * Curated to the most commonly used countries — comprehensive
	 * enough that "search by name or dial" finds anything reasonable,
	 * small enough that the bundle isn't bloated with every
	 * micronation. Filter `neural_forms_phone_countries` (server) or
	 * window.neuralFormsPhoneCountries (client) to extend.
	 *
	 * Flag emoji is generated on the fly from the ISO code via the
	 * Unicode regional-indicator-symbol trick — no per-country png.
	 */
	var NF_COUNTRIES = ( typeof window !== 'undefined' && Array.isArray( window.neuralFormsPhoneCountries ) )
		? window.neuralFormsPhoneCountries
		: [
			[ 'AF', 'Afghanistan',          '+93'  ],
			[ 'AL', 'Albania',              '+355' ],
			[ 'DZ', 'Algeria',              '+213' ],
			[ 'AR', 'Argentina',            '+54'  ],
			[ 'AM', 'Armenia',              '+374' ],
			[ 'AU', 'Australia',            '+61'  ],
			[ 'AT', 'Austria',              '+43'  ],
			[ 'AZ', 'Azerbaijan',           '+994' ],
			[ 'BH', 'Bahrain',              '+973' ],
			[ 'BD', 'Bangladesh',           '+880' ],
			[ 'BY', 'Belarus',              '+375' ],
			[ 'BE', 'Belgium',              '+32'  ],
			[ 'BO', 'Bolivia',              '+591' ],
			[ 'BA', 'Bosnia and Herzegovina', '+387' ],
			[ 'BR', 'Brazil',               '+55'  ],
			[ 'BG', 'Bulgaria',             '+359' ],
			[ 'KH', 'Cambodia',             '+855' ],
			[ 'CM', 'Cameroon',             '+237' ],
			[ 'CA', 'Canada',               '+1'   ],
			[ 'CL', 'Chile',                '+56'  ],
			[ 'CN', 'China',                '+86'  ],
			[ 'CO', 'Colombia',             '+57'  ],
			[ 'CR', 'Costa Rica',           '+506' ],
			[ 'HR', 'Croatia',              '+385' ],
			[ 'CU', 'Cuba',                 '+53'  ],
			[ 'CY', 'Cyprus',               '+357' ],
			[ 'CZ', 'Czechia',              '+420' ],
			[ 'DK', 'Denmark',              '+45'  ],
			[ 'DO', 'Dominican Republic',   '+1'   ],
			[ 'EC', 'Ecuador',              '+593' ],
			[ 'EG', 'Egypt',                '+20'  ],
			[ 'SV', 'El Salvador',          '+503' ],
			[ 'EE', 'Estonia',              '+372' ],
			[ 'ET', 'Ethiopia',             '+251' ],
			[ 'FI', 'Finland',              '+358' ],
			[ 'FR', 'France',               '+33'  ],
			[ 'GE', 'Georgia',              '+995' ],
			[ 'DE', 'Germany',              '+49'  ],
			[ 'GH', 'Ghana',                '+233' ],
			[ 'GR', 'Greece',               '+30'  ],
			[ 'GT', 'Guatemala',            '+502' ],
			[ 'HN', 'Honduras',             '+504' ],
			[ 'HK', 'Hong Kong',            '+852' ],
			[ 'HU', 'Hungary',              '+36'  ],
			[ 'IS', 'Iceland',              '+354' ],
			[ 'IN', 'India',                '+91'  ],
			[ 'ID', 'Indonesia',            '+62'  ],
			[ 'IR', 'Iran',                 '+98'  ],
			[ 'IQ', 'Iraq',                 '+964' ],
			[ 'IE', 'Ireland',              '+353' ],
			[ 'IL', 'Israel',               '+972' ],
			[ 'IT', 'Italy',                '+39'  ],
			[ 'JM', 'Jamaica',              '+1'   ],
			[ 'JP', 'Japan',                '+81'  ],
			[ 'JO', 'Jordan',               '+962' ],
			[ 'KZ', 'Kazakhstan',           '+7'   ],
			[ 'KE', 'Kenya',                '+254' ],
			[ 'KW', 'Kuwait',               '+965' ],
			[ 'LV', 'Latvia',               '+371' ],
			[ 'LB', 'Lebanon',              '+961' ],
			[ 'LY', 'Libya',                '+218' ],
			[ 'LT', 'Lithuania',            '+370' ],
			[ 'LU', 'Luxembourg',           '+352' ],
			[ 'MO', 'Macau',                '+853' ],
			[ 'MY', 'Malaysia',             '+60'  ],
			[ 'MT', 'Malta',                '+356' ],
			[ 'MX', 'Mexico',               '+52'  ],
			[ 'MD', 'Moldova',              '+373' ],
			[ 'MC', 'Monaco',               '+377' ],
			[ 'MN', 'Mongolia',             '+976' ],
			[ 'ME', 'Montenegro',           '+382' ],
			[ 'MA', 'Morocco',              '+212' ],
			[ 'NP', 'Nepal',                '+977' ],
			[ 'NL', 'Netherlands',          '+31'  ],
			[ 'NZ', 'New Zealand',          '+64'  ],
			[ 'NI', 'Nicaragua',            '+505' ],
			[ 'NG', 'Nigeria',              '+234' ],
			[ 'KP', 'North Korea',          '+850' ],
			[ 'MK', 'North Macedonia',      '+389' ],
			[ 'NO', 'Norway',               '+47'  ],
			[ 'OM', 'Oman',                 '+968' ],
			[ 'PK', 'Pakistan',             '+92'  ],
			[ 'PA', 'Panama',               '+507' ],
			[ 'PY', 'Paraguay',             '+595' ],
			[ 'PE', 'Peru',                 '+51'  ],
			[ 'PH', 'Philippines',          '+63'  ],
			[ 'PL', 'Poland',               '+48'  ],
			[ 'PT', 'Portugal',             '+351' ],
			[ 'PR', 'Puerto Rico',          '+1'   ],
			[ 'QA', 'Qatar',                '+974' ],
			[ 'RO', 'Romania',              '+40'  ],
			[ 'RU', 'Russia',               '+7'   ],
			[ 'SA', 'Saudi Arabia',         '+966' ],
			[ 'RS', 'Serbia',               '+381' ],
			[ 'SG', 'Singapore',            '+65'  ],
			[ 'SK', 'Slovakia',             '+421' ],
			[ 'SI', 'Slovenia',             '+386' ],
			[ 'ZA', 'South Africa',         '+27'  ],
			[ 'KR', 'South Korea',          '+82'  ],
			[ 'ES', 'Spain',                '+34'  ],
			[ 'LK', 'Sri Lanka',            '+94'  ],
			[ 'SE', 'Sweden',               '+46'  ],
			[ 'CH', 'Switzerland',          '+41'  ],
			[ 'SY', 'Syria',                '+963' ],
			[ 'TW', 'Taiwan',               '+886' ],
			[ 'TZ', 'Tanzania',             '+255' ],
			[ 'TH', 'Thailand',             '+66'  ],
			[ 'TN', 'Tunisia',              '+216' ],
			[ 'TR', 'Türkiye',              '+90'  ],
			[ 'UA', 'Ukraine',              '+380' ],
			[ 'AE', 'United Arab Emirates', '+971' ],
			[ 'GB', 'United Kingdom',       '+44'  ],
			[ 'US', 'United States',        '+1'   ],
			[ 'UY', 'Uruguay',              '+598' ],
			[ 'UZ', 'Uzbekistan',           '+998' ],
			[ 'VE', 'Venezuela',            '+58'  ],
			[ 'VN', 'Vietnam',              '+84'  ],
			[ 'YE', 'Yemen',                '+967' ],
			[ 'ZM', 'Zambia',               '+260' ],
			[ 'ZW', 'Zimbabwe',             '+263' ],
		];

	/** Generate a flag emoji from a 2-letter ISO code via regional indicators. */
	function flagFromIso( iso ) {
		var s = String( iso || '' ).toUpperCase();
		if ( s.length !== 2 || ! /^[A-Z]{2}$/.test( s ) ) return '🏳️';
		return String.fromCodePoint( 127397 + s.charCodeAt( 0 ) ) + String.fromCodePoint( 127397 + s.charCodeAt( 1 ) );
	}

	function findCountryByIso( iso ) {
		var target = String( iso || '' ).toUpperCase();
		for ( var i = 0; i < NF_COUNTRIES.length; i++ ) {
			if ( NF_COUNTRIES[ i ][ 0 ] === target ) return NF_COUNTRIES[ i ];
		}
		return null;
	}

	/**
	 * Wire every phone-picker wrapper inside the form.
	 *
	 * Picker UX:
	 *   - Initial flag set from data-default-country, with the dial code
	 *     prefilled into the tel input (only if it's currently empty —
	 *     don't clobber a saved value).
	 *   - Click flag → toggle dropdown open / closed.
	 *   - Type in search → filter list by name or dial code.
	 *   - Click an item → set flag + replace the leading dial code in the
	 *     input (preserves any user-typed digits past the prefix), close.
	 *   - Click outside / Escape → close.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initPhonePickers( form ) {
		var pickers = form.querySelectorAll( '[data-neural-forms-phone="1"]' );
		for ( var p = 0; p < pickers.length; p++ ) {
			initOnePhonePicker( pickers[ p ] );
		}
	}

	function initOnePhonePicker( wrap ) {
		var flagBtn  = wrap.querySelector( '.neural-forms-form__phone-flag' );
		var flagSpan = wrap.querySelector( '.neural-forms-form__phone-flag-emoji' );
		var input    = wrap.querySelector( 'input[type="tel"]' );
		var dropdown = wrap.querySelector( '.neural-forms-form__phone-dropdown' );
		var search   = wrap.querySelector( '.neural-forms-form__phone-search' );
		var list     = wrap.querySelector( '.neural-forms-form__phone-list' );
		if ( ! flagBtn || ! flagSpan || ! input || ! dropdown || ! list ) return;

		var current = findCountryByIso( wrap.getAttribute( 'data-default-country' ) ) || findCountryByIso( 'US' );
		if ( ! current ) return;

		var hydrated = false;
		function hydrate() {
			if ( hydrated ) return;
			var html = '';
			for ( var i = 0; i < NF_COUNTRIES.length; i++ ) {
				var c = NF_COUNTRIES[ i ];
				html += '<li role="option" data-iso="' + c[ 0 ] + '" data-dial="' + c[ 2 ] + '" data-search="'
					+ ( c[ 1 ] + ' ' + c[ 2 ] ).toLowerCase().replace( /"/g, '&quot;' )
					+ '"><span class="neural-forms-form__phone-list-flag">' + flagFromIso( c[ 0 ] ) + '</span>'
					+ '<span class="neural-forms-form__phone-list-name">' + c[ 1 ] + '</span>'
					+ '<span class="neural-forms-form__phone-list-dial">' + c[ 2 ] + '</span></li>';
			}
			list.innerHTML = html;
			hydrated = true;
		}

		function applyCountry( country, prefillInput ) {
			current = country;
			flagSpan.textContent = flagFromIso( country[ 0 ] );
			wrap.setAttribute( 'data-current-country', country[ 0 ] );
			if ( prefillInput ) {
				var existing = input.value || '';
				// Replace a leading "+XXX " (or "+XXX") prefix with the new
				// dial code; otherwise just prepend the dial code to the
				// untouched value. Keeps any digits the user already typed.
				// Trailing space after the dial code so visitor-typed
				// digits don't run into the prefix — submission reads as
				// "+1 9785880685" instead of "+19785880685" which is
				// much easier to scan in entries / CSV exports.
				var stripped = existing.replace( /^\s*\+\d+\s*/, '' );
				input.value  = country[ 2 ] + ' ' + ( stripped ? stripped.trim() : '' );
			}
		}

		// Initial flag — set without prefilling if the input has any
		// saved value; only prefill the dial code on a blank input.
		applyCountry( current, ! ( input.value && input.value.trim() !== '' ) );

		function open() {
			hydrate();
			dropdown.hidden = false;
			flagBtn.setAttribute( 'aria-expanded', 'true' );
			search.value = '';
			filter( '' );
			setTimeout( function () { try { search.focus(); } catch ( _ ) { /* noop */ } }, 0 );
		}
		function close() {
			dropdown.hidden = true;
			flagBtn.setAttribute( 'aria-expanded', 'false' );
		}
		function toggle() {
			if ( dropdown.hidden ) open(); else close();
		}

		function filter( q ) {
			var needle = String( q || '' ).toLowerCase().trim();
			var items  = list.querySelectorAll( 'li' );
			for ( var i = 0; i < items.length; i++ ) {
				var hay = items[ i ].getAttribute( 'data-search' ) || '';
				items[ i ].hidden = needle !== '' && hay.indexOf( needle ) === -1;
			}
		}

		flagBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			toggle();
		} );

		search.addEventListener( 'input', function () { filter( search.value ); } );
		search.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { e.preventDefault(); close(); flagBtn.focus(); }
		} );

		list.addEventListener( 'click', function ( e ) {
			var li = e.target && e.target.closest && e.target.closest( 'li' );
			if ( ! li ) return;
			var iso = li.getAttribute( 'data-iso' );
			var country = findCountryByIso( iso );
			if ( country ) {
				applyCountry( country, true );
				close();
				try { input.focus(); } catch ( _ ) { /* noop */ }
			}
		} );

		// Close when clicking outside this picker.
		document.addEventListener( 'click', function ( e ) {
			if ( dropdown.hidden ) return;
			if ( wrap.contains( e.target ) ) return;
			close();
		} );
	}

	function initRepeaters( form ) {
		var repeaters = form.querySelectorAll( '.neural-forms-repeater' );
		for ( var i = 0; i < repeaters.length; i++ ) {
			refreshRepeaterControls( repeaters[ i ] );
		}

		// Delegated click — survives DOM additions without re-binding.
		form.addEventListener( 'click', function ( e ) {
			var target = e.target;
			if ( ! target || typeof target.closest !== 'function' ) return;
			var addBtn    = target.closest( '[data-action="repeater-add"]' );
			var removeBtn = target.closest( '[data-action="repeater-remove"]' );
			if ( addBtn ) {
				e.preventDefault();
				handleRepeaterAdd( form, addBtn );
				return;
			}
			if ( removeBtn ) {
				e.preventDefault();
				handleRepeaterRemove( form, removeBtn );
			}
		} );
	}

	function handleRepeaterAdd( form, addBtn ) {
		var repeaterId = addBtn.getAttribute( 'data-target' );
		if ( ! repeaterId ) return;
		var repeater = form.querySelector(
			'.neural-forms-repeater[data-neural-forms-repeater="' + cssEscapeValue( repeaterId ) + '"]'
		);
		if ( ! repeater ) return;
		var list     = repeater.querySelector( '.neural-forms-repeater__list' );
		var template = repeater.querySelector(
			'template.neural-forms-repeater__template[data-template-for="' + cssEscapeValue( repeaterId ) + '"]'
		);
		if ( ! list || ! template || ! template.content ) return;

		var max = parseInt( repeater.getAttribute( 'data-max' ) || '5', 10 ) || 5;
		var current = list.querySelectorAll( '.neural-forms-repeater__item' ).length;
		if ( current >= max ) return;

		// Clone the template and stamp the new index across every name /
		// id / for / aria-describedby template attribute. We pass the
		// repeater element through explicitly because the cloned
		// fragment isn't attached to the DOM yet — Element.closest()
		// on a DocumentFragment child returns null, so the header
		// reindex can't find data-item-num-template otherwise.
		var fragment = template.content.cloneNode( true );
		stampRepeaterIndex( fragment, current, repeater );

		list.appendChild( fragment );
		refreshRepeaterControls( repeater );
	}

	function handleRepeaterRemove( form, removeBtn ) {
		var item = removeBtn.closest( '.neural-forms-repeater__item' );
		if ( ! item ) return;
		var repeater = item.closest( '.neural-forms-repeater' );
		if ( ! repeater ) return;
		var list = repeater.querySelector( '.neural-forms-repeater__list' );
		if ( ! list ) return;

		var min = parseInt( repeater.getAttribute( 'data-min' ) || '1', 10 ) || 1;
		var current = list.querySelectorAll( '.neural-forms-repeater__item' ).length;
		// Allow removing down to 0 when min === 0; otherwise keep min.
		if ( current <= Math.max( 1, min ) && current <= min ) return;
		if ( min > 0 && current <= min ) return;

		item.parentNode.removeChild( item );

		// Reindex the survivors so name / id stay contiguous.
		var items = list.querySelectorAll( '.neural-forms-repeater__item' );
		for ( var i = 0; i < items.length; i++ ) {
			stampRepeaterIndex( items[ i ], i, repeater );
		}
		refreshRepeaterControls( repeater );
	}

	/**
	 * Rewrite every name / id / for / aria-describedby attribute in
	 * a freshly-cloned template fragment (or an existing item being
	 * reindexed) by substituting __INDEX__ with `index` across the
	 * stored template attributes (data-name-template, data-id-template,
	 * data-for-template, data-aria-describedby-template,
	 * data-error-id-template).
	 *
	 * `index` is 0-based. The 1-based human number for the visible
	 * item label gets stamped from `data-item-num-template` on the
	 * containing fieldset.
	 *
	 * Accepts either a DocumentFragment (initial add) or an Element
	 * (reindex pass). The caller MUST pass `repeaterEl` because a
	 * freshly-cloned DocumentFragment has no enclosing fieldset
	 * (it isn't attached to the DOM yet), so Element.closest() from
	 * inside the fragment cannot find data-item-num-template. We
	 * accept the repeater element as a parameter rather than walking
	 * up from the cloned header.
	 */
	function stampRepeaterIndex( root, index, repeaterEl ) {
		var idxStr   = String( index );
		var idxHuman = String( index + 1 );

		// On a freshly cloned fragment the outermost element is the
		// <li>; on a reindex we already have an Element. Both cases
		// expose querySelectorAll so we can walk them uniformly.
		var item = root.querySelector
			? ( root.nodeType === 1 ? root : root.querySelector( '.neural-forms-repeater__item' ) )
			: null;
		if ( item && item.setAttribute ) {
			item.setAttribute( 'data-index', idxStr );
		}

		// Visible item label (e.g. "1.", "2."). Template lives on the
		// fieldset's data-item-num-template attribute; substitute
		// __INDEX_1__ (1-based) and __INDEX__ (0-based) verbatim.
		var headers = root.querySelectorAll
			? root.querySelectorAll( '[data-item-num]' )
			: [];
		// Resolve the template ONCE — the repeater element is the
		// same for every header inside the same fragment.
		var headerTpl = repeaterEl ? repeaterEl.getAttribute( 'data-item-num-template' ) : null;
		// Fallback: if no repeaterEl was passed (defensive), try to
		// walk up from each header. Works for elements already in the
		// DOM; returns null for fragment children.
		for ( var h = 0; h < headers.length; h++ ) {
			var tpl = headerTpl;
			if ( ! tpl ) {
				var fallbackRepeater = headers[ h ].closest && headers[ h ].closest( '.neural-forms-repeater' );
				tpl = fallbackRepeater ? fallbackRepeater.getAttribute( 'data-item-num-template' ) : null;
			}
			if ( ! tpl ) continue;
			headers[ h ].textContent = tpl
				.replace( /__INDEX_1__/g, idxHuman )
				.replace( /__INDEX__/g, idxStr );
		}

		stampAttrFromTemplate( root, '[data-name-template]',                 'data-name-template',                 'name',             idxStr );
		stampAttrFromTemplate( root, '[data-id-template]',                   'data-id-template',                   'id',               idxStr );
		stampAttrFromTemplate( root, '[data-for-template]',                  'data-for-template',                  'for',              idxStr );
		stampAttrFromTemplate( root, '[data-aria-describedby-template]',     'data-aria-describedby-template',     'aria-describedby', idxStr );
		stampAttrFromTemplate( root, '[data-error-id-template]',             'data-error-id-template',             'id',               idxStr );
	}

	function stampAttrFromTemplate( root, selector, tplAttr, targetAttr, idxStr ) {
		if ( ! root.querySelectorAll ) return;
		var nodes = root.querySelectorAll( selector );
		for ( var i = 0; i < nodes.length; i++ ) {
			var tpl = nodes[ i ].getAttribute( tplAttr );
			if ( tpl == null ) continue;
			nodes[ i ].setAttribute( targetAttr, tpl.replace( /__INDEX__/g, idxStr ) );
		}
	}

	/**
	 * Show / hide the add + remove buttons based on the current
	 * item count vs data-min / data-max.
	 *   - add hidden when count >= max.
	 *   - remove hidden on every item when count <= min (so the
	 *     visitor can't drop below the required minimum).
	 */
	function refreshRepeaterControls( repeater ) {
		var min = parseInt( repeater.getAttribute( 'data-min' ) || '1', 10 ) || 1;
		var max = parseInt( repeater.getAttribute( 'data-max' ) || '5', 10 ) || 5;
		var list  = repeater.querySelector( '.neural-forms-repeater__list' );
		var items = list ? list.querySelectorAll( '.neural-forms-repeater__item' ) : [];
		var count = items.length;

		var addBtn = repeater.querySelector( '[data-action="repeater-add"]' );
		if ( addBtn ) {
			if ( count >= max ) addBtn.setAttribute( 'hidden', '' );
			else                addBtn.removeAttribute( 'hidden' );
		}
		// Hide ALL remove buttons when at-or-below min (with the
		// "at least 1 row" safety net when min === 0).
		var atOrBelowMin = ( min > 0 ) ? count <= min : count <= 1;
		for ( var i = 0; i < items.length; i++ ) {
			var rm = items[ i ].querySelector( '[data-action="repeater-remove"]' );
			if ( ! rm ) continue;
			if ( atOrBelowMin ) rm.setAttribute( 'hidden', '' );
			else                rm.removeAttribute( 'hidden' );
		}
	}

	/**
	 * Escape a value for use inside a CSS attribute selector. The
	 * field id only contains [a-z0-9_], but defence in depth — escape
	 * common selector specials so a future change in id allowlist
	 * doesn't suddenly break the selector.
	 */
	function cssEscapeValue( v ) {
		return String( v ).replace( /["\\]/g, '\\$&' );
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

		// Client-side pre-submit validation. Server validation remains
		// the source of truth, but this gives the visitor immediate
		// inline feedback on required + type errors (phone "0",
		// malformed email, etc.) without a round-trip. clearErrors()
		// runs inside validateClientSide() so callers don't have to.
		if ( ! validateClientSide( form ) ) {
			return;
		}

		// Reset UI, disable form, then fire the request.
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
						// Some apiFetch wrappers expose the JSON body
						// at result.body; raw fetch already does. Either
						// shape is supported defensively.
						var payload = ( result && result.body && typeof result.body === 'object' )
							? result.body
							: ( result && typeof result === 'object' ? result : {} );
						var polls = ( payload && typeof payload.polls === 'object' ) ? payload.polls : null;
						var quiz  = ( payload && typeof payload.quiz  === 'object' ) ? payload.quiz  : null;

						// In-form placeholders (still useful for
						// always-visible polls before/after submit, and
						// as an aria-live target). Render BEFORE the
						// success swap so any redirect path keeps the
						// state correct for back-navigation.
						if ( polls ) renderPollResults( form, polls );
						if ( quiz )  renderQuizResults( form, quiz );

						// Build a combined results card placed INSIDE
						// the success block (a sibling of the form,
						// outside the form.hidden=true region). This
						// is what the user actually sees post-submit
						// — the in-form placeholders are hidden the
						// moment showSuccess() runs.
						populateDynamicResults( form, polls, quiz );

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
	 * Mirrors SubmitEndpoint::resolve_hidden_field_ids(): every rule is
	 * re-evaluated from scratch, iterating to a fixed point so a field
	 * whose controller was hidden in an earlier pass is hidden too (the
	 * engine treats a hidden controller as unavailable → rule fails).
	 *
	 * @param {HTMLFormElement} form
	 */
	function updateConditionalFields( form ) {
		var wrappers = form.querySelectorAll( '[data-conditional]' );
		if ( wrappers.length === 0 ) {
			return;
		}

		var entries = [];
		for ( var i = 0; i < wrappers.length; i++ ) {
			var wrapper = wrappers[ i ];
			var raw;
			try {
				raw = JSON.parse( wrapper.getAttribute( 'data-conditional' ) );
			} catch ( parseErr ) {
				continue;
			}
			var tree = normalizeConditional( raw );
			if ( ! tree ) {
				continue;
			}
			entries.push( {
				wrapper: wrapper,
				id:      wrapper.getAttribute( 'data-neural-forms-field-id' ) || '',
				tree:    tree
			} );
		}

		var hidden = {};
		var getValue = function ( fieldId ) { return getFieldValue( form, fieldId ); };
		var isUnavailable = function ( fieldId ) { return !! hidden[ fieldId ]; };

		var guard = 0;
		var changed;
		do {
			changed = false;
			for ( var j = 0; j < entries.length; j++ ) {
				var e = entries[ j ];
				if ( e.id && hidden[ e.id ] ) {
					continue;
				}
				if ( ! evaluateConditionTree( e.tree, getValue, isUnavailable ) ) {
					if ( e.id ) {
						hidden[ e.id ] = true;
					} else {
						e.forceHidden = true;
					}
					changed = true;
				}
			}
		} while ( changed && ++guard < 50 );

		for ( var k = 0; k < entries.length; k++ ) {
			var en = entries[ k ];
			var isHidden = en.forceHidden || ( en.id && hidden[ en.id ] );
			if ( isHidden ) {
				en.wrapper.style.display = 'none';
				clearFieldValues( en.wrapper );
			} else {
				en.wrapper.style.display = '';
			}
		}
	}

	/**
	 * Normalise a data-conditional payload (v2, or the legacy single
	 * rule {field, operator, value}) to one tree:
	 *
	 *   { action: 'show'|'hide', match: 'all'|'any',
	 *     rules: [ {field, operator, value} ], groups: [ {match, rules} ] }
	 *
	 * Mirrors ConditionEngine::normalize() in PHP. Returns null when
	 * the payload carries no usable rule.
	 *
	 * @param  {Object} raw
	 * @return {Object|null}
	 */
	function normalizeConditional( raw ) {
		if ( ! raw || typeof raw !== 'object' ) {
			return null;
		}
		var normRule = function ( r ) {
			if ( ! r || typeof r !== 'object' ) return null;
			var field = r.field === undefined || r.field === null ? '' : String( r.field );
			if ( field === '' ) return null;
			var value = r.value;
			if ( value === undefined || value === null || typeof value === 'object' ) value = '';
			return {
				field:    field,
				operator: r.operator === undefined || r.operator === null ? 'equals' : String( r.operator ),
				value:    String( value )
			};
		};
		var tree = {
			action: raw.action === 'hide' ? 'hide' : 'show',
			match:  raw.match === 'any' ? 'any' : 'all',
			rules:  [],
			groups: []
		};
		var i, r;
		if ( Array.isArray( raw.rules ) ) {
			for ( i = 0; i < raw.rules.length; i++ ) {
				r = normRule( raw.rules[ i ] );
				if ( r ) tree.rules.push( r );
			}
		} else if ( raw.field !== undefined ) {
			r = normRule( raw );
			if ( r ) tree.rules.push( r );
		}
		if ( Array.isArray( raw.groups ) ) {
			for ( i = 0; i < raw.groups.length; i++ ) {
				var g = raw.groups[ i ];
				if ( ! g || typeof g !== 'object' || ! Array.isArray( g.rules ) ) continue;
				var ng = { match: g.match === 'any' ? 'any' : 'all', rules: [] };
				for ( var j = 0; j < g.rules.length; j++ ) {
					r = normRule( g.rules[ j ] );
					if ( r ) ng.rules.push( r );
				}
				if ( ng.rules.length ) tree.groups.push( ng );
			}
		}
		if ( ! tree.rules.length && ! tree.groups.length ) {
			return null;
		}
		return tree;
	}

	/**
	 * Evaluate a normalised condition tree. Returns true when the owner
	 * should be VISIBLE. Mirrors ConditionEngine::evaluate() in PHP.
	 *
	 * @param  {Object}   tree           From normalizeConditional().
	 * @param  {Function} getValue       fieldId → current string value.
	 * @param  {Function} isUnavailable  fieldId → true when that controller
	 *                                   is itself hidden (rule then fails).
	 * @return {boolean}
	 */
	function evaluateConditionTree( tree, getValue, isUnavailable ) {
		if ( ! tree ) {
			return true;
		}
		var evalGroup = function ( match, rules, groups ) {
			var results = [];
			var i;
			for ( i = 0; i < rules.length; i++ ) {
				var rule = rules[ i ];
				if ( isUnavailable && isUnavailable( rule.field ) ) {
					results.push( false );
				} else {
					results.push( evaluateCondition( rule.operator, getValue( rule.field ), rule.value ) );
				}
			}
			for ( i = 0; i < groups.length; i++ ) {
				results.push( evalGroup( groups[ i ].match, groups[ i ].rules, [] ) );
			}
			if ( ! results.length ) {
				return true;
			}
			if ( match === 'any' ) {
				return results.indexOf( true ) !== -1;
			}
			return results.indexOf( false ) === -1;
		};
		var matched = evalGroup( tree.match, tree.rules, tree.groups );
		return tree.action === 'hide' ? ! matched : matched;
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
	 * Operators:
	 *   equals / not_equals / contains (empty rule value never matches,
	 *     mirroring PHP) / empty / not_empty
	 *   gte / lte — numeric comparison; if either side does not parse as
	 *     a finite number, returns false (rule not satisfied → field
	 *     stays hidden). Safer than treating the comparison as true
	 *     when the data shape is unexpected.
	 *
	 * @param  {string} operator      Rule operator.
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
			case 'contains':   return r !== '' && v.indexOf( r ) !== -1; // empty needle never matches (same as PHP)
			case 'empty':      return v.trim() === '';
			case 'not_empty':  return v.trim() !== '';
			case 'gte':
			case 'lte': {
				var vNum = parseFloat( v );
				var rNum = parseFloat( r );
				if ( ! isFinite( vNum ) || ! isFinite( rNum ) ) return false;
				return operator === 'gte' ? vNum >= rNum : vNum <= rNum;
			}
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
	 * Parse a bracketed input name into a path of segments.
	 *
	 * Examples:
	 *   "email"             '->-> ["email"]
	 *   "picks[]"           '->-> ["picks", ""]
	 *   "matrix[purchase]"  '->-> ["matrix", "purchase"]
	 *   "matrix[row][]"     '->-> ["matrix", "row", ""]
	 *
	 * An empty-string segment means "push onto an array at this level"
	 * (matches how PHP parses `foo[]=1&foo[]=2` from a multipart body).
	 *
	 * Returns a single-element array for names without brackets, so
	 * callers can treat every input the same way.
	 *
	 * @param  {string} name
	 * @return {string[]}
	 */
	function parseBracketName( name ) {
		var open = name.indexOf( '[' );
		if ( open === -1 ) return [ name ];
		var parts = [ name.slice( 0, open ) ];
		var rest  = name.slice( open );
		var re    = /\[([^\]]*)\]/g;
		var m;
		while ( ( m = re.exec( rest ) ) !== null ) {
			parts.push( m[ 1 ] );
		}
		return parts;
	}

	/**
	 * Write `value` into `target` at the given path of segments.
	 *
	 * Path semantics mirror PHP's bracket parser:
	 *   - A trailing empty segment ("") means "this level is an array,
	 *     push the value." Multiple inputs with the same array path
	 *     accumulate into a single array on the target.
	 *   - Any other segment is a string key into an object. Intermediate
	 *     containers are auto-created as arrays or objects depending on
	 *     whether the NEXT segment is the array marker.
	 *
	 * `opts.skipPush` initialises the container chain without pushing —
	 * used so an "all-unchecked" checkbox group still produces an empty
	 * array on the wire, preserving the pre-matrix serializer's behaviour.
	 *
	 * @param {Object|Array} target
	 * @param {string[]}     path
	 * @param {*}            value
	 * @param {Object}       [opts]
	 */
	function setNestedValue( target, path, value, opts ) {
		opts = opts || {};
		var cur = target;
		for ( var i = 0; i < path.length - 1; i++ ) {
			var seg  = path[ i ];
			var next = path[ i + 1 ];
			var expectArray = next === '';
			if ( cur[ seg ] === undefined || cur[ seg ] === null ) {
				cur[ seg ] = expectArray ? [] : {};
			}
			cur = cur[ seg ];
		}
		var last = path[ path.length - 1 ];
		if ( last === '' ) {
			// Append to the array at the parent path.
			if ( ! Array.isArray( cur ) ) return;
			if ( opts.skipPush ) return;
			cur.push( value );
		} else {
			cur[ last ] = value;
		}
	}

	/**
	 * Collect all named form controls into a nested JSON-friendly object
	 * that matches what PHP's $_POST parser produces from the same
	 * multipart submission.
	 *
	 * Bracket-aware so Matrix submissions and any future nested field
	 * type serialise correctly on the JSON (no-files) path:
	 *
	 *   <input name="email">                              '->-> { email: "..." }
	 *   <input name="picks[]" type="checkbox">             '->-> { picks: ["a","b"] }
	 *   <input name="m[purchase]" type="radio">            '->-> { m: { purchase: "ok" } }
	 *   <input name="m[purchase][]" type="checkbox">       '->-> { m: { purchase: ["a","b"] } }
	 *
	 * Type rules (unchanged from the pre-matrix serializer):
	 *   checkbox single  '->-> integer 1 (checked) or 0 (unchecked). Matches
	 *                       SubmissionValidator::CHECKED_CHECKBOX_VALUES.
	 *   checkbox array   '->-> array of checked values; empty array when
	 *                       none checked (matches the legacy serializer's
	 *                       always-initialised behaviour).
	 *   radio            '->-> value of the checked option only; absent when
	 *                       none checked, so the server's required check fires.
	 *   file             '->-> skipped — files only ride the multipart path.
	 *   all other        '->-> .value as a string.
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

			var path        = parseBracketName( el.name );
			var isArrayPath = path[ path.length - 1 ] === '';

			if ( el.type === 'checkbox' && isArrayPath ) {
				// Multi-option group OR matrix-checkbox cell. Pre-init the
				// array on the FIRST encounter (skipPush) so groups whose
				// every option is unchecked still send `[]` on the wire —
				// matches the pre-matrix serializer's behaviour exactly.
				setNestedValue( data, path, null, { skipPush: true } );
				if ( el.checked ) {
					setNestedValue( data, path, el.value );
				}
			} else if ( el.type === 'checkbox' ) {
				setNestedValue( data, path, el.checked ? 1 : 0 );
			} else if ( el.type === 'radio' ) {
				// Write only when checked - leave the key absent for unchecked radios.
				if ( el.checked ) {
					setNestedValue( data, path, el.value );
				}
			} else {
				// text, email, tel, number, textarea, select, hidden, ...
				setNestedValue( data, path, el.value );
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
	/**
	 * Human size label for a file input's limit. Prefers the server-rendered
	 * data-max-size-label (already capped by the host's upload limit and
	 * formatted, e.g. "2 MB" / "1.5 MB" / "512 KB"); falls back to rounding
	 * the byte count.
	 *
	 * @param  {HTMLInputElement} input
	 * @param  {number}           maxSize  bytes
	 * @return {string}
	 */
	function fileSizeLabel( input, maxSize ) {
		var label = input.getAttribute( 'data-max-size-label' );
		if ( label ) {
			return label;
		}
		var mb = maxSize / ( 1024 * 1024 );
		return ( mb >= 1 ? ( Math.round( mb * 10 ) / 10 ) + ' MB' : Math.round( maxSize / 1024 ) + ' KB' );
	}

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
				return 'The selected file exceeds the maximum allowed size of ' + fileSizeLabel( input, maxSize ) + '.';
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
	 * Resolve the human-readable label for a field wrapper so client-side
	 * validation messages can read like the server's ("Email is required.",
	 * "Phone must be a valid phone number."). FormRenderer always emits a
	 * <label> or <legend> when the field has one — visible, or
	 * .screen-reader-text when the block hides labels — so the placeholder
	 * fallback below is only reached for fields with no label at all.
	 *
	 * @param {Element} wrapper  .neural-forms-form__field element.
	 * @param {Element} input    The <input>/<select>/<textarea>.
	 * @returns {string}         A label-like string, never empty.
	 */
	function fieldLabelText( wrapper, input ) {
		var labelEl = wrapper.querySelector( '.neural-forms-form__label, label.screen-reader-text, legend' );
		if ( labelEl ) {
			// Strip the required asterisk (rendered as <span> with text '*')
			// and trim whitespace.
			var txt = ( labelEl.textContent || '' ).replace( /\*/g, '' ).trim();
			if ( txt !== '' ) return txt;
		}
		if ( input ) {
			var aria = input.getAttribute( 'aria-label' );
			if ( aria && aria.trim() !== '' ) return aria.trim();
			var ph = input.getAttribute( 'placeholder' );
			if ( ph && ph.trim() !== '' ) return ph.trim();
		}
		return 'This field';
	}

	/**
	 * Apply field-level error state to a wrapper. Mirrors the DOM mutations
	 * that showFieldErrors() makes for server-returned errors so the same
	 * CSS hooks (red border via __field--has-error, error paragraph slot)
	 * light up.
	 *
	 * @param {Element} wrapper
	 * @param {string}  message
	 */
	function markFieldInvalid( wrapper, message ) {
		var errEl = wrapper.querySelector( '.neural-forms-form__field-error' );
		if ( errEl ) {
			errEl.textContent = message;
			errEl.hidden      = false;
		}
		wrapper.classList.add( 'neural-forms-form__field--has-error' );
		var input = wrapper.querySelector( 'input, select, textarea' );
		if ( input ) {
			input.setAttribute( 'aria-invalid', 'true' );
		}
	}

	/**
	 * Client-side type validation for a single non-empty input value.
	 *
	 * Returns an error message (string) when the value fails the type
	 * check for the given HTML5 input type, or null when it passes /
	 * the type has no client-side rule. The rules deliberately MIRROR
	 * the server's check_type() so a value that passes here also
	 * passes the server, and vice versa (no UX mismatch).
	 *
	 *   - email   matches RFC-light "<local>@<host>.<tld>" with no spaces
	 *   - tel     digits-and-separators only, 7+ digits total (rejects "0",
	 *             single-digit entries, alphabetic strings)
	 *   - url     parseable by URL() — same intent as FILTER_VALIDATE_URL
	 *   - number  finite numeric — same as is_numeric()
	 *   - date    YYYY-MM-DD plus a real-date check (rejects 2025-13-40)
	 *
	 * @param {string}  type     Lowercase input.type ('email', 'tel', …).
	 * @param {string}  value    The trimmed value (caller guarantees non-empty).
	 * @param {string}  label    Field label for the user-visible message.
	 * @returns {string|null}
	 */
	function clientTypeError( type, value, label ) {
		switch ( type ) {
			case 'email':
				if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
					return label + ' must be a valid email address.';
				}
				return null;

			case 'tel':
				// Allowed punctuation: + (leading only), digits, spaces,
				// dashes, parens, dots. Need at least 7 digits to be a
				// plausible phone number. A bare "0" or "1" has 1 digit
				// and rightly fails. Letters or symbols outside the
				// allowed set also fail.
				if ( ! /^\+?[\d\s().\-]+$/.test( value ) ) {
					return label + ' must be a valid phone number.';
				}
				var digits = value.replace( /\D/g, '' );
				if ( digits.length < 7 || digits.length > 15 ) {
					return label + ' must be a valid phone number.';
				}
				return null;

			case 'url':
				try {
					// URL() throws on malformed values; same intent as
					// PHP's FILTER_VALIDATE_URL.
					new URL( value );
				} catch ( e ) {
					return label + ' must be a valid URL.';
				}
				return null;

			case 'number':
				if ( ! isFinite( Number( value ) ) ) {
					return label + ' must be a number.';
				}
				return null;

			case 'date':
				if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
					return label + ' must be a valid date.';
				}
				// Reject impossible dates (e.g. 2025-13-40). The Date
				// constructor accepts YYYY-MM-DD but normalises out-of-
				// range parts — round-trip the parse to detect that.
				var parts = value.split( '-' );
				var y = Number( parts[ 0 ] ), m = Number( parts[ 1 ] ), d = Number( parts[ 2 ] );
				var dt = new Date( Date.UTC( y, m - 1, d ) );
				if (
					isNaN( dt.getTime() )
					|| dt.getUTCFullYear() !== y
					|| dt.getUTCMonth() + 1  !== m
					|| dt.getUTCDate()       !== d
				) {
					return label + ' must be a valid date.';
				}
				return null;
		}
		return null;
	}

	/**
	 * Client-side mirror of the server-side file validation in
	 * SubmitEndpoint::upload_file_cell. Returns a localised error
	 * string when the file should be rejected, or null when it's OK.
	 *
	 * Mirrors two server checks: file size (against data-max-size in
	 * bytes that the renderer emits) and file type (against the field's
	 * accept attribute when set, else the plugin's global allowed
	 * extension list which mirrors ALLOWED_MIME_GROUPS).
	 *
	 * Why we check extension AND mime: some files have no registered
	 * browser MIME at all (.pages, .ai, .psd) — file.type is empty so
	 * a mime-only check would let them through. Extension-based
	 * fallback closes that gap and matches the server's
	 * wp_check_filetype_and_ext behaviour.
	 *
	 * @param {HTMLInputElement} input
	 * @param {File}             file
	 * @param {string}           label
	 * @returns {string|null}
	 */
	function clientFileError( input, file, label ) {
		// Size check
		var maxSize = Number( input.getAttribute( 'data-max-size' ) || 0 );
		if ( maxSize > 0 && file.size > maxSize ) {
			return label + ': file exceeds the maximum allowed size of ' + fileSizeLabel( input, maxSize ) + '.';
		}

		// Type check
		var fileMime = ( file.type || '' ).toLowerCase();
		var fileName = ( file.name || '' ).toLowerCase();
		var dotAt    = fileName.lastIndexOf( '.' );
		var fileExt  = dotAt > -1 ? fileName.slice( dotAt + 1 ) : '';

		// MIME → extension table mirroring the server's
		// ALLOWED_MIME_GROUPS so the ext fallback can resolve known MIMEs
		// back to file extensions.
		var MIME_TO_EXTS = {
			'image/jpeg':                                                                  [ 'jpg', 'jpeg' ],
			'image/png':                                                                   [ 'png' ],
			'image/gif':                                                                   [ 'gif' ],
			'image/webp':                                                                  [ 'webp' ],
			'application/pdf':                                                             [ 'pdf' ],
			'application/msword':                                                          [ 'doc' ],
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document':     [ 'docx' ],
			'application/vnd.oasis.opendocument.text':                                     [ 'odt' ],
			'text/plain':                                                                  [ 'txt' ],
			'text/csv':                                                                    [ 'csv' ]
		};

		var acceptAttr = ( input.getAttribute( 'accept' ) || '' ).trim();
		if ( acceptAttr !== '' ) {
			// Field-level restriction: compare against the accept list.
			var allowedMimes = acceptAttr.split( ',' ).map( function ( s ) { return s.trim().toLowerCase(); } ).filter( Boolean );
			var allowedExts  = [];
			for ( var k = 0; k < allowedMimes.length; k++ ) {
				var exts = MIME_TO_EXTS[ allowedMimes[ k ] ] || [];
				allowedExts = allowedExts.concat( exts );
			}
			var mimeOk = fileMime !== '' && allowedMimes.indexOf( fileMime ) !== -1;
			var extOk  = fileExt  !== '' && allowedExts.indexOf( fileExt ) !== -1;
			if ( ! mimeOk && ! extOk ) {
				return label + ': file type not allowed.';
			}
		} else {
			// No field-level restriction → fall back to the plugin's
			// global extension list so the client check still matches
			// what the server will accept.
			var GLOBAL_EXTS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'odt', 'txt', 'csv' ];
			if ( fileExt !== '' && GLOBAL_EXTS.indexOf( fileExt ) === -1 ) {
				return label + ': file type not allowed.';
			}
			// Empty extension with no MIME → let the server reject;
			// extremely rare and we don't want to nag on files that
			// genuinely have no name extension at all.
		}

		return null;
	}

	/**
	 * Walk every visible field wrapper inside `scope` (the form, or a
	 * single multi-step step) and validate required + type. Returns the
	 * first wrapper that failed so the caller can scroll/focus it.
	 *
	 * Conditional-hidden wrappers ([hidden] / display:none) are skipped
	 * so visitors aren't blocked by rules on a field they can't see.
	 * Checkbox / radio groups are intentionally left to the server —
	 * HTML5 `required` on a single checkbox in a group doesn't
	 * represent the group rule, and the server already validates them.
	 *
	 * @param {Element} scope
	 * @returns {Element|null}  First failing wrapper, or null when all pass.
	 */
	function validateFieldsIn( scope ) {
		var wrappers = scope.querySelectorAll( '.neural-forms-form__field' );
		var firstError = null;

		for ( var i = 0; i < wrappers.length; i++ ) {
			var w = wrappers[ i ];

			// Skip fields hidden by conditional logic.
			if ( w.hasAttribute( 'hidden' ) ) continue;
			if ( w.style && w.style.display === 'none' ) continue;

			var input = w.querySelector( 'input, select, textarea' );
			if ( ! input ) continue;

			// Honeypot — server enforces it; client treats as transparent.
			if ( input.name === 'neural_forms_website' ) continue;

			// Hidden type without `required` is not user-facing; skip.
			if ( input.type === 'hidden' && ! input.required ) continue;

			// Checkbox / radio groups: leave to server (see docblock).
			if ( input.type === 'checkbox' || input.type === 'radio' ) continue;

			var label    = fieldLabelText( w, input );
			var required = input.required || input.hasAttribute( 'required' );

			if ( input.type === 'file' ) {
				if ( required && input.files && input.files.length === 0 ) {
					markFieldInvalid( w, label + ' is required.' );
					if ( ! firstError ) firstError = w;
					continue;
				}
				// Size + type checks when a file is selected — mirrors
				// the server-side validation in
				// SubmitEndpoint::upload_file_cell so multi-step Next
				// blocks here instead of bouncing to a final-submit error.
				if ( input.files && input.files.length > 0 ) {
					for ( var fi = 0; fi < input.files.length; fi++ ) {
						var fileErr = clientFileError( input, input.files[ fi ], label );
						if ( fileErr ) {
							markFieldInvalid( w, fileErr );
							if ( ! firstError ) firstError = w;
							break;
						}
					}
				}
				continue;
			}

			var value   = input.value != null ? String( input.value ) : '';
			var trimmed = value.trim();

			// Phone: the country picker pre-fills the dial code ("+1 "), so
			// an untouched field is "+1", not "". Treat a dial-code-only
			// value as empty — mirrors SubmissionValidator::normalize_phone()
			// so optional phones pass and required ones say "is required".
			if ( ( input.type || '' ).toLowerCase() === 'tel' && /^\+\d{1,4}$/.test( trimmed ) ) {
				trimmed = '';
			}

			if ( required && trimmed === '' ) {
				markFieldInvalid( w, label + ' is required.' );
				if ( ! firstError ) firstError = w;
				continue;
			}

			// Type validation only kicks in when the visitor has actually
			// entered something. Empty + optional is always fine.
			if ( trimmed !== '' ) {
				var typeErr = clientTypeError( ( input.type || '' ).toLowerCase(), trimmed, label );
				if ( typeErr ) {
					markFieldInvalid( w, typeErr );
					if ( ! firstError ) firstError = w;
				}
			}
		}

		return firstError;
	}

	/**
	 * Form-wide pre-submit validation. Clears prior errors, runs the
	 * required + type pass across the whole form, and on failure
	 * scrolls to / focuses the first invalid wrapper. Returns true
	 * when the submission can proceed.
	 *
	 * @param {HTMLFormElement} form
	 * @returns {boolean}
	 */
	function validateClientSide( form ) {
		clearErrors( form );
		var firstError = validateFieldsIn( form );
		if ( firstError ) {
			firstError.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			var focusTarget = firstError.querySelector( 'input, select, textarea' );
			if ( focusTarget && typeof focusTarget.focus === 'function' ) {
				setTimeout( function () { focusTarget.focus(); }, 350 );
			}
			return false;
		}
		return true;
	}

	/**
	 * Capture the initial state of every poll-results container at form
	 * init time so the reset path can restore "always" polls to a clean,
	 * non-stale render and "after_vote" polls to empty/hidden.
	 *
	 * Called once per form during init(). The snapshot is the SSR markup
	 * the server emitted on page load: for "always" polls this is the
	 * pre-rendered bars; for "after_vote" polls it's the empty + hidden
	 * placeholder. The snapshot is keyed against the element so it
	 * survives further DOM mutations within the container.
	 *
	 * @param {HTMLFormElement} form
	 */
	function snapshotPollResults( form ) {
		var containers = form.querySelectorAll( '[data-poll-results]' );
		var snapshots  = [];
		for ( var i = 0; i < containers.length; i++ ) {
			var c = containers[ i ];
			snapshots.push( {
				el:         c,
				html:       c.innerHTML,
				hidden:     !! c.hidden,
				visibility: c.getAttribute( 'data-poll-visibility' ) || 'after_vote'
			} );
		}
		// Stash on the form via a non-enumerable expando. WeakMap would
		// be cleaner but adds bytes for a single-call lookup we never
		// need to GC (the form lifecycle owns the snapshot).
		form._nfPollSnapshots = snapshots;
	}

	/**
	 * Reset poll-results containers to their post-page-load state.
	 *
	 *   never      — no element exists; nothing to do.
	 *   after_vote — clear innerHTML, hide. The next successful submit
	 *                will repopulate via renderPollResults().
	 *   always     — restore the snapshot HTML so we never display the
	 *                stale totals from the previous POST and never
	 *                accumulate duplicate "Total votes" / bar rows.
	 *
	 * renderPollResults already uses container.innerHTML = ... (replace,
	 * not append), so single-submit behaviour is correct on its own.
	 * This function exists for the "submit another response" path where
	 * the previous POST's bars would otherwise linger in the DOM.
	 *
	 * @param {HTMLFormElement} form
	 */
	function resetPollResults( form ) {
		var snapshots = form._nfPollSnapshots || [];
		for ( var i = 0; i < snapshots.length; i++ ) {
			var s = snapshots[ i ];
			if ( ! s || ! s.el || ! s.el.isConnected ) continue;
			if ( s.visibility === 'after_vote' ) {
				s.el.innerHTML = '';
				s.el.hidden    = true;
			} else if ( s.visibility === 'always' ) {
				s.el.innerHTML = s.html;
				s.el.hidden    = s.hidden;
			}
			// "never" containers are not emitted by the renderer at all,
			// so a snapshot for them never exists. No branch needed.
		}
	}

	/**
	 * Snapshot quiz feedback placeholders + the form-level summary so
	 * the reset path can return them to their post-page-load state.
	 *
	 * Both surfaces are SSR-empty + hidden, but capturing them means
	 * we don't depend on those defaults staying empty (e.g. a future
	 * always-visible mode could SSR last-attempt totals).
	 *
	 * @param {HTMLFormElement} form
	 */
	function snapshotQuizResults( form ) {
		var feedbacks = form.querySelectorAll( '[data-quiz-feedback]' );
		var snapshots = [];
		for ( var i = 0; i < feedbacks.length; i++ ) {
			var c = feedbacks[ i ];
			snapshots.push( { el: c, html: c.innerHTML, hidden: !! c.hidden } );
		}
		var summary = form.querySelector( '[data-quiz-summary]' );
		var summarySnapshot = summary
			? { el: summary, html: summary.innerHTML, hidden: !! summary.hidden }
			: null;

		form._nfQuizSnapshots       = snapshots;
		form._nfQuizSummarySnapshot = summarySnapshot;
	}

	/**
	 * Reset quiz feedback placeholders + summary card to SSR state.
	 * Mirrors resetPollResults but the contract is simpler — every
	 * quiz container resets to empty + hidden by default.
	 *
	 * @param {HTMLFormElement} form
	 */
	function resetQuizResults( form ) {
		var snapshots = form._nfQuizSnapshots || [];
		for ( var i = 0; i < snapshots.length; i++ ) {
			var s = snapshots[ i ];
			if ( ! s || ! s.el || ! s.el.isConnected ) continue;
			s.el.innerHTML = s.html;
			s.el.hidden    = s.hidden;
		}
		var sum = form._nfQuizSummarySnapshot;
		if ( sum && sum.el && sum.el.isConnected ) {
			sum.el.innerHTML = sum.html;
			sum.el.hidden    = sum.hidden;
		}
	}

	/**
	 * Reset multi-step UI to step 0. Calls into the closure-bound reset
	 * exposed by initMultiStep() via form._nfMultiStepReset (set during
	 * init). No-op for single-step forms.
	 *
	 * @param {HTMLFormElement} form
	 */
	function resetMultiStep( form ) {
		if ( typeof form._nfMultiStepReset === 'function' ) {
			form._nfMultiStepReset();
		}
	}

	/**
	 * Centralised "Submit another response" reset. Single source of
	 * truth so the success-link handler doesn't drift from any future
	 * reset trigger (e.g. a redirect-back, a programmatic API).
	 *
	 *   - form values: cleared via the native HTMLFormElement.reset()
	 *     so all input types (text/select/checkboxes/radio/file) follow
	 *     their HTML defaults. The honeypot input is unaffected — its
	 *     name + style="display:none" remain intact so bot detection is
	 *     preserved across resets.
	 *   - errors / global error: cleared via clearErrors().
	 *   - poll results: per-visibility cleanup via resetPollResults().
	 *   - multi-step: returned to step 0 via resetMultiStep().
	 *   - submit button: re-enabled in case a transient state lingered.
	 *   - CAPTCHA: reset via the existing widget reset path so a fresh
	 *     challenge is required for the next submission.
	 *
	 * @param {HTMLFormElement} form
	 */
	function resetFormState( form ) {
		form.reset();
		clearErrors( form );
		resetPollResults( form );
		resetQuizResults( form );
		clearDynamicResults( form );
		resetMultiStep( form );

		var submit = form.querySelector( '.neural-forms-form__submit' );
		if ( submit ) {
			submit.disabled = false;
			form.classList.remove( 'neural-forms-form--submitting' );
			var original = submit.getAttribute( 'data-original-html' );
			if ( original !== null ) {
				submit.innerHTML = original;
			}
		}

		if ( typeof resetCaptchaWidget === 'function' ) {
			try { resetCaptchaWidget( form ); } catch ( e ) { /* noop */ }
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
				// Stash the original HTML so we can restore icon markup later.
				// data-original-html is preferred over the legacy data-original-text
				// because the button may contain an icon <span> that textContent
				// would discard on restore.
				if ( ! btn.hasAttribute( 'data-original-html' ) ) {
					btn.setAttribute( 'data-original-html', btn.innerHTML );
				}
				btn.innerHTML = '<span class="neural-forms-form__spinner" aria-hidden="true"></span> Submitting\u2026';
			}
		} else {
			form.classList.remove( 'neural-forms-form--submitting' );
			if ( btn ) {
				btn.disabled = false;
				var original = btn.getAttribute( 'data-original-html' );
				if ( original ) {
					btn.innerHTML = original;
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
						// Centralised reset:
						//   - clears values, errors, global error, captcha
						//   - resets multi-step to step 0
						//   - clears poll-results state per visibility
						resetFormState( form );
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

	/**
	 * Poll Phase 3: render the poll-results bars for any poll fields
	 * that came back attached to the submit response.
	 *
	 * Honours the contract emitted by SubmitEndpoint and FormRenderer:
	 *
	 *   polls = {
	 *     "<field_id>": {
	 *       total_votes: int,
	 *       options: [ { label, value, votes, percentage }, ... ],
	 *       chart_type: "bar" | "percentage",
	 *       results_visibility: "after_vote" | "always"
	 *     },
	 *     ...
	 *   }
	 *
	 * The placeholder div is `[data-poll-results="<field_id>"]`. Phase 1
	 * emits it `hidden` for after_vote; Phase 3 unhides it after writing
	 * the bars in. For "always" visibility the renderer pre-rendered the
	 * markup at SSR time — this method overwrites it with the freshest
	 * totals so a vote refreshes the public view immediately.
	 *
	 * No-op when polls is missing/empty or when no matching placeholder
	 * exists in the DOM (e.g. the form was rendered before this feature
	 * shipped, or all polls have visibility="never").
	 *
	 * @param {HTMLFormElement} form
	 * @param {object}          polls
	 */
	function renderPollResults( form, polls ) {
		if ( form.getAttribute( 'data-show-poll-results' ) === '0' ) return;
		Object.keys( polls ).forEach( function ( fieldId ) {
			var poll = polls[ fieldId ];
			if ( ! poll || typeof poll !== 'object' ) return;

			var sel = '[data-poll-results="' + cssEscape( fieldId ) + '"]';
			var container = form.querySelector( sel );
			if ( ! container ) return;

			var total   = ( typeof poll.total_votes === 'number' ) ? poll.total_votes : 0;
			var options = Array.isArray( poll.options ) ? poll.options : [];
			var chart   = ( poll.chart_type === 'percentage' ) ? 'percentage' : 'bar';

			var html = ''
				+ '<p class="neural-forms-form__poll-total">'
				+ pollMessage( 'Total votes: ' ) + total
				+ '</p>'
				+ '<ul class="neural-forms-form__poll-bars" data-poll-chart="' + chart + '">';

			options.forEach( function ( opt ) {
				if ( ! opt || typeof opt !== 'object' ) return;
				var label = String( opt.label != null ? opt.label : '' );
				var votes = Number( opt.votes ) || 0;
				var pct   = Number( opt.percentage );
				if ( ! isFinite( pct ) || pct < 0 ) pct = 0;
				if ( pct > 100 ) pct = 100;

				html += '<li class="neural-forms-form__poll-bar">'
					+ '<div class="neural-forms-form__poll-bar-head">'
					+ '<span class="neural-forms-form__poll-bar-label">' + escapeHtml( label ) + '</span>'
					+ '<span class="neural-forms-form__poll-bar-count">' + votes + ' (' + pct + '%)</span>'
					+ '</div>';
				if ( chart !== 'percentage' ) {
					html += '<div class="neural-forms-form__poll-bar-track" aria-hidden="true">'
						+ '<div class="neural-forms-form__poll-bar-fill" style="width:' + pct + '%"></div>'
						+ '</div>';
				}
				html += '</li>';
			} );

			html += '</ul>';

			container.innerHTML = html;
			container.hidden    = false;
		} );
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	// Minimal CSS.escape() polyfill for the [data-poll-results=…] selector.
	// Field ids are validated server-side to /^[a-z0-9_]+$/ so this only
	// has to handle plain ASCII without breaking on older browsers.
	function cssEscape( s ) {
		if ( window.CSS && typeof window.CSS.escape === 'function' ) {
			return window.CSS.escape( s );
		}
		return String( s ).replace( /([^a-zA-Z0-9_-])/g, '\\$1' );
	}

	// Translation seam. The poll markup is intentionally tiny - one
	// "Total votes: " label - so we don't pull in @wordpress/i18n on the
	// frontend bundle. Sites that need a localised label can filter the
	// SSR copy via the renderer; the JS path mirrors the English text
	// to stay consistent with the post-vote refresh.
	function pollMessage( s ) { return s; }

	/**
	 * Quiz Phase 1: render the score summary + per-question feedback
	 * from the submit response. The placeholder div per question is
	 * `[data-quiz-feedback="<field_id>"]`; the form-level summary is
	 * a single `[data-quiz-summary]` near the submit button.
	 *
	 * Privacy contract: the server omits correct_value/correct_label/
	 * explanation when show_correct_answer === 'never'. We honour
	 * that here too — never invent them, never read schema-side data.
	 *
	 * @param {HTMLFormElement} form
	 * @param {object} quiz   { score, max_score, percentage, questions: [...] }
	 */
	function renderQuizResults( form, quiz ) {
		if ( ! quiz || typeof quiz !== 'object' ) return;
		var summaryCheck = form.querySelector( '[data-quiz-summary]' );
		if ( summaryCheck && summaryCheck.dataset.showResults === '0' ) return;

		var score    = Number( quiz.score )      || 0;
		var maxScore = Number( quiz.max_score )  || 0;
		var pct      = Number( quiz.percentage );
		if ( ! isFinite( pct ) || pct < 0 ) pct = 0;
		if ( pct > 100 ) pct = 100;

		// Form-level summary card.
		var summary = form.querySelector( '[data-quiz-summary]' );
		if ( summary ) {
			var summaryHtml = ''
				+ '<p class="neural-forms-form__quiz-summary-line">'
				+ escapeHtml( pollMessage( 'Your score: ' ) )
				+ '<strong>' + score + ' / ' + maxScore + '</strong>'
				+ ' (' + pct + '%)'
				+ '</p>';

			// Phase 3: pass/fail badge — only when show_pass_fail is on
			// (server includes `passed` only in that case).
			if ( typeof quiz.passed === 'boolean' ) {
				var passClass = quiz.passed
					? 'neural-forms-form__quiz-passfail neural-forms-form__quiz-passfail--passed'
					: 'neural-forms-form__quiz-passfail neural-forms-form__quiz-passfail--failed';
				var passLabel = quiz.passed ? pollMessage( 'Passed' ) : pollMessage( 'Failed' );
				var passingScore = Number( quiz.passing_score ) || 0;
				summaryHtml += '<p class="' + passClass + '">'
					+ '<span class="neural-forms-form__quiz-passfail-badge">' + escapeHtml( passLabel ) + '</span>'
					+ '<span class="neural-forms-form__quiz-passfail-meta">'
					+ escapeHtml( pollMessage( 'Passing score: ' ) ) + passingScore + '%'
					+ '</span>'
					+ '</p>';
			}

			// Phase 3: result message — independent of show_pass_fail.
			if ( quiz.result_message && typeof quiz.result_message === 'object' ) {
				var rmTitle   = String( quiz.result_message.title   || '' );
				var rmMessage = String( quiz.result_message.message || '' );
				if ( rmTitle || rmMessage ) {
					summaryHtml += '<div class="neural-forms-form__quiz-result-message">';
					if ( rmTitle )   summaryHtml += '<p class="neural-forms-form__quiz-result-title">' + escapeHtml( rmTitle ) + '</p>';
					if ( rmMessage ) summaryHtml += '<p class="neural-forms-form__quiz-result-body">'  + escapeHtml( rmMessage ) + '</p>';
					summaryHtml += '</div>';
				}
			}

			summary.innerHTML = summaryHtml;
			summary.hidden    = false;
		}

		// Per-question feedback rows.
		var questions = Array.isArray( quiz.questions ) ? quiz.questions : [];
		questions.forEach( function ( q ) {
			if ( ! q || typeof q !== 'object' ) return;
			var fieldId = String( q.field_id || '' );
			if ( ! fieldId ) return;

			var sel = '[data-quiz-feedback="' + cssEscape( fieldId ) + '"]';
			var container = form.querySelector( sel );
			if ( ! container ) return;

			var verdictClass = q.correct
				? 'neural-forms-form__quiz-verdict neural-forms-form__quiz-verdict--correct'
				: 'neural-forms-form__quiz-verdict neural-forms-form__quiz-verdict--incorrect';
			var verdictText = q.correct
				? pollMessage( 'Correct' )
				: pollMessage( 'Incorrect' );

			var html = '<div class="' + verdictClass + '">'
				+ '<span class="neural-forms-form__quiz-verdict-icon" aria-hidden="true">'
				+ ( q.correct ? '✓' : '✗' )
				+ '</span>'
				+ '<span class="neural-forms-form__quiz-verdict-text">' + escapeHtml( verdictText ) + '</span>'
				+ '</div>';

			// Correct-answer reveal + explanation are emitted ONLY when
			// the server flagged show_correct_answer = true on this
			// question. Guard against a malformed/old response by
			// double-checking the field's existence too.
			if ( q.show_correct_answer && q.correct_label ) {
				html += '<p class="neural-forms-form__quiz-correct">'
					+ escapeHtml( pollMessage( 'Correct answer: ' ) )
					+ '<strong>' + escapeHtml( String( q.correct_label ) ) + '</strong>'
					+ '</p>';
			}
			if ( q.show_correct_answer && q.explanation ) {
				html += '<p class="neural-forms-form__quiz-explanation">'
					+ escapeHtml( String( q.explanation ) )
					+ '</p>';
			}

			container.innerHTML = html;
			container.hidden    = false;
		} );
	}

	/**
	 * Populate the success-state results container.
	 *
	 * The .neural-forms-form-wrap > .neural-forms-form__success block
	 * is the success surface the user actually SEES post-submit; the
	 * <form> itself is hidden by showSuccess(). Per-field poll/quiz
	 * placeholders live INSIDE the form so they vanish too. This
	 * helper writes a self-contained results card into a sibling div
	 * that lives inside the success block, so poll bars + quiz score
	 * stay visible alongside the success message.
	 *
	 * Builds output from BOTH polls and quiz responses. Empty when
	 * neither is present. No-op when a redirect is configured (the
	 * caller short-circuits via redirectIfConfigured before showing
	 * the success state, so this code never runs in the redirect
	 * path).
	 *
	 * @param {HTMLFormElement} form
	 * @param {object|null}     polls  Map keyed by field_id, or null.
	 * @param {object|null}     quiz   { score, max_score, percentage, questions } or null.
	 */
	function populateDynamicResults( form, polls, quiz ) {
		var wrap = form.parentElement;
		if ( ! wrap ) return;
		var container = wrap.querySelector( '[data-neural-forms-dynamic-results]' );
		if ( ! container ) return;

		var html = '';

		// Quiz first — score is the most prominent post-submit info.
		var quizSummaryEl = form.querySelector( '[data-quiz-summary]' );
		var showQuizResults = ! quizSummaryEl || quizSummaryEl.getAttribute( 'data-show-results' ) !== '0';
		if ( quiz && typeof quiz === 'object' && showQuizResults ) {
			var score    = Number( quiz.score )      || 0;
			var maxScore = Number( quiz.max_score )  || 0;
			var pct      = Number( quiz.percentage );
			if ( ! isFinite( pct ) || pct < 0 ) pct = 0;
			if ( pct > 100 ) pct = 100;

			html += '<div class="neural-forms-form__dynamic-quiz">'
				+ '<div class="neural-forms-form__quiz-summary">'
				+ '<p class="neural-forms-form__quiz-summary-line">'
				+ escapeHtml( pollMessage( 'Your score: ' ) )
				+ '<strong>' + score + ' / ' + maxScore + '</strong>'
				+ ' (' + pct + '%)'
				+ '</p>';

			// Phase 3: pass/fail badge.
			if ( typeof quiz.passed === 'boolean' ) {
				var passClassD = quiz.passed
					? 'neural-forms-form__quiz-passfail neural-forms-form__quiz-passfail--passed'
					: 'neural-forms-form__quiz-passfail neural-forms-form__quiz-passfail--failed';
				var passLabelD   = quiz.passed ? pollMessage( 'Passed' ) : pollMessage( 'Failed' );
				var passingScoreD = Number( quiz.passing_score ) || 0;
				html += '<p class="' + passClassD + '">'
					+ '<span class="neural-forms-form__quiz-passfail-badge">' + escapeHtml( passLabelD ) + '</span>'
					+ '<span class="neural-forms-form__quiz-passfail-meta">'
					+ escapeHtml( pollMessage( 'Passing score: ' ) ) + passingScoreD + '%'
					+ '</span>'
					+ '</p>';
			}

			// Phase 3: result message.
			if ( quiz.result_message && typeof quiz.result_message === 'object' ) {
				var rmT = String( quiz.result_message.title   || '' );
				var rmM = String( quiz.result_message.message || '' );
				if ( rmT || rmM ) {
					html += '<div class="neural-forms-form__quiz-result-message">';
					if ( rmT ) html += '<p class="neural-forms-form__quiz-result-title">' + escapeHtml( rmT ) + '</p>';
					if ( rmM ) html += '<p class="neural-forms-form__quiz-result-body">'  + escapeHtml( rmM ) + '</p>';
					html += '</div>';
				}
			}

			html += '</div>';

			var qs = Array.isArray( quiz.questions ) ? quiz.questions : [];
			if ( qs.length > 0 ) {
				html += '<ul class="neural-forms-form__dynamic-quiz-questions">';
				qs.forEach( function ( q ) {
					if ( ! q || typeof q !== 'object' ) return;

					var verdictClass = q.correct
						? 'neural-forms-form__quiz-verdict neural-forms-form__quiz-verdict--correct'
						: 'neural-forms-form__quiz-verdict neural-forms-form__quiz-verdict--incorrect';
					var verdictText = q.correct ? pollMessage( 'Correct' ) : pollMessage( 'Incorrect' );
					var label = String( q.label || q.field_id || '' );
					var sel   = q.selected_label != null ? String( q.selected_label ) : '';

					html += '<li class="neural-forms-form__dynamic-quiz-question">'
						+ '<p class="neural-forms-form__dynamic-quiz-label">' + escapeHtml( label ) + '</p>'
						+ '<div class="' + verdictClass + '">'
						+ '<span class="neural-forms-form__quiz-verdict-icon" aria-hidden="true">'
						+ ( q.correct ? '✓' : '✗' )
						+ '</span>'
						+ '<span class="neural-forms-form__quiz-verdict-text">' + escapeHtml( verdictText ) + '</span>'
						+ '</div>';

					if ( sel ) {
						html += '<p class="neural-forms-form__quiz-selected">'
							+ escapeHtml( pollMessage( 'Your answer: ' ) )
							+ '<strong>' + escapeHtml( sel ) + '</strong>'
							+ '</p>';
					}
					if ( q.show_correct_answer && q.correct_label ) {
						html += '<p class="neural-forms-form__quiz-correct">'
							+ escapeHtml( pollMessage( 'Correct answer: ' ) )
							+ '<strong>' + escapeHtml( String( q.correct_label ) ) + '</strong>'
							+ '</p>';
					}
					if ( q.show_correct_answer && q.explanation ) {
						html += '<p class="neural-forms-form__quiz-explanation">'
							+ escapeHtml( String( q.explanation ) )
							+ '</p>';
					}
					html += '</li>';
				} );
				html += '</ul>';
			}

			html += '</div>';
		}

		// Then poll bars. Each visible/after_vote poll renders a card.
		var showPollResults = form.getAttribute( 'data-show-poll-results' ) !== '0';
		if ( polls && typeof polls === 'object' && showPollResults ) {
			Object.keys( polls ).forEach( function ( fieldId ) {
				var poll = polls[ fieldId ];
				if ( ! poll || typeof poll !== 'object' ) return;
				var total   = ( typeof poll.total_votes === 'number' ) ? poll.total_votes : 0;
				var options = Array.isArray( poll.options ) ? poll.options : [];
				var chart   = ( poll.chart_type === 'percentage' ) ? 'percentage' : 'bar';

				html += '<div class="neural-forms-form__dynamic-poll" data-poll-results-dynamic="' + escapeHtml( String( fieldId ) ) + '">'
					+ '<p class="neural-forms-form__poll-total">'
					+ escapeHtml( pollMessage( 'Total votes: ' ) ) + total
					+ '</p>'
					+ '<ul class="neural-forms-form__poll-bars" data-poll-chart="' + chart + '">';

				options.forEach( function ( opt ) {
					if ( ! opt || typeof opt !== 'object' ) return;
					var optLabel = String( opt.label != null ? opt.label : '' );
					var votes    = Number( opt.votes ) || 0;
					var optPct   = Number( opt.percentage );
					if ( ! isFinite( optPct ) || optPct < 0 ) optPct = 0;
					if ( optPct > 100 ) optPct = 100;

					html += '<li class="neural-forms-form__poll-bar">'
						+ '<div class="neural-forms-form__poll-bar-head">'
						+ '<span class="neural-forms-form__poll-bar-label">' + escapeHtml( optLabel ) + '</span>'
						+ '<span class="neural-forms-form__poll-bar-count">' + votes + ' (' + optPct + '%)</span>'
						+ '</div>';
					if ( chart !== 'percentage' ) {
						html += '<div class="neural-forms-form__poll-bar-track" aria-hidden="true">'
							+ '<div class="neural-forms-form__poll-bar-fill" style="width:' + optPct + '%"></div>'
							+ '</div>';
					}
					html += '</li>';
				} );

				html += '</ul></div>';
			} );
		}

		if ( html === '' ) {
			container.innerHTML = '';
			container.hidden    = true;
			return;
		}

		container.innerHTML = html;
		container.hidden    = false;
	}

	/**
	 * Reset the dynamic-results container to its post-page-load state
	 * on "Submit another response". Wipes innerHTML + re-hides.
	 *
	 * @param {HTMLFormElement} form
	 */
	function clearDynamicResults( form ) {
		var wrap = form.parentElement;
		if ( ! wrap ) return;
		var container = wrap.querySelector( '[data-neural-forms-dynamic-results]' );
		if ( ! container ) return;
		container.innerHTML = '';
		container.hidden    = true;
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

		var prevBtn  = form.querySelector( '[data-step-action="prev"]' );
		var nextBtn  = form.querySelector( '[data-step-action="next"]' );
		var submit   = form.querySelector( '.neural-forms-form__submit' );
		var progress = form.querySelector( '.neural-forms-form__progress' );

		var current = 0;

		// Transition config (read once from data attrs set by BlockHandler).
		var transitionType   = form.getAttribute( 'data-step-transition' )          || 'none';
		var transitionDir    = form.getAttribute( 'data-step-transition-dir' )       || 'auto';
		var transitionDur    = parseInt( form.getAttribute( 'data-step-transition-duration' ) || '350', 10 );
		var transitionEasing = form.getAttribute( 'data-step-transition-easing' )    || 'ease';
		var prefersReduced   = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var useTransition    = transitionType !== 'none' && ! prefersReduced;

		/**
		 * Resolve the CSS enter/exit class suffix based on direction config
		 * and whether the user navigated forward (isForward).
		 *
		 * Direction map per transition type:
		 *   slide/fade: left|right|up|down  (auto → right on forward, left on back)
		 *   scale:      in|out              (auto → in on forward, out on back)
		 *   flip:       horizontal|vertical (auto → horizontal)
		 */
		function resolveDir( isForward ) {
			if ( transitionType === 'scale' ) {
				var d = transitionDir === 'out' ? 'out' : ( transitionDir === 'in' ? 'in' : ( isForward ? 'in' : 'out' ) );
				// scale reuses right/left CSS class names for in/out
				return d === 'in' ? 'right' : 'left';
			}
			if ( transitionType === 'flip' ) {
				return ( transitionDir === 'vertical' ) ? 'up' : 'down';
			}
			// slide / fade
			if ( transitionDir === 'auto' ) return isForward ? 'right' : 'left';
			return transitionDir; // left|right|up|down
		}

		function updateProgress( index ) {
			if ( ! progress ) return;
			var mode = progress.getAttribute( 'data-progress-mode' );

			if ( mode === 'numbers' ) {
				var items = progress.querySelectorAll( '.neural-forms-form__progress-item' );
				for ( var i = 0; i < items.length; i++ ) {
					items[ i ].classList.remove( 'is-active', 'is-complete' );
					if ( i < index )      items[ i ].classList.add( 'is-complete' );
					else if ( i === index ) items[ i ].classList.add( 'is-active' );
				}
				return;
			}

			if ( mode === 'bar' ) {
				var fill = progress.querySelector( '.neural-forms-form__progress-bar-fill' );
				var text = progress.querySelector( '.neural-forms-form__progress-text' );
				var track = progress.querySelector( '.neural-forms-form__progress-bar-track' );
				var pct  = steps.length > 0 ? Math.round( ( ( index + 1 ) / steps.length ) * 100 ) : 0;
				if ( fill ) fill.style.width = pct + '%';
				if ( text ) text.textContent = 'Step ' + ( index + 1 ) + ' of ' + steps.length;
				if ( track ) track.setAttribute( 'aria-valuenow', String( index + 1 ) );
			}
		}

		// isForward: true = Next, false = Prev. Undefined on initial render.
		// fromIndex: the step we're leaving (must be passed explicitly because
		//            `current` is already updated before show() is called).
		// suppressScroll: true = skip the post-render scrollIntoView. Set
		//                 only by the page-load initial render so a page
		//                 with multi-step forms doesn't auto-scroll the
		//                 viewport down to the first one. Next/Prev clicks
		//                 and the "Submit another response" reset still
		//                 scroll because that's the desired UX there.
		function show( index, isForward, fromIndex, suppressScroll ) {
			if ( useTransition && isForward !== undefined && fromIndex !== undefined && fromIndex >= 0 ) {
				animateTransition( fromIndex, index, isForward );
			} else {
				// Plain show — no animation (initial render, reset, or no transition).
				for ( var i = 0; i < steps.length; i++ ) {
					if ( i === index ) {
						steps[ i ].classList.add( 'is-active' );
						steps[ i ].style.display = '';
					} else {
						steps[ i ].classList.remove( 'is-active' );
						steps[ i ].style.display = 'none';
					}
				}
				afterShow( index, suppressScroll );
			}
		}

		// The steps-body wrapper is the animation container — it only contains
		// the progress indicator and step divs; nav/submit sit outside it.
		var stepsBody = form.querySelector( '.neural-forms-form__steps-body' );

		var _animating = false;

		function animateTransition( fromIndex, toIndex, isForward ) {
			if ( _animating ) return;
			// No steps-body wrapper means transition is disabled (non-block path).
			if ( ! stepsBody ) { return; }
			_animating = true;

			var outStep = steps[ fromIndex ];
			var inStep  = steps[ toIndex ];
			var dir     = resolveDir( isForward );

			var exitDir = {
				right: 'left',  left: 'right',
				up:    'down',  down: 'up'
			}[ dir ] || dir;

			var enterClass = 'nf-step-enter-from-' + dir;
			var exitClass  = 'nf-step-exit-to-' + exitDir;

			var transCSS = 'transform ' + transitionDur + 'ms ' + transitionEasing
				+ ', opacity ' + transitionDur + 'ms ' + transitionEasing;

			// ── Phase 1: measure BEFORE any DOM changes ───────────────────
			// offsetTop/offsetLeft point to the element's BORDER edge, but
			// position:absolute top/left position the MARGIN edge. Subtract
			// the step's computed margins so the element lands exactly where
			// it was in flow regardless of any margin/padding set by the user.
			var outCS    = window.getComputedStyle( outStep );
			var snapTop  = outStep.offsetTop  - ( parseFloat( outCS.marginTop  ) || 0 );
			var snapLeft = outStep.offsetLeft - ( parseFloat( outCS.marginLeft ) || 0 );
			var snapW    = outStep.offsetWidth;
			var bodyH    = stepsBody.offsetHeight;

			// ── Phase 2: apply all changes in one synchronous batch ───────
			// Lock the wrapper height so it doesn't collapse when steps leave flow.
			stepsBody.style.height = bodyH + 'px';

			// Prepare inStep (no transition — just position it off-screen).
			inStep.classList.add( 'is-active' );
			inStep.style.display    = '';
			inStep.style.transition = 'none';
			inStep.style.position   = 'absolute';
			inStep.style.top        = snapTop  + 'px';
			inStep.style.left       = snapLeft + 'px';
			inStep.style.width      = snapW    + 'px';
			inStep.style.zIndex     = '1';
			inStep.classList.add( enterClass );

			// Take outStep out of flow at its exact measured position.
			outStep.style.transition = 'none';
			outStep.style.position   = 'absolute';
			outStep.style.top        = snapTop  + 'px';
			outStep.style.left       = snapLeft + 'px';
			outStep.style.width      = snapW    + 'px';
			outStep.style.zIndex     = '0';

			// ── Phase 3: reflow → rAF → start transitions ─────────────────
			/* jshint ignore:start */
			void outStep.offsetWidth;
			/* jshint ignore:end */

			requestAnimationFrame( function () {
				inStep.style.transition  = transCSS;
				outStep.style.transition = transCSS;
				inStep.classList.remove( enterClass );
				outStep.classList.add( exitClass );
			} );

			function onDone() {
				outStep.removeEventListener( 'transitionend', onDone );

				// Reset exiting step.
				outStep.classList.remove( 'is-active', exitClass );
				outStep.style.cssText = '';
				outStep.style.display = 'none';

				// Reset entering step — clear all animation inline styles.
				inStep.classList.remove( enterClass );
				inStep.style.cssText = '';

				// Release locked wrapper height.
				stepsBody.style.height = '';

				_animating = false;
				afterShow( toIndex );
			}

			outStep.addEventListener( 'transitionend', onDone );

			// Fallback in case transitionend doesn't fire.
			setTimeout( function () {
				if ( _animating ) onDone();
			}, transitionDur + 100 );
		}

		function afterShow( index, suppressScroll ) {
			updateNavLabels( steps[ index ] );

			if ( prevBtn ) prevBtn.style.display = ( index === 0 ) ? 'none' : '';
			if ( nextBtn ) nextBtn.style.display = ( index === steps.length - 1 ) ? 'none' : '';
			if ( submit )  submit.style.display  = ( index === steps.length - 1 ) ? '' : 'none';

			updateProgress( index );

			if ( typeof updateConditionalFields === 'function' ) {
				updateConditionalFields( form );
			}

			// Skip the scrollIntoView when this is the page-load initial
			// render. Without the guard, every multi-step form on a page
			// runs scrollIntoView({behavior:'smooth'}) at load time and
			// the last one's smooth scroll wins, jerking the viewport
			// down to that form for no reason. Next/Prev navigation and
			// the "Submit another response" reset still pass through
			// here without suppressScroll, so user-initiated transitions
			// keep their natural scroll-to-the-new-step behaviour.
			if ( suppressScroll ) return;

			var heading = steps[ index ].querySelector( '.neural-forms-form__step-title' );
			var target  = heading || steps[ index ];
			if ( target && typeof target.scrollIntoView === 'function' ) {
				try { target.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); } catch ( e ) { /* noop */ }
			}
		}

		function updateNavLabels( step ) {
			if ( ! step ) return;
			var nav      = form.querySelector( '[data-neural-forms-steps-nav]' );
			var defNext  = nav ? ( nav.getAttribute( 'data-nav-default-next' ) || '' ) : '';
			var defPrev  = nav ? ( nav.getAttribute( 'data-nav-default-prev' ) || '' ) : '';
			var ovNext   = nav && nav.hasAttribute( 'data-nav-override-next' );
			var ovPrev   = nav && nav.hasAttribute( 'data-nav-override-prev' );
			// Block-level override always wins; otherwise per-step label
			// wins with the resolved default (block or form) as fallback.
			var nextLabel = ovNext ? defNext : ( step.getAttribute( 'data-step-button-next' ) || defNext );
			var prevLabel = ovPrev ? defPrev : ( step.getAttribute( 'data-step-button-previous' ) || defPrev );
			// When an icon is present FormRenderer wraps the text in .nf-btn-label
			// so we can update it without clobbering the icon <span>.
			if ( nextBtn && nextLabel ) {
				var nextLbl = nextBtn.querySelector( '.nf-btn-label' );
				if ( nextLbl ) { nextLbl.textContent = nextLabel; } else { nextBtn.textContent = nextLabel; }
			}
			if ( prevBtn && prevLabel ) {
				var prevLbl = prevBtn.querySelector( '.nf-btn-label' );
				if ( prevLbl ) { prevLbl.textContent = prevLabel; } else { prevBtn.textContent = prevLabel; }
			}
		}

		/**
		 * Validate the visible fields inside the current step before
		 * letting the visitor advance. Delegates to validateFieldsIn()
		 * so the rules (required + type for email/tel/url/number/date)
		 * match the full pre-submit pass exactly — no UX gap where a
		 * field passes step nav but trips on final submit.
		 *
		 * Clears prior errors inside this step first so re-validating
		 * an already-corrected step doesn't leave stale red borders.
		 */
		function validateStep( step ) {
			// Local error reset scoped to this step (don't clear errors
			// in subsequent steps the visitor hasn't reached yet).
			var stepErrors = step.querySelectorAll( '.neural-forms-form__field-error' );
			for ( var i = 0; i < stepErrors.length; i++ ) {
				stepErrors[ i ].textContent = '';
				stepErrors[ i ].hidden      = true;
			}
			var stepInvalidWrappers = step.querySelectorAll( '.neural-forms-form__field--has-error' );
			for ( var j = 0; j < stepInvalidWrappers.length; j++ ) {
				stepInvalidWrappers[ j ].classList.remove( 'neural-forms-form__field--has-error' );
				var invInput = stepInvalidWrappers[ j ].querySelector( '[aria-invalid]' );
				if ( invInput ) invInput.removeAttribute( 'aria-invalid' );
			}

			var firstError = validateFieldsIn( step );
			if ( firstError ) {
				var focusTarget = firstError.querySelector( 'input, select, textarea' );
				if ( focusTarget && typeof focusTarget.focus === 'function' ) {
					try { focusTarget.focus(); } catch ( e ) { /* noop */ }
				}
				return false;
			}
			return true;
		}

		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( _animating ) return;
				if ( ! validateStep( steps[ current ] ) ) return;
				if ( current < steps.length - 1 ) {
					var from = current;
					current += 1;
					show( current, true, from );
				}
			} );
		}

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( _animating ) return;
				if ( current > 0 ) {
					var from = current;
					current -= 1;
					show( current, false, from );
				}
			} );
		}

		// Initial render: only step 0 visible, submit hidden, prev hidden.
		// 4th arg true = suppress the auto-scrollIntoView so a page with
		// one or more multi-step forms doesn't yank the viewport down
		// to the first form on page load. See show()/afterShow() for the
		// scroll-suppression rationale.
		show( 0, undefined, undefined, true );

		// Expose a reset hook so resetFormState() can return this form
		// to step 0 after "Submit another response". Closure-bound so
		// `current` is the same variable the click handlers mutate; no
		// drift between visible state and internal index.
		form._nfMultiStepReset = function () {
			current = 0;
			show( 0 );
		};
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
