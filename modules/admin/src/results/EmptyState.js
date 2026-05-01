/**
 * Reusable empty / loading / error placeholder for the Results screen.
 *
 * tone: 'neutral' | 'error'
 */

export default function EmptyState( { title, subtitle, tone = 'neutral' } ) {
	const cls = 'nf-results__empty' + ( tone === 'error' ? ' nf-results__empty--error' : '' );
	return (
		<div className={ cls } role={ tone === 'error' ? 'alert' : 'status' }>
			<p className="nf-results__empty-title">{ title }</p>
			{ subtitle && <p className="nf-results__empty-subtitle">{ subtitle }</p> }
		</div>
	);
}
