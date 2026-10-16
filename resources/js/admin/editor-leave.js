/**
 * Unsaved changes in the visual editor are not lost by accident. Links that leave the
 * editor ask first, in Filament's dialog (DesignPage::leaveEditorAction), whether to
 * discard the changes or save them; closing the tab, reloading or the browser's back
 * button get the browser's own "Leave site?" prompt, the only one browsers allow there.
 *
 * The editor marks unsaved changes on its save status (.fpb-status-dirty).
 */
let leaving = false

const editor = () => document.querySelector('.fpb')
const hasUnsavedChanges = () => !leaving && !!document.querySelector('.fpb .fpb-status-dirty')

window.cmsLeaveEditor = (url) => {
    leaving = true
    window.location.href = url
}

window.addEventListener('beforeunload', (event) => {
    if (hasUnsavedChanges()) {
        event.preventDefault()
        event.returnValue = ''
    }
})

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]')

    // Links on the canvas go nowhere (canvas-links.js): clicking one edits it.
    if (! link || ! editor() || ! hasUnsavedChanges() || link.closest('.fpb-canvas')) {
        return
    }

    // Opening something next to the editor is fine: new tabs, downloads, in-page anchors.
    if (link.target === '_blank' || link.hasAttribute('download') || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return
    }

    const url = new URL(link.href, window.location.href)

    if (url.origin !== window.location.origin || (url.pathname === window.location.pathname && url.hash)) {
        return
    }

    event.preventDefault()
    event.stopPropagation()

    const component = window.Livewire?.find(editor().closest('[wire\\:id]')?.getAttribute('wire:id'))

    component ? component.mountAction('leaveEditor', { url: url.href }) : window.cmsLeaveEditor(url.href)
}, true)
