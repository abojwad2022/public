import Welcome from './Screens/Welcome';
import ConfigureCoupons from './Screens/ConfigureCoupons';
import UserDetails from './Screens/UserDetails';
import RecommendPlugins from './Screens/RecommendPlugins';
import FinalStep from './Screens/FinalStep';
import { __ } from '@wordpress/i18n';
import parse from 'html-react-parser';

import CartFlowsLogo from '../../../../images/logos/cartflows.gif';
import ModernCartLogo from '../../../../images/logos/modern-cart.svg';
import WCARLogo from '../../../../images/logos/wcar.gif';
import SureFormsLogo from '../../../../images/logos/sureforms.gif';
import SureRankLogo from '../../../../images/logos/surerank.jpg';

const Screens = [
	Welcome,
	ConfigureCoupons,
	UserDetails,
	RecommendPlugins,
	FinalStep,
];

const RecommendedPlugins = [
	{
		name: 'CartFlows',
		description: __(
			'Create beautiful checkout pages & sales funnels for WooCommerce.',
			'power-coupons'
		),
		slug: 'cartflows',
		logo: CartFlowsLogo,
	},
	{
		name: 'Modern Cart',
		description: __(
			'Add a sleek side cart and free shipping bar to keep shoppers buying.',
			'power-coupons'
		),
		slug: 'modern-cart',
		logo: ModernCartLogo,
	},
	{
		name: 'Cart Abandonment Recovery',
		description: __(
			'Start recovering lost revenue with ease in less than 10 minutes.',
			'power-coupons'
		),
		slug: 'woo-cart-abandonment-recovery',
		logo: WCARLogo,
	},
	{
		name: 'SureForms',
		description: __(
			'Create forms that feel like a chat. One question at a time keeps users engaged.',
			'power-coupons'
		),
		slug: 'sureforms',
		logo: SureFormsLogo,
	},
	{
		name: 'SureRank',
		description: __(
			'Just a simple, lightweight SEO assistant that helps your site rise in the rankings.',
			'power-coupons'
		),
		slug: 'surerank',
		logo: SureRankLogo,
	},
];

const IconList = {
	check: '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3.00098L4.5 8.50098L2 6.00098" stroke="#566A86" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	close: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4L4 12" stroke="#111827" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" /><path d="M4 4L12 12" stroke="#111827" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" /></svg>',
	chevronLeft:
		'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 15.001L7.5 10.001L12.5 5.00098" stroke="#111827" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	chevronRight:
		'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 15.001L12.5 10.001L7.5 5.00098" stroke="white" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/></svg>',
};

const RenderIcon = ( icon ) => {
	return parse( IconList[ icon ] );
};

const RedirectToDashboard = () => {
	window.location.href = 'admin.php?page=power_coupons_settings';
};

export {
	Screens,
	IconList,
	RenderIcon,
	RecommendedPlugins,
	RedirectToDashboard,
};
