export const initialState =
	window.powerCouponsSettings?.power_coupons_settings || {};

const reducer = ( state, action ) => {
	switch ( action.type ) {
		case 'CHANGE':
			return {
				...action.data,
			};

		default:
			return state;
	}
};

export default reducer;
