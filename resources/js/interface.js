// What a person arranged in the interface (appearance, sidebar, collapsed board columns) lives on the server, so a second
// device shows the same (InterfaceController). The layout seeds the keys Flux keeps in localStorage from it on every page,
// this file reports changes back. Only what changed is sent, so a device that has not seen another one's change does not
// take it back.

const RETRY_AFTER = 5000
const MAX_RETRY_DELAY = 300000
const ADOPTED_KEY = 'sprint.interface-adopted'

// Not yet started requests, in the order of the changes; one request at a time is on its way
let outbox = []
let pending = {}
let timer = null
let sending = false
let retries = 0

// Whether this device has a change the server has not confirmed yet; a page that arrives meanwhile must not take it back
const hasUnsentChanges = () => timer !== null || sending || outbox.length > 0 || Object.keys(pending).length > 0

window.sprintHasUnsentChanges = hasUnsentChanges

// What waits until everything has reached the server (see whenSaved)
let afterSaved = []

const settle = () => {
    if (hasUnsentChanges()) {
        return
    }

    const run = afterSaved

    afterSaved = []
    run.forEach((callback) => callback())
}

// Runs the callback once the server has everything this device changed (at once when there is nothing on its way)
export const whenSaved = (callback) => {
    afterSaved.push(callback)
    settle()
}

// Changes merged into others: values replace, collapsed columns (column id => collapsed) merge one by one
const merge = (older, newer) => {
    const { board_collapsed: olderBoards, mine_board_collapsed: olderMine, ...olderOthers } = older
    const { board_collapsed: newerBoards, mine_board_collapsed: newerMine, ...newerOthers } = newer
    const merged = { ...olderOthers, ...newerOthers }

    if (olderBoards || newerBoards) {
        merged.board_collapsed = { ...olderBoards }

        for (const [project, columns] of Object.entries(newerBoards ?? {})) {
            merged.board_collapsed[project] = { ...merged.board_collapsed[project], ...columns }
        }
    }

    if (olderMine || newerMine) {
        merged.mine_board_collapsed = { ...olderMine, ...newerMine }
    }

    return merged
}

const send = async () => {
    const url = document.querySelector('meta[name=sprint-interface]')?.content

    if (sending || outbox.length === 0 || ! url) {
        return
    }

    const changes = outbox.shift()

    sending = true

    try {
        const response = await fetch(url, {
            method: 'PATCH',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('script[data-csrf]')?.dataset.csrf ?? '' },
            body: JSON.stringify(changes),
        })

        // A refused change (expired session, invalid value) would be refused again; the others are tried again a little later
        if (! response.ok && (response.status >= 500 || response.status === 429)) {
            throw new Error('not saved')
        }

        // The revision of the state the server has now; a page rendered before it arrives with an older one and is ignored
        const { rev = 0 } = await response.json().catch(() => ({}))

        window.sprintInterface = { ...window.sprintInterface, rev: Math.max(rev, window.sprintInterface?.rev ?? 0) }
        retries = 0
        sending = false
        send()
        settle()
    } catch {
        sending = false

        // Kept and tried again, a little later each time: a change is not given up on because the network was away for a while
        outbox.unshift(changes)
        timer ??= setTimeout(flush, Math.min(RETRY_AFTER * 2 ** retries++, MAX_RETRY_DELAY))
    }
}

const flush = () => {
    clearTimeout(timer)
    timer = null

    if (Object.keys(pending).length > 0) {
        outbox.push(pending)
        pending = {}
    }

    send()
}

// A page that is going away sends everything that has not started in one request (a later change last, so it wins)
const flushForExit = () => {
    flush()

    if (outbox.length > 0) {
        outbox = [outbox.reduce(merge)]
        sending = false
        send()
    }
}

// What the server already has (as of the last page) is not sent again; Flux also writes its keys when nothing changed, and a
// device that has not seen another one's change must not take it back by repeating its own old value.
const isNews = (key, value) => JSON.stringify(window.sprintInterface?.[key]) !== JSON.stringify(value)

// Collapsed columns are sent one by one (column id => collapsed), only those that differ from what the page brought
const newColumns = (known = [], columns = {}) => Object.fromEntries(Object.entries(columns).filter(([column, collapsed]) => known.includes(Number(column)) !== collapsed))

const withColumn = (known = [], columns) => [...known.filter((column) => ! (String(column) in columns)), ...Object.keys(columns).filter((column) => columns[column]).map(Number)]

// Changes within a moment (toggling several columns) travel together
export const saveInterface = (changes) => {
    const { board_collapsed: boards = {}, mine_board_collapsed: mine = {}, ...others } = changes
    const known = window.sprintInterface ?? {}
    const news = Object.fromEntries(Object.entries(others).filter(([key, value]) => isNews(key, value)))
    const newBoards = Object.fromEntries(Object.entries(boards).map(([project, columns]) => [project, newColumns(known.board_collapsed?.[project], columns)]).filter(([, columns]) => Object.keys(columns).length > 0))
    const newMine = newColumns(known.mine_board_collapsed, mine)

    if (Object.keys(news).length + Object.keys(newBoards).length + Object.keys(newMine).length === 0) {
        return
    }

    pending = merge(pending, { ...news, ...(Object.keys(newBoards).length > 0 && { board_collapsed: newBoards }), ...(Object.keys(newMine).length > 0 && { mine_board_collapsed: newMine }) })

    const next = { ...known, ...news }

    for (const [project, columns] of Object.entries(newBoards)) {
        next.board_collapsed = { ...next.board_collapsed, [project]: withColumn(known.board_collapsed?.[project], columns) }
    }

    if (Object.keys(newMine).length > 0) {
        next.mine_board_collapsed = withColumn(known.mine_board_collapsed, newMine)
    }

    window.sprintInterface = next
    timer ??= setTimeout(flush, 400)
}

document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && flushForExit())
window.addEventListener('pagehide', flushForExit)
document.addEventListener('livewire:navigate', flush)

// Flux keeps whether the sidebar is collapsed in localStorage itself (the appearance is saved from its menu, see user-menu)
const setItem = Storage.prototype.setItem

Storage.prototype.setItem = function (key, value) {
    setItem.call(this, key, value)

    if (! window.sprintSeeding && this === window.localStorage && key === 'flux-sidebar-collapsed-desktop' && (value === 'true' || value === 'false') && window.matchMedia('(min-width: 1024px)').matches) {
        saveInterface({ sidebar_collapsed: value === 'true' })
    }
}

// Once per browser, the settings it holds from before they were kept on the server move there (what is not the default:
// light or dark, a collapsed sidebar, collapsed columns); the old column keys are dropped. Where one browser is used by
// several people, only the first one to come after the update gets them.
export const adoptLocalInterface = () => {
    const state = window.sprintInterface

    if (! state) {
        return
    }

    try {
        if (localStorage.getItem(ADOPTED_KEY)) {
            return
        }

        const changes = {}
        const oldKeys = []
        const appearance = localStorage.getItem('flux.appearance')

        if (! ('appearance' in state) && (appearance === 'light' || appearance === 'dark')) {
            changes.appearance = appearance
        }

        if (! ('sidebar_collapsed' in state) && localStorage.getItem('flux-sidebar-collapsed-desktop') === 'true' && window.matchMedia('(min-width: 1024px)').matches) {
            changes.sidebar_collapsed = true
        }

        for (const key of Object.keys(localStorage).filter((key) => key.startsWith('_x_board-collapsed-') || key === '_x_mine-board-collapsed')) {
            try {
                const ids = JSON.parse(localStorage.getItem(key)).filter((id) => Number.isInteger(id))
                const columns = Object.fromEntries(ids.map((id) => [id, true]))

                if (ids.length === 0) {
                    // nothing to adopt
                } else if (key === '_x_mine-board-collapsed') {
                    if (! ('mine_board_collapsed' in state)) {
                        changes.mine_board_collapsed = columns
                    }
                } else {
                    const project = key.slice('_x_board-collapsed-'.length)

                    if (/^[1-9]\d*$/.test(project) && ! (project in (state.board_collapsed ?? {}))) {
                        changes.board_collapsed = { ...changes.board_collapsed, [project]: columns }
                    }
                }
            } catch {}

            oldKeys.push(key)
        }

        if (Object.keys(changes).length > 0) {
            saveInterface(changes)
        }

        // Only when the server has them: if the page closes or the network is away before, the next page tries again
        whenSaved(() => {
            localStorage.setItem(ADOPTED_KEY, '1')
            oldKeys.forEach((key) => localStorage.removeItem(key))
        })
    } catch {}
}

// A page change brings what the server has now; the appearance of a page that is already open follows it
export const followServerAppearance = () => {
    const appearance = window.sprintInterface?.appearance

    // A change of this device that has not reached the server yet is newer than what the page brought
    if (hasUnsentChanges()) {
        return
    }

    try {
        const flux = window.Alpine.evaluate(document.body, '$flux')

        if (appearance && flux.appearance !== appearance) {
            flux.appearance = appearance
        }
    } catch {}
}
