import { Accordion } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { GraduationCap } from 'lucide-react';
import PageHeader from '@AdminComponents/page-header';
import LearnChapter from './learn-chapter';
import LearnAllDoneCard from './learn-all-done-card';
import useLearnProgress from './use-learn-progress';
import useCtaHandler from './cta-handler';

const Learn = () => {
	const {
		chapters,
		isStepComplete,
		isStepAutoDetected,
		markStep,
		getChapterStats,
		getOverallStats,
	} = useLearnProgress();

	const onCta = useCtaHandler( markStep );
	const overall = getOverallStats();

	const firstIncomplete = chapters.find( ( c ) => {
		const s = getChapterStats( c.id );
		return s.done < s.total;
	} );
	const defaultValue = firstIncomplete
		? [ firstIncomplete.id ]
		: chapters.map( ( c ) => c.id );

	return (
		<div className="w-full p-5 pb-8 xl:p-8 max-w-[920px] mx-auto flex flex-col gap-7">
			<PageHeader
				title={ __( 'Learn', 'surerank' ) }
				icon={ GraduationCap }
				description={ __(
					'Set up SureRank end to end with a step-by-step guide to make your site discoverable, shareable, and ready to rank.',
					'surerank'
				) }
			/>
			{ overall.pct === 100 && <LearnAllDoneCard /> }
			<div className="p-6 bg-white shadow-sm rounded-xl">
				<Accordion
					type="simple"
					defaultValue={ defaultValue }
					className="flex flex-col gap-3"
				>
					{ chapters.map( ( chapter ) => (
						<LearnChapter
							key={ chapter.id }
							value={ chapter.id }
							chapter={ chapter }
							stats={ getChapterStats( chapter.id ) }
							isStepComplete={ isStepComplete }
							isStepAutoDetected={ isStepAutoDetected }
							markStep={ markStep }
							onCta={ onCta }
						/>
					) ) }
				</Accordion>
			</div>
		</div>
	);
};

export default Learn;
