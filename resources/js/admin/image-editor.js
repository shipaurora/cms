/**
 * The per-block image editor: Cropper.js (the same library Filament's own upload editor
 * uses) for crop, rotation and flips, plus CSS filters to preview exposure and contrast.
 *
 * The state is what gets stored on the block: the crop as fractions of the rotated
 * original, so the server can reproduce it exactly at any size (Aurora\Support\Renditions).
 */
import Cropper from 'cropperjs'
import 'cropperjs/dist/cropper.css'

const ASPECTS = [
    { label: 'Free', value: null },
    { label: '16:9', value: '16/9' },
    { label: '4:3', value: '4/3' },
    { label: '1:1', value: '1/1' },
    { label: '3:4', value: '3/4' },
    { label: '9:16', value: '9/16' },
    { label: '21:9', value: '21/9' },
    { label: '3:2', value: '3/2' },
]

const ratio = (aspect) => {
    if (! aspect) {
        return NaN
    }

    const [w, h] = aspect.split('/').map(Number)

    return w / h
}

function register(Alpine) {
    Alpine.data('cmsImageEditor', ({ state, src }) => ({
        state,
        src,
        cropper: null,
        timer: null,
        start: null,
        aspects: ASPECTS,

        init() {
            this.state = { exposure: 0, contrast: 0, grayscale: false, ...(this.state ?? {}) }

            // The modal animates in: wait until the image has a size before measuring it.
            this.start = () => (this.timer = setTimeout(() => this.setUp(), 150))
            this.$refs.image.complete ? this.start() : this.$refs.image.addEventListener('load', this.start, { once: true })
        },

        destroy() {
            // Closed before it was set up: don't build a cropper on a detached image.
            clearTimeout(this.timer)
            this.$refs.image?.removeEventListener('load', this.start)
            this.cropper?.destroy()
            this.cropper = null
        },

        setUp() {
            const saved = this.state ?? {}

            this.cropper = new Cropper(this.$refs.image, {
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                background: false,
                zoomable: false,
                movable: false,
                aspectRatio: ratio(saved.aspect),
                ready: () => {
                    if (saved.rotate) {
                        this.cropper.rotateTo(saved.rotate)
                    }

                    this.cropper.scale(saved.flip_x ? -1 : 1, saved.flip_y ? -1 : 1)

                    if (saved.crop) {
                        const canvas = this.cropper.getCanvasData()

                        this.cropper.setData({
                            x: saved.crop.x * canvas.naturalWidth,
                            y: saved.crop.y * canvas.naturalHeight,
                            width: saved.crop.width * canvas.naturalWidth,
                            height: saved.crop.height * canvas.naturalHeight,
                        })
                    }

                    this.sync()
                },
                cropend: () => this.sync(),
            })
        },

        sync() {
            if (! this.cropper) {
                return
            }

            const data = this.cropper.getData()
            const canvas = this.cropper.getCanvasData()

            this.state = {
                ...this.state,
                crop: {
                    x: +(data.x / canvas.naturalWidth).toFixed(5),
                    y: +(data.y / canvas.naturalHeight).toFixed(5),
                    width: +(data.width / canvas.naturalWidth).toFixed(5),
                    height: +(data.height / canvas.naturalHeight).toFixed(5),
                },
                rotate: ((Math.round(data.rotate) % 360) + 360) % 360,
                flip_x: data.scaleX < 0,
                flip_y: data.scaleY < 0,
            }
        },

        setAspect(aspect) {
            this.state = { ...this.state, aspect }
            this.cropper?.setAspectRatio(ratio(aspect))
            this.sync()
        },

        rotate(degrees) {
            this.cropper?.rotate(degrees)
            this.sync()
        },

        flip(axis) {
            const data = this.cropper?.getData()

            if (! data) {
                return
            }

            axis === 'x' ? this.cropper.scaleX(-data.scaleX) : this.cropper.scaleY(-data.scaleY)
            this.sync()
        },

        reset() {
            this.state = { ...this.state, aspect: null, crop: null, rotate: 0, flip_x: false, flip_y: false, exposure: 0, contrast: 0, grayscale: false }
            this.cropper?.reset()
            this.cropper?.setAspectRatio(NaN)
            this.sync()
        },

        signed(value) {
            const number = Number(value ?? 0)

            return number > 0 ? `+${number}` : `${number}`
        },

        /**
         * A close preview of the server's brightness, contrast and greyscale filters.
         */
        get filterStyle() {
            const exposure = 1 + Number(this.state?.exposure ?? 0) / 100
            const contrast = 1 + Number(this.state?.contrast ?? 0) / 100

            return `--editor-filter: brightness(${exposure}) contrast(${contrast}) grayscale(${this.state?.grayscale ? 1 : 0})`
        },
    }))
}

window.Alpine ? register(window.Alpine) : document.addEventListener('alpine:init', () => register(window.Alpine))
