import { Checkbox, Container } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { Header, NavButtons, Wrapper } from '../Components';
import { RecommendedPlugins } from '../Utils';
import { useOnboardingContext } from '../Context';

const PluginCard = ( { plugin } ) => (
	<div className="p-2 gap-1 items-center bg-background-primary rounded-md shadow-soft-shadow-inner">
		<Container
			align="start"
			className="gap-1 p-1"
			containerType="flex"
			justify="between"
		>
			<Container.Item className="flex items-start gap-[12px]">
				<img width={ 24 } src={ plugin.logo } alt={ plugin.name } />

				<div className="flex flex-col gap-[2px]">
					<strong className="text-[#111827] font-[400] text-[16px] line-[24px]">
						{ plugin.name }
					</strong>
					<p className="m-0 text-[#566A86] font-[400] text-[14px] line-[20px]">
						{ plugin.description }
					</p>
				</div>
			</Container.Item>
			<Container.Item className="flex">
				<Checkbox
					size="sm"
					checked={ plugin.checked }
					onChange={ plugin.onChange }
				/>
			</Container.Item>
		</Container>
	</div>
);

const RecommendPlugins = () => {
	const { currentScreenData, handleData } = useOnboardingContext();

	return (
		<>
			<Header
				heading={ __(
					'Add More Power to Your Website',
					'power-coupons'
				) }
				subHeading={ __(
					'These tools can help you build your website faster and easier. Try them out and see how they can help your website grow.',
					'power-coupons'
				) }
			/>
			<Wrapper>
				<Container.Item className="flex flex-col md:w-full lg:w-full bg-field-primary-background gap-1 p-1 rounded-lg">
					{ RecommendedPlugins.map( ( plugin, index ) => (
						<PluginCard
							key={ index }
							plugin={ {
								...plugin,
								checked: currentScreenData[ plugin.slug ],
								onChange: ( value ) =>
									handleData( plugin.slug, value ),
							} }
						/>
					) ) }
				</Container.Item>
			</Wrapper>

			<NavButtons />
		</>
	);
};

export default RecommendPlugins;
