/**
 * The admin theme belongs to the person, not the browser: whenever it changes (the user
 * menu, Filament's switcher or the command palette all fire `theme-changed`), it is saved
 * on their account. The page applies the saved theme before Filament reads it (see the
 * HEAD_START hook in AdminPanelProvider).
 */
window.addEventListener('theme-changed', (event) => {
    const theme = event.detail
    const url = document.querySelector('meta[name="cms-theme-url"]')?.content

    if (! url || ! ['light', 'dark', 'system'].includes(theme)) {
        return
    }

    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ theme }),
    }).catch(() => {})
})
