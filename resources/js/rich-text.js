/**
 * The canvas's rich text editor (Editable::richText()), its own script so the admin's other
 * pages don't carry it: loaded by admin/canvas-rich-text.js the first time it's needed.
 *
 * Formatting, links, headings, lists and quotes only, the same set the server keeps
 * (Aurora\Support\InlineRichText): no images, tables or code. The editor starts from the
 * stored text as a document, not from what the block drew (its shortcodes expanded), and
 * sends its document back when the caret leaves; Escape puts the text back as it was.
 */
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Placeholder } from '@tiptap/extensions'
import styles from './rich-text.css?inline'

const BUTTONS = [
    { name: 'bold', label: 'B', run: (chain) => chain.toggleBold() },
    { name: 'italic', label: 'I', run: (chain) => chain.toggleItalic() },
    { name: 'underline', label: 'U', run: (chain) => chain.toggleUnderline() },
    { name: 'strike', label: 'S', run: (chain) => chain.toggleStrike() },
    { name: 'link', label: '↗', link: true },
    null,
    { name: 'heading', attrs: { level: 2 }, label: 'H2', run: (chain) => chain.toggleHeading({ level: 2 }) },
    { name: 'heading', attrs: { level: 3 }, label: 'H3', run: (chain) => chain.toggleHeading({ level: 3 }) },
    { name: 'bulletList', label: '•', run: (chain) => chain.toggleBulletList() },
    { name: 'orderedList', label: '1.', run: (chain) => chain.toggleOrderedList() },
    { name: 'blockquote', label: '”', run: (chain) => chain.toggleBlockquote() },
]

let stylesAdded = false

// The fields being edited, or opening: a click while the document is on its way isn't another editor.
const editing = new WeakSet()

function addStyles() {
    if (stylesAdded) {
        return
    }

    const style = document.createElement('style')
    style.dataset.cmsRichText = ''
    style.textContent = styles
    // First in the head: the admin theme's own rules for these classes win.
    document.head.prepend(style)
    stylesAdded = true
}

function wireFor(el) {
    const id = el.closest('[wire\\:id]')?.getAttribute('wire:id')

    return id ? window.Livewire.find(id) : null
}

/**
 * Put an editor on a rich text field of the canvas. Resolves once it's editing, or not at
 * all when the text has more than this editor knows (it's then edited in the inspector).
 */
export async function edit(el, { strings }, at = null) {
    const wire = wireFor(el)
    const { fpbBlock: block, fpbField: field } = el.dataset

    if (!wire || editing.has(el)) {
        return
    }

    editing.add(el)

    // Meanwhile the block selects itself and the canvas is drawn again.
    const { editable, document: content } = await wire.richTextDocument(block, field)

    if (!editable) {
        editing.delete(el)
        el.title = strings.locked

        return
    }

    el.dataset.cmsRichActive = ''
    // The package's canvas script ends an edit on Enter unless the field is multi-line.
    el.dataset.fpbMultiline = 'true'

    addStyles()

    const drawn = el.innerHTML
    const toolbar = document.createElement('div')
    let prompting = false
    let done = false

    el.replaceChildren()

    const editor = new Editor({
        element: el,
        content,
        extensions: [
            StarterKit.configure({
                code: false,
                codeBlock: false,
                horizontalRule: false,
                dropcursor: false,
                gapcursor: false,
                trailingNode: false,
                heading: { levels: [2, 3] },
                // Links as they're written: no new tab or nofollow unless the link says so.
                link: { openOnClick: false, autolink: true, HTMLAttributes: { target: null, rel: null } },
            }),
            Placeholder.configure({ placeholder: el.dataset.fpbPlaceholder || strings.placeholder }),
        ],
        editorProps: { attributes: { class: 'cms-rich-text-editor' } },
    })

    const original = JSON.stringify(editor.getJSON())

    // Back to how the block drew it: saved, the server draws it again.
    const finish = (save) => {
        if (done) {
            return
        }

        done = true

        const changed = save && JSON.stringify(editor.getJSON()) !== original
        const html = changed ? editor.getHTML() : drawn

        if (changed) {
            wire.setBlockField(block, field, editor.getJSON())
        }

        editor.destroy()
        toolbar.remove()
        window.removeEventListener('scroll', place, true)
        window.removeEventListener('resize', place)
        el.innerHTML = html
        document.removeEventListener('keydown', onEscape, true)
        el.removeEventListener('focusin', stop)
        el.removeEventListener('focusout', stop)
        delete el.dataset.cmsRichActive
        delete el.dataset.fpbMultiline
        editing.delete(el)
    }

    // The package's canvas script commits [data-fpb-field] elements as plain text: this one isn't.
    const stop = (event) => event.stopPropagation()

    // Before the package's canvas script, which would put the text back as plain text.
    const onEscape = (event) => {
        if (event.key === 'Escape' && el.contains(event.target)) {
            event.preventDefault()
            event.stopPropagation()
            finish(false)
        }
    }

    document.addEventListener('keydown', onEscape, true)
    el.addEventListener('focusin', stop)
    el.addEventListener('focusout', stop)

    editor.on('blur', () => {
        if (!prompting) {
            finish(true)
        }
    })

    /* ── The toolbar, above the text ── */

    toolbar.className = 'cms-rich-text-toolbar'
    toolbar.setAttribute('role', 'toolbar')
    toolbar.setAttribute('aria-label', strings.toolbar)

    const link = () => {
        prompting = true
        const url = window.prompt(strings.linkPrompt, editor.getAttributes('link').href ?? '')
        prompting = false

        if (url === null) {
            return editor.commands.focus()
        }

        url.trim() === ''
            ? editor.chain().focus().extendMarkRange('link').unsetLink().run()
            : editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run()
    }

    const buttons = BUTTONS.map((button) => {
        if (button === null) {
            const divider = document.createElement('span')
            divider.className = 'cms-rich-text-divider'
            toolbar.append(divider)

            return null
        }

        const node = document.createElement('button')
        node.type = 'button'
        node.className = 'cms-rich-text-button'
        node.textContent = button.label
        node.title = strings[button.attrs ? `${button.name}${button.attrs.level}` : button.name]
        node.setAttribute('aria-label', node.title)
        // Keeps the caret in the text.
        node.addEventListener('mousedown', (event) => event.preventDefault())
        node.addEventListener('click', () => (button.link ? link() : button.run(editor.chain().focus()).run()))
        toolbar.append(node)

        return { button, node }
    }).filter(Boolean)

    const update = () => {
        for (const { button, node } of buttons) {
            const active = editor.isActive(button.name, button.attrs ?? {})
            node.classList.toggle('cms-rich-text-button-active', active)
            node.setAttribute('aria-pressed', active ? 'true' : 'false')
        }
    }

    function place() {
        const box = el.getBoundingClientRect()
        const height = toolbar.offsetHeight
        const top = box.top - height - 8 >= 8 ? box.top - height - 8 : box.bottom + 8

        toolbar.style.top = `${Math.round(top)}px`
        toolbar.style.left = `${Math.round(Math.max(8, Math.min(box.left, window.innerWidth - toolbar.offsetWidth - 8)))}px`
    }

    document.body.append(toolbar)
    editor.on('transaction', () => {
        update()
        place()
    })
    window.addEventListener('scroll', place, true)
    window.addEventListener('resize', place)
    update()
    place()

    // The caret where the click was, else at the end.
    const position = at ? editor.view.posAtCoords({ left: at.x, top: at.y })?.pos : null
    position != null ? editor.commands.focus(position) : editor.commands.focus('end')
}
