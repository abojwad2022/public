import { __ } from '@wordpress/i18n';
import { Link } from 'react-router-dom';
import { Topbar, Badge } from '@bsf/force-ui';

import Logo from '../../../images/logo.svg';

import { useStateValue } from './Data';

const menus = powerCouponsSettings.admin_header_menus;

function Header( props ) {
	const { activePath } = props;
	const [ data ] = useStateValue();

	return (
		<div className="-ml-2.5 md:-ml-5 power_coupons-header--wrapper">
			<Topbar
				className="power_coupons-header--content h-16 min-h-[unset] p-0 border-0 border-b border-solid border-border-subtle bg-white relative z-10"
				gap={ 0 }
				role="navigation"
				aria-label={ __( 'Main Navigation', 'power-coupons' ) }
			>
				<Topbar.Left className="power_coupons-header--content-left lg:px-5 px-3">
					<Topbar.Item>
						<img
							className="lg:block h-[2.6rem] w-auto"
							src={ Logo }
							alt={ __( 'Power Coupons', 'power-coupons' ) }
						/>
					</Topbar.Item>
				</Topbar.Left>
				<Topbar.Middle
					align="left"
					className="power_coupons-header--content-middle h-full gap-2 md:gap-4"
					role="menubar"
				>
					{ menus.map( ( menu ) => (
						<Topbar.Item
							className="h-full"
							key={ `?page=power_coupons_settings&path=${ menu.path }` }
							role="menuitem"
						>
							<Link
								to={ {
									pathname: 'admin.php',
									search: `?page=power_coupons_settings${
										'' !== menu.path
											? '&path=' + menu.path
											: ''
									}`,
								} }
								className={ `${
									activePath === menu.path
										? ' border-wpcolor hover:text-wphovercolor text-gray-900 inline-flex items-center px-1 border-b-2 text-[0.940rem] font-medium focus:shadow-none'
										: 'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 border-b-2 text-[0.940rem] font-medium focus:shadow-none'
								} no-underline h-full border-solid border-0` }
								aria-current={
									activePath === menu.path
										? 'page'
										: undefined
								}
							>
								{ menu.name }
							</Link>
						</Topbar.Item>
					) ) }
				</Topbar.Middle>
				<Topbar.Right
					className="power_coupons-header--content-right p-2 md:p-4 gap-2 md:gap-4"
					gap="md"
				>
					{ !! data?.pro_version && (
						<Topbar.Item>
							{ 'Activated' === data.license_status ? (
								<span
									className="inline-flex items-center no-underline gap-1.5 px-3 py-1 border whitespace-nowrap bg-green-50 border-green-200 text-green-600 hover:text-green-600 focus:text-green-600 pointer-events-none disabled rounded text-xs cursor-pointer font-normal"
									aria-label={ __(
										'License Status: Valid',
										'power-coupons'
									) }
								>
									{ __( 'Valid License', 'power-coupons' ) }
								</span>
							) : (
								<span
									className="inline-flex items-center no-underline gap-1.5 px-3 py-1 border whitespace-nowrap bg-red-50 border-red-200 text-red-600 hover:text-red-600 focus:text-red-600 pointer-events-none disabled rounded text-xs cursor-pointer font-normal"
									aria-label={ __(
										'License Status: Not Activated',
										'power-coupons'
									) }
								>
									{ __(
										'Activate License',
										'power-coupons'
									) }
								</span>
							) }
						</Topbar.Item>
					) }
					<Topbar.Item>
						<span title={ powerCouponsSettings.version }>
							<Badge
								icon={ null }
								label={ powerCouponsSettings.version }
								size="sm"
								type="pill"
								variant="neutral"
								disableHover
							/>
						</span>
					</Topbar.Item>
				</Topbar.Right>
			</Topbar>
		</div>
	);
}

export default Header;
