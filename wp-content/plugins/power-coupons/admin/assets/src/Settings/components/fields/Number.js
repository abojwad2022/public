import { useState, useRef, useEffect } from 'react';
import { useStateValue } from '../Data';
import { debounce } from 'lodash';
import { Input } from '@bsf/force-ui';
import { parseFieldName, getNestedValue, setNestedValue } from './fieldUtils';

function Number( props ) {
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
		debounceDispatch( { type: 'CHANGE', data: newData } );
	}

	return (
		<div className="flex sm:justify-end">
			<Input
				className={ `${
					props.badge ? 'w-24 ' : 'w-32 rounded-r-md'
				} focus:[&>input]:ring-focus` }
				suffix={
					<span className="text-badge-color-gray p-0.5 text-center text-xs font-medium">
						{ props.type }
					</span>
				}
				type="number"
				name={ props.name }
				value={ value }
				onChange={ handleChange }
				min={ props.min || 0 }
				step={ props.step || 'any' }
			/>
		</div>
	);
}

export default Number;
