import { useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import parse from 'html-react-parser';

import { useStateValue } from '../Data';
import { debounce } from 'lodash';

// Component for selecting icons from a predefined set of options
const IconPicker = ( props ) => {
	const [ value, setValue ] = useState( props.value );
	const [ data, dispatch ] = useStateValue();

	const { name, options } = props;

	// Debounced dispatch to prevent rapid state updates
	const debounceDispatch = useRef(
		debounce( async ( dispatchParams ) => {
			dispatch( dispatchParams );
		}, 500 )
	).current;

	// Cleanup debounced function on unmount
	useEffect( () => {
		return () => {
			debounceDispatch.cancel();
		};
	}, [ debounceDispatch ] );

	// Handle icon selection changes
	function handleChange( val ) {
		setValue( val );

		const newData = data;
		const elements = name.split( /[\[\]]/ );

		newData[ elements[ 0 ] ][ elements[ 1 ] ] = val;
		debounceDispatch( {
			type: 'CHANGE',
			data: newData,
		} );
	}
	return (
		<div className="flex sm:justify-between flex-wrap">
			{ options.map( ( option, index ) => (
				<div
					key={ index }
					className={ `p-2 flex justify-center items-center cursor-pointer rounded-md ${
						parseInt( value ) === index
							? 'bg-wpcolorfaded text-wpcolor'
							: 'text-text-secondary'
					} transition-colors duration-300 ease-in-out hover:bg-wpcolorfaded hover:text-wpcolor focus-visible:outline focus-visible:outline-2` }
					onClick={ () => handleChange( index ) }
					// handle keyboard events
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' || e.code === 'Space' ) {
							e.preventDefault();
							handleChange( index );
						}
					} }
					role="button"
					tabIndex="0"
					aria-label={ sprintf(
						/* translators: %d: icon number */
						__( 'Cart icon %d', 'power-coupons' ),
						index + 1
					) }
					title={ sprintf(
						/* translators: %d: icon number */
						__( 'Cart icon %d', 'power-coupons' ),
						index + 1
					) }
				>
					{ parse( option ) }
				</div>
			) ) }
		</div>
	);
};

export default IconPicker;
