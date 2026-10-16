/**
 * Keeps the screen in step with the in-panel copilot. The copilot changes content through
 * the CMS tools on the server, so a page editor (or menu editor) the user has open would
 * still show, and later save, the old version. This reads a copy of the copilot's stream
 * and, once a reply that changed content has finished, reloads the open editor.
 *
 * Never over unsaved edits: then it says so, with a button to reload when the user is ready.
 * What it needs from the server (the tools that change content, Aurora\Support\CopilotTools,
 * the panel's path and its wording) is in window.cmsCopilot (resources/views/filament/head.blade.php).
 */
const settings = () => window.cmsCopilot ?? { changingTools: [], panelPath: '/admin', strings: {} }

// The editors, under /admin/{site}/…: a page's canvas and form, the pages list, a menu, the media library.
const reloadScreens = () => {
    const panel = settings().panelPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

    return [
        new RegExp(`^${panel}/[^/]+/pages/[^/]+/(design|edit)$`),
        new RegExp(`^${panel}/[^/]+/pages/?$`),
        new RegExp(`^${panel}/[^/]+/menus/[^/]+/edit$`),
        new RegExp(`^${panel}/[^/]+/assets`),
    ]
}

/**
 * Edits not saved yet: a form that differs from what was saved (Filament's own check, as its
 * leave-the-page warning makes it), or the visual editor's "not saved" status.
 */
const hasUnsavedChanges = () => {
    if (document.querySelector('.fpb-status-dirty')) {
        return true
    }

    return (window.Livewire?.all() ?? []).some((component) => {
        const wire = component.$wire

        return typeof wire?.savedDataHash === 'string'
            && window.jsMd5
            && window.jsMd5(JSON.stringify(wire.data).replace(/\\/g, '')) !== wire.savedDataHash
    })
}

const reload = () => (window.Livewire?.navigate ? window.Livewire.navigate(window.location.href) : window.location.reload())

const reloadIfOpen = () => {
    if (! reloadScreens().some((pattern) => pattern.test(window.location.pathname))) {
        return
    }

    if (! hasUnsavedChanges()) {
        reload()

        return
    }

    const { strings } = settings()

    new window.FilamentNotification()
        .title(strings.changed)
        .body(strings.unsaved)
        .warning()
        .persistent()
        .actions([
            new window.FilamentNotificationAction('reload')
                .label(strings.reload)
                .button()
                .close()
                .dispatch('cms-copilot-reload'),
        ])
        .send()
}

window.addEventListener('cms-copilot-reload', reload)

async function watch(response) {
    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''
    let event = null
    let changed = false

    for (;;) {
        const { done, value } = await reader.read()

        if (done) {
            break
        }

        buffer += decoder.decode(value, { stream: true })
        const lines = buffer.split('\n')
        buffer = lines.pop() ?? ''

        for (const line of lines) {
            if (line.startsWith('event: ')) {
                event = line.slice(7).trim()
            } else if (line.startsWith('data: ') && event === 'tool_result') {
                try {
                    const data = JSON.parse(line.slice(6))
                    const failed = data.success === false || /^\s*\{?\s*"?(error|isError)"?/i.test(data.result ?? '')

                    changed ||= settings().changingTools.includes(data.tool_name) && ! failed
                } catch {
                    // Not JSON; ignore.
                }
            }
        }
    }

    if (changed) {
        // Let the chat finish rendering the reply first.
        setTimeout(reloadIfOpen, 600)
    }
}

const nativeFetch = window.fetch.bind(window)

window.fetch = async (input, init) => {
    const response = await nativeFetch(input, init)
    const url = typeof input === 'string' ? input : input?.url ?? ''

    if (url.includes('/copilot/stream') && response.ok && response.body) {
        watch(response.clone()).catch(() => {})
    }

    return response
}

// The command palette's assistant commands (Aurora\Support\PaletteCommands): open the chat,
// and send it the prompt when there is one.
window.addEventListener('cms-assistant-prompt', (event) => {
    window.dispatchEvent(new CustomEvent('copilot-open'))

    const prompt = event.detail?.prompt

    if (prompt) {
        setTimeout(() => window.Livewire?.dispatch('copilot-quick-action', { prompt }), 150)
    }
})

