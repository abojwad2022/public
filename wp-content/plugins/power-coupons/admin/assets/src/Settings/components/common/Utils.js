import { __ } from '@wordpress/i18n';
import parse from 'html-react-parser';

export const BOGOPresets = [
	{
		key: 'buy-x-get-x-free',
		title: __( 'Buy X Get X Free', 'power-coupons' ),
		description: __(
			'Give the same product free when customers buy X.',
			'power-coupons'
		),
	},
	{
		key: 'buy-x-get-y',
		title: __( 'Buy X Get Y', 'power-coupons' ),
		description: __(
			'Offer a different product free with qualifying purchase.',
			'power-coupons'
		),
	},
	{
		key: 'buy-x-get-y-at-x-percent-off',
		title: __( 'Buy X Get Y at X% Off', 'power-coupons' ),
		description: __(
			'Give a discounted product when X is purchased.',
			'power-coupons'
		),
	},
	{
		key: 'spend-x-get-y-free',
		title: __( 'Spend $X Get Y for Free', 'power-coupons' ),
		description: __(
			'Offer a free product on minimum cart value.',
			'power-coupons'
		),
	},
	{
		key: 'spend-x-get-y-at-x-percent-off',
		title: __( 'Spend $X Get Y at X% Off', 'power-coupons' ),
		description: __(
			'Unlock a discounted product after spending $X.',
			'power-coupons'
		),
	},
	{
		key: 'spend-x-get-free-shipping',
		title: __( 'Spend $X Get Free Shipping', 'power-coupons' ),
		description: __(
			'Enable free shipping when cart total reaches $X.',
			'power-coupons'
		),
	},
];

export const IconList = {
	chevronLeft:
		'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 15L7.5 10L12.5 5" stroke="#737373" stroke-width="1.04167" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	arrowRight:
		'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4.1665 10H15.8332" stroke="#9CA3AF" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 4.16663L15.8333 9.99996L10 15.8333" stroke="#9CA3AF" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	check: '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3.00098L4.5 8.50098L2 6.00098" stroke="#566A86" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	close: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4L4 12" stroke="#111827" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 4L12 12" stroke="#111827" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	plus: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4.16663V15.8333" stroke="white" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.16675 10H15.8334" stroke="white" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	search: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.16667 15.8333C12.8486 15.8333 15.8333 12.8486 15.8333 9.16667C15.8333 5.48477 12.8486 2.5 9.16667 2.5C5.48477 2.5 2.5 5.48477 2.5 9.16667C2.5 12.8486 5.48477 15.8333 9.16667 15.8333Z" stroke="#6B7280" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5 17.5L13.875 13.875" stroke="#6B7280" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	edit: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.33333 2.66663H2.66667C2.31304 2.66663 1.97391 2.80711 1.72386 3.05716C1.47381 3.30721 1.33333 3.64634 1.33333 3.99997V13.3333C1.33333 13.687 1.47381 14.0261 1.72386 14.2761C1.97391 14.5262 2.31304 14.6666 2.66667 14.6666H12C12.3536 14.6666 12.6928 14.5262 12.9428 14.2761C13.1929 14.0261 13.3333 13.687 13.3333 13.3333V8.66663" stroke="#6B7280" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.3333 1.66665C12.5985 1.40144 12.9583 1.25244 13.3333 1.25244C13.7084 1.25244 14.0681 1.40144 14.3333 1.66665C14.5985 1.93187 14.7475 2.29158 14.7475 2.66665C14.7475 3.04172 14.5985 3.40144 14.3333 3.66665L8 9.99998L5.33333 10.6666L6 7.99998L12.3333 1.66665Z" stroke="#6B7280" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	delete: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 4H3.33333H14" stroke="#6B7280" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.33325 4.00004V2.66671C5.33325 2.31309 5.47373 1.97395 5.72378 1.7239C5.97383 1.47385 6.31296 1.33337 6.66659 1.33337H9.33325C9.68687 1.33337 10.026 1.47385 10.2761 1.7239C10.5261 1.97395 10.6666 2.31309 10.6666 2.66671V4.00004M12.6666 4.00004V13.3334C12.6666 13.687 12.5261 14.0261 12.2761 14.2762C12.026 14.5262 9.68687 14.6667 9.33325 14.6667H6.66659C6.31296 14.6667 5.97383 14.5262 5.72378 14.2762C5.47373 14.0261 5.33325 13.687 5.33325 13.3334V4.00004H12.6666Z" stroke="#6B7280" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
};

export const getBOGOPresetData = ( key ) =>
	BOGOPresets.find( ( p ) => p.key === key );

export const RenderIcon = ( icon ) => {
	return parse( IconList[ icon ] );
};

/**
 * Create excerpt from a sentence
 *
 * @param {string} text      - The original sentence
 * @param {number} maxLength - Maximum length of excerpt (default: 100)
 * @param {string} ellipsis  - Ellipsis character(s) to append (default: "...")
 * @return {string} The truncated excerpt.
 */
export const createExcerpt = ( text, maxLength = 50, ellipsis = '...' ) => {
	if ( ! text || typeof text !== 'string' ) {
		return '';
	}

	// Trim whitespace
	const cleanText = text.trim();

	// If already within limit, return as is
	if ( cleanText.length <= maxLength ) {
		return cleanText;
	}

	// Cut text to maxLength
	let excerpt = cleanText.substring( 0, maxLength );

	// Avoid cutting in the middle of a word
	const lastSpaceIndex = excerpt.lastIndexOf( ' ' );
	if ( lastSpaceIndex > 0 ) {
		excerpt = excerpt.substring( 0, lastSpaceIndex );
	}

	return excerpt + ellipsis;
};
