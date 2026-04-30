/**
 * Layout row in the form builder.
 *
 * Visually a boxed group with a header (drag handle, column-count select,
 * gap select, duplicate, delete) and 1-4 column drop targets stacked
 * inline. Each column is its own sortable area and accepts both fields
 * being reordered within it AND fields being dragged in from siblings.
 *
 * Rows themselves are also sortable at the top level so the admin can
 * reorder rows up and down.
 */

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { __, sprintf } from '@wordpress/i18n';
import Column from './Column';
import { clampColumnCount, MIN_COLUMNS, MAX_COLUMNS, VALID_GAPS } from './schemaHelper';

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
} ) {
	const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable( {
		id: row.id,
		data: { kind: 'row' },
	} );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
	};

	const colCount = clampColumnCount( row.settings?.columns ?? row.columns?.length ?? 1 );
	const gap      = VALID_GAPS.includes( row.settings?.gap ) ? row.settings.gap : 'medium';

	const handleColCount = ( nextCount ) => {
		// Defer the actual resize to the parent so the global field index
		// (used for dnd-kit's flat sortable list) stays in sync.
		onChange( { type: 'resize', nextCount } );
	};

	const handleGap = ( nextGap ) => {
		onChange( {
			type: 'patch',
			patch: { settings: { ...( row.settings || {} ), gap: nextGap } },
		} );
	};

	return (
		<div
			ref={ setNodeRef }
			style={ style }
			className={ 'nf-row' + ( isDragging ? ' is-dragging' : '' ) }
			data-nf-row-id={ row.id }
		>
			<header className="nf-row__header">
				<button
					type="button"
					className="nf-row__handle"
					aria-label={ __( 'Drag row', 'neural-forms-lite' ) }
					title={ __( 'Drag row', 'neural-forms-lite' ) }
					{ ...attributes }
					{ ...listeners }
				>
					<span aria-hidden="true">⋮⋮</span>
				</button>
				<span className="nf-row__label">
					{ sprintf(
						/* translators: %d: column count */
						__( '%d-column row', 'neural-forms-lite' ),
						colCount
					) }
				</span>

				<label className="nf-row__select">
					<span className="screen-reader-text">{ __( 'Columns', 'neural-forms-lite' ) }</span>
					<select
						value={ colCount }
						onChange={ ( e ) => handleColCount( parseInt( e.target.value, 10 ) ) }
					>
						{ Array.from( { length: MAX_COLUMNS - MIN_COLUMNS + 1 }, ( _, i ) => MIN_COLUMNS + i ).map( ( n ) => (
							<option key={ n } value={ n }>
								{ sprintf(
									/* translators: %d: column count */
									__( '%d column', 'neural-forms-lite' ),
									n
								) }
							</option>
						) ) }
					</select>
				</label>

				<label className="nf-row__select">
					<span className="screen-reader-text">{ __( 'Gap', 'neural-forms-lite' ) }</span>
					<select value={ gap } onChange={ ( e ) => handleGap( e.target.value ) }>
						<option value="none">{ __( 'No gap', 'neural-forms-lite' ) }</option>
						<option value="small">{ __( 'Small gap', 'neural-forms-lite' ) }</option>
						<option value="medium">{ __( 'Medium gap', 'neural-forms-lite' ) }</option>
						<option value="large">{ __( 'Large gap', 'neural-forms-lite' ) }</option>
					</select>
				</label>

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
					/>
				) ) }
			</div>
		</div>
	);
}
