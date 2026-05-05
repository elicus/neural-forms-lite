/**
 * Static lookup tables for the Entries inbox.
 *
 * Pulled out to keep EntriesScreen.js focused on data flow. Edit one
 * place to add/rename a sidebar item or capability gate.
 */

export const SYSTEM_VIEWS = [
	{ id: 'inbox',       label: 'Inbox'       },
	{ id: 'unread',      label: 'Unread'      },
	{ id: 'needs_reply', label: 'Needs Reply' },
	{ id: 'starred',     label: 'Starred'     },
	{ id: 'spam',        label: 'Spam'        },
	{ id: 'trash',       label: 'Trash'       },
];

export const SMART_FILTERS = [
	{ id: 'high_priority',  label: 'High Priority'  },
	{ id: 'spam_suspected', label: 'Spam Suspected' },
];

// Smart folders — sit between system views and per-form folders.
// Backend resolves these via ?folder=, NOT ?view=. Always rendered.
export const SMART_FOLDER_IDS = [ 'polls', 'quizzes', 'normal_inbox' ];

export function isSmartFolder( id ) {
	return SMART_FOLDER_IDS.indexOf( id ) !== -1;
}

// AI response carousel kinds — match AIResponseRepository constants.
export const KIND_ANALYZE    = 'analyze';
export const KIND_REANALYZE  = 'reanalyze';
export const KIND_REGENERATE = 'regenerate';
export const KIND_POLISH     = 'polish';
