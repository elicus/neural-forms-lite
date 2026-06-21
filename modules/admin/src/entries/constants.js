/**
 * Static lookup tables for the Entries inbox.
 *
 * Pulled out to keep EntriesScreen.js focused on data flow. Edit one
 * place to add/rename a sidebar item or capability gate.
 *
 * Polls/Quizzes smart folders intentionally absent here — Phase 5 of
 * refactor/remove-protected-admin-bundle drops them from the source
 * Entries UI to match the rich UI's taxonomy. Re-add only via a
 * separate scoped task with a paired Pro release.
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

// AI response carousel kinds — match AIResponseRepository constants.
export const KIND_ANALYZE    = 'analyze';
export const KIND_REANALYZE  = 'reanalyze';
export const KIND_REGENERATE = 'regenerate';
export const KIND_POLISH     = 'polish';
