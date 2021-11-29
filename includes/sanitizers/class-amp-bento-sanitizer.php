<?php
/**
 * Class AMP_Bento_Sanitizer.
 *
 * @package AMP
 */

use AmpProject\AmpWP\ValidationExemption;
use AmpProject\Attribute;
use AmpProject\Dom\Element;
use AmpProject\CssLength;
use AmpProject\Layout;

/**
 * Convert all bento-prefixed components into amp-prefixed components, or else mark them as PX-verified if they have no
 * AMP versions. Remove Bento stylesheets and scripts if they aren't needed.
 *
 * @since 2.2
 * @internal
 */
class AMP_Bento_Sanitizer extends AMP_Base_Sanitizer {

	/** @var string */
	const XPATH_BENTO_ELEMENTS_QUERY = './/*[ starts-with( name(), "bento-" ) ]';

	/**
	 * Tag and attribute sanitizer.
	 *
	 * @var AMP_Base_Sanitizer
	 */
	protected $tag_and_attribute_sanitizer;

	/**
	 * Init.
	 *
	 * @param AMP_Base_Sanitizer[] $sanitizers Sanitizers.
	 */
	public function init( $sanitizers ) {
		parent::init( $sanitizers );

		if ( array_key_exists( AMP_Tag_And_Attribute_Sanitizer::class, $sanitizers ) ) {
			$this->tag_and_attribute_sanitizer = $sanitizers[ AMP_Tag_And_Attribute_Sanitizer::class ];
		}
	}

	/**
	 * Get mapping of HTML selectors to the AMP component selectors which they may be converted into.
	 *
	 * @return array Mapping.
	 */
	public function get_selector_conversion_mapping() {
		$mapping = [];
		foreach ( AMP_Allowed_Tags_Generated::get_extension_specs() as $amp_extension_name => $extension_spec ) {
			if ( empty( $extension_spec['bento'] ) ) {
				continue;
			}
			$bento_extension_name = str_replace( 'amp-', 'bento-', $amp_extension_name );
			if ( $bento_extension_name !== $amp_extension_name ) {
				$mapping[ $bento_extension_name ] = [ $amp_extension_name ];
			}
		}

		return $mapping;
	}

	/**
	 * Indicate that the selector conversion mappings do not involve light shadow DOM.
	 *
	 * For example, with `bento-base-carousel`, the descendant `h2` elements will be present in the document initially.
	 * So a selector like `bento-base-carousel h2` will not have issues with tree shaking when it is converted into
	 * `amp-base-carousel h2`. Additionally, Bento components by definition use the _actual_ real shadow DOM, so if
	 * there were a selector like `bento-foo div` then the `div` would never match an element since is beyond the
	 * shadow boundary, and tree shaking should be free to remove such a selector. Selectors that are targeting
	 * slotted elements are not inside the shadow DOM, so for example `bento-base-carousel img` will target an actual
	 * element in the initial DOM, even though `bento-base-carousel` has other elements that are beyond the shadow
	 * DOM boundary.
	 *
	 * @return false
	 */
	public function has_light_shadow_dom() {
		return false;
	}

	/**
	 * Sanitize.
	 */
	public function sanitize() {
		$bento_elements = $this->dom->xpath->query( self::XPATH_BENTO_ELEMENTS_QUERY, $this->dom->body );

		$bento_elements_discovered = [];
		$bento_elements_converted  = [];

		$extension_specs = AMP_Allowed_Tags_Generated::get_extension_specs();
		foreach ( $bento_elements as $bento_element ) {
			/** @var Element $bento_element */
			$bento_name = $bento_element->tagName;
			$amp_name   = str_replace( 'bento-', 'amp-', $bento_name );

			$bento_elements_discovered[ $bento_name ] = true;

			// Skip Bento components which aren't valid (yet).
			if ( ! array_key_exists( $amp_name, $extension_specs ) ) {
				ValidationExemption::mark_node_as_px_verified( $bento_element );
				continue;
			}

			$amp_element = $this->dom->createElement( $amp_name );
			while ( $bento_element->attributes->length ) {
				/** @var DOMAttr $attribute */
				$attribute = $bento_element->attributes->item( 0 );

				// Essential for unique attributes like ID, or else PHP DOM will keep it referencing the old element.
				$bento_element->removeAttributeNode( $attribute );

				$amp_element->setAttributeNode( $attribute );
			}

			while ( $bento_element->firstChild instanceof DOMNode ) {
				$amp_element->appendChild( $bento_element->removeChild( $bento_element->firstChild ) );
			}

			$this->adapt_layout_styles( $amp_element );

			$bento_element->parentNode->replaceChild( $amp_element, $bento_element );

			$bento_elements_converted[ $bento_name ] = true;
		}

		// Remove the Bento external stylesheets which are no longer necessary. For the others, mark as PX-verified.
		$links = $this->dom->xpath->query(
			'//link[ @rel = "stylesheet" and starts-with( @href, "https://cdn.ampproject.org/v0/bento-" ) ]'
		);
		foreach ( $links as $link ) {
			/** @var Element $link */
			$bento_name = $this->get_bento_component_name_from_url( $link->getAttribute( Attribute::HREF ) );
			if ( ! $bento_name ) {
				continue;
			}

			if (
				// If the Bento element doesn't exist in the page, remove the extraneous stylesheet.
				! array_key_exists( $bento_name, $bento_elements_discovered )
				||
				// If the Bento element was converted to AMP, then remove the now-unnecessary stylesheet.
				array_key_exists( $bento_name, $bento_elements_converted )
			) {
				$link->parentNode->removeChild( $link );
			} else {
				ValidationExemption::mark_node_as_px_verified( $link );
				ValidationExemption::mark_node_as_px_verified( $link->getAttributeNode( Attribute::HREF ) );
			}
		}

		// Keep track of the number of Bento scripts we kept, as then we'll need to make sure we keep the Bento runtime script.
		$non_amp_scripts_retained = 0;

		// Handle Bento scripts.
		$scripts = $this->dom->xpath->query(
			'//script[ starts-with( @src, "https://cdn.ampproject.org/v0/bento" ) ]'
		);
		foreach ( $scripts as $script ) {
			/** @var Element $script */
			$bento_name = $this->get_bento_component_name_from_url( $script->getAttribute( Attribute::SRC ) );
			if ( ! $bento_name ) {
				continue;
			}

			if (
				// If the Bento element doesn't exist in the page, remove the extraneous script.
				! array_key_exists( $bento_name, $bento_elements_discovered )
				||
				// If the Bento element was converted to AMP, then remove the now-unnecessary script.
				array_key_exists( $bento_name, $bento_elements_converted )
			) {
				$script->parentNode->removeChild( $script );
			} else {
				ValidationExemption::mark_node_as_px_verified( $script );
				$non_amp_scripts_retained++;
			}
		}

		// Remove the Bento runtime script if it is not needed, or else mark it as PX-verified.
		$bento_runtime_scripts = $this->dom->xpath->query(
			'//script[ @src = "https://cdn.ampproject.org/bento.mjs" or @src = "https://cdn.ampproject.org/bento.js" ]'
		);
		if ( 0 === $non_amp_scripts_retained ) {
			foreach ( $bento_runtime_scripts as $bento_runtime_script ) {
				$bento_runtime_script->parentNode->removeChild( $bento_runtime_script );
			}
		} else {
			foreach ( $bento_runtime_scripts as $bento_runtime_script ) {
				ValidationExemption::mark_node_as_px_verified( $bento_runtime_script );
			}
		}

		// If bento-prefixed components were discovered, then ensure that the tag-and-attribute sanitizer will prefer
		// Bento components when validating and that it will use the Bento versions of component scripts, and ultimately
		// AMP_Theme_Support::ensure_required_markup() will add the Bento experiment opt-in which is still required at
		// the moment.
		if ( count( $bento_elements_discovered ) > 0 && $this->tag_and_attribute_sanitizer ) {
			$this->tag_and_attribute_sanitizer->update_args(
				[ 'prefer_bento' => true ]
			);
		}
	}

	/**
	 * Parse Bento component name from a Bento CDN URL.
	 *
	 * @param string $url URL for script or stylesheet.
	 * @return string|null Bento component name or null if no match was made.
	 */
	private function get_bento_component_name_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path && preg_match( '#^(bento-.*?)-\d+\.\d+\.(m?js|css)#', basename( $path ), $matches ) ) {
			return $matches[1];
		} else {
			return null;
		}
	}

	/**
	 * Adapt inline styles from Bento element to AMP layout attributes.
	 *
	 * This will try its best to convert `width`, `height`, and `aspect-ratio` inline styles over to their corresponding
	 * AMP layout attributes. In order for a Bento component to be AMP-compatible, it needs to utilize inline styles
	 * for its dimensions rather than rely on a stylesheet rule.
	 *
	 * @param Element $amp_element AMP element (converted from Bento).
	 */
	private function adapt_layout_styles( Element $amp_element ) {
		$style_string = $amp_element->getAttribute( Attribute::STYLE );
		if ( ! $style_string ) {
			return;
		}

		$styles = $this->parse_style_string( $style_string );

		$layout_attributes = [];

		if ( isset( $styles['height'] ) ) {
			$height = new CssLength( $styles['height'] );
			$height->validate( false, false );
			if ( $height->isValid() ) {
				$layout_attributes[ Attribute::HEIGHT ] = $height->getNumeral() . ( $height->getUnit() !== 'px' ? $height->getUnit() : '' );
				unset( $styles['height'] );
			}
		}

		if ( ! isset( $styles['width'] ) || '100%' === $styles['width'] ) {
			$layout_attributes[ Attribute::WIDTH ]  = 'auto';
			$layout_attributes[ Attribute::LAYOUT ] = Layout::FIXED_HEIGHT;
			unset( $styles['width'] );
		} else {
			$width = new CssLength( $styles['width'] );
			$width->validate( false, false );
			if ( $width->isValid() ) {
				$layout_attributes[ Attribute::WIDTH ] = $width->getNumeral() . ( $width->getUnit() !== 'px' ? $width->getUnit() : '' );
				unset( $styles['width'] );
			}
		}

		if (
			isset( $styles['aspect-ratio'] )
			&&
			preg_match( '#(?P<width>\d+(?:.\d+)?)(?:\s*/\s*(?P<height>\d+(?:.\d+)?))?#', $styles['aspect-ratio'], $matches )
		) {
			$layout_attributes[ Attribute::HEIGHT ] = isset( $matches['height'] ) ? $matches['height'] : '1';
			$layout_attributes[ Attribute::WIDTH ]  = $matches['width'];
			$layout_attributes[ Attribute::LAYOUT ] = Layout::RESPONSIVE;
			unset( $styles['aspect-ratio'] );
		}

		if ( $layout_attributes ) {
			$amp_element->setAttribute( Attribute::STYLE, $this->reassemble_style_string( $styles ) );
			foreach ( $layout_attributes as $attribute_name => $attribute_value ) {
				$amp_element->setAttribute( $attribute_name, $attribute_value );
			}
		}
	}
}
