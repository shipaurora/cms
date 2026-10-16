/**
 * Global search results that open when clicked, in every browser.
 *
 * Safari (and Firefox on macOS) don't move focus to a link when it is clicked. Pressing
 * a result therefore took focus out of the search field to nowhere, Filament read that
 * as "clicked away" and hid the results between mouse down and mouse up, so the click
 * never reached the link. Keeping focus in the field while a result is pressed lets the
 * click through; keyboard navigation is unaffected.
 */
document.addEventListener(
    'mousedown',
    (event) => {
        if (event.button === 0 && event.target.closest?.('.fi-global-search-results-ctn a, .fi-global-search-result-link')) {
            event.preventDefault()
        }
    },
    true,
)
