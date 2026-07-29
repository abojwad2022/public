import { Select as ForceUISelect } from '@bsf/force-ui';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Panel,
	PanelBody,
	PanelRow,
	ToggleControl,
} from '@wordpress/components';
import {
	Fragment,
	createRoot,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import './styles.scss';

// Searchable Single Select Component for Products/Categories.
const SearchableSelect = ( { value, onChange, searchType, placeholder } ) => {
	const [ searchResults, setSearchResults ] = useState( [] );
	const [ selectedValue, setSelectedValue ] = useState( null );
	const searchTimeout = useRef( null );

	// Load selected item by ID
	const loadSelectedItem = useCallback(
		async ( id ) => {
			if ( ! id || id === '' || id === 0 ) {
				setSelectedValue( null );
				return;
			}

			try {
				const endpoint =
					searchType === 'products'
						? 'products'
						: 'products/categories';
				const response = await apiFetch( {
					path: `/wc/v3/${ endpoint }/${ id }`,
				} );

				if ( response && response.id && response.name ) {
					setSelectedValue( {
						id: response.id,
						name: response.name,
					} );
				} else {
					setSelectedValue( null );
				}
			} catch ( error ) {
				console.error( 'Error loading selected item:', error );
				setSelectedValue( null );
			}
		},
		[ searchType ]
	);

	// Load selected item on mount or when value changes
	useEffect( () => {
		if ( value && value !== '' && value !== 0 ) {
			loadSelectedItem( value );
		} else {
			setSelectedValue( null );
		}
	}, [ value, loadSelectedItem ] );

	// Search with debounce
	const handleSearch = ( term ) => {
		clearTimeout( searchTimeout.current );

		if ( term.length < 3 ) {
			setSearchResults( [] );
			return;
		}

		searchTimeout.current = setTimeout( async () => {
			try {
				const endpoint =
					searchType === 'products'
						? 'products'
						: 'products/categories';
				const response = await apiFetch( {
					path: `/wc/v3/${ endpoint }?search=${ encodeURIComponent(
						term
					) }&per_page=20`,
				} );

				const results = response.map( ( { id, name } ) => ( {
					id,
					name,
				} ) );
				setSearchResults( results );
			} catch ( error ) {
				console.error( 'Search error:', error );
				setSearchResults( [] );
			}
		}, 300 );
	};

	// Handle selection change and notify parent with single ID
	const handleSelectionChange = ( newValue ) => {
		setSelectedValue( newValue );
		onChange( newValue ? newValue.id : '' );
	};

	return (
		<div className="power-coupons-rules-forceui-select-wrapper">
			<ForceUISelect
				combobox
				size="md"
				value={ selectedValue }
				searchFn={ handleSearch }
				onChange={ handleSelectionChange }
			>
				<ForceUISelect.Button
					className="power-coupons-rules-forceui-select-button"
					render={ ( val ) => val.name }
					placeholder={ placeholder || 'Select' }
				/>
				<ForceUISelect.Options>
					{ searchResults.map( ( { id, name } ) => (
						<ForceUISelect.Option key={ id } value={ { id, name } }>
							{ name }
						</ForceUISelect.Option>
					) ) }
				</ForceUISelect.Options>
			</ForceUISelect>
		</div>
	);
};

const createRandomID = ( prefix ) => {
	// Using bitwise to turn random into an integer (very fast).
	return `${ prefix }_${ Date.now() }_${
		// eslint-disable-next-line no-bitwise
		( ( Math.random() * 1e9 ) | 0 ).toString( 36 )
	}`;
};

const Separator = ( { children } ) => {
	if ( children ) {
		return (
			<div className="power-coupons-rules__separator">
				<span className="power-coupons-rules__separator-left" />
				{ children }
				<span className="power-coupons-rules__separator-right" />
			</div>
		);
	}

	return <hr className="power-coupons-rules__separator" />;
};

const Fields = ( {
	groups,
	groupIndex,
	ruleIndex,
	removeCondition,
	updateRule,
} ) => {
	const rule = groups[ groupIndex ].rules[ ruleIndex ];

	const RULE_CONDITIONS = {
		cart_total: {
			label: __( 'Cart Total', 'power-coupons' ),
			operators: [
				{ key: 'equal_to', name: __( 'Is equal to', 'power-coupons' ) },
				{
					key: 'not_equal_to',
					name: __( 'Is not equal to', 'power-coupons' ),
				},
				{
					key: 'less_than',
					name: __( 'Is less than', 'power-coupons' ),
				},
				{
					key: 'less_than_or_equal',
					name: __( 'Is less than or equal to', 'power-coupons' ),
				},
				{
					key: 'greater_than',
					name: __( 'Is greater than', 'power-coupons' ),
				},
				{
					key: 'greater_than_or_equal',
					name: __( 'Is greater than or equal to', 'power-coupons' ),
				},
			],
			step: '0.01',
			inputType: 'number',
			placeholder: __( 'Enter amount', 'power-coupons' ),
		},
		cart_items: {
			label: __( 'Number of Cart Items', 'power-coupons' ),
			operators: [
				{ key: 'equal_to', name: __( 'Is equal to', 'power-coupons' ) },
				{
					key: 'not_equal_to',
					name: __( 'Is not equal to', 'power-coupons' ),
				},
				{
					key: 'less_than',
					name: __( 'Is less than', 'power-coupons' ),
				},
				{
					key: 'less_than_or_equal',
					name: __( 'Is less than or equal to', 'power-coupons' ),
				},
				{
					key: 'greater_than',
					name: __( 'Is greater than', 'power-coupons' ),
				},
				{
					key: 'greater_than_or_equal',
					name: __( 'Is greater than or equal to', 'power-coupons' ),
				},
			],
			step: '1',
			inputType: 'number',
			placeholder: __( 'Enter quantity', 'power-coupons' ),
		},
		products: {
			label: __( 'Products', 'power-coupons' ),
			operators: [
				{ key: 'in_list', name: __( 'Includes', 'power-coupons' ) },
				{ key: 'not_in_list', name: __( 'Excludes', 'power-coupons' ) },
			],
			inputType: 'dropdown',
			placeholder: __( 'Search for products…', 'power-coupons' ),
		},
		product_categories: {
			label: __( 'Product Categories', 'power-coupons' ),
			operators: [
				{ key: 'in_list', name: __( 'Includes', 'power-coupons' ) },
				{ key: 'not_in_list', name: __( 'Excludes', 'power-coupons' ) },
			],
			inputType: 'dropdown',
			placeholder: __( 'Search for categories…', 'power-coupons' ),
		},
	};

	// Initialize state from rule data
	const [ selectedCondition, setSelectedCondition ] = useState( {
		id: rule.type,
		name: RULE_CONDITIONS[ rule.type ].label,
	} );
	const [ selectedOperator, setSelectedOperator ] = useState( {
		id: rule.operator,
		name:
			RULE_CONDITIONS[ rule.type ].operators.find(
				( op ) => op.key === rule.operator
			)?.name || RULE_CONDITIONS[ rule.type ].operators[ 0 ].name,
	} );
	const [ ruleValue, setRuleValue ] = useState(
		rule.value !== undefined ? rule.value : ''
	);

	// Track if this is the first render to avoid resetting values on mount
	const isFirstRender = useRef( true );

	const handleForceUISelectButton = ( e ) => e.preventDefault();

	// Update rule when condition type changes (but not on first render)
	useEffect( () => {
		const buttons = document.querySelectorAll(
			'.power-coupons-rules-forceui-select-button'
		);
		buttons.forEach( ( button ) =>
			button.addEventListener( 'click', handleForceUISelectButton )
		);

		// Skip on first render to preserve loaded values
		if ( isFirstRender.current ) {
			isFirstRender.current = false;
			return;
		}

		// Reset operator to first available when condition changes
		const newOperator = {
			id: RULE_CONDITIONS?.[ selectedCondition.id ]?.operators[ 0 ].key,
			name: RULE_CONDITIONS?.[ selectedCondition.id ]?.operators[ 0 ]
				.name,
		};
		setSelectedOperator( newOperator );

		// Update rule with new type and reset operator and value
		updateRule( ruleIndex, {
			...rule,
			type: selectedCondition.id,
			operator: newOperator.id,
			value: '',
		} );
		setRuleValue( '' );

		return () =>
			buttons.forEach( ( button ) =>
				button.removeEventListener( 'click', handleForceUISelectButton )
			);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selectedCondition.id ] );

	// Update rule when operator changes
	const handleOperatorChange = ( value ) => {
		setSelectedOperator( value );
		updateRule( ruleIndex, {
			...rule,
			operator: value.id,
		} );
	};

	// Update rule when value changes
	const handleValueChange = ( value ) => {
		setRuleValue( value );
		updateRule( ruleIndex, {
			...rule,
			value,
		} );
	};

	return (
		<div className="power-coupons-rules__body__conditions__fields">
			{ /* Hidden inputs for server-side persistence - matches old.index.js structure */ }
			<input
				type="hidden"
				name={ `pc_rule_groups[${ groupIndex }][rules][${ ruleIndex }][rule_id]` }
				value={ rule.rule_id }
			/>
			<input
				type="hidden"
				name={ `pc_rule_groups[${ groupIndex }][rules][${ ruleIndex }][type]` }
				value={ selectedCondition.id }
			/>
			<input
				type="hidden"
				name={ `pc_rule_groups[${ groupIndex }][rules][${ ruleIndex }][operator]` }
				value={ selectedOperator.id }
			/>
			<input
				type="hidden"
				name={ `pc_rule_groups[${ groupIndex }][rules][${ ruleIndex }][value]` }
				value={ ruleValue || '' }
			/>

			<div className="power-coupons-rules-forceui-select-wrapper">
				<ForceUISelect
					size="md"
					value={ selectedCondition }
					onChange={ ( value ) => {
						setSelectedCondition( value );
					} }
				>
					<ForceUISelect.Button
						className="power-coupons-rules-forceui-select-button"
						render={ ( val ) => val.name }
					/>
					<ForceUISelect.Options>
						{ Object.keys( RULE_CONDITIONS ).map(
							( subConditionKey ) => {
								const name =
									RULE_CONDITIONS[ subConditionKey ].label;
								return (
									<ForceUISelect.Option
										key={ subConditionKey }
										value={ { id: subConditionKey, name } }
									>
										{ name }
									</ForceUISelect.Option>
								);
							}
						) }
					</ForceUISelect.Options>
				</ForceUISelect>
			</div>

			{ Object.keys( RULE_CONDITIONS ).map( ( key ) => {
				if ( selectedCondition.id === key ) {
					return (
						<Fragment key={ key }>
							<div className="power-coupons-rules-forceui-select-wrapper">
								<ForceUISelect
									size="md"
									value={ selectedOperator }
									onChange={ handleOperatorChange }
								>
									<ForceUISelect.Button
										className="power-coupons-rules-forceui-select-button"
										render={ ( val ) => val.name }
									/>
									<ForceUISelect.Options>
										{ RULE_CONDITIONS[ key ].operators.map(
											( { key: id, name } ) => {
												return (
													<ForceUISelect.Option
														key={ id }
														value={ { id, name } }
													>
														{ name }
													</ForceUISelect.Option>
												);
											}
										) }
									</ForceUISelect.Options>
								</ForceUISelect>
							</div>

							{ RULE_CONDITIONS[ key ].inputType ===
							'dropdown' ? (
								<SearchableSelect
									key={ `${ rule.rule_id }-${ selectedCondition.id }` }
									value={ ruleValue }
									onChange={ handleValueChange }
									searchType={
										selectedCondition.id === 'products'
											? 'products'
											: 'categories'
									}
									placeholder={
										RULE_CONDITIONS[ key ].placeholder
									}
								/>
							) : (
								<input
									className="power-coupons-rules__body__conditions__fields__input"
									type="number"
									step={ RULE_CONDITIONS[ key ].step }
									value={ ruleValue || '' }
									min={ 0 }
									onChange={ ( e ) =>
										handleValueChange( +e.target.value )
									}
									placeholder={
										RULE_CONDITIONS[ key ].placeholder
									}
								/>
							) }
						</Fragment>
					);
				}
				return null;
			} ) }

			<Button
				className="power-coupons-rules__body__conditions__fields__remove-condition"
				onClick={ () => removeCondition( ruleIndex ) }
				icon={ <TrashIcon /> }
			/>
		</div>
	);
};

const Conditions = ( { groups, groupIndex, setGroups } ) => {
	const rules = groups[ groupIndex ].rules || [];

	const addGroup = () => {
		const newGroup = {
			group_id: createRandomID( 'group' ),
			rules: [],
		};

		setGroups( [ ...groups, newGroup ] );
	};

	const removeGroup = ( indexToRemove ) => {
		// Don't remove if it's the last group
		if ( groups.length <= 1 ) {
			return;
		}

		const updatedGroups = groups.filter(
			( _, index ) => index !== indexToRemove
		);
		setGroups( updatedGroups );
	};

	const addCondition = () => {
		const updatedGroups = [ ...groups ];
		updatedGroups[ groupIndex ].rules.push( {
			rule_id: createRandomID( 'rule' ),
			type: 'cart_total',
			operator: 'greater_than_or_equal',
			value: '',
		} );
		setGroups( updatedGroups );
	};

	const removeCondition = ( ruleIndex ) => {
		const updatedGroups = [ ...groups ];
		updatedGroups[ groupIndex ].rules = updatedGroups[
			groupIndex
		].rules.filter( ( _, index ) => index !== ruleIndex );

		// If current group has no rules
		if ( updatedGroups[ groupIndex ].rules.length === 0 ) {
			// If this is the last group, keep the empty group
			if ( updatedGroups.length === 1 ) {
				setGroups( updatedGroups );
				return;
			}
			// If not the last group, remove the empty group
			removeGroup( groupIndex );
			return;
		}

		setGroups( updatedGroups );
	};

	const updateRule = ( ruleIndex, updatedRule ) => {
		const updatedGroups = [ ...groups ];
		updatedGroups[ groupIndex ].rules[ ruleIndex ] = updatedRule;
		setGroups( updatedGroups );
	};

	if ( rules.length ) {
		return (
			<div className="power-coupons-rules__body__conditions">
				{ rules.map( ( rule, ruleIndex ) => (
					<div
						key={ rule.rule_id }
						id={ `power-coupons-rules-${ rule.rule_id }` }
					>
						<Fields
							groups={ groups }
							groupIndex={ groupIndex }
							ruleIndex={ ruleIndex }
							removeCondition={ removeCondition }
							updateRule={ updateRule }
						/>

						<Separator>{ __( 'AND', 'power-coupons' ) }</Separator>

						{ rules.length === ruleIndex + 1 && (
							<div className="power-coupons-rules__body__conditions__fields !justify-center !gap-0">
								<Button
									icon={ <PlusIcon /> }
									iconSize={ 16 }
									iconPosition="right"
									onClick={ addCondition }
									className="power-coupons-rules__body__btn"
								>
									{ __( 'AND', 'power-coupons' ) }
								</Button>
							</div>
						) }
					</div>
				) ) }
			</div>
		);
	}

	return (
		<div className="power-coupons-rules__body__conditions__add-new">
			<Button
				icon={ <PlusIcon /> }
				iconSize={ 16 }
				iconPosition="right"
				onClick={ () => {
					addCondition();
					addGroup();
				} }
				className="power-coupons-rules__body__btn"
			>
				{ __( 'Add Condition', 'power-coupons' ) }
			</Button>
		</div>
	);
};

const Groups = () => {
	// Ensure groups have proper structure with rules array
	const sanitizeGroups = ( groups ) => {
		if ( ! Array.isArray( groups ) || groups.length === 0 ) {
			return [
				{
					group_id: createRandomID( 'group' ),
					rules: [],
				},
			];
		}

		// Ensure each group has a rules array
		const sanitizedGroups = groups.map( ( group ) => ( {
			...group,
			group_id: group.group_id || createRandomID( 'group' ),
			rules:
				Array.isArray( group.rules ) && group.rules.length > 0
					? group.rules.map( ( rule ) => ( {
							rule_id: rule.rule_id || createRandomID( 'rule' ),
							type: rule.type || 'cart_total',
							operator: rule.operator || 'greater_than_or_equal',
							value: rule.value !== undefined ? rule.value : '',
					  } ) )
					: [],
		} ) );

		const lastGroup = sanitizedGroups[ sanitizedGroups.length - 1 ];

		if ( lastGroup.rules.length > 0 ) {
			sanitizedGroups.push( {
				group_id: createRandomID( 'group' ),
				rules: [],
			} );
		}

		return sanitizedGroups;
	};

	const [ groups, setGroups ] = useState(
		sanitizeGroups( powerCouponsRules.data.groups )
	);

	return (
		<>
			<Separator />

			<Panel header={ null } className="power-coupons-rules__body">
				{ groups.map( ( group, groupIndex ) => {
					return (
						<Fragment key={ group.group_id }>
							<PanelBody
								className={ `power-coupons-rules-${ group.group_id }` }
								title="Display This Coupon If:"
								initialOpen={ true }
							>
								{ /* Hidden input for group_id */ }
								<input
									type="hidden"
									name={ `pc_rule_groups[${ groupIndex }][group_id]` }
									value={ group.group_id }
								/>
								<PanelRow>
									<Conditions
										groups={ groups }
										groupIndex={ groupIndex }
										setGroups={ setGroups }
									/>
								</PanelRow>
							</PanelBody>

							{ groups.length !== groupIndex + 1 && (
								<Separator>
									{ __( 'OR', 'power-coupons' ) }
								</Separator>
							) }
						</Fragment>
					);
				} ) }
			</Panel>
		</>
	);
};

const App = () => {
	const [ isEnabled, setIsEnabled ] = useState(
		powerCouponsRules.data.enabled === 'yes'
	);

	return (
		<>
			{ /* Hidden input for enable/disable - matches old.index.js structure */ }
			<input
				type="hidden"
				name="_pc_rule_enable_conditions"
				value={ isEnabled ? 'yes' : 'no' }
			/>

			<div className="power-coupons-rules__toggle-rules__header">
				<div className="power-coupons-rules__toggle-rules__header__title">
					<strong>
						{ __( 'Enable Conditional Rule', 'power-coupons' ) }
					</strong>
					<p>
						{ __(
							'Set rules to decide when this coupon works. The coupon applies only when the selected rules are met.',
							'power-coupons'
						) }
					</p>
				</div>
				<ToggleControl
					label={ null }
					checked={ isEnabled }
					onChange={ setIsEnabled }
					className="power-coupons-rules__toggle-rules__header__control"
					__nextHasNoMarginBottom // Start opting into the new margin-free styles that will become the default in a future version
				/>
			</div>

			{ isEnabled && <Groups /> }
		</>
	);
};

// Wait for coupon data tabs to be ready
jQuery( '.coupon_data_tabs' ).ready( function () {
	// Get the last active tab from session storage
	const lastActiveTab = sessionStorage.getItem(
		'_power_coupons_last_active_coupon_tab'
	);

	// If the last active tab was the power coupons rules tab
	if ( 'power_coupons_rules_options' === lastActiveTab ) {
		// Find and click the tab element to activate it
		const tabToClick = document.querySelector(
			`.coupon_data_tabs.wc-tabs li.${ lastActiveTab } a`
		);
		if ( tabToClick ) {
			tabToClick.click();
			// Clear the stored tab after activating
			sessionStorage.removeItem(
				'_power_coupons_last_active_coupon_tab'
			);
		}
	}

	// Store the active tab when form is submitted
	document
		.querySelector( 'form#post' )
		.addEventListener( 'submit', function () {
			// Get currently active tab
			const activeTab = document.querySelector(
				'.coupon_data_tabs.wc-tabs li.active'
			);
			// Store first class name as the tab identifier
			const currentActiveTab = activeTab.classList[ 0 ];
			// Save to session storage
			sessionStorage.setItem(
				'_power_coupons_last_active_coupon_tab',
				currentActiveTab
			);
		} );
} );

jQuery( document ).ready( function () {
	jQuery( '._power_coupon_start_date_field' ).insertBefore(
		'.expiry_date_field'
	);
	jQuery( '#_power_coupon_divider' ).insertBefore( '.free_shipping_field' );
} );

/**
 * Initialize the React application
 */
const container = document.getElementById( 'power_coupons_rules_tab' );
const root = createRoot( container );
root.render( <App /> );
