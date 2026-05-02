/**
 * Layout row in the form builder.
 *
 * Header layout (left → middle → right):
 *
 *   [drag] ROW [N COLUMN]   [1][2][3][4]   GAP [None][Small][Medium][Large] [↑][↓][⎘][×]
 *   ^------- left ------^   ^-- middle --^   ^------------------ right ----------------^
 *
 * The column-count segmented control sits in the middle. Gap moves to
 * the right side, immediately before the row-action icons, so the
 * toolbar reads as: "what is this row" / "how many columns" / "how
 * does it look + manage". Right-side cluster is grouped with
 * margin-left:auto and wraps cleanly on narrow viewports.
 *
 * Rows themselves are sortable at the top level so the admin can
 * reorder rows up and down.
 */

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { __, sprintf } from '@wordpress/i18n';
import Column from './Column';
import { clampColumnCount, MIN_COLUMNS, MAX_COLUMNS, VALID_GAPS } from './schemaHelper';

const COLUMN_OPTIONS = Array.from(
	{ length: MAX_COLUMNS - MIN_COLUMNS + 1 },
	( _, i ) => MIN_COLUMNS + i
);

const GAP_OPTIONS = [
	{ value: 'none',   label: 'None'   },
	{ value: 'small',  label: 'Small'  },
	{ value: 'medium', label: 'Medium' },
	{ value: 'large',  label: 'Large'  },
];

export default function RowBlock( {
	row,
	onChange,
	onDuplicate,
	onDelete,
	onMoveUp,
	onMoveDown,
	canMoveUp,
	canMoveDown,
	registerColumnDropZone,
	renderField,
	isSelected,
	onSelect,
	selectedColumnId,
	onSelectColumn,
} ) {
	const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable( {
		id: row.id,
		data: { kind: 'row', type: 'row' },
	} );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
	};

	const colCount = clampColumnCount( row.settings?.columns ?? row.columns?.length ?? 1 );
	const gap      = VALID_GAPS.includes( row.settings?.gap ) ? row.settings.gap : 'medium';

	const handleColCount = ( nextCount ) => {
		// Defer the actual resize to the parent so the global field
		// index (used for dnd-kit's flat sortable list) stays in sync.
		// resizeRow's "merge tail fields into last surviving column" rule
		// is what guarantees no field is lost when shrinking 4 → 2.
		onChange( { type: 'resize', nextCount } );
	};

	const handleGap = ( nextGap ) => {
		onChange( {
			type: 'patch',
			patch: { settings: { ...( row.settings || {} ), gap: nextGap } },
		} );
	};

	const selectClick = ( e ) => {
		if ( typeof onSelect !== 'function' ) return;
		const tag = e.target?.tagName;
		if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'OPTION' ) {
			return;
		}
		e.stopPropagation();
		onSelect();
	};

	const colCountLabel = sprintf(
		/* translators: %d: column count */
		__( '%d column', 'neural-forms-lite' ),
		colCount
	);
	const colCountLabelPlural = sprintf(
		/* translators: %d: column count */
		__( '%d columns', 'neural-forms-lite' ),
		colCount
	);

	return (
		<div
			ref={ setNodeRef }
			style={ style }
			className={
				'nf-row' +
				( isDragging ? ' is-dragging' : '' ) +
				( isSelected ? ' is-selected' : '' )
			}
			data-nf-row-id={ row.id }
			onClick={ selectClick }
		>
			<header className="nf-row__header">
				<div className="nf-row__group nf-row__group--left">
					<button
						type="button"
						ref={ setActivatorNodeRef }
						className="nf-drag-zone nf-drag-zone--row"
						aria-label={ __( 'Drag row', 'neural-forms-lite' ) }
						title={ __( 'Drag to move', 'neural-forms-lite' ) }
						{ ...attributes }
						{ ...listeners }
					>
						<span className="nf-drag-zone__icon" aria-hidden="true">⋮⋮</span>
					</button>
					<span className="nf-row__title">{ __( 'Row', 'neural-forms-lite' ) }</span>
					<span className="nf-row__pill">{ colCount === 1 ? colCountLabel : colCountLabelPlural }</span>
				</div>

				<div className="nf-row__group nf-row__group--middle">
					<div className="nf-row__seg" role="group" aria-label={ __( 'Column count', 'neural-forms-lite' ) }>
						{ COLUMN_OPTIONS.map( ( n ) => (
							<button
								key={ n }
								type="button"
								className={ 'nf-row__seg-btn' + ( n === colCount ? ' is-active' : '' ) }
								aria-pressed={ n === colCount }
								onClick={ ( e ) => { e.stopPropagation(); handleColCount( n ); } }
								title={ sprintf(
									/* translators: %d: column count */
									__( '%d column layout', 'neural-forms-lite' ),
									n
								) }
							>{ n }</button>
						) ) }
					</div>
				</div>

				<div className="nf-row__group nf-row__group--right">
					<div className="nf-row__seg nf-row__seg--gap" role="group" aria-label={ __( 'Row gap', 'neural-forms-lite' ) }>
						<span className="nf-row__seg-prefix">{ __( 'Gap', 'neural-forms-lite' ) }</span>
						{ GAP_OPTIONS.map( ( g ) => (
							<button
								key={ g.value }
								type="button"
								className={ 'nf-row__seg-btn' + ( g.value === gap ? ' is-active' : '' ) }
								aria-pressed={ g.value === gap }
								onClick={ ( e ) => { e.stopPropagation(); handleGap( g.value ); } }
								title={ __( g.label, 'neural-forms-lite' ) }
							>{ __( g.label, 'neural-forms-lite' ) }</button>
						) ) }
					</div>

					<div className="nf-row__actions">
						<button
							type="button"
							className="nf-row__icon-btn"
							onClick={ onMoveUp }
							disabled={ ! canMoveUp }
							aria-label={ __( 'Move row up', 'neural-forms-lite' ) }
							title={ __( 'Move row up', 'neural-forms-lite' ) }
						>↑</button>
						<button
							type="button"
							className="nf-row__icon-btn"
							onClick={ onMoveDown }
							disabled={ ! canMoveDown }
							aria-label={ __( 'Move row down', 'neural-forms-lite' ) }
							title={ __( 'Move row down', 'neural-forms-lite' ) }
						>↓</button>
						<button
							type="button"
							className="nf-row__icon-btn"
							onClick={ onDuplicate }
							aria-label={ __( 'Duplicate row', 'neural-forms-lite' ) }
							title={ __( 'Duplicate row', 'neural-forms-lite' ) }
						>⎘</button>
						<button
							type="button"
							className="nf-row__icon-btn nf-row__icon-btn--danger"
							onClick={ onDelete }
							aria-label={ __( 'Delete row', 'neural-forms-lite' ) }
							title={ __( 'Delete row', 'neural-forms-lite' ) }
						>×</button>
					</div>
				</div>
			</header>

			<div
				className={ 'nf-row__grid nf-row__grid--cols-' + colCount + ' nf-row__grid--gap-' + gap }
			>
				{ ( row.columns || [] ).slice( 0, colCount ).map( ( col, idx ) => (
					<Column
						key={ col.id }
						rowId={ row.id }
						column={ col }
						colIndex={ idx }
						registerDropZone={ registerColumnDropZone }
						renderField={ renderField }
						isSelected={ selectedColumnId === col.id }
						onSelect={
							typeof onSelectColumn === 'function'
								? () => onSelectColumn( col.id )
								: undefined
						}
					/>
				) ) }
			</div>
		</div>
	);
}
