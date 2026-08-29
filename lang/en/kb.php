<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 10 — Knowledge Base strings (§14: manager UI under kb.*; C-08; N-03)
|------------------------------------------------------------------------------
|
| Opened at S10 step 1 with the tombstone states (FR-16) the resolver yields;
| every later surface adds its keys here and in lang/am/kb.php in the SAME
| commit. Destructive-tier strings (category delete-with-reassign, archive
| confirm, sanitizer override, visibility flip, restore, lock takeover) are
| marked for R-14 as they land.
|
*/

return [
    'tombstone' => [
        'current' => 'This article is current.',
        'retired' => 'This article was updated or removed.',
        'unknown' => 'This article is no longer available.',
        'read_current' => 'Read the current version',
    ],
    'blocks' => [
        'also_asked' => 'Also asked', // FR-06 FAQ variants — the appended block's label (step 9)
    ],
    'notify' => [
        'review_requested' => 'Article submitted for review',
        'changes_requested' => 'Changes requested on your article',
        'lock_taken_over' => 'Someone took over editing your article',
    ],
    'flow' => [ // FR-19/BR-04 (step 11): the bot-menu contract's labels — rendering is Doc 05/12's
        'category_title' => 'Help topics: :name',
        'category_empty' => 'No articles here yet.',
        'category_unknown' => 'This topic is no longer available.',
        'article_summary' => ':summary — read more: :link',
        'start_chat' => 'Chat with us',
    ],
    'manager' => [ // step 12 ([B9]): the staff surfaces — functional-first from DS tokens
        'title' => 'Knowledge Base',
        'categories' => 'Categories',
        'articles' => 'Articles',
        'detail' => 'Article',
        'no_category' => 'Pick a category',
        'no_article' => 'Pick an article',
        'empty_category' => 'No articles in this category yet.',
        'new_article' => 'New article',
        'audience_public' => 'Public',
        'audience_internal' => 'Internal',
        'internal_banner' => 'INTERNAL — never shown to customers, never cited by the AI chatbot.',
        'status_draft' => 'Draft',
        'status_in_review' => 'In review',
        'status_published' => 'Published',
        'status_archived' => 'Archived',
        'ts_current' => 'Current',
        'ts_outdated' => 'Outdated',
        'ts_missing' => 'Missing',
        'source_ai' => 'AI draft',
        'edit' => 'Edit',
        'history' => 'History',
        'editor_title' => 'Editor',
        'block_paragraph' => 'Paragraph',
        'block_image' => 'Image (alt text)',
        'add_paragraph' => 'Add paragraph',
        'save' => 'Save',
        'saved' => 'Saved',
        'summary' => 'Summary',
        'article_title' => 'Title',
        'sanitizer_warnings' => 'Sanitizer flags — the publisher decides: keep needs a reason, or edit the text',
        'override_reason' => 'Keep as-is because…',
        'submit_review' => 'Submit for review',
        'request_changes' => 'Request changes',
        'publish' => 'Publish',
        'publish_confirm' => 'Publish this variant?',
        'publish_audience' => 'Audience',
        'publish_outdated' => 'Publishing this source flips these translations to Outdated:',
        'missing_alt' => 'Images without alt text block a public publish:',
        'missing_media' => 'These image files no longer exist:',
        'unpublish' => 'Unpublish',
        'archive' => 'Archive',
        'ai_drawer' => 'AI tools',
        'ai_simplify' => 'Simplify',
        'ai_faq' => 'FAQ variant',
        'ai_translate_all' => 'AI-translate missing languages (drafts)',
        'ai_note' => 'AI drafts — a human publishes (never automatic).',
        'review_thread' => 'Review comments',
        'comment_placeholder' => 'Comment on this draft…',
        'comment_send' => 'Comment',
        'resolve' => 'Resolve',
        'stats_views' => 'Views',
        'stats_citations' => 'AI citations',
        'stats_inserts' => 'Agent inserts',
        'stats_helpful' => 'Helpful',
        'stats_deflection' => 'Deflection candidates',
        'stats_formula_note' => 'formula: Doc 15',
        'versions_title' => 'Version history',
        'version' => 'Version',
        'restore' => 'Restore as new draft',
        'restored' => 'A new draft working copy was created from this version.',
        'diff' => 'Diff',
        'diff_added' => 'Added',
        'diff_removed' => 'Removed',
        'diff_changed' => 'Changed',
        'loading' => 'Loading…',
        'error' => 'Something went wrong. The data above may be stale.',
        'cite_current' => 'This citation points at the current version.',
        'cite_retired' => 'This exact version was cited; the article has since changed or been retired.',
        'cite_unknown' => 'Nothing is known about this reference.',
        'cite_view_current' => 'Open the current version',
        'lock_held' => 'Someone else is editing — you can take over.',
        'lock_takeover' => 'Take over',
    ],
];
