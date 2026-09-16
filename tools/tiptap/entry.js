/*
 * What the admin gets on window.BoxletTipTap. Nothing else is exposed.
 *
 * The schema is exactly the storage whitelist (SPEC §5.3): paragraph, h2-h4, bold,
 * italic, link, bullet and ordered lists, blockquote, hard break, plus undo/redo. Every
 * StarterKit node outside that list is switched off where the editor is built, so the
 * editor cannot produce markup the server would throw away.
 *
 * These are exports, not assignments to window: esbuild's --global-name wraps this as
 * `var BoxletTipTap = (() => { ... })()`, which in a classic script IS the global and is
 * assigned last. A window.BoxletTipTap set inside would be overwritten by it.
 */
export { Editor } from '@tiptap/core';
export { default as StarterKit } from '@tiptap/starter-kit';
export { default as Link } from '@tiptap/extension-link';
