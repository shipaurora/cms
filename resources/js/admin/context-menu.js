/**
 * Right-click menus in the media library.
 *
 * Image cards: "Open" (the file's page), then the table's own bulk actions, for the selected
 * images. A card that isn't selected becomes the selection first (as in a file manager), so
 * the action is for it.
 * The menu's items are copies of Filament's bulk action buttons; choosing one clicks the
 * real button, so the actions, their modals and permissions are exactly the toolbar's.
 *
 * Folder tiles: the folder actions (open, rename, move, delete), from a template the folder
 * browser renders, mounted with that folder as the "folder" argument.
 *
 * Empty space among the files: the header's "More" menu (new folder, rename, move, delete),
 * copied the same way as the bulk actions, so it is the same menu.
 */
let openMenu = null
let opener = null

/**
 * A file the menu selected on its own (right-clicking a file that wasn't selected): shown
 * without its checkbox, and unselected again once the menu, or the action it opened, is done.
 */
let menuSelection = null

function undoMenuSelection() {
    if (!menuSelection) {
        return
    }

    const { table, value, card, container } = menuSelection
    menuSelection = null
    card.classList.remove('cms-asset-record-menu-target')
    container?.classList.remove('cms-menu-selecting')

    if (card.isConnected && table.isRecordSelected(value)) {
        table.deselectAllRecords()
    }
}

function closeMenu({ refocus = false, chosen = false } = {}) {
    // Closed without choosing: the file the menu selected goes back to unselected.
    if (openMenu && !chosen) {
        undoMenuSelection()
    }

    openMenu?.remove()
    openMenu = null

    // Back to where the menu was opened from, when it's closed from the keyboard.
    if (refocus && opener?.isConnected) {
        opener.focus()
    }

    opener = null
}

/**
 * A menu item copied from a rendered Filament dropdown item, without its Alpine and
 * Livewire wiring.
 */
function menuItem(source, onChoose) {
    const item = source.cloneNode(true)

    // Loading spinners only show while Livewire works; without that wiring they'd always show.
    item.querySelectorAll('*').forEach((element) => {
        if (element.classList.contains('fi-loading-indicator') || [...element.attributes].some((attribute) => attribute.name.startsWith('wire:loading') && !attribute.name.includes('.remove'))) {
            element.remove()
        }
    })

    for (const element of [item, ...item.querySelectorAll('*')]) {
        for (const attribute of [...element.attributes]) {
            if (/^(x-|wire:|@|:)/.test(attribute.name)) {
                element.removeAttribute(attribute.name)
            }
        }
    }

    item.removeAttribute('style')
    item.setAttribute('type', 'button')
    item.setAttribute('role', 'menuitem')
    item.addEventListener('click', (event) => {
        event.preventDefault()
        closeMenu({ chosen: true })
        onChoose()
    })

    return item
}

/**
 * Copies of a Filament dropdown's items, with 'divider' where they move to another of its
 * groups, so the menu keeps the dropdown's sections.
 */
function copiesWithDividers(sources, onChoose) {
    const items = []
    let group = null

    for (const source of sources) {
        const list = source.closest('.fi-dropdown-list')

        if (group && list !== group) {
            items.push('divider')
        }

        group = list
        items.push(menuItem(source, () => onChoose(source)))
    }

    return items
}

/**
 * @param {Array<HTMLElement|'divider'>} items  'divider' starts a new section
 */
function showMenu(event, items) {
    closeMenu()

    const panel = document.createElement('div')
    panel.className = 'fi-dropdown-panel cms-context-menu'
    panel.setAttribute('role', 'menu')

    let list = null

    for (const item of items) {
        if (item === 'divider' || !list) {
            list = document.createElement('div')
            list.className = 'fi-dropdown-list'
            panel.append(list)
        }

        if (item !== 'divider') {
            list.append(item)
        }
    }

    document.body.append(panel)

    // At the pointer, kept inside the window. Opened from the keyboard (Shift+F10, the menu
    // key) there is no pointer, so at the element instead.
    const fromKeyboard = event.clientX === 0 && event.clientY === 0
    const target = fromKeyboard ? event.target.getBoundingClientRect() : null
    const x = fromKeyboard ? target.left + 8 : event.clientX
    const y = fromKeyboard ? target.top + 8 : event.clientY
    const { width, height } = panel.getBoundingClientRect()
    panel.style.left = `${Math.max(8, Math.min(x, window.innerWidth - width - 8))}px`
    panel.style.top = `${Math.max(8, Math.min(y, window.innerHeight - height - 8))}px`

    opener = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null
    openMenu = panel
    panel.addEventListener('keydown', navigate)
    panel.querySelector('[role="menuitem"]')?.focus()
}

/**
 * Arrow keys, Home and End move between the items; Tab leaves the menu.
 */
function navigate(event) {
    const items = [...openMenu.querySelectorAll('[role="menuitem"]')]
    const index = items.indexOf(document.activeElement)
    const next = {
        ArrowDown: (index + 1) % items.length,
        ArrowUp: (index - 1 + items.length) % items.length,
        Home: 0,
        End: items.length - 1,
    }[event.key]

    if (next !== undefined) {
        event.preventDefault()
        items[next]?.focus()
    } else if (event.key === 'Tab') {
        event.preventDefault()
        closeMenu({ refocus: true })
    }
}

function imageMenu(event, card) {
    // "Open" first: the file's page (to view, or to edit for those who may), as clicking it.
    const link = card.querySelector('a[href]')
    const openSource = card.closest('[wire\\:id]')?.querySelector('template[data-cms-file-menu]')?.content.querySelector('[data-file-action="open"]')
    const open = link && openSource ? [menuItem(openSource, () => link.click())] : []

    const checkbox = card.querySelector('.fi-ta-record-checkbox')

    // No checkbox: no bulk actions for this person (a file they can only read), just "Open".
    const actions = checkbox
        ? [...card.closest('.fi-ta-ctn').querySelectorAll('.fi-dropdown-list-item')].filter((button) =>
            (button.getAttribute('x-on:click') ?? '').includes('bulk'),
        )
        : []

    if (!actions.length) {
        if (!open.length) {
            return false
        }

        showMenu(event, open)

        return true
    }

    const table = window.Alpine.$data(checkbox)

    if (!table.isRecordSelected(checkbox.value)) {
        // The menu's own selection: no ticked checkbox, select-all state or bulk actions button
        // appearing, just the card marked.
        const container = card.closest('.fi-ta-ctn')

        table.deselectAllRecords()
        table.selectRecords([checkbox.value])
        menuSelection = { table, value: checkbox.value, card, container }
        card.classList.add('cms-asset-record-menu-target')
        container?.classList.add('cms-menu-selecting')
    }

    // "Open" is for one file: not when the menu is for several selected ones.
    const one = !table.isTrackingDeselectedRecords && table.selectedRecords.size === 1
    const first = one ? open : []

    showMenu(event, [...first, ...(first.length ? ['divider'] : []), ...copiesWithDividers(actions, (action) => action.click())])

    return true
}

function folderMenu(event, tile) {
    const template = tile.closest('[wire\\:id]')?.querySelector('template[data-cms-folder-menu]')

    if (!template) {
        return false
    }

    const wire = window.Livewire.find(tile.closest('[wire\\:id]').getAttribute('wire:id'))
    const folder = tile.dataset.cmsFolder
    // Only what this person may do with this folder (read-only: just "Open").
    const allowed = (tile.dataset.cmsFolderActions ?? 'open').split(',')

    showMenu(
        event,
        copiesWithDividers([...template.content.querySelectorAll('[data-folder-action]')].filter((source) => allowed.includes(source.dataset.folderAction)), (source) =>
            source.dataset.folderAction === 'open'
                ? wire.openFolder(folder)
                : wire.mountAction(source.dataset.folderAction, { folder }),
        ),
    )

    return true
}

/**
 * Empty space in the media library's files: not a card, a tile, or something with a menu of
 * its own (fields, links, buttons keep the browser's).
 */
function filesMenu(event, area) {
    if (event.target.closest('input, textarea, select, a, button, [contenteditable], .fi-dropdown-panel')) {
        return false
    }

    const actions = [...document.querySelectorAll('[data-cms-files-actions] .fi-dropdown-panel .fi-dropdown-list-item')]

    if (!actions.length) {
        return false
    }

    showMenu(event, copiesWithDividers(actions, (action) => action.click()))

    return true
}

document.addEventListener('contextmenu', (event) => {
    const card = event.target.closest('.cms-asset-record')
    const tile = event.target.closest('[data-cms-folder]')
    const files = ! card && ! tile ? event.target.closest('.cms-media-layout') : null

    if ((card && imageMenu(event, card)) || (tile && folderMenu(event, tile)) || (files && filesMenu(event, files))) {
        event.preventDefault()

        return
    }

    closeMenu()
})

document.addEventListener('pointerdown', (event) => {
    if (openMenu && !openMenu.contains(event.target)) {
        closeMenu()
    }
})

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && openMenu) {
        closeMenu({ refocus: true })
    }
})

window.addEventListener('scroll', () => closeMenu(), true)
window.addEventListener('resize', () => closeMenu())
document.addEventListener('livewire:navigating', () => closeMenu())

// The action chosen from the menu is done (or cancelled): its file goes back to unselected.
window.addEventListener('modal-closed', () => undoMenuSelection())
