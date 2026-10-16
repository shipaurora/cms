/**
 * Leaving a form with unsaved changes by a link asks first in Filament's dialog
 * (Aurora\Filament\Concerns\ConfirmsLeaving): save and leave, or discard the changes. A form
 * has changes when its data no longer matches what Filament remembered when it was loaded
 * or saved (savedDataHash), the same check as Filament's own warning. Reloading, closing the
 * tab or the back button still get the browser's prompt: browsers allow no other there.
 */
const changedForm = () => window.Livewire?.all().find(({ $wire }) =>
    $wire.confirmsLeaving === true
        && typeof $wire.savedDataHash === 'string'
        && typeof window.jsMd5 === 'function'
        && window.jsMd5(JSON.stringify($wire.data).replace(/\\/g, '')) !== $wire.savedDataHash,
)

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]')

    // The visual editor asks in its own way (editor-leave.js).
    if (! link || document.querySelector('.fpb')) {
        return
    }

    // Opening something next to the form is fine: new tabs, downloads, in-page anchors.
    if (link.target === '_blank' || link.hasAttribute('download') || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return
    }

    // Links that run an action instead ("Edit visually") ask in that action.
    if ([...link.attributes].some((attribute) => attribute.name.startsWith('wire:click'))) {
        return
    }

    const url = new URL(link.href, window.location.href)

    if (url.origin !== window.location.origin || (url.pathname === window.location.pathname && url.hash)) {
        return
    }

    const form = changedForm()

    if (! form) {
        return
    }

    event.preventDefault()
    event.stopPropagation()

    form.$wire.mountAction('leavePage', { url: url.href })
}, true)
