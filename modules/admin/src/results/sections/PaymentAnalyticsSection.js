/**
 * Payments — analytics section for forms that take payments (1.2.0 part B).
 *
 * Reads GET /forms/{id}/analytics/payments (StatsEndpoint): revenue and
 * counts per currency, entries by payment status, a 30-day net-revenue
 * trend and the latest attempts. Pro-gated through the `payments`
 * capability exactly like Page Performance: locked installs see an upsell
 * card, forms without payments see nothing (the section hides itself), and
 * forms with payments switched on but no attempts yet see a short empty
 * state. Fetching lives here so buildSections.js stays shape-only.
 *
 * Reuses the Page Performance card chrome (.nf-pp*) so the two Pro
 * sections look like siblings; only the table / pills / trend are new
 * (.nf-pay*).
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api';

function getCapabilities() {
	const root = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	return ( root.plugin && root.plugin.capabilities ) || {};
}

function adminBase() {
	const u = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.admin?.ajaxUrl ) || '';
	try { return u ? new URL( '../', u ).pathname : '/wp-admin/'; } catch ( e ) { return '/wp-admin/'; }
}

function entryUrl( formId, entryId ) {
	return adminBase() + 'admin.php?page=neural-forms-entries&form_id=' + Number( formId ) + '&view=inbox&entry_id=' + Number( entryId );
}

function paymentsPageUrl( formId ) {
	return adminBase() + 'admin.php?page=neural-forms-payments&form_id=' + Number( formId );
}

function formatDate( s ) {
	if ( ! s ) return '—';
	try {
		const d = new Date( String( s ).replace( ' ', 'T' ) + 'Z' );
		return Number.isNaN( d.getTime() ) ? s : d.toLocaleString();
	} catch ( e ) {
		return s;
	}
}

const STATUS_LABEL = {
	paid:               __( 'Paid',             'neural-forms-lite' ),
	pending:            __( 'Awaiting',         'neural-forms-lite' ),
	processing:         __( 'Processing',       'neural-forms-lite' ),
	failed:             __( 'Failed',           'neural-forms-lite' ),
	expired:            __( 'Cancelled',        'neural-forms-lite' ),
	replaced:           __( 'Replaced',         'neural-forms-lite' ),
	refunded:           __( 'Refunded',         'neural-forms-lite' ),
	partially_refunded: __( 'Partly refunded',  'neural-forms-lite' ),
};

function StatusPill( { status } ) {
	const key = STATUS_LABEL[ status ] ? status : 'pending';
	return <span className={ 'nf-pay__pill nf-pay__pill--' + key }>{ STATUS_LABEL[ key ] }</span>;
}

function Head( { sub, pro } ) {
	return (
		<header className="nf-pp__head">
			<h3 className="nf-pp__title">{ __( 'Payments', 'neural-forms-lite' ) }</h3>
			{ pro && <span className="nf-pp__pro-pill">{ __( 'Pro', 'neural-forms-lite' ) }</span> }
			{ sub && <p className="nf-pp__sub">{ sub }</p> }
		</header>
	);
}

function LockedCard() {
	return (
		<section className="nf-pp nf-pp--locked nf-pay">
			<Head pro />
			<p className="nf-pp__desc">
				{ __( 'Take payments with Stripe or PayPal and see revenue, conversion, refunds and every attempt for this form right here.', 'neural-forms-lite' ) }
			</p>
			<a className="nf-pp__upgrade" href="https://neuralforms.ai/pricing/" target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
			</a>
		</section>
	);
}

function Trend( { points, currency } ) {
	const max = Math.max( 0, ...points.map( ( p ) => Number( p.net ) || 0 ) );
	if ( max <= 0 ) return null;
	return (
		<div className="nf-pay__trend" aria-label={ __( 'Net revenue per day, last 30 days', 'neural-forms-lite' ) }>
			{ points.map( ( p ) => {
				const v = Math.max( 0, Number( p.net ) || 0 );
				const h = v > 0 ? Math.max( 6, Math.round( ( v / max ) * 100 ) ) : 2;
				return (
					<span
						key={ p.date }
						className={ 'nf-pay__trend-bar' + ( v > 0 ? ' is-paid' : '' ) }
						style={ { height: h + '%' } }
						title={ p.date + ' · ' + ( p.count || 0 ) + ' · ' + currency + ' ' + ( v / 100 ).toFixed( 2 ) }
					/>
				);
			} ) }
		</div>
	);
}

export default function PaymentAnalyticsSection( { formId } ) {
	const caps     = useMemo( getCapabilities, [] );
	const unlocked = !! caps.payments;

	const [ data,    setData    ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error,   setError   ] = useState( '' );

	useEffect( () => {
		if ( ! unlocked || ! formId ) return;
		let cancelled = false;
		setLoading( true );
		setError( '' );
		api( `/forms/${ Number( formId ) }/analytics/payments` )
			.then( ( res ) => { if ( cancelled ) return; setData( res ); setLoading( false ); } )
			.catch( ( err ) => { if ( cancelled ) return; setError( err?.message || __( 'Could not load payment analytics.', 'neural-forms-lite' ) ); setLoading( false ); } );
		return () => { cancelled = true; };
	}, [ formId, unlocked ] );

	if ( ! unlocked ) return <LockedCard />;

	if ( loading && ! data ) {
		return <section className="nf-pp nf-pay"><Head /><p className="nf-pp__desc">{ __( 'Loading…', 'neural-forms-lite' ) }</p></section>;
	}
	if ( error ) {
		return <section className="nf-pp nf-pay"><Head /><p className="nf-pp__error">{ error }</p></section>;
	}
	if ( ! data ) return null;

	const totals  = Array.isArray( data.totals ) ? data.totals : [];
	const entries = data.entries || {};
	const recent  = Array.isArray( data.recent ) ? data.recent : [];
	const hasAny  = totals.length > 0 || recent.length > 0;

	// Forms that never took a payment and have payments off: stay out of the way.
	if ( ! data.takes_payments && ! hasAny ) return null;

	if ( ! hasAny ) {
		return (
			<section className="nf-pp nf-pay">
				<Head sub={ __( 'This form takes payments.', 'neural-forms-lite' ) } />
				<p className="nf-pp__desc">{ __( 'No checkout has been started yet. Revenue, conversion and the latest payments will appear here after the first one.', 'neural-forms-lite' ) }</p>
			</section>
		);
	}

	const providers = Object.keys( data.providers || {} );
	const subParts  = [];
	if ( providers.length ) subParts.push( providers.map( ( p ) => p === 'paypal' ? 'PayPal' : ( p === 'stripe' ? 'Stripe' : p ) ).join( ', ' ) );
	subParts.push( sprintf(
		/* translators: 1: paid entries, 2: entries that started a checkout */
		__( '%1$d of %2$d checkouts paid', 'neural-forms-lite' ),
		Number( entries.paid ) || 0,
		Number( entries.started ) || 0
	) );

	return (
		<section className="nf-pp nf-pay">
			<Head sub={ subParts.join( ' · ' ) } />

			{ totals.map( ( t ) => (
				<div key={ t.currency + ( t.livemode ? '-live' : '-test' ) } className="nf-pay__group">
					{ ( totals.length > 1 || ! t.livemode ) && (
						<div className="nf-pay__group-label">
							{ t.currency }
							{ ! t.livemode && <span className="nf-pay__pill nf-pay__pill--test">{ __( 'test mode', 'neural-forms-lite' ) }</span> }
						</div>
					) }
					<div className="nf-pp__cards">
						<div className="nf-pp__card nf-pp__card--primary">
							<div className="nf-pp__card-label">{ __( 'Net revenue', 'neural-forms-lite' ) }</div>
							<div className="nf-pay__value">{ t.formatted.net }</div>
							<div className="nf-pp__card-stat">{ sprintf( __( '%s received', 'neural-forms-lite' ), t.formatted.paid ) }</div>
						</div>
						<div className="nf-pp__card">
							<div className="nf-pp__card-label">{ __( 'Payments', 'neural-forms-lite' ) }</div>
							<div className="nf-pay__value">{ t.paid_count }</div>
							<div className="nf-pp__card-stat">{ sprintf( __( 'Average %s', 'neural-forms-lite' ), t.formatted.average ) }</div>
						</div>
						<div className="nf-pp__card">
							<div className="nf-pp__card-label">{ __( 'Conversion', 'neural-forms-lite' ) }</div>
							<div className="nf-pay__value">{ ( Number( entries.conversion ) || 0 ) + '%' }</div>
							<div className="nf-pp__card-stat">{ sprintf( __( '%d cancelled or failed', 'neural-forms-lite' ), Number( entries.cancelled ) || 0 ) }</div>
						</div>
						<div className="nf-pp__card">
							<div className="nf-pp__card-label">{ __( 'Awaiting payment', 'neural-forms-lite' ) }</div>
							<div className="nf-pay__value">{ t.open_count }</div>
							<div className="nf-pp__card-stat">{ t.formatted.open }</div>
						</div>
						<div className="nf-pp__card">
							<div className="nf-pp__card-label">{ __( 'Refunded', 'neural-forms-lite' ) }</div>
							<div className="nf-pay__value">{ t.formatted.refunded }</div>
							<div className="nf-pp__card-stat">{ sprintf( _n( '%d entry', '%d entries', Number( entries.refunded ) || 0, 'neural-forms-lite' ), Number( entries.refunded ) || 0 ) }</div>
						</div>
					</div>
				</div>
			) ) }

			<Trend points={ Array.isArray( data.trend ) ? data.trend : [] } currency={ data.currency || 'USD' } />

			{ recent.length > 0 && (
				<table className="nf-pay__table">
					<thead>
						<tr>
							<th>{ __( 'When', 'neural-forms-lite' ) }</th>
							<th>{ __( 'Customer', 'neural-forms-lite' ) }</th>
							<th>{ __( 'Amount', 'neural-forms-lite' ) }</th>
							<th>{ __( 'Status', 'neural-forms-lite' ) }</th>
							<th>{ __( 'Entry', 'neural-forms-lite' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ recent.map( ( r ) => (
							<tr key={ r.payment_id }>
								<td>{ formatDate( r.paid_at || r.created_at ) }</td>
								<td>
									{ r.customer_name || r.customer_email || '—' }
									{ r.customer_name && r.customer_email && <small>{ r.customer_email }</small> }
								</td>
								<td>
									{ r.amount_paid_minor > 0 ? r.formatted.paid : r.formatted.due }
									{ r.kind === 'deposit' && <small>{ __( 'deposit', 'neural-forms-lite' ) }</small> }
									{ r.amount_refunded_minor > 0 && <small>{ sprintf( __( '%s refunded', 'neural-forms-lite' ), r.formatted.refunded ) }</small> }
								</td>
								<td><StatusPill status={ r.status } /></td>
								<td><a href={ entryUrl( data.form_id, r.entry_id ) }>#{ r.entry_id }</a></td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<p className="nf-pay__foot">
				<a href={ paymentsPageUrl( data.form_id ) }>{ __( 'Open the Payments page', 'neural-forms-lite' ) }</a>
			</p>
		</section>
	);
}
