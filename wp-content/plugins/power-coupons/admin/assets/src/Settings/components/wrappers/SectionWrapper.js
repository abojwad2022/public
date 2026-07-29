import { useState } from 'react';

function SectionWrapper( props ) {
	const { children, heading } = props;
	const [ show, toggle ] = useState( true );

	return (
		<>
			<div
				className="flex cursor-pointer justify-between border-t border-b -ml-0 -mr-0 sm:-ml-6 sm:-mr-6 lg:-ml-8 lg:-mr-0 border-gray-200 bg-gray-50 pl-8 pr-5 py-2 text-sm font-medium text-gray-500"
				onClick={ () => toggle( ! show ) }
			>
				<h3>{ heading }</h3>
				<span className="flex items-center">
					{ show && (
						<svg
							xmlns="http://www.w3.org/2000/svg"
							className="h-4 w-4"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
							strokeWidth="2"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								d="M19 9l-7 7-7-7"
							/>
						</svg>
					) }
					{ ! show && (
						<svg
							xmlns="http://www.w3.org/2000/svg"
							className="h-4 w-4"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
							strokeWidth="2"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								d="M5 15l7-7 7 7"
							/>
						</svg>
					) }
				</span>
			</div>
			<div>{ show && children }</div>
		</>
	);
}

export default SectionWrapper;
