import { Badge, SearchBox } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useEffect, useState, useRef } from 'react';

const CategorySelector = ( { label, placeholder, value = [], onChange } ) => {
	const [ selectedCategories, setSelectedCategories ] = useState( [] );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ categories, setCategories ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ open, setOpen ] = useState( false );
	const debounceRef = useRef( null );

	// Pre-populate selected categories when editing existing values.
	useEffect( () => {
		if ( value.length > 0 ) {
			fetchCategoriesByIds( value );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}

		if ( searchTerm.length > 1 ) {
			setLoading( true );
			debounceRef.current = setTimeout( () => {
				searchCategories( searchTerm );
				setOpen( true );
			}, 400 );
		} else {
			setCategories( [] );
			setOpen( false );
		}

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ searchTerm ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const fetchCategoriesByIds = async ( ids ) => {
		try {
			const formData = new FormData();
			formData.append( 'action', 'power_coupons_get_categories_by_ids' );
			formData.append(
				'_wpnonce',
				window.powerCouponsSettings?.update_nonce || ''
			);
			ids.forEach( ( id, index ) =>
				formData.append( `ids[${ index }]`, id )
			);

			const response = await fetch( ajaxurl, {
				method: 'POST',
				body: formData,
			} );
			const result = await response.json();

			if ( result.success && result.data.length > 0 ) {
				setSelectedCategories( result.data );
			}
		} catch ( error ) {
			console.error( 'Error fetching categories by IDs:', error );
		}
	};

	const searchCategories = async ( term ) => {
		setLoading( true );
		try {
			const nonce = window.powerCouponsSettings?.update_nonce;

			const formData = new FormData();
			formData.append( 'action', 'power_coupons_search_categories' );
			formData.append( 'term', term );
			formData.append( '_wpnonce', nonce || '' );

			const response = await fetch( ajaxurl, {
				method: 'POST',
				body: formData,
			} );
			const result = await response.json();

			if ( result.success ) {
				setCategories( result.data );
			} else {
				console.error( 'Search failed:', result );
				setCategories( [] );
			}
		} catch ( error ) {
			console.error( 'Error searching categories:', error );
			setCategories( [] );
		}
		setLoading( false );
	};

	const addCategory = ( category ) => {
		if ( ! selectedCategories.find( ( c ) => c.id === category.id ) ) {
			const newSelected = [ ...selectedCategories, category ];
			setSelectedCategories( newSelected );
			onChange( newSelected.map( ( c ) => c.id ) );
			setSearchTerm( '' );
			setOpen( false );
		}
	};

	const removeCategory = ( categoryId ) => {
		const newSelected = selectedCategories.filter(
			( c ) => c.id !== categoryId
		);
		setSelectedCategories( newSelected );
		onChange( newSelected.map( ( c ) => c.id ) );
	};

	return (
		<div className="flex flex-col gap-2">
			{ label && (
				/* eslint-disable-next-line jsx-a11y/label-has-associated-control */
				<label className="text-sm font-medium">{ label }</label>
			) }

			<SearchBox
				variant="secondary"
				closeAfterSelect={ false }
				loading={ loading }
				setOpen={ setOpen }
				open={ open }
				size="md"
			>
				<SearchBox.Input
					className="w-[98%] [&_span]:hidden"
					placeholder={ placeholder }
					value={ searchTerm }
					onChange={ setSearchTerm }
				/>
				<SearchBox.Portal id="power-coupons-settings">
					<SearchBox.Content>
						<SearchBox.List>
							{ categories.length > 0 ? (
								categories.map( ( category ) => {
									const isSelected = selectedCategories.find(
										( c ) => c.id === category.id
									);
									return (
										<SearchBox.Item
											className={
												isSelected
													? 'opacity-50 cursor-not-allowed'
													: 'cursor-pointer'
											}
											key={ category.id }
											onClick={ () => {
												if ( ! isSelected ) {
													addCategory( category );
												}
											} }
										>
											{ category.name }
										</SearchBox.Item>
									);
								} )
							) : (
								<SearchBox.Empty>
									{ /* eslint-disable no-nested-ternary */ }
									{ searchTerm.length < 2
										? __(
												'Type at least 2 letters to search',
												'power-coupons'
										  )
										: loading
										? __( 'Searching…', 'power-coupons' )
										: __(
												'No categories found',
												'power-coupons'
										  ) }
									{ /* eslint-enable no-nested-ternary */ }
								</SearchBox.Empty>
							) }
						</SearchBox.List>
					</SearchBox.Content>
				</SearchBox.Portal>
			</SearchBox>

			{ selectedCategories.length > 0 && (
				<div className="flex flex-wrap gap-2 mt-2">
					{ selectedCategories.map( ( category ) => (
						<Badge
							closable
							onClose={ () => removeCategory( category.id ) }
							key={ category.id }
							label={ category.name }
							size="sm"
							type="pill"
							variant="neutral"
						/>
					) ) }
				</div>
			) }
		</div>
	);
};

export default CategorySelector;
