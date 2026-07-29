import { Fragment, useState, useRef, useEffect } from 'react';
import { Listbox, Transition } from '@headlessui/react';
import { ChevronDownIcon } from '@heroicons/react/24/outline';

import { useStateValue } from '../Data';
import { debounce } from 'lodash';

import FieldWrapper from '../wrappers/FieldWrapper';
import { parseFieldName, getNestedValue, setNestedValue } from './fieldUtils';

function DropdownField( props ) {
	const { title, description, name, optionsArray, disabled = false } = props;
	const [ data, dispatch ] = useStateValue();
	const parts = parseFieldName( name );
	const currentValue = getNestedValue( data, parts );

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

	const dbValue =
		Object.keys( optionsArray ).find(
			( key ) => optionsArray[ key ].id === currentValue
		) || '0';
	const [ selected, setSelected ] = useState( optionsArray[ dbValue ] );

	function handleOnChange( selectedValue ) {
		const currentStoredValue = getNestedValue( data, parts );
		if ( currentStoredValue !== selectedValue ) {
			const newData = setNestedValue( data, parts, selectedValue );
			debounceDispatch( {
				type: 'CHANGE',
				data: newData,
			} );
		}
	}

	// Generate a unique ID for this dropdown for better accessibility
	const dropdownId = `dropdown-${ name.replace( /[\[\]]/g, '-' ) }`;

	return (
		<FieldWrapper
			title={ title }
			description={ description }
			disabled={ disabled }
		>
			<div className="flex-grow w-[25%]">
				<Listbox
					name={ name }
					value={ selected }
					onChange={ ( newSelected ) => {
						setSelected( newSelected );
						handleOnChange( newSelected.id );
					} }
					as="div"
					id={ dropdownId }
				>
					<Listbox.Label className="sr-only">{ title }</Listbox.Label>
					<div className="relative mt-1 w-full min-w-32">
						<Listbox.Button
							className="relative w-full py-2 pl-3 pr-10 text-left bg-white rounded-md cursor-pointer border border-solid border-border-subtle active:border-wpcolor active:outline-none sm:text-sm focus:outline-none focus:ring-2 focus:ring-wpcolor focus:border-wpcolor"
							aria-labelledby={ `${ dropdownId }-label` }
						>
							<span
								className="block truncate"
								id={ `${ dropdownId }-label` }
							>
								{ selected.name }
							</span>
							<span className="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
								<ChevronDownIcon
									className="w-5 h-5 text-gray-400"
									aria-hidden="true"
								/>
							</span>
						</Listbox.Button>
						<Transition
							as={ Fragment }
							leave="transition ease-in duration-100"
							leaveFrom="opacity-100"
							leaveTo="opacity-0"
						>
							<Listbox.Options className="absolute min-w-full w-auto py-1 mt-1 z-40 overflow-auto text-base bg-white rounded-md shadow-lg max-h-60 ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
								{ optionsArray.map( ( options, id ) => (
									<Listbox.Option
										key={ id }
										className={ ( { active } ) =>
											`${
												active
													? ' text-white bg-wpcolor'
													: 'text-gray-900'
											}
									cursor-default select-none relative py-1 px-3 whitespace-nowrap`
										}
										value={ options }
									>
										{ ( {
											active,
											selected: isSelected,
										} ) => (
											<>
												<span
													className={ `${
														isSelected
															? 'font-medium'
															: 'font-normal'
													} block` }
												>
													{ options.name }
												</span>
												{ isSelected ? (
													<span
														className={ `${
															active
																? 'text-wpcolor'
																: 'text-wpcolor20'
														}
											absolute inset-y-0 left-0 flex items-center pl-3` }
														aria-hidden="true"
													>
														{ /* Selected indicator */ }
													</span>
												) : null }
											</>
										) }
									</Listbox.Option>
								) ) }
							</Listbox.Options>
						</Transition>
					</div>
				</Listbox>
				<input
					type="hidden"
					name={ name }
					value={ selected.id }
					aria-hidden="true"
				/>
			</div>
		</FieldWrapper>
	);
}

export default DropdownField;
