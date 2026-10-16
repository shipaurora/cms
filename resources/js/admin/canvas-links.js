/**
 * Links on the visual editor's canvas don't go anywhere: clicking one selects it (or the
 * block it's in) for editing, as anything else on the canvas does. Only the browser's own
 * following of the link is stopped, never the click, so the editor still hears it; forms on
 * the canvas aren't sent either. The preview (its own frame) still follows links.
 */
const onCanvas = (target) => target?.closest?.('.fpb-canvas')

document.addEventListener('click', (event) => {
    if (onCanvas(event.target) && event.target.closest('a[href]')) {
        event.preventDefault()
    }
}, true)

// Middle-click and ⌘/Ctrl-click would open it in a new tab instead.
document.addEventListener('auxclick', (event) => {
    if (onCanvas(event.target) && event.target.closest('a[href]')) {
        event.preventDefault()
    }
}, true)

document.addEventListener('submit', (event) => {
    if (onCanvas(event.target)) {
        event.preventDefault()
    }
}, true)
