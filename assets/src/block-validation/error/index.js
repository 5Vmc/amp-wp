/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { PanelBody, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import {
	AMP_VALIDITY_REST_FIELD_NAME,
	VALIDATION_ERROR_ACK_ACCEPTED_STATUS,
	VALIDATION_ERROR_ACK_REJECTED_STATUS,
} from '../constants';
import { NewTabIcon } from '../icon';
import { ErrorPanelTitle } from './error-panel-title';
import { ErrorContent } from './error-content';

/**
 * Component rendering an individual error. Parent component is a <ul>.
 *
 * @param {Object} props Component props.
 * @param {string} props.clientId
 * @param {number} props.status
 * @param {number} props.term_id
 */
export function Error( { clientId, status, term_id: termId, ...props } ) {
	const { selectBlock } = useDispatch( 'core/block-editor' );
	const { review_link: reviewLink } = useSelect( ( select ) => select( 'core/editor' ).getEditedPostAttribute( AMP_VALIDITY_REST_FIELD_NAME ) ) || {};
	const reviewed = status === VALIDATION_ERROR_ACK_ACCEPTED_STATUS || status === VALIDATION_ERROR_ACK_REJECTED_STATUS;

	const { blockType } = useSelect( ( select ) => {
		const blockDetails = clientId ? select( 'core/block-editor' ).getBlock( clientId ) : null;
		const blockTypeDetails = blockDetails ? select( 'core/blocks' ).getBlockType( blockDetails.name ) : null;

		return {
			blockType: blockTypeDetails,
		};
	}, [ clientId ] );

	return (
		<li className="amp-error-container">
			<PanelBody
				className={ `amp-error amp-error--${ reviewed ? 'reviewed' : 'new' }` }
				title={
					<ErrorPanelTitle { ...props } blockType={ blockType } status={ status } />
				}
				initialOpen={ false }
			>
				<ErrorContent { ...props } clientId={ clientId } blockType={ blockType } status={ status } />

				<div className="amp-error__actions">
					<Button
						className="amp-error__select-block"
						isSecondary
						onClick={ () => {
							selectBlock( clientId );
						} }
					>
						{ __( 'Select block', 'amp' ) }
					</Button>
					<a
						href={ addQueryArgs( reviewLink, { term_id: termId } ) }
						target="_blank"
						rel="noreferrer"
						className="amp-error__details-link"
					>
						{ __( 'View details', 'amp' ) }
						<NewTabIcon />
					</a>
				</div>

			</PanelBody>
		</li>
	);
}
Error.propTypes = {
	clientId: PropTypes.string,
	status: PropTypes.number.isRequired,
	term_id: PropTypes.number.isRequired,
};
