/**
 * Whether the sidebar is collapsed belongs to the person, not the browser: when they open
 * or collapse it on a desktop-sized screen, it is saved on their account. The page applies
 * the saved state before Filament reads it (see the HEAD_START hook in AdminPanelProvider).
 */
function rememberSidebar() {
    const url = document.querySelector('meta[name="cms-sidebar-url"]')?.content
    const sidebar = window.Alpine?.store('sidebar')

    if (! url || ! sidebar) {
        return
    }

    let saved = sidebar.isOpenDesktop

    window.Alpine.effect(() => {
        const open = sidebar.isOpenDesktop

        // Only real changes, and only on a desktop-sized screen: phones close the sidebar
        // by themselves, which says nothing about what the person prefers.
        if (open === saved || window.innerWidth < 1024) {
            return
        }

        saved = open

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ collapsed: ! open }),
        }).catch(() => {})
    })
}

// Alpine may already be running by the time this module loads.
if (window.Alpine?.store?.('sidebar')) {
    rememberSidebar()
} else {
    document.addEventListener('alpine:initialized', rememberSidebar)
}
