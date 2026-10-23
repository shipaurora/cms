/**
 * Rich text on the visual editor's canvas (Editable::richText()): clicking it puts an editor
 * on it. The editor is its own script (rich-text.js), fetched the first time it's needed.
 */
document.addEventListener('click', (event) => {
    const el = event.target?.closest?.('.fpb-canvas [data-fpb-kind="richText"]')

    if (!el || el.dataset.cmsRichActive !== undefined || event.target.closest('.cms-rich-text-editor') || !window.cmsRichText) {
        return
    }

    import(window.cmsRichText.src).then(({ edit }) => edit(el, window.cmsRichText, { x: event.clientX, y: event.clientY }))
    // Before the block hears it: it selects itself and stops the click there.
}, true)
