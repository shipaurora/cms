/**
 * Drag and drop in the media library: images and folders dropped onto folder tiles or
 * breadcrumbs are moved there (ListAssets::moveAssetsToFolder / moveFolderToFolder).
 *
 * Moving things around the library uses pointer events and a ghost of our own rather than
 * the browser's drag and drop, which can't show a reliable preview of a card and makes
 * Safari put a "0" badge on the cursor. Press an image card (or a folder tile) and move a
 * few pixels to pick it up; a selected card carries the whole selection, including
 * "select all", which Filament's table tracks as the records that were deselected.
 *
 * Image files dragged in from the desktop do use native drag and drop (it is the only way
 * to receive files). They upload into the open folder, or into the folder tile or
 * breadcrumb they are dropped on, each with its own progress bar, and are stored by
 * ListAssets::storeDroppedUpload() when they arrive.
 */
const STACK_LIMIT = 5
const STACK_STEP = 10
const GHOST_PADDING = 16
const DRAG_THRESHOLD = 5
const EDGE_SCROLL = 60
const PARALLEL_UPLOADS = 3
const MAX_IMAGE_BYTES = 10 * 1024 * 1024
const MAX_FILE_BYTES = 50 * 1024 * 1024

function register(Alpine) {
    Alpine.data('cmsMediaDrag', ({ canUpload = false, strings = {} } = {}) => ({
        strings,
        dragging: null,
        over: undefined,
        overName: null,
        canUpload,
        fileDepth: 0,
        uploads: [],
        dismissTimer: null,
        pending: null,
        ghost: null,
        dimmed: [],
        justDragged: false,

        /**
         * A translated string from the view, with :placeholders filled in.
         */
        t(key, replacements = {}) {
            return Object.entries(replacements).reduce(
                (text, [name, value]) => text.replaceAll(`:${name}`, value),
                this.strings[key] ?? key,
            )
        },

        init() {
            const move = (event) => this.pointerMove(event)
            const up = (event) => this.pointerUp(event)
            const key = (event) => event.key === 'Escape' && this.cancel()
            // A drag ends with a click on the card's link; swallow it so the card doesn't open.
            const click = (event) => {
                if (this.justDragged) {
                    event.preventDefault()
                    event.stopPropagation()
                    this.justDragged = false
                }
            }

            window.addEventListener('pointermove', move)
            window.addEventListener('pointerup', up)
            window.addEventListener('keydown', key)
            window.addEventListener('click', click, true)

            this.destroy = () => {
                window.removeEventListener('pointermove', move)
                window.removeEventListener('pointerup', up)
                window.removeEventListener('keydown', key)
                window.removeEventListener('click', click, true)
                this.cancel()
            }
        },

        record(element) {
            return element?.closest?.('.cms-asset-record')
        },

        keyOf(record) {
            return (record.getAttribute('wire:key') ?? '').split('.table.records.').pop()
        },

        table() {
            const element = document.querySelector('[x-data^=filamentTable]')

            return element ? Alpine.$data(element) : null
        },

        /* ── Moving images and folders ──────────────────── */

        pointerDown(event) {
            if (event.button !== 0 || event.pointerType === 'touch' || event.target.closest('input, button, label')) {
                return
            }

            const tile = event.target.closest('[data-cms-folder]')
            const record = tile ? null : this.record(event.target)

            if (! tile && ! record) {
                return
            }

            // Stop the browser's own drag of the card's link and image.
            ;(tile ?? record).querySelectorAll('a, img').forEach((element) => element.setAttribute('draggable', 'false'))

            this.pending = { tile, record, x: event.clientX, y: event.clientY }
        },

        pointerMove(event) {
            if (this.pending && ! this.dragging) {
                if (Math.hypot(event.clientX - this.pending.x, event.clientY - this.pending.y) < DRAG_THRESHOLD) {
                    return
                }

                this.begin(event)
            }

            if (! this.dragging) {
                return
            }

            event.preventDefault()
            this.moveGhost(event)
            this.hitTest(event)
            this.edgeScroll(event)
        },

        pointerUp() {
            const target = this.over
            const payload = this.dragging

            this.pending = null

            if (! payload) {
                return
            }

            this.justDragged = true
            setTimeout(() => (this.justDragged = false), 50)
            this.cancel()

            if (target === undefined) {
                return
            }

            payload.folder
                ? this.$wire.moveFolderToFolder(payload.folder, target)
                : this.$wire.moveAssetsToFolder(payload.ids, target, payload.all, payload.except)
        },

        begin(event) {
            const { tile, record } = this.pending
            const source = tile ?? record
            const rect = source.getBoundingClientRect()

            if (tile) {
                this.dragging = { folder: tile.dataset.cmsFolder, count: 1 }
                this.ghost = this.stack([tile], rect, 1)
                this.dimmed = [tile]
            } else {
                const id = this.keyOf(record)
                const table = this.table()
                const selected = table && table.isRecordSelected(id)

                this.dragging = selected
                    ? (table.isTrackingDeselectedRecords
                        ? { ids: [], all: true, except: [...table.deselectedRecords], count: table.getSelectedRecordsCount() }
                        : { ids: [...table.selectedRecords], all: false, except: [], count: table.selectedRecords.size })
                    : { ids: [id], all: false, except: [], count: 1 }

                const others = selected
                    ? [...document.querySelectorAll('.cms-asset-record')].filter((other) => other !== record && table.isRecordSelected(this.keyOf(other)))
                    : []

                this.ghost = this.stack([record, ...others].slice(0, STACK_LIMIT), rect, this.dragging.count)
                this.dimmed = [record, ...others]
            }

            this.ghost.offsetX = this.pending.x - rect.left + GHOST_PADDING
            this.ghost.offsetY = this.pending.y - rect.top + GHOST_PADDING

            // Inside the grid the copies keep the cards' styling; rows need the table around them.
            ;(source.tagName === 'TR' ? (source.closest('.fi-ta') ?? document.body) : source.parentElement).appendChild(this.ghost)

            this.dimmed.forEach((element) => {
                element.style.transition = 'opacity 150ms'
                element.style.opacity = '0.4'
            })
            document.documentElement.style.userSelect = 'none'
            document.documentElement.style.cursor = 'grabbing'
            window.getSelection()?.removeAllRanges()

            this.moveGhost(event)
        },

        moveGhost(event) {
            if (! this.ghost) {
                return
            }

            this.ghost.style.transform = `translate(${event.clientX - this.ghost.offsetX}px, ${event.clientY - this.ghost.offsetY}px)`
        },

        hitTest(event) {
            const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-drop-folder]')
            const folder = target ? (target.dataset.dropFolder || null) : undefined

            if (target && this.dragging.folder === folder) {
                this.over = undefined
                this.overName = null

                return
            }

            this.over = folder
            this.overName = target?.dataset.dropName ?? null
        },

        edgeScroll(event) {
            if (event.clientY < EDGE_SCROLL) {
                window.scrollBy(0, -12)
            } else if (event.clientY > window.innerHeight - EDGE_SCROLL) {
                window.scrollBy(0, 12)
            }
        },

        cancel() {
            this.ghost?.remove()
            this.ghost = null
            this.dimmed.forEach((element) => (element.style.opacity = ''))
            this.dimmed = []
            this.pending = null
            document.documentElement.style.userSelect = ''
            document.documentElement.style.cursor = ''
            this.end()
        },

        /**
         * The ghost that follows the pointer: a copy of what is being dragged, with any other
         * selected cards fanned out behind it and a count.
         */
        stack(records, rect, count) {
            const depth = (records.length - 1) * STACK_STEP
            const ghost = document.createElement('div')

            ghost.setAttribute('x-ignore', '')
            ghost.setAttribute('aria-hidden', 'true')
            Object.assign(ghost.style, {
                position: 'fixed',
                top: '0',
                left: '0',
                zIndex: '60',
                willChange: 'transform',
                width: `${rect.width + depth + GHOST_PADDING * 2}px`,
                height: `${rect.height + depth + GHOST_PADDING * 2}px`,
                pointerEvents: 'none',
                // See-through, like the drag images the operating system draws.
                opacity: '0.5',
            })

            records.forEach((record, index) => {
                let copy = record.cloneNode(true)

                // In the list layout records are table rows, which need a table to lay out.
                if (record.tagName === 'TR') {
                    const table = document.createElement('table')
                    table.className = record.closest('table')?.className ?? ''
                    table.classList.add('bg-white', 'dark:bg-gray-900')
                    table.appendChild(document.createElement('tbody')).appendChild(copy)
                    copy = table
                }

                ;[copy, ...copy.querySelectorAll('[wire\\:key]')].forEach((element) => element.removeAttribute('wire:key'))
                copy.querySelectorAll('input[type=checkbox]').forEach((checkbox) => (checkbox.checked = true))
                this.paintImages(record, copy)

                Object.assign(copy.style, {
                    backgroundColor: this.backgroundOf(record),
                    borderRadius: getComputedStyle(record).borderRadius,
                    position: 'absolute',
                    top: `${GHOST_PADDING + index * STACK_STEP}px`,
                    left: `${GHOST_PADDING + index * STACK_STEP}px`,
                    width: `${rect.width}px`,
                    height: `${rect.height}px`,
                    margin: '0',
                    overflow: 'hidden',
                    zIndex: `${records.length - index}`,
                    transform: index === 0 ? 'none' : `rotate(${index % 2 ? 2.5 : -2.5}deg)`,
                    boxShadow: '0 12px 28px -6px rgb(0 0 0 / 0.3)',
                })

                ghost.appendChild(copy)
            })

            if (count < 2) {
                return ghost
            }

            const badge = document.createElement('span')

            badge.textContent = this.t(count === 1 ? 'files_one' : 'files_many', { count })
            badge.className = 'rounded-full bg-primary-600 px-3 py-1 text-sm font-semibold text-white shadow-lg'
            Object.assign(badge.style, {
                position: 'absolute',
                top: `${GHOST_PADDING / 2}px`,
                left: `${GHOST_PADDING + rect.width - 24}px`,
                transform: 'translateX(-100%)',
                zIndex: `${records.length + 1}`,
                whiteSpace: 'nowrap',
            })
            ghost.appendChild(badge)

            return ghost
        },

        /**
         * Copied <img> elements haven't decoded when the browser snapshots the ghost, so they
         * come out blank. A canvas painted from the already loaded original shows at once.
         */
        paintImages(record, copy) {
            const originals = [...record.querySelectorAll('img')]

            copy.querySelectorAll('img').forEach((image, index) => {
                const original = originals[index]

                if (! original?.complete || ! original.naturalWidth) {
                    return
                }

                const box = original.getBoundingClientRect()
                const scale = window.devicePixelRatio || 1
                const canvas = document.createElement('canvas')

                canvas.width = Math.round(box.width * scale)
                canvas.height = Math.round(box.height * scale)
                canvas.className = original.className
                canvas.style.cssText = original.style.cssText
                canvas.style.width = `${box.width}px`
                canvas.style.height = `${box.height}px`

                // Like object-fit: cover.
                const ratio = Math.max(canvas.width / original.naturalWidth, canvas.height / original.naturalHeight)
                const width = original.naturalWidth * ratio
                const height = original.naturalHeight * ratio

                canvas.getContext('2d').drawImage(original, (canvas.width - width) / 2, (canvas.height - height) / 2, width, height)
                image.replaceWith(canvas)
            })
        },

        /**
         * The card's own background, or the first opaque one behind it.
         */
        backgroundOf(element) {
            for (let current = element; current; current = current.parentElement) {
                const color = getComputedStyle(current).backgroundColor

                if (color && color !== 'transparent' && ! /rgba\(.*,\s*0\)$/.test(color)) {
                    return color
                }
            }

            return document.documentElement.classList.contains('dark') ? '#18181b' : '#ffffff'
        },

        end() {
            this.dragging = null
            this.over = undefined
            this.overName = null
        },

        /* ── Files from the desktop ─────────────────────── */

        /**
         * Highlights the folder tile or breadcrumb files are dragged over.
         */
        enter(event, folder) {
            if (! this.isFileDrag(event) || ! this.canUpload) {
                return
            }

            event.preventDefault()
            event.dataTransfer.dropEffect = 'copy'
            this.over = folder
            this.overName = event.currentTarget.dataset.dropName ?? null
        },

        leave(event, folder) {
            if (this.over === folder && ! event.currentTarget.contains(event.relatedTarget)) {
                this.over = undefined
                this.overName = null
            }
        },

        isFileDrag(event) {
            return ! this.dragging && [...(event.dataTransfer?.types ?? [])].includes('Files')
        },

        get isDraggingFiles() {
            return this.fileDepth > 0
        },

        get dropLabel() {
            return this.t('drop_to', { folder: this.overName ?? this.$root.dataset.folderName })
        },

        fileEnter(event) {
            if (this.isFileDrag(event)) {
                this.fileDepth++
            }
        },

        fileLeave(event) {
            if (this.isFileDrag(event)) {
                this.fileDepth = Math.max(0, this.fileDepth - 1)
            }
        },

        fileOver(event) {
            if (! this.isFileDrag(event)) {
                return
            }

            // Without this the browser would open the image in place of the admin.
            event.preventDefault()
            event.dataTransfer.dropEffect = this.canUpload ? 'copy' : 'none'
        },

        fileDrop(event) {
            if (! this.isFileDrag(event)) {
                return
            }

            event.preventDefault()

            const folder = this.over !== undefined ? this.over : (this.$wire.folder ?? null)

            this.fileDepth = 0
            this.end()

            if (this.canUpload) {
                this.queue([...event.dataTransfer.files], folder)
            }
        },

        queue(files, folder) {
            clearTimeout(this.dismissTimer)

            for (const file of files) {
                // The type is checked on the server, which knows every type it takes.
                const isImage = file.type.startsWith('image/')
                const error = file.size > (isImage ? MAX_IMAGE_BYTES : MAX_FILE_BYTES)
                    ? this.t(isImage ? 'too_large_image' : 'too_large_file')
                    : null

                this.uploads.push({
                    key: 'u' + Math.random().toString(36).slice(2, 12),
                    file,
                    folder,
                    name: file.name,
                    preview: error || ! file.type.startsWith('image/') ? null : URL.createObjectURL(file),
                    progress: 0,
                    status: error ? 'failed' : 'queued',
                    error,
                    id: null,
                })
            }

            this.next()
        },

        get activeUploads() {
            return this.uploads.filter((upload) => ['queued', 'uploading', 'saving'].includes(upload.status))
        },

        get uploadHeading() {
            const done = this.uploads.filter((upload) => upload.status === 'done').length
            const failed = this.uploads.filter((upload) => upload.status === 'failed').length
            const total = this.uploads.length

            if (this.activeUploads.length) {
                return this.t('uploading', { current: total - this.activeUploads.length + 1, total })
            }

            return failed
                ? this.t('uploaded_failed', { done, failed })
                : this.t(done === 1 ? 'uploaded_one' : 'uploaded_many', { count: done })
        },

        next() {
            const running = this.uploads.filter((upload) => ['uploading', 'saving'].includes(upload.status)).length

            this.uploads
                .filter((upload) => upload.status === 'queued')
                .slice(0, Math.max(0, PARALLEL_UPLOADS - running))
                .forEach((upload) => this.send(upload))

            if (this.uploads.length && ! this.activeUploads.length && ! this.uploads.some((upload) => upload.status === 'failed')) {
                this.dismissTimer = setTimeout(() => this.clearUploads(), 5000)
            }
        },

        send(upload) {
            upload.status = 'uploading'

            this.$wire.upload(
                'dropped.' + upload.key,
                upload.file,
                async () => {
                    upload.progress = 100
                    upload.status = 'saving'

                    const result = await this.$wire.storeDroppedUpload(upload.key, upload.folder)

                    upload.status = result?.error ? 'failed' : 'done'
                    upload.error = result?.error ?? null
                    upload.id = result?.id ?? null
                    this.next()
                },
                () => {
                    upload.status = 'failed'
                    upload.error = upload.error ?? this.t('failed')
                    this.next()
                },
                (event) => {
                    upload.progress = event.detail.progress
                },
            )
        },

        clearUploads() {
            if (this.activeUploads.length) {
                return
            }

            this.uploads.forEach((upload) => upload.preview && URL.revokeObjectURL(upload.preview))
            this.uploads = []
        },
    }))
}

window.Alpine ? register(window.Alpine) : document.addEventListener('alpine:init', () => register(window.Alpine))
