/**
 * Reusable empty / loading / error placeholder for the Results screen.
 *
 * Variants:
 *   tone:    'neutral' | 'error'          — colour signal
 *   variant: 'inline'  | 'card'           — 'card' renders the bigger
 *                                            hero-card empty state with
 *                                            optional CTA actions; default
 *                                            'inline' keeps the original
 *                                            slim-text behaviour for
 *                                            mid-page status messages
 *                                            (loading analytics…, error,
 *                                            "select a form", etc.)
 *   actions: [{ label, href, primary? }]  — only honoured by 'card'
 */

export default function EmptyState( { title, subtitle, tone = 'neutral', variant = 'inline', actions } ) {
	const isCard = variant === 'card';

	const cls = [
		'nf-results__empty',
		tone === 'error' ? 'nf-results__empty--error' : '',
		isCard ? 'nf-results__empty--card' : '',
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ cls } role={ tone === 'error' ? 'alert' : 'status' }>
			{ isCard && (
				<div className="nf-results__empty-icon" aria-hidden="true">
					{ /* Brand-tinted chart placeholder. Pure SVG so we
					     don't pull in an icon font. Dark-blue circle +
					     mint accent matches the Neural Forms palette. */ }
					<svg width="48" height="48" viewBox="0 0 48 48" fill="none">
						<circle cx="24" cy="24" r="22" fill="#080f5b" opacity="0.08" />
						<rect x="14" y="24" width="4" height="14" rx="1.5" fill="#080f5b" />
						<rect x="22" y="18" width="4" height="20" rx="1.5" fill="#15db95" />
						<rect x="30" y="14" width="4" height="24" rx="1.5" fill="#080f5b" />
					</svg>
				</div>
			) }
			<p className="nf-results__empty-title">{ title }</p>
			{ subtitle && <p className="nf-results__empty-subtitle">{ subtitle }</p> }
			{ isCard && Array.isArray( actions ) && actions.length > 0 && (
				<div className="nf-results__empty-actions">
					{ actions.map( ( a ) => (
						<a
							key={ a.label }
							href={ a.href }
							className={
								'nf-results__empty-btn'
								+ ( a.primary ? ' nf-results__empty-btn--primary' : '' )
							}
						>
							{ a.label }
						</a>
					) ) }
				</div>
			) }
		</div>
	);
}
