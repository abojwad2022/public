import { Badge, SearchBox } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useEffect, useState, useRef } from 'react';

const ProductSelector = ( {
	label,
	placeholder,
	value = [],
	onChange,
	portalId = 'power-coupons-bogo-modal',
} ) => {
	const [ selectedProducts, setSelectedProducts ] = useState( [] );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ products, setProducts ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ open, setOpen ] = useState( false );
	const debounceRef = useRef( null );

	// Pre-populate selected products when editing an existing offer
	useEffect( () => {
		if ( value.length > 0 ) {
			fetchProductsByIds( value );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}

		if ( searchTerm.length > 2 ) {
			setLoading( true );
			debounceRef.current = setTimeout( () => {
				searchProducts( searchTerm );
				setOpen( true );
			}, 400 );
		} else {
			setProducts( [] );
			setOpen( false );
		}

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ searchTerm ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const fetchProductsByIds = async ( ids ) => {
		try {
			const formData = new FormData();
			formData.append( 'action', 'power_coupons_get_products_by_ids' );
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
				setSelectedProducts( result.data );
			}
		} catch ( error ) {
			console.error( 'Error fetching products by IDs:', error );
		}
	};

	const searchProducts = async ( term ) => {
		setLoading( true );
		try {
			const nonce = window.powerCouponsSettings?.update_nonce;

			const formData = new FormData();
			formData.append( 'action', 'power_coupons_search_products' );
			formData.append( 'term', term );
			formData.append( '_wpnonce', nonce || '' );

			const response = await fetch( ajaxurl, {
				method: 'POST',
				body: formData,
			} );
			const result = await response.json();

			if ( result.success ) {
				setProducts( result.data );
			} else {
				console.error( 'Search failed:', result );
				setProducts( [] );
			}
		} catch ( error ) {
			console.error( 'Error searching products:', error );
			setProducts( [] );
		}
		setLoading( false );
	};

	const addProduct = ( product ) => {
		if ( ! selectedProducts.find( ( p ) => p.id === product.id ) ) {
			const newSelected = [ ...selectedProducts, product ];
			setSelectedProducts( newSelected );
			onChange( newSelected.map( ( p ) => p.id ) );
			setSearchTerm( '' );
			setOpen( false );
		}
	};

	const removeProduct = ( productId ) => {
		const newSelected = selectedProducts.filter(
			( p ) => p.id !== productId
		);
		setSelectedProducts( newSelected );
		onChange( newSelected.map( ( p ) => p.id ) );
	};

	return (
		<div className="flex flex-col gap-2">
			{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
			<label className="text-sm font-medium">{ label }</label>

			<SearchBox
				variant="secondary"
				closeAfterSelect={ false }
				loading={ loading }
				setOpen={ setOpen }
				open={ open }
				size="md"
			>
				<SearchBox.Input
					className="w-[98%] [&_span]:hidden" // We added "[&_span]:hidden" class here to hide the search icon and shortcut key icon as we don't have props to do that.
					placeholder={ placeholder }
					value={ searchTerm }
					onChange={ setSearchTerm }
				/>
				<SearchBox.Portal id={ portalId }>
					<SearchBox.Content>
						<SearchBox.List>
							{ products.length > 0 ? (
								products.map( ( product ) => {
									const isSelected = selectedProducts.find(
										( p ) => p.id === product.id
									);
									return (
										<SearchBox.Item
											className={
												isSelected
													? 'opacity-50 cursor-not-allowed'
													: 'cursor-pointer'
											}
											key={ product.id }
											onClick={ () => {
												if ( ! isSelected ) {
													addProduct( product );
												}
											} }
										>
											{ product.name }
										</SearchBox.Item>
									);
								} )
							) : (
								<SearchBox.Empty>
									{ /* eslint-disable no-nested-ternary */ }
									{ searchTerm.length < 3
										? __(
												'Type at least 3 letters to search',
												'power-coupons'
										  )
										: loading
										? __( 'Searching…', 'power-coupons' )
										: __(
												'No products found',
												'power-coupons'
										  ) }
									{ /* eslint-enable no-nested-ternary */ }
								</SearchBox.Empty>
							) }
						</SearchBox.List>
					</SearchBox.Content>
				</SearchBox.Portal>
			</SearchBox>

			{ selectedProducts.length > 0 && (
				<div className="flex flex-wrap gap-2 mt-2">
					{ selectedProducts.map( ( product ) => (
						<Badge
							closable
							onClose={ () => removeProduct( product.id ) }
							key={ product.id }
							label={ product.name }
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

export default ProductSelector;
