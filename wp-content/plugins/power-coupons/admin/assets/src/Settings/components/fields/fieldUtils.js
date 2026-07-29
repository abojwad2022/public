/**
 * Field utilities for handling nested field names
 *
 * @package
 */

/**
 * Parse field name like "general[enable_plugin]" into array of parts
 *
 * @param {string} name Field name with bracket notation
 * @return {Array} Array of field name parts
 */
export const parseFieldName = ( name ) => {
	const parts = name.split( /[\[\]]/ ).filter( Boolean );
	return parts;
};

/**
 * Get nested value from object using array of keys
 *
 * @param {Object} obj   Object to get value from
 * @param {Array}  parts Array of keys to traverse
 * @return {*} Value at nested path
 */
export const getNestedValue = ( obj, parts ) => {
	return parts.reduce( ( acc, part ) => acc?.[ part ], obj );
};

/**
 * Set nested value in object using array of keys (immutable)
 *
 * @param {Object} obj   Object to set value in
 * @param {Array}  parts Array of keys to traverse
 * @param {*}      value Value to set
 * @return {Object} New object with value set
 */
export const setNestedValue = ( obj, parts, value ) => {
	const newObj = { ...obj };
	let current = newObj;

	for ( let i = 0; i < parts.length - 1; i++ ) {
		if (
			! current[ parts[ i ] ] ||
			typeof current[ parts[ i ] ] !== 'object'
		) {
			current[ parts[ i ] ] = {};
		} else {
			current[ parts[ i ] ] = { ...current[ parts[ i ] ] };
		}
		current = current[ parts[ i ] ];
	}

	current[ parts[ parts.length - 1 ] ] = value;
	return newObj;
};
