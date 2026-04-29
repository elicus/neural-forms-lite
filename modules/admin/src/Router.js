/**
 * Screen router - picks the right top-level screen based on the
 * `screen` value PHP localized into window.neuralFormsAdmin, with a
 * URL-param override that swaps the Forms list for the Builder when
 * the admin is editing or creating a form.
 *
 * No <BrowserRouter>; WordPress already owns the URL via ?page=.
 */

import Forms    from './screens/Forms';
import Entries  from './screens/Entries';
import Stats    from './screens/Stats';
import Settings from './screens/Settings';
import Builder  from './forms/Builder';

export default function Router( { screen, data } ) {
	if ( screen === 'forms' ) {
		const params = new URLSearchParams( window.location.search );
		const editId = parseInt( params.get( 'edit' ) || '0', 10 );
		const isNew  = params.get( 'new' ) === '1';

		if ( editId > 0 ) {
			return <Builder formId={ editId } isNew={ false } />;
		}
		if ( isNew ) {
			return <Builder formId={ 0 } isNew={ true } />;
		}
		return <Forms data={ data } />;
	}

	switch ( screen ) {
		case 'entries':  return <Entries  data={ data } />;
		case 'stats':    return <Stats    data={ data } />;
		case 'settings': return <Settings data={ data } />;
		default:
			return <Forms data={ data } />;
	}
}
