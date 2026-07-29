import { useState, useRef, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import reactCSS from 'reactcss';
import { useStateValue } from '../Data';
import { debounce } from 'lodash';
import {
	ColorPicker as WpColorPicker,
	ColorPalette,
} from '@wordpress/components';
import { ArrowPathIcon, GlobeAltIcon } from '@heroicons/react/24/outline';
import { parseFieldName, getNestedValue, setNestedValue } from './fieldUtils';

function ColorPicker( props ) {
	const { name, defaultColor } = props;
	const [ data, dispatch ] = useStateValue();
	const parts = parseFieldName( name );
	const initialValue = getNestedValue( data, parts );
	const [ displayColorPicker, setdisplayColorPicker ] = useState( false );
	const [ color, setColor ] = useState( initialValue );
	const colorPalette = window.powerCouponsSettings?.theme_colors?.map(
		( theme_color ) => {
			return {
				name: theme_color,
				color: theme_color,
			};
		}
	);
	const [ rotate, setRotate ] = useState( false );

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

	// Handle ESC key to close the color picker
	useEffect( () => {
		const handleKeyDown = ( e ) => {
			if ( displayColorPicker && e.key === 'Escape' ) {
				handleClose();
			}
		};
		if ( displayColorPicker ) {
			document.addEventListener( 'keydown', handleKeyDown );
		}
		return () => {
			document.removeEventListener( 'keydown', handleKeyDown );
		};
	}, [ displayColorPicker ] );

	const styles = reactCSS( {
		default: {
			color: {
				width: '36px',
				height: '30px',
				background: color,
			},
		},
	} );

	// Generate a unique ID for this color picker for better accessibility
	const colorPickerId = `colorpicker-${ name.replace( /[\[\]]/g, '-' ) }`;

	const handleClick = () => {
		setdisplayColorPicker( ( prevValue ) => ! prevValue );
	};

	const handleClose = () => {
		setdisplayColorPicker( false );
	};

	const handleResetColor = () => {
		if ( color !== defaultColor ) {
			handleChange( defaultColor );
			setRotate( true );
			setTimeout( () => {
				setRotate( false );
			}, 500 );
		}
	};

	const handleChange = ( newcolor ) => {
		const colorValue = newcolor ? newcolor : defaultColor;
		if ( newcolor ) {
			setColor( newcolor );
		}

		// Trigger change
		const changeEvent = new CustomEvent( 'powercoupons:color:change', {
			bubbles: true,
			detail: {
				e: 'color',
				name: props.name,
				value: colorValue,
			},
		} );

		document.dispatchEvent( changeEvent );

		const currentValue = getNestedValue( data, parts );
		if ( currentValue !== colorValue ) {
			const newData = setNestedValue( data, parts, colorValue );
			debounceDispatch( {
				type: 'CHANGE',
				data: newData,
			} );
		}
	};

	// Return the icon's color class based on the color selected
	const iconColorBasedOnBgColor = ( bgColor ) => {
		const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(
			bgColor
		);
		const parsedColor = result
			? {
					r: parseInt( result[ 1 ], 16 ),
					g: parseInt( result[ 2 ], 16 ),
					b: parseInt( result[ 3 ], 16 ),
			  }
			: null;
		if ( parsedColor ) {
			const brightness = Math.round(
				( parsedColor.r * 299 +
					parsedColor.g * 587 +
					parsedColor.b * 114 ) /
					1000
			);
			const textColour = brightness > 125 ? 'text-black' : 'text-white';
			return textColour;
		}
		return 'text-white';
	};

	const iconColor = iconColorBasedOnBgColor( color );

	return (
		<>
			<div className="power_coupons-field-data-content">
				<div className="power_coupons-colorpicker-selector sm:justify-end">
					<div
						className="power_coupons-colorpicker-swatch-wrap focus-visible:ring-1 focus-visible:ring-toggle-on"
						onClick={ handleClick }
						onKeyDown={ ( e ) => {
							// Handle keyboard events for accessibility
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								handleClick();
							}
						} }
						role="button"
						tabIndex="0"
						aria-haspopup="true"
						aria-expanded={ displayColorPicker }
						aria-controls={ colorPickerId }
						id={ `${ colorPickerId }-trigger` }
					>
						<span
							className="power_coupons-colorpicker-swatch flex justify-center items-center"
							style={ styles.color }
							aria-hidden="true"
						>
							{ window.powerCouponsSettings?.theme_colors?.includes(
								color
							) && (
								<GlobeAltIcon
									className={ `h-5 w-5 ${ iconColor }` }
								/>
							) }
						</span>
						<span className="power_coupons-colorpicker-label">
							{ __( 'Select Color', 'power-coupons' ) }
						</span>
						<input
							type="hidden"
							name={ name }
							value={ color }
							aria-labelledby={ `${ colorPickerId }-trigger` }
						/>
					</div>
					<span
						className={ `power_coupons-colorpicker-reset focus-visible:ring-1 ${
							color === defaultColor ? 'opacity-40' : ''
						}` }
						onClick={ handleResetColor }
						onKeyDown={ ( e ) =>
							e.key === 'Enter' && handleResetColor()
						}
						title={ __( 'Reset', 'power-coupons' ) }
						aria-label={ __(
							'Reset to default color',
							'power-coupons'
						) }
						type="button"
						tabIndex="0"
					>
						<ArrowPathIcon
							className={ `h-5 w-5 stroke-2 transform ${
								rotate ? 'rotate-180' : ''
							} transition-transform duration-500` }
							aria-hidden="true"
						/>
					</span>
				</div>
				<div className="power_coupons-color-picker">
					{ displayColorPicker ? (
						<div
							className="power_coupons-color-picker-popover"
							id={ colorPickerId }
						>
							<div
								className="power_coupons-color-picker-cover"
								onClick={ handleClose }
							/>
							<div className="bg-white shadow-lg relative z-999999">
								<WpColorPicker
									color={ color }
									onChange={ handleChange }
									enableAlpha
								/>
								<div className="px-4 pb-4">
									<ColorPalette
										colors={ colorPalette }
										value={ color }
										onChange={ handleChange }
										disableCustomColors
										clearable={ false }
									/>
								</div>
							</div>
						</div>
					) : null }
				</div>
			</div>
		</>
	);
}

export default ColorPicker;
