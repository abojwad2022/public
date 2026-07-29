import { Container, Toaster, toast, Table, Switch } from '@bsf/force-ui';
import React, { useEffect, useRef, useState } from 'react';
import { useHistory, useLocation } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import {
	EyeIcon,
	PencilIcon,
	DocumentDuplicateIcon,
	TrashIcon,
} from '@heroicons/react/24/outline';

import { useStateValue } from './Data';
import Header from './Header';
import Settings from './path/Settings';
import apiFetch from '@wordpress/api-fetch';
import BOGO from './path/BOGO';
import Points from './path/Points';
import LicenseNotice from './tabs/LicenseNotice';
import ProUpsell from './tabs/ProUpsell';
import DummyPointsTable from './tabs/PointsUpsell'; // Campaigns-only upsell placeholder
const DUMMY_OFFERS = [
	{
		name: 'Buy 2 Get 1 Free — T-Shirts',
		description: 'Buy any 2 t-shirts and get the 3rd free',
		type: 'Buy X Get X',
	},
	{
		name: 'BOGO 50% Off — Accessories',
		description: 'Buy one accessory, get another at 50% off',
		type: 'Buy X Get Y',
	},
	{
		name: 'Spend $100 Get Free Gift',
		description: 'Free gift item on orders above $100',
		type: 'Spend X Get Y',
	},
	{
		name: 'Buy 3 Get 1 Free — Socks',
		description: 'Stock up and save on socks',
		type: 'Buy X Get X',
	},
	{
		name: 'Holiday BOGO — All Products',
		description: 'Holiday season buy one get one on everything',
		type: 'Buy X Get Y',
	},
	{
		name: 'Summer Sale — Buy 2 Get 30% Off',
		description: 'Summer clearance percentage discount',
		type: 'Buy X Get X',
	},
	{
		name: 'Flash Sale — Free Shipping Item',
		description: 'Limited time free shipping product offer',
		type: 'Spend X Get Y',
	},
	{
		name: 'VIP Members — Extra 20% Off',
		description: 'Exclusive member discount on second item',
		type: 'Buy X Get Y',
	},
	{
		name: 'Back to School — Buy 2 Get 1 Free',
		description: 'Stock up on school supplies with BOGO savings',
		type: 'Buy X Get X',
	},
	{
		name: 'Clearance BOGO — Winter Collection',
		description: 'Buy one winter item, get another free',
		type: 'Buy X Get Y',
	},
	{
		name: 'Spend $75 Get 15% Off Next Order',
		description: 'Reward loyal shoppers with a future discount',
		type: 'Spend X Get Y',
	},
	{
		name: 'Bundle Deal — Buy 4 Pay for 3',
		description: 'Mix and match any 4 items, cheapest is free',
		type: 'Buy X Get X',
	},
];

function DummyBOGOTable() {
	return (
		<div className="bg-background-primary rounded-xl border border-border-subtle p-4 flex flex-col gap-4">
			<div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
				<h2 className="m-0 text-xl font-semibold text-text-primary">
					{ __( 'BOGO Offers', 'power-coupons' ) }
				</h2>
				<div className="flex items-center gap-3 sm:gap-4 w-full sm:w-auto">
					<div className="relative flex-1 sm:flex-none">
						<input
							type="text"
							className="block w-full sm:w-64 pl-10 pr-3 py-2 border border-border-subtle rounded-md text-sm placeholder-text-tertiary"
							placeholder={ __(
								'Search offers…',
								'power-coupons'
							) }
							readOnly
							tabIndex={ -1 }
						/>
					</div>
					<button
						type="button"
						className="flex items-center gap-2 px-4 py-2 text-white bg-orange-500 rounded-md border-none cursor-default whitespace-nowrap"
						tabIndex={ -1 }
					>
						<span>
							{ __( 'Create New Offer', 'power-coupons' ) }
						</span>
					</button>
				</div>
			</div>
			<Table
				checkboxSelection
				className="whitespace-nowrap sm:whitespace-normal"
			>
				<Table.Head>
					<Table.HeadCell>
						{ __( 'Offer Name', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						{ __( 'Description', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						{ __( 'Offer Type', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						{ __( 'Status', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						<Container
							align="center"
							className="gap-2"
							justify="end"
						>
							{ __( 'Actions', 'power-coupons' ) }
						</Container>
					</Table.HeadCell>
				</Table.Head>
				<Table.Body>
					{ DUMMY_OFFERS.map( ( offer, index ) => (
						<Table.Row key={ index }>
							<Table.Cell className="text-text-secondary text-sm font-normal">
								{ offer.name }
							</Table.Cell>
							<Table.Cell className="text-text-secondary text-sm font-normal">
								{ offer.description }
							</Table.Cell>
							<Table.Cell className="text-text-secondary text-sm font-normal">
								{ offer.type }
							</Table.Cell>
							<Table.Cell>
								<Switch
									size="sm"
									defaultValue={ index < 7 }
									className="[&>input]:!border-none"
								/>
							</Table.Cell>
							<Table.Cell>
								<Container
									align="center"
									className="gap-2"
									justify="end"
								>
									<EyeIcon className="h-4 w-4 text-icon-secondary" />
									<PencilIcon className="h-4 w-4 text-icon-secondary" />
									<DocumentDuplicateIcon className="h-4 w-4 text-icon-secondary" />
									<TrashIcon className="h-4 w-4 text-icon-secondary" />
								</Container>
							</Table.Cell>
						</Table.Row>
					) ) }
				</Table.Body>
			</Table>
		</div>
	);
}

function ViewContainer() {
	const [ data ] = useStateValue();
	const [ settingsTab, setSettingsTab ] = useState( '' );
	const query = new URLSearchParams( useLocation().search );
	const activePage = 'power_coupons_settings';
	const activePath = query.get( 'path' ) || 'settings';

	const [ processing, setProcessing ] = useState( false );
	const updateData = useRef( false );

	useEffect( () => {
		if ( ! updateData.current ) {
			updateData.current = true;
			return;
		}

		const formData = new window.FormData();
		formData.append( 'action', 'power_coupons_update_settings' );
		formData.append( 'security', window.powerCouponsSettings.update_nonce );
		formData.append( 'power_coupons_settings', JSON.stringify( data ) );

		setProcessing( true );

		apiFetch( {
			url: window.powerCouponsSettings.ajax_url,
			method: 'POST',
			body: formData,
		} )
			.then( () => {
				toast.success( 'Successfully Saved!', {
					description: '',
				} );
			} )
			.catch( () => {
				toast.error(
					__( 'Failed to save settings.', 'power-coupons' ),
					{
						description: '',
					}
				);
			} )
			.finally( () => {
				setProcessing( false );
			} );
	}, [ data ] );

	const history = useHistory();
	const navigation = [];

	const settingsTabs = window.powerCouponsSettings.settings_tabs
		? Object.values( window.powerCouponsSettings.settings_tabs )
		: [];

	Object.values( settingsTabs )
		.sort( ( a, b ) => ( a.priority || 0 ) - ( b.priority || 0 ) )
		.forEach( ( tab ) => {
			navigation.push( {
				name: tab.label || tab.name,
				slug: tab.slug,
			} );
		} );

	const settingsTabSlugs = settingsTabs.map( ( tab ) => tab.slug );
	const tab = settingsTabSlugs.includes( query.get( 'tab' ) )
		? query.get( 'tab' )
		: getSettingsTab();

	function navigate( navigateTab ) {
		setSettingsTab( navigateTab );
		history.push(
			'admin.php?page=power_coupons_settings&path=settings&tab=' +
				navigateTab
		);
	}

	function getSettingsTab() {
		return settingsTab || 'power_coupons_general';
	}

	const showLicenseNotice =
		!! data?.pro_version && 'Activated' !== data.license_status;

	return (
		<form
			className="powerCouponsSettings"
			id="powerCouponsSettings"
			method="post"
		>
			<Container
				className="h-full"
				containerType="flex"
				direction="column"
				gap={ 0 }
			>
				<Container.Item>
					<Header
						processing={ processing }
						activePage={ activePage }
						activePath={ activePath }
					/>
				</Container.Item>
				{ 'settings' === activePath && (
					<Container.Item className="flex gap-4 bg-background-secondary max-h-[calc(100%_-_6rem)]">
						<Toaster
							position="top-right"
							design="stack"
							theme="light"
							autoDismiss={ true }
							dismissAfter={ 2000 }
							className="top-16"
						/>
						<Settings
							navigation={ navigation }
							tab={ tab }
							navigate={ navigate }
						/>
					</Container.Item>
				) }
				{ 'bogo' === activePath && (
					<Container.Item className="m-[32px_32px_32px_12px]">
						<Toaster
							position="top-right"
							design="stack"
							theme="light"
							autoDismiss={ true }
							dismissAfter={ 2000 }
							className="top-16"
						/>
						{ ! window.powerCouponsSettings.is_pro_active && (
							<ProUpsell>
								<DummyBOGOTable />
							</ProUpsell>
						) }
						{ window.powerCouponsSettings.is_pro_active &&
							showLicenseNotice && (
								<LicenseNotice navigate={ navigate } />
							) }
						{ window.powerCouponsSettings.is_pro_active &&
							! showLicenseNotice && (
								<BOGO
									navigation={ navigation }
									tab={ tab }
									navigate={ navigate }
									toast={ toast }
								/>
							) }
					</Container.Item>
				) }
				{ 'points' === activePath && (
					<Container.Item className="m-[32px_32px_32px_12px]">
						<Toaster
							position="top-right"
							design="stack"
							theme="light"
							autoDismiss={ true }
							dismissAfter={ 2000 }
							className="top-16"
						/>
						{ ! window.powerCouponsSettings.is_pro_active && (
							<ProUpsell>
								<DummyPointsTable />
							</ProUpsell>
						) }
						{ window.powerCouponsSettings.is_pro_active &&
							showLicenseNotice && (
								<LicenseNotice navigate={ navigate } />
							) }
						{ window.powerCouponsSettings.is_pro_active &&
							! showLicenseNotice && (
								<Points
									navigation={ navigation }
									tab={ tab }
									navigate={ navigate }
									toast={ toast }
								/>
							) }
					</Container.Item>
				) }
			</Container>
		</form>
	);
}

export default ViewContainer;
