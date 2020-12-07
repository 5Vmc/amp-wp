/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Attempts to get the title of the plugin or theme responsible for an error.
 *
 * Adapted from AMP_Validated_URL_Post_Type::render_sources_column PHP method.
 *
 * @param {Object[]} sources Error source details from the PHP backtrace.
 */
export function getErrorSourceTitle( sources ) {
	const keyedSources = { theme: [], plugin: [], 'mu-plugin': [], embed: [], core: [] };
	for ( const source of sources ) {
		if ( source.type && source.type in keyedSources ) {
			keyedSources[ source.type ].push( source );
		}
	}

	const output = [];
	const pluginNames = [ ...new Set( keyedSources.plugin.map( ( { name } ) => name ) ) ];
	const muPluginNames = [ ...new Set( keyedSources[ 'mu-plugin' ].map( ( { name } ) => name ) ) ];
	const combinedPluginNames = [ ...pluginNames, ...muPluginNames ];

	if ( 1 === combinedPluginNames.length ) {
		output.push( global.ampBlockValidation.pluginNames[ combinedPluginNames[ 0 ] ] || combinedPluginNames[ 0 ] );
	} else {
		const pluginCount = pluginNames.length;
		const muPluginCount = muPluginNames.length;

		if ( 0 < pluginCount ) {
			output.push( sprintf( '%1$s (%2$d)', __( 'Plugins', 'amp' ), pluginCount ) );
		}

		if ( 0 < muPluginCount ) {
			output.push( sprintf( '%1$s (%2$d)', __( 'Must-use plugins', 'amp' ), muPluginCount ) );
		}
	}

	if ( 0 === keyedSources.embed.length ) {
		const activeThemeSources = keyedSources.theme.filter( ( { name } ) => global.ampBlockValidation.themeSlug === name );
		const inactiveThemeSources = keyedSources.theme.filter( ( { name } ) => global.ampBlockValidation.themeSlug !== name );
		if ( 0 < activeThemeSources.length ) {
			output.push( global.ampBlockValidation.themeName );
		}

		if ( 0 < inactiveThemeSources ) {
			// Translators: placeholder is the slug of an inactive WordPress theme.
			output.push( sprintf( __( 'Inactive theme (%s)', 'amp' ), inactiveThemeSources[ 0 ].name ) );
		}
	}

	if ( 0 === output.length && 0 < keyedSources.embed.length ) {
		output.push( __( 'Embed', 'amp' ) );
	}

	if ( 0 === output.length && 0 < keyedSources.core.length ) {
		output.push( __( 'Core', 'amp' ) );
	}

	return output.join( ', ' );
}
