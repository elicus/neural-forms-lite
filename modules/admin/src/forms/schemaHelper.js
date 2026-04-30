/**
 * Mirror of PHP SchemaHelper for the React builder.
 *
 * Rows are layout containers, never form fields. Validation, submission,
 * exports, and notifications all flatten rows + columns away on the
 * server. The builder UI handles them as draggable groups.
 */

export const ROW_TYPE = 'row';
export const MIN_COLUMNS = 1;
export const MAX_COLUMNS = 4;
export const VALID_GAPS = [ 'none', 'small', 'medium', 'large' ];

let _idCounter = 0;
function token( prefix ) {
	_idCounter += 1;
	return prefix + '_' + Date.now().toString( 36 ) + _idCounter.toString( 36 );
}

export const newRowId    = () => token( 'row' );
export const newColumnId = () => token( 'col' );
export const newFieldId  = ( type ) => token( ( type || 'field' ).replace( /[^a-z0-9]+/gi, '' ).toLowerCase() );

export function isRow( node ) {
	return !! ( node && node.type === ROW_TYPE );
}

export function clampColumnCount( n ) {
	const v = parseInt( n, 10 ) || 0;
	return Math.max( MIN_COLUMNS, Math.min( MAX_COLUMNS, v ) );
}

/** Walk every leaf field, in source order. Rows + columns are skipped. */
export function flattenFields( nodes ) {
	const out = [];
	if ( ! Array.isArray( nodes ) ) return out;
	for ( const node of nodes ) {
		if ( ! node ) continue;
		if ( isRow( node ) ) {
			for ( const col of ( node.columns || [] ) ) {
				out.push( ...flattenFields( col?.fields || [] ) );
			}
			continue;
		}
		if ( node.type ) out.push( node );
	}
	return out;
}

/** Build a brand-new row with N empty columns. */
export function makeRow( colCount ) {
	const count = clampColumnCount( colCount );
	const columns = [];
	for ( let i = 0; i < count; i += 1 ) {
		columns.push( { id: newColumnId(), fields: [] } );
	}
	return {
		id: newRowId(),
		type: ROW_TYPE,
		settings: { columns: count, gap: 'medium' },
		columns,
	};
}

/**
 * Resize a row's columns without losing fields.
 *
 * Growing: append empty columns.
 * Shrinking: every field from the dropped columns is appended to the
 * last kept column. Never destroys a field.
 */
export function resizeRow( row, nextCount ) {
	if ( ! isRow( row ) ) return row;
	const next = clampColumnCount( nextCount );
	let columns = Array.isArray( row.columns ) ? row.columns.slice() : [];

	if ( columns.length > next ) {
		const kept   = columns.slice( 0, next );
		const tail   = columns.slice( next );
		const last   = next - 1;
		const merged = ( kept[ last ]?.fields || [] ).slice();
		for ( const col of tail ) {
			for ( const f of ( col?.fields || [] ) ) merged.push( f );
		}
		kept[ last ] = { ...( kept[ last ] || { id: newColumnId() } ), fields: merged };
		columns = kept;
	} else if ( columns.length < next ) {
		while ( columns.length < next ) {
			columns.push( { id: newColumnId(), fields: [] } );
		}
	}

	return {
		...row,
		settings: { ...( row.settings || {} ), columns: next },
		columns,
	};
}

/** Find which container ([rowIndex, colIndex] or [-1, -1] for top level) holds a given field id. */
export function locateField( nodes, fieldId ) {
	for ( let i = 0; i < nodes.length; i += 1 ) {
		const node = nodes[ i ];
		if ( ! node ) continue;
		if ( ! isRow( node ) ) {
			if ( node.id === fieldId ) return { topIndex: i, rowIndex: -1, colIndex: -1, fieldIndex: i };
			continue;
		}
		const cols = node.columns || [];
		for ( let c = 0; c < cols.length; c += 1 ) {
			const fields = cols[ c ]?.fields || [];
			for ( let f = 0; f < fields.length; f += 1 ) {
				if ( fields[ f ]?.id === fieldId ) {
					return { topIndex: i, rowIndex: i, colIndex: c, fieldIndex: f };
				}
			}
		}
	}
	return null;
}

/** Duplicate a row with fresh ids on every node so id-uniqueness holds. */
export function duplicateRow( row ) {
	if ( ! isRow( row ) ) return row;
	return {
		...row,
		id: newRowId(),
		columns: ( row.columns || [] ).map( ( col ) => ( {
			...col,
			id: newColumnId(),
			fields: ( col.fields || [] ).map( ( f ) => ( { ...f, id: newFieldId( f.type ) } ) ),
		} ) ),
	};
}
