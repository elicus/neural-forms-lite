/**
 * Form settings › Create post / User registration (1.3.0, Pro caps
 * `frontend_posts` / `user_registration`). Engine + validation live in
 * modules/submissions/ (PostSubmission, UserRegistration, Mapping).
 *
 * Boot data: neuralFormsAdmin.plugin.frontendSubmissions =
 *   { postTypes: { slug: { label, taxonomies[{slug,label}] } },
 *     roles: { slug: label }  (only roles without admin / editing rights),
 *     authors: [{ id, name }], currentUser }
 */
import { __ } from '@wordpress/i18n';
import { flattenFields } from './schemaHelper';

function boot() {
	const p = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.plugin ) || {};
	return { caps: p.capabilities || {}, fs: p.frontendSubmissions || { postTypes: {}, roles: {}, authors: [], currentUser: 0 } };
}

function dataFields( fields ) {
	return flattenFields( Array.isArray( fields ) ? fields : [] ).filter( ( n ) => n && n.id && ! [ 'html', 'calculated', 'password' ].includes( n.type ) );
}

const label = ( f ) => ( f.label || f.id ) + ' (' + f.type + ')';

function FieldSelect( { value, onChange, fields, types, empty } ) {
	const list = fields.filter( ( f ) => ! types || types.includes( f.type ) );
	return (
		<select value={ value || '' } onChange={ ( e ) => onChange( e.target.value ) }>
			<option value="">{ empty || __( '— None —', 'neural-forms-lite' ) }</option>
			{ list.map( ( f ) => <option key={ f.id } value={ f.id }>{ label( f ) }</option> ) }
		</select>
	);
}

function Toggle( { checked, onChange, title, hint } ) {
	return (
		<label className="nf-form-settings__field nf-payments-section__toggle">
			<input type="checkbox" checked={ !! checked } onChange={ ( e ) => onChange( e.target.checked ) } />
			<span>
				<strong>{ title }</strong>
				{ hint && <small>{ hint }</small> }
			</span>
		</label>
	);
}

function TokenChips( { fields, onInsert } ) {
	return (
		<div className="nf-fs-tokens">
			<span className="nf-form-settings__hint">{ __( 'Insert a field:', 'neural-forms-lite' ) }</span>
			{ fields.map( ( f ) => (
				<button type="button" key={ f.id } className="nf-fs-tokens__chip" onClick={ () => onInsert( '{' + f.id + '}' ) }>{ f.label || f.id }</button>
			) ) }
		</div>
	);
}

function MetaRows( { rows, onChange, fields, keyPlaceholder } ) {
	const list = Array.isArray( rows ) ? rows : [];
	const set  = ( i, patch ) => onChange( list.map( ( r, j ) => ( j === i ? { ...r, ...patch } : r ) ) );
	return (
		<div className="nf-fs-rows">
			{ list.map( ( r, i ) => (
				<div className="nf-fs-rows__row" key={ i }>
					<input type="text" value={ r.key || '' } placeholder={ keyPlaceholder } onChange={ ( e ) => set( i, { key: e.target.value.toLowerCase().replace( /[^a-z0-9_\-]/g, '' ) } ) } />
					<FieldSelect value={ r.field } onChange={ ( v ) => set( i, { field: v } ) } fields={ fields } empty={ __( '— Field —', 'neural-forms-lite' ) } />
					<button type="button" className="button-link" onClick={ () => onChange( list.filter( ( _, j ) => j !== i ) ) }>{ __( 'Remove', 'neural-forms-lite' ) }</button>
				</div>
			) ) }
			{ list.length < 20 && (
				<button type="button" className="button" onClick={ () => onChange( [ ...list, { key: '', field: '' } ] ) }>{ __( '+ Add custom field', 'neural-forms-lite' ) }</button>
			) }
		</div>
	);
}

export function CreatePostSection( { value, onChange, fields } ) {
	const { caps, fs } = boot();
	if ( ! caps.frontend_posts ) {
		return <div className="nf-form-settings__pro-note">{ __( 'Turn submissions into posts, pages or any public post type: blog posts from guest authors, directory listings, testimonials, events. Available in Neural Forms Pro.', 'neural-forms-lite' ) }</div>;
	}
	const all   = dataFields( fields );
	const first = all.find( ( f ) => f.type === 'text' );
	const v     = { enabled: false, post_type: 'post', status: 'pending', title: '', content: '', excerpt: '', featured_image: '', author: fs.currentUser || 0, terms: [], meta: [], ...( value || {} ) };
	const set   = ( patch ) => onChange( { ...v, ...patch } );
	const types = fs.postTypes || {};
	const taxes = ( types[ v.post_type ] && types[ v.post_type ].taxonomies ) || [];

	const enable = ( on ) => {
		if ( on && ! v.title && ! v.content ) {
			const long = all.filter( ( f ) => f.type === 'textarea' );
			set( { enabled: true, title: first ? '{' + first.id + '}' : '', content: long.map( ( f ) => '{' + f.id + '}' ).join( '\n\n' ) } );
		} else {
			set( { enabled: on } );
		}
	};

	return (
		<div className="nf-fs-section">
			<Toggle checked={ v.enabled } onChange={ enable } title={ __( 'Create a post from each submission', 'neural-forms-lite' ) } hint={ __( 'Runs after the entry is saved. Spam is never posted. Visitor text is inserted as plain text, so it can never add HTML or shortcodes.', 'neural-forms-lite' ) } />
			{ v.enabled && (
				<>
					<div className="nf-fs-grid">
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Post type', 'neural-forms-lite' ) }</span>
							<select value={ v.post_type } onChange={ ( e ) => set( { post_type: e.target.value, terms: [] } ) }>
								{ Object.keys( types ).map( ( slug ) => <option key={ slug } value={ slug }>{ types[ slug ].label }</option> ) }
							</select>
						</label>
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Status', 'neural-forms-lite' ) }</span>
							<select value={ v.status } onChange={ ( e ) => set( { status: e.target.value } ) }>
								<option value="pending">{ __( 'Pending review (recommended)', 'neural-forms-lite' ) }</option>
								<option value="draft">{ __( 'Draft', 'neural-forms-lite' ) }</option>
								<option value="publish">{ __( 'Published straight away', 'neural-forms-lite' ) }</option>
							</select>
						</label>
					</div>
					{ v.status === 'publish' && <p className="nf-form-settings__hint nf-fs-warn">{ __( 'Anyone who can reach the form can publish on your site. Turn on a CAPTCHA under Security, or use Pending review.', 'neural-forms-lite' ) }</p> }

					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Title', 'neural-forms-lite' ) }</span>
						<input type="text" value={ v.title } maxLength={ 300 } onChange={ ( e ) => set( { title: e.target.value } ) } />
					</label>
					<TokenChips fields={ all } onInsert={ ( t ) => set( { title: v.title + t } ) } />

					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Content', 'neural-forms-lite' ) }</span>
						<textarea rows={ 6 } value={ v.content } onChange={ ( e ) => set( { content: e.target.value } ) } />
						<span className="nf-form-settings__hint">{ __( 'You can use basic HTML in this template. Field answers are added as plain text.', 'neural-forms-lite' ) }</span>
					</label>
					<TokenChips fields={ all } onInsert={ ( t ) => set( { content: v.content + t } ) } />

					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Excerpt (optional)', 'neural-forms-lite' ) }</span>
						<input type="text" value={ v.excerpt } maxLength={ 1000 } onChange={ ( e ) => set( { excerpt: e.target.value } ) } />
					</label>

					<div className="nf-fs-grid">
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Featured image (optional)', 'neural-forms-lite' ) }</span>
							<FieldSelect value={ v.featured_image } onChange={ ( id ) => set( { featured_image: id } ) } fields={ all } types={ [ 'file' ] } />
						</label>
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Author for visitors who are not logged in', 'neural-forms-lite' ) }</span>
							<select value={ v.author || '' } onChange={ ( e ) => set( { author: parseInt( e.target.value, 10 ) || 0 } ) }>
								<option value="">{ __( '— Choose —', 'neural-forms-lite' ) }</option>
								{ ( fs.authors || [] ).map( ( a ) => <option key={ a.id } value={ a.id }>{ a.name }</option> ) }
							</select>
							<span className="nf-form-settings__hint">{ __( 'Logged-in visitors (and accounts created by this form) become the author themselves.', 'neural-forms-lite' ) }</span>
						</label>
					</div>

					{ taxes.length > 0 && (
						<div className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Categories and tags from answers (optional)', 'neural-forms-lite' ) }</span>
							{ taxes.map( ( t ) => {
								const row = ( v.terms || [] ).find( ( r ) => r.taxonomy === t.slug );
								return (
									<div className="nf-fs-rows__row" key={ t.slug }>
										<span className="nf-fs-rows__name">{ t.label }</span>
										<FieldSelect
											value={ row ? row.field : '' }
											fields={ all }
											types={ [ 'select', 'radio', 'checkboxes', 'checkbox', 'text', 'hidden' ] }
											onChange={ ( id ) => set( { terms: [ ...( v.terms || [] ).filter( ( r ) => r.taxonomy !== t.slug ), ...( id ? [ { taxonomy: t.slug, field: id } ] : [] ) ] } ) }
										/>
									</div>
								);
							} ) }
							<span className="nf-form-settings__hint">{ __( 'Answers are matched to existing terms by name or slug. New terms are never created.', 'neural-forms-lite' ) }</span>
						</div>
					) }

					<div className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Custom fields (optional)', 'neural-forms-lite' ) }</span>
						<MetaRows rows={ v.meta } onChange={ ( meta ) => set( { meta } ) } fields={ all } keyPlaceholder={ __( 'meta_key', 'neural-forms-lite' ) } />
					</div>
				</>
			) }
		</div>
	);
}

export function RegisterUserSection( { value, onChange, fields } ) {
	const { caps, fs } = boot();
	if ( ! caps.user_registration ) {
		return <div className="nf-form-settings__pro-note">{ __( 'Let visitors create an account on your site with this form: choose the role, map name, username and password fields, and log them in straight away. Available in Neural Forms Pro.', 'neural-forms-lite' ) }</div>;
	}
	const flat  = flattenFields( Array.isArray( fields ) ? fields : [] ).filter( ( n ) => n && n.id );
	const all   = dataFields( fields );
	const roles = fs.roles || {};
	const v     = { enabled: false, role: 'subscriber', email: '', username: '', password: '', first_name: '', last_name: '', website: '', meta: [], auto_login: false, notify_user: true, notify_admin: false, logged_in: 'hide', logged_in_message: '', activation: 'none', activation_subject: '', activation_message: '', activated_url: '', pending_message: '', ...( value || {} ) };
	const set   = ( patch ) => onChange( { ...v, ...patch } );
	const pick  = ( type ) => ( flat.find( ( f ) => f.type === type ) || {} ).id || '';

	const enable = ( on ) => {
		if ( on && ! v.email ) {
			set( { enabled: true, email: pick( 'email' ), password: pick( 'password' ), first_name: pick( 'name' ), role: roles.subscriber ? 'subscriber' : ( Object.keys( roles )[ 0 ] || '' ) } );
		} else {
			set( { enabled: on } );
		}
	};
	const hasEmail = flat.some( ( f ) => f.type === 'email' );

	return (
		<div className="nf-fs-section">
			{ ! hasEmail && <p className="nf-form-settings__hint">{ __( 'Add an Email field first — every account needs an email address.', 'neural-forms-lite' ) }</p> }
			<Toggle checked={ v.enabled } onChange={ enable } title={ __( 'Create a user account from each submission', 'neural-forms-lite' ) } hint={ __( 'A taken email or username is shown as a field error before anything is saved. Visitors who are already logged in are never registered again.', 'neural-forms-lite' ) } />
			{ v.enabled && (
				<>
					<div className="nf-fs-grid">
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Email (required)', 'neural-forms-lite' ) }</span>
							<FieldSelect value={ v.email } onChange={ ( id ) => set( { email: id } ) } fields={ flat } types={ [ 'email' ] } empty={ __( '— Choose —', 'neural-forms-lite' ) } />
						</label>
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Role', 'neural-forms-lite' ) }</span>
							<select value={ v.role } onChange={ ( e ) => set( { role: e.target.value } ) }>
								{ Object.keys( roles ).map( ( slug ) => <option key={ slug } value={ slug }>{ roles[ slug ] }</option> ) }
							</select>
							<span className="nf-form-settings__hint">{ __( 'Roles with admin or editing rights are never offered.', 'neural-forms-lite' ) }</span>
						</label>
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Username', 'neural-forms-lite' ) }</span>
							<FieldSelect value={ v.username } onChange={ ( id ) => set( { username: id } ) } fields={ flat } types={ [ 'text' ] } empty={ __( 'Make one from the email', 'neural-forms-lite' ) } />
						</label>
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Password', 'neural-forms-lite' ) }</span>
							<FieldSelect value={ v.password } onChange={ ( id ) => set( { password: id } ) } fields={ flat } types={ [ 'password' ] } empty={ __( 'Email a "set your password" link', 'neural-forms-lite' ) } />
						</label>
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Name or first name', 'neural-forms-lite' ) }</span>
							<FieldSelect value={ v.first_name } onChange={ ( id ) => set( { first_name: id } ) } fields={ flat } types={ [ 'name', 'text' ] } />
						</label>
						{ ( flat.find( ( f ) => f.id === v.first_name ) || {} ).type !== 'name' && (
							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Last name', 'neural-forms-lite' ) }</span>
								<FieldSelect value={ v.last_name } onChange={ ( id ) => set( { last_name: id } ) } fields={ flat } types={ [ 'text' ] } />
							</label>
						) }
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Website', 'neural-forms-lite' ) }</span>
							<FieldSelect value={ v.website } onChange={ ( id ) => set( { website: id } ) } fields={ flat } types={ [ 'url', 'text' ] } />
						</label>
					</div>
					{ ! v.password && <p className="nf-form-settings__hint">{ __( 'Without a Password field, the new user always gets an email with a link to set their password.', 'neural-forms-lite' ) }</p> }

					<div className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Extra profile fields (optional)', 'neural-forms-lite' ) }</span>
						<MetaRows rows={ v.meta } onChange={ ( meta ) => set( { meta } ) } fields={ all } keyPlaceholder={ __( 'user_meta_key', 'neural-forms-lite' ) } />
					</div>

					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'New accounts must be', 'neural-forms-lite' ) }</span>
						<select value={ v.activation } onChange={ ( e ) => set( { activation: e.target.value } ) }>
							<option value="none">{ __( 'Active straight away', 'neural-forms-lite' ) }</option>
							<option value="email">{ __( 'Confirmed by email (recommended)', 'neural-forms-lite' ) }</option>
							<option value="admin">{ __( 'Approved by an admin', 'neural-forms-lite' ) }</option>
						</select>
						<span className="nf-form-settings__hint">
							{ v.activation === 'email' && __( 'The account has no role and cannot log in until the person clicks the link we email them (valid for 48 hours). Logging in before that sends a fresh link.', 'neural-forms-lite' ) }
							{ v.activation === 'admin' && __( 'The account has no role and cannot log in until you approve it under Users › Pending confirmation. You get an email for each new account.', 'neural-forms-lite' ) }
							{ v.activation === 'none' && __( 'The account works immediately; the email address is not checked.', 'neural-forms-lite' ) }
						</span>
					</label>
					{ v.activation !== 'none' && (
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Message after submitting (optional)', 'neural-forms-lite' ) }</span>
							<input type="text" value={ v.pending_message } maxLength={ 300 } placeholder={ v.activation === 'admin' ? __( 'Thanks! Your account will be active as soon as it has been approved. We will email you.', 'neural-forms-lite' ) : __( 'Thanks! Please check your email and click the link to activate your account.', 'neural-forms-lite' ) } onChange={ ( e ) => set( { pending_message: e.target.value } ) } />
						</label>
					) }
					{ v.activation === 'email' && (
						<>
							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Confirmation email subject', 'neural-forms-lite' ) }</span>
								<input type="text" value={ v.activation_subject } maxLength={ 200 } placeholder={ __( 'Confirm your account at {site_name}', 'neural-forms-lite' ) } onChange={ ( e ) => set( { activation_subject: e.target.value } ) } />
							</label>
							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Confirmation email text', 'neural-forms-lite' ) }</span>
								<textarea rows={ 6 } value={ v.activation_message } placeholder={ __( 'Hi {first_name}, … {activation_link}', 'neural-forms-lite' ) } onChange={ ( e ) => set( { activation_message: e.target.value } ) } />
								<span className="nf-form-settings__hint">{ __( 'Tags: {activation_link} {first_name} {username} {site_name}. Leave empty for the default text. The link is added at the end if you leave it out.', 'neural-forms-lite' ) }</span>
							</label>
						</>
					) }
					{ v.activation !== 'none' && (
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Page to open after activation (optional)', 'neural-forms-lite' ) }</span>
							<input type="url" value={ v.activated_url } placeholder="https://" onChange={ ( e ) => set( { activated_url: e.target.value } ) } />
							<span className="nf-form-settings__hint">{ __( 'Leave empty to show the login page with "Your account is active".', 'neural-forms-lite' ) }</span>
						</label>
					) }

					<Toggle checked={ v.auto_login } onChange={ ( on ) => set( { auto_login: on } ) } title={ v.activation === 'email' ? __( 'Log the user in when they click the activation link', 'neural-forms-lite' ) : __( 'Log the new user in straight away', 'neural-forms-lite' ) } hint={ v.activation === 'admin' ? __( 'Not used with admin approval: people log in themselves once approved.', 'neural-forms-lite' ) : ( v.activation === 'none' ? __( 'The email address is not verified first. Use "On success" to send them to a members page.', 'neural-forms-lite' ) : '' ) } />
					<Toggle checked={ v.notify_user } onChange={ ( on ) => set( { notify_user: on } ) } title={ __( 'Email the new user their login details', 'neural-forms-lite' ) } />
					<Toggle checked={ v.notify_admin } onChange={ ( on ) => set( { notify_admin: on } ) } title={ __( 'Email the site admin about each new account', 'neural-forms-lite' ) } hint={ __( 'WordPress\'s own "New user registration" email. The normal submission notification still goes out.', 'neural-forms-lite' ) } />

					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'When someone who is logged in opens the form', 'neural-forms-lite' ) }</span>
						<select value={ v.logged_in } onChange={ ( e ) => set( { logged_in: e.target.value } ) }>
							<option value="hide">{ __( 'Show a message instead of the form', 'neural-forms-lite' ) }</option>
							<option value="show">{ __( 'Show the form (no account is created)', 'neural-forms-lite' ) }</option>
						</select>
					</label>
					{ v.logged_in === 'hide' && (
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Message', 'neural-forms-lite' ) }</span>
							<input type="text" value={ v.logged_in_message } maxLength={ 300 } placeholder={ __( 'You are already registered and logged in.', 'neural-forms-lite' ) } onChange={ ( e ) => set( { logged_in_message: e.target.value } ) } />
						</label>
					) }
					<p className="nf-form-settings__hint">{ __( 'Tip: turn on a CAPTCHA under Security so bots cannot create accounts.', 'neural-forms-lite' ) }</p>
				</>
			) }
		</div>
	);
}
