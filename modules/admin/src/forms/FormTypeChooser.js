/**
 * Empty-canvas chooser tile.
 *
 * Renders inside the canvas when the schema is empty. The active
 * tiles (Blank form, Multi-step) spawn a starter shape so the user
 * is never stuck with an unhelpful empty canvas. Other types are
 * visual-only placeholders for the upcoming form-type router.
 *
 * Future direction:
 *   When "Create Form" lives ABOVE the builder (a separate chooser
 *   page), this component becomes redundant and gets removed. Until
 *   then it sets users' expectations and lets them ship a blank or
 *   multi-step form in one click.
 */

import { __ } from '@wordpress/i18n';

const TYPES = [
	{ id: 'blank',        label: 'Blank form',         locked: false, hint: 'Start from scratch.'                                  },
	{ id: 'contact',      label: 'Contact form',       locked: true                                                                },
	{ id: 'lead',         label: 'Lead generation',    locked: true                                                                },
	{ id: 'feedback',     label: 'Feedback',           locked: true                                                                },
	{ id: 'registration', label: 'Registration',       locked: true                                                                },
	{ id: 'multistep',    label: 'Multi-step',         locked: false, hint: 'Spawn a 2-step starter you can edit.'                 },
	{ id: 'quiz',         label: 'Quiz',               locked: true                                                                },
	{ id: 'poll',         label: 'Poll',               locked: true                                                                },
	{ id: 'testimonial',  label: 'Testimonial',        locked: true                                                                },
	{ id: 'ai',           label: 'AI-generated',       locked: true,  pro: true                                                    },
];

export default function FormTypeChooser( { onPickBlank, onPickMultistep } ) {
	const handlePick = ( id ) => {
		if ( id === 'blank' )     return typeof onPickBlank     === 'function' && onPickBlank();
		if ( id === 'multistep' ) return typeof onPickMultistep === 'function' && onPickMultistep();
	};

	return (
		<div className="nf-type-chooser">
			<h3 className="nf-type-chooser__title">{ __( 'Start with a template', 'neural-forms-lite' ) }</h3>
			<p className="nf-type-chooser__hint">
				{ __( 'Pick a starting point or open a blank canvas. Templates and AI generation are coming soon.', 'neural-forms-lite' ) }
			</p>
			<div className="nf-type-chooser__grid">
				{ TYPES.map( ( t ) => (
					<button
						key={ t.id }
						type="button"
						className={
							'nf-type-chooser__tile'
							+ ( t.locked ? ' is-locked' : '' )
							+ ( t.pro ? ' is-pro' : '' )
						}
						onClick={ () => ! t.locked && handlePick( t.id ) }
						disabled={ t.locked }
						title={ t.locked ? __( 'Coming soon', 'neural-forms-lite' ) : t.hint || '' }
					>
						<span className="nf-type-chooser__tile-label">{ t.label }</span>
						{ t.pro && <span className="nf-type-chooser__tile-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span> }
						{ t.locked && ! t.pro && <span className="nf-type-chooser__tile-badge">{ __( 'Soon', 'neural-forms-lite' ) }</span> }
					</button>
				) ) }
			</div>
		</div>
	);
}
