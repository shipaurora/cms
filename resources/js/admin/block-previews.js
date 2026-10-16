/**
 * The visual editor's block list: pointing at a block (or tabbing to it) also shows its
 * picture larger, beside the list. The picture is the one in the block's card (Aurora's copy
 * of the editor view, from Aurora\Blocks\Blocks::preview()); blocks without one show none.
 */
let card = null

const hide = () => card?.classList.remove('cms-block-preview-shown')

const show = (item) => {
    const src = item.querySelector('.cms-palette-preview')?.getAttribute('src')

    if (! src) {
        return hide()
    }

    if (! card) {
        card = document.createElement('div')
        card.className = 'cms-block-preview'
        card.setAttribute('aria-hidden', 'true')
        card.innerHTML = '<img alt="">'
        document.body.appendChild(card)
    }

    card.querySelector('img').src = src

    // Beside the list, level with the block, kept on screen.
    const rect = item.getBoundingClientRect()
    const height = 220
    card.style.left = `${rect.right + 12}px`
    card.style.top = `${Math.max(12, Math.min(rect.top - 8, window.innerHeight - height - 12))}px`
    card.classList.add('cms-block-preview-shown')
}

const itemIn = (target) => target?.closest?.('.fpb-palette .fpb-palette-item[data-type]')

document.addEventListener('mouseover', (event) => (itemIn(event.target) ? show(itemIn(event.target)) : hide()))
document.addEventListener('focusin', (event) => (itemIn(event.target) ? show(itemIn(event.target)) : hide()))
document.addEventListener('mouseout', (event) => itemIn(event.target) && ! itemIn(event.relatedTarget) && hide())
// Gone while a block is dragged in, and when the list scrolls away from it.
document.addEventListener('dragstart', hide)
document.addEventListener('scroll', hide, true)
