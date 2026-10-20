// What a person arranged in the interface (appearance, sidebar, collapsed board columns) lives on the server, so a second
// device shows the same (InterfaceController). The layout seeds the keys Flux keeps in localStorage from it on every page,
// this file reports changes back. Only what changed is sent, so a device that has not seen another one's change does not
// take it back.

let pending = {}
let timer = null
let sending = 0

const flush = () => {
    clearTimeout(timer)
    timer = null

    const url = document.querySelector('meta[name=sprint-interface]')?.content

    if (! url || Object.keys(pending).length === 0) {
        pending = {}

        return
    }

    const changes = pending

    pending = {}

    sending++

    fetch(url, {
        method: 'PATCH',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('script[data-csrf]')?.dataset.csrf ?? '' },
        body: JSON.stringify(changes),
    }).catch(() => {}).finally(() => sending--)
}

// What the server already has (as of the last page) is not sent again; Flux also writes its keys when nothing changed, and a
// device that has not seen another one's change must not take it back by repeating its own old value.
const isNews = (key, value) => JSON.stringify(window.sprintInterface?.[key]) !== JSON.stringify(value)

// Changes within a moment (toggling several columns) travel together; a board's columns are replaced as a whole per board
export const saveInterface = (changes) => {
    const { board_collapsed: boards = {}, ...others } = changes
    const news = Object.fromEntries(Object.entries(others).filter(([key, value]) => isNews(key, value)))
    const newBoards = Object.fromEntries(Object.entries(boards).filter(([project, ids]) => JSON.stringify(window.sprintInterface?.board_collapsed?.[project] ?? []) !== JSON.stringify(ids)))

    if (Object.keys(news).length === 0 && Object.keys(newBoards).length === 0) {
        return
    }

    pending = { ...pending, ...news }

    if (Object.keys(newBoards).length > 0) {
        pending.board_collapsed = { ...pending.board_collapsed, ...newBoards }
    }

    window.sprintInterface = { ...window.sprintInterface, ...news, ...(Object.keys(newBoards).length > 0 && { board_collapsed: { ...window.sprintInterface?.board_collapsed, ...newBoards } }) }
    timer ??= setTimeout(flush, 400)
}

document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && flush())
window.addEventListener('pagehide', flush)
document.addEventListener('livewire:navigate', flush)

// Flux keeps whether the sidebar is collapsed in localStorage itself (the appearance is saved from its menu, see user-menu)
const setItem = Storage.prototype.setItem

Storage.prototype.setItem = function (key, value) {
    setItem.call(this, key, value)

    if (! window.sprintSeeding && this === window.localStorage && key === 'flux-sidebar-collapsed-desktop') {
        saveInterface({ sidebar_collapsed: value === 'true' })
    }
}

// Where this browser has settings the server does not know yet (from before they were kept there), they move to the
// server once; the old column keys of the boards are dropped either way.
export const adoptLocalInterface = () => {
    const state = window.sprintInterface

    if (! state) {
        return
    }

    try {
        const changes = {}
        const appearance = localStorage.getItem('flux.appearance')
        const sidebar = localStorage.getItem('flux-sidebar-collapsed-desktop')

        if (! ('appearance' in state) && appearance) {
            changes.appearance = appearance
        }

        if (! ('sidebar_collapsed' in state) && sidebar) {
            changes.sidebar_collapsed = sidebar === 'true'
        }

        for (const key of Object.keys(localStorage).filter((key) => key.startsWith('_x_board-collapsed-') || key === '_x_mine-board-collapsed')) {
            const ids = JSON.parse(localStorage.getItem(key))

            if (Array.isArray(ids) && ids.length > 0) {
                if (key === '_x_mine-board-collapsed') {
                    changes.mine_board_collapsed ??= state.mine_board_collapsed ?? ids
                } else {
                    const project = key.slice('_x_board-collapsed-'.length)

                    if (! state.board_collapsed || ! (project in state.board_collapsed)) {
                        changes.board_collapsed = { ...changes.board_collapsed, [project]: ids }
                    }
                }
            }

            localStorage.removeItem(key)
        }

        if (Object.keys(changes).length > 0) {
            saveInterface(changes)
        }
    } catch {}
}

// A page change brings what the server has now; the appearance of a page that is already open follows it
export const followServerAppearance = () => {
    const appearance = window.sprintInterface?.appearance

    // A change of this device that has not reached the server yet is newer than what the page brought
    if (timer || sending > 0) {
        return
    }

    try {
        const flux = window.Alpine.evaluate(document.body, '$flux')

        if (appearance && flux.appearance !== appearance) {
            flux.appearance = appearance
        }
    } catch {}
}
