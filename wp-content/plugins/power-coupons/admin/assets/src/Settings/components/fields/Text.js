import { useState, useRef, useEffect } from 'react';
import { Input } from '@bsf/force-ui';
import { debounce } from 'lodash';

import { useStateValue } from '../Data';
import { parseFieldName, getNestedValue, setNestedValue } from './fieldUtils';

function Text( props ) {
	const [ data, dispatch ] = useStateValue();
	const parts = parseFieldName( props.name );
	const initialValue = getNestedValue( data, parts );
	const [ value, setValue ] = useState( initialValue );

	const debounceDispatch = useRef(
		debounce( async ( dispatchParams ) => {
			dispatch( dispatchParams );
		}, 500 )
	).current;

	useEffect( () => {
		return () => {
			debounceDispatch.cancel();
		};
	}, [ debounceDispatch ] );

	function handleChange( val ) {
		setValue( val );

		const newData = setNestedValue( data, parts, val );
		debounceDispatch( {
			type: 'CHANGE',
			data: newData,
		} );
	}
	return (
		<Input
			className="w-full focus:[&>input]:ring-focus"
			type="text"
			size="md"
			name={ props.name }
			value={ value }
			onChange={ handleChange }
			min="0"
			max={ props.max }
		/>
	);
}

export default Text;
