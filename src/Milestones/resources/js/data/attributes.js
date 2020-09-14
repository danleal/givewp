/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Block Attributes
*/

const blockAttributes = {
	title: {
		type: 'string',
		default: __( 'Back to School Fundraiser', 'give' ),
	},
	description: {
		type: 'string',
		default: __( 'This is a sample description.', 'give' ),
	},
	image: {
		type: 'string',
		default: '',
	},
	ids: {
		type: 'array',
		default: [],
	},
	categories: {
		type: 'array',
		default: [],
	},
	tags: {
		type: 'array',
		default: [],
	},
	metric: {
		type: 'string',
		default: 'revenue',
	},
	deadline: {
		type: 'string',
		default: '',
	},
	goal: {
		type: 'string',
		default: '',
	},
};

export default blockAttributes;
