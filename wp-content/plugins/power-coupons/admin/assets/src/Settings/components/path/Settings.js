import { Container, Label, Menu, Sidebar } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';

import FieldContainer from '../wrappers/FieldContainer';
import LicenseNotice from '../tabs/LicenseNotice';
import LicenseSettings from '../tabs/LicenseSettings';
import { useStateValue } from '../Data';

import parse from 'html-react-parser';

function Settings( props ) {
	const { navigation, tab, navigate } = props;
	const [ data ] = useStateValue();

	const showLicenseNotice =
		!! data?.pro_version &&
		'Activated' !== data.license_status &&
		'power_coupons_license' !== tab;
	return (
		<>
			<div className="mcw-sidebar h-full min-h-screen flex flex-col w-auto -ml-2.5 md:-ml-5 sticky top-0 lg:top-4">
				<Sidebar
					borderOn
					className="!h-full md:pl-3 lg:p-4 lg:w-64 border-none rounded-br-lg box-border flex-grow"
				>
					<Sidebar.Body>
						<Sidebar.Item>
							<Menu className="w-full p-0 gap-4" size="md">
								<Menu.List open>
									{ navigation.map( ( item ) => (
										<Menu.Item
											key={ item.slug }
											active={ tab === item.slug }
											onClick={ () => {
												navigate( item.slug );
											} }
											className={ `lg:justify-start justify-center h-9 ${
												tab === item.slug &&
												'bg-wphovercolorfaded font-medium'
											} hover:bg-wphovercolorfaded` }
										>
											{ powerCouponsSettings
												.settings_icons[ item.slug ] &&
												parse(
													powerCouponsSettings
														.settings_icons[
														item.slug
													]
												) }
											<span className="lg:block hidden">
												{ item.name }
											</span>
										</Menu.Item>
									) ) }
								</Menu.List>
							</Menu>
							<Container
								containerType="flex"
								direction="column"
								gap="xs"
								className="lg:p-4 mt-7 lg:border lg:border-solid lg:border-wpcolor rounded-md"
							>
								<Container.Item className="lg:flex gap-2 items-center hidden">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										fill="none"
										viewBox="0 0 24 24"
										strokeWidth={ 2 }
										stroke="currentColor"
										className="h-5 w-5 text-wpcolor"
										strokeLinecap="round"
										strokeLinejoin="round"
									>
										<path d="M3 11h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5Zm0 0a9 9 0 1 1 18 0m0 0v5a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3Z"></path>
										<path d="M21 16v2a4 4 0 0 1-4 4h-5"></path>
									</svg>
									<Label size="md" className="font-semibold">
										{ __(
											'Need Support?',
											'power-coupons'
										) }
									</Label>
								</Container.Item>
								<Container.Item className="lg:block hidden">
									<p className="font-normal text-sm text-text-field-helper m-0 pb-2">
										{ __(
											"We're happy to help!",
											'power-coupons'
										) }
									</p>
								</Container.Item>
								<Container.Item>
									<a
										href="https://cartflows.com/support"
										target="_blank"
										rel="noreferrer"
										className="flex justify-center p-2.5 no-underline text-white hover:text-white bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-black"
									>
										<svg
											xmlns="http://www.w3.org/2000/svg"
											fill="none"
											viewBox="0 0 24 24"
											strokeWidth={ 1.7 }
											stroke="currentColor"
											className="h-5 w-5 block lg:hidden"
											strokeLinecap="round"
											strokeLinejoin="round"
										>
											<path d="M3 11h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5Zm0 0a9 9 0 1 1 18 0m0 0v5a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3Z"></path>
											<path d="M21 16v2a4 4 0 0 1-4 4h-5"></path>
										</svg>
										<span className="text-center w-full text-sm font-semibold lg:block hidden p-0">
											{ __(
												'Request Support',
												'power-coupons'
											) }
										</span>
									</a>
								</Container.Item>
							</Container>
						</Sidebar.Item>
					</Sidebar.Body>
				</Sidebar>
			</div>
			<Container
				className="w-full max-w-[43.5rem] mx-auto mt-8 pr-4 pb-5 gap-0"
				direction="column"
			>
				{ 'power_coupons_license' === tab && <LicenseSettings /> }
				{ 'power_coupons_license' !== tab && showLicenseNotice && (
					<LicenseNotice navigate={ navigate } />
				) }
				{ 'power_coupons_license' !== tab && ! showLicenseNotice && (
					<FieldContainer key={ tab } tabKey={ tab } />
				) }
			</Container>
		</>
	);
}

export default Settings;
