import { useState, useRef, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import { Switch } from '@bsf/force-ui';
import { useStateValue } from '../Data';
import { debounce } from 'lodash';
import FieldWrapper from '../wrappers/FieldWrapper';
import { parseFieldName, getNestedValue, setNestedValue } from './fieldUtils';

function ToggleField( props ) {
	const { title, description, name, disabled = false } = props;
	const [ data, dispatch ] = useStateValue();
	const parts = parseFieldName( name );
	const initialValue = getNestedValue( data, parts );
	const [ enable, setEnable ] = useState( initialValue );

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

	function handleOnChange( state ) {
		const newValue = ! state;
		setEnable( newValue );
		if ( 'function' === typeof props.manageState ) {
			props.manageState( newValue );
		}

		const newData = setNestedValue( data, parts, newValue );

		debounceDispatch( {
			type: 'CHANGE',
			data: newData,
		} );
	}

	// Generate a unique ID for this toggle for better accessibility
	const toggleId = `toggle-${ name.replace( /[\[\]]/g, '-' ) }`;

	return (
		<FieldWrapper
			title={ title }
			description={ description }
			disabled={ disabled }
		>
			<div>
				<Switch
					id={ toggleId }
					aria-label={ `${ title } - ${
						enable
							? __( 'Enabled', 'power-coupons' )
							: __( 'Disabled', 'power-coupons' )
					}` }
					aria-checked={ enable }
					value={ enable }
					name={ name }
					disabled={ disabled }
					onChange={ () => {
						handleOnChange( enable );
					} }
					onKeyDown={ ( e ) => {
						// Handle keyboard events for accessibility
						if ( e.key === 'Enter' || e.key === ' ' ) {
							e.preventDefault();
							handleOnChange( enable );
						}
					} }
					size="md"
					className="border-none power_coupons-toggle-field"
					role="switch"
				/>
			</div>
		</FieldWrapper>
	);
}

export default ToggleField;
