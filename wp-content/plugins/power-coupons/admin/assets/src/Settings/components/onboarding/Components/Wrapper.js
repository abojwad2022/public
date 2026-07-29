export default ( { children, className } ) => (
	<div className={ `!mt-0 p-[8px] ${ className || '' }` }>{ children }</div>
);
