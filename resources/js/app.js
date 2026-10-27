import { adoptLocalInterface, followServerAppearance, saveInterface } from './interface.js'

window.saveInterface = saveInterface
adoptLocalInterface()
document.addEventListener('livewire:navigated', followServerAppearance)

// Passkeys are only needed on the login page and in the profile; the library is loaded on first use.
window.passkeys = () => import('@laravel/passkeys').then((module) => module.Passkeys)

// The service worker (route pwa.service-worker); it sits at the root so its scope covers the whole app
const SERVICE_WORKER_URL = '/sw.js'

const registerMentionable = () => {
    // People come with the page, or (options.users is null) are fetched from the component when the editor is focused
    // or the first @ is typed; tasks are looked up on the server while typing (options.searchTasks).
    window.Alpine.data('mentionable', (options) => ({
        open: false,
        query: '',
        active: 0,
        matches: [],
        start: -1,
        searchTimer: null,
        known: options.users,
        loading: null,

        loadPeople() {
            this.loading ??= this.$wire.mentionPeople()
                .then((users) => { this.known = users })
                .catch(() => { this.loading = null })

            return this.loading
        },

        people(query) {
            return this.known
                .filter((user) => user.name.toLowerCase().includes(query))
                .slice(0, 8)
                .map((user) => ({ type: 'user', id: user.id, label: user.name, note: user.note, initials: user.initials, avatar: user.avatar, color: user.color }))
        },

        field() {
            return this.$root.querySelector('textarea')
        },

        show(matches) {
            this.matches = matches
            this.active = Math.min(this.active, Math.max(matches.length - 1, 0))
            this.open = matches.length > 0
        },

        onInput(event) {
            const field = this.field()

            if (event.target !== field) {
                return
            }

            const before = field.value.slice(0, field.selectionStart)
            const match = before.match(/(^|\s)@([^\s@[\]]{0,30})$/)

            clearTimeout(this.searchTimer)

            if (!match) {
                this.open = false
                this.start = -1

                return
            }

            if (this.known === null) {
                this.loadPeople().then(() => this.known !== null && this.onInput(event))

                return
            }

            const query = match[2].toLowerCase()
            const people = this.people(query)

            this.query = query
            this.start = before.length - match[2].length - 1
            this.active = 0
            this.show(people)

            if (!options.searchTasks) {
                return
            }

            this.searchTimer = setTimeout(() => {
                this.$wire.mentionTasks(query).then((tasks) => {
                    if (this.query !== query || this.start < 0) {
                        return
                    }

                    this.show([...people, ...tasks.map((task) => ({ type: 'task', id: task.id, label: task.title }))])
                })
            }, 150)
        },

        onKeydown(event) {
            if (!this.open || !this.matches.length) {
                return
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault()
                this.active = (this.active + 1) % this.matches.length
            } else if (event.key === 'ArrowUp') {
                event.preventDefault()
                this.active = (this.active - 1 + this.matches.length) % this.matches.length
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault()
                this.select(this.matches[this.active])
            } else if (event.key === 'Escape') {
                event.preventDefault()
                this.open = false
            }
        },

        select(item) {
            const field = this.field()
            const label = item.label.replace(/\[/g, '(').replace(/\]/g, ')').replace(/\s+/g, ' ')
            const token = `@[${label}](${item.type}:${item.id}) `
            const end = field.selectionStart

            field.value = field.value.slice(0, this.start) + token + field.value.slice(end)
            field.selectionStart = field.selectionEnd = this.start + token.length
            field.dispatchEvent(new Event('input', { bubbles: true }))
            field.focus()
            this.open = false
            this.start = -1
        },
    }))
}


/**
 * Who else has the same page open, from a Reverb presence channel; the page itself never re-renders for it.
 */
const registerPresence = () => {
    window.Alpine.data('presence', (channel, selfId, labels) => ({
        users: [],

        init() {
            if (!window.Echo) {
                return
            }

            window.Echo.join(channel)
                .here((users) => { this.users = users.filter((user) => user.id !== selfId) })
                .joining((user) => {
                    if (user.id !== selfId && !this.users.some((other) => other.id === user.id)) {
                        this.users.push(user)
                    }
                })
                .leaving((user) => { this.users = this.users.filter((other) => other.id !== user.id) })
        },

        destroy() {
            window.Echo?.leave(channel)
        },

        label() {
            if (this.users.length === 1) {
                return labels.one.replace(':name', this.users[0].name)
            }

            return this.users.length > 1 && labels.many ? labels.many.replace(':count', this.users.length) : ''
        },
    }))
}

/**
 * Timeline bars: drag to move, drag an edge to stretch or shrink; the new dates are saved in whole days.
 */
const registerTimelineBar = () => {
    window.Alpine.data('timelineBar', (taskId) => ({
        mode: null,
        startX: 0,
        delta: 0,
        dayWidth: 0,
        width: 0,
        moved: false,

        begin(event, mode) {
            // The bar stands still while tasks are selected (the page sets `selecting` in the browser at once)
            if (event.button !== 0 || this.$wire.selecting) {
                return
            }

            const grid = this.$root.parentElement

            this.mode = mode
            this.startX = event.clientX
            this.delta = 0
            this.moved = false
            this.width = this.$root.getBoundingClientRect().width
            this.dayWidth = grid.getBoundingClientRect().width / Number(grid.dataset.days)

            this.$root.setPointerCapture(event.pointerId)
            event.preventDefault()
        },

        move(event) {
            if (this.mode === null) {
                return
            }

            const distance = event.clientX - this.startX

            if (Math.abs(distance) > 3) {
                this.moved = true
            }

            this.delta = Math.round(distance / this.dayWidth)

            const shift = this.delta * this.dayWidth

            this.$root.style.justifySelf = 'start'
            this.$root.style.transform = this.mode === 'end' ? '' : `translateX(${shift}px)`
            this.$root.style.width = `${this.mode === 'move' ? this.width : this.width + (this.mode === 'end' ? shift : -shift)}px`
        },

        finish() {
            if (this.mode === null) {
                return
            }

            const mode = this.mode
            const delta = this.delta

            this.cancel()

            if (delta !== 0) {
                this.$wire.reschedule(taskId, mode, delta)
            }
        },

        cancel() {
            this.mode = null
            this.$root.style.transform = ''
            this.$root.style.width = ''
            this.$root.style.justifySelf = ''
        },

        suppressClick(event) {
            if (this.moved) {
                event.preventDefault()
                event.stopPropagation()
                this.moved = false
            }
        },
    }))
}

// Crops a picture to the center square and shrinks it, as WebP or (where the browser cannot encode it) JPEG on white.
const squareImage = async (file, size) => {
    const bitmap = await createImageBitmap(file)
    const side = Math.min(bitmap.width, bitmap.height)
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = Math.min(size, side)
    canvas.getContext('2d').drawImage(bitmap, (bitmap.width - side) / 2, (bitmap.height - side) / 2, side, side, 0, 0, canvas.width, canvas.height)
    bitmap.close()

    const encode = (target, type) => new Promise((resolve) => target.toBlob(resolve, type, 0.85))
    let blob = await encode(canvas, 'image/webp')

    if (! blob || blob.type !== 'image/webp') {
        const flat = document.createElement('canvas')
        flat.width = flat.height = canvas.width
        const context = flat.getContext('2d')
        context.fillStyle = '#ffffff'
        context.fillRect(0, 0, flat.width, flat.height)
        context.drawImage(canvas, 0, 0)
        blob = await encode(flat, 'image/jpeg')
    }

    return new File([blob], blob.type === 'image/webp' ? 'avatar.webp' : 'avatar.jpg', { type: blob.type })
}

const registerAvatarPicker = () => {
    window.Alpine.data('avatarPicker', (size) => ({
        busy: false,

        async pick(file) {
            if (! file) {
                return
            }

            this.busy = true
            // Pictures the browser cannot read go up unchanged; the server then decides.
            const upload = await squareImage(file, size).catch(() => file)
            const done = () => { this.busy = false }
            this.$wire.upload('avatarUpload', upload, done, done)
        },
    }))
}

// One emoji, as Emoji::normalize() accepts it: a flag, a keycap, a flag of a region, or pictographs (with skin tone), joined ones included
const EMOJI = /^(?:[\u{1F1E6}-\u{1F1FF}]{2}|[0-9#*]\uFE0F?\u20E3|\u{1F3F4}[\u{E0020}-\u{E007E}]+\u{E007F}|\p{Extended_Pictographic}\uFE0F?[\u{1F3FB}-\u{1F3FF}]?(?:\u200D\p{Extended_Pictographic}\uFE0F?[\u{1F3FB}-\u{1F3FF}]?)*)$/u

// The field for any other reaction emoji (components/reactions.blade.php). Letters and other text vanish as they are typed or pasted, only
// the last emoji stays. Enter sends it and never reaches the task's form around it; while an input method is still composing, Enter only
// finishes that. enterkeyhint="send" makes phone keyboards show Send instead of Next, which would jump to the next field of the form.
const registerEmojiField = () => {
    window.Alpine.data('emojiField', (target, id) => ({
        emoji: '',

        // The markup of the menu, sent once with the page (components/reactions.blade.php)
        menuHtml() {
            return this.$el.closest('[wire\\:id]').querySelector('template[data-reaction-menu]').innerHTML
        },

        react(emoji) {
            this.$wire.react(target, id, emoji)
        },

        keepEmoji(event) {
            if (event.isComposing) {
                return
            }

            const graphemes = [...new Intl.Segmenter().segment(event.target.value)].map(({ segment }) => segment.trim())

            this.emoji = graphemes.findLast((grapheme) => EMOJI.test(grapheme)) ?? ''
            event.target.value = this.emoji
        },

        send(event) {
            if (event.isComposing) {
                return
            }

            event.preventDefault()

            if (this.emoji === '') {
                return
            }

            this.react(this.emoji)
            this.emoji = ''
        },
    }))
}

// Push notifications for this device (profile): the browser subscribes with the installation's public key (VAPID) and
// the subscription is stored for the person. iPhones and iPads only offer it to Sprint opened from the home screen.
const keyBytes = (base64) => {
    const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/')

    return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0))
}

const sameBytes = (a, b) => a.length === b.length && a.every((byte, index) => byte === b[index])

// The worker is registered on every page in production; in development only once push needs it
const pushRegistration = async () => {
    if (! await navigator.serviceWorker.getRegistration('/')) {
        await navigator.serviceWorker.register(SERVICE_WORKER_URL)
    }

    return navigator.serviceWorker.ready
}

const registerPushToggle = () => {
    window.Alpine.data('pushToggle', (publicKey) => ({
        state: 'loading',
        busy: false,

        async init() {
            const appleMobile = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
            const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true

            if (! publicKey || ! ('serviceWorker' in navigator) || ! ('PushManager' in window) || ! ('Notification' in window)) {
                this.state = appleMobile && ! standalone ? 'install' : 'unsupported'

                return
            }

            if (Notification.permission === 'denied') {
                this.state = 'denied'

                return
            }

            const subscription = await (await pushRegistration()).pushManager.getSubscription()

            this.state = subscription && await this.$wire.hasPushSubscription(subscription.endpoint) ? 'on' : 'off'
        },

        async turnOn() {
            this.busy = true

            try {
                if (await Notification.requestPermission() !== 'granted') {
                    this.state = Notification.permission === 'denied' ? 'denied' : 'off'

                    return
                }

                const registration = await pushRegistration()
                const key = keyBytes(publicKey)
                let subscription = await registration.pushManager.getSubscription()

                // One made with other keys (the installation got new ones) cannot be used any more
                if (subscription && ! sameBytes(new Uint8Array(subscription.options.applicationServerKey ?? []), key)) {
                    await subscription.unsubscribe()
                    subscription = null
                }

                subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })

                const { endpoint, keys } = subscription.toJSON()
                const encoding = (PushManager.supportedContentEncodings ?? []).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm'

                this.state = await this.$wire.savePushSubscription(endpoint, keys.p256dh, keys.auth, encoding) === true ? 'on' : 'failed'
            } catch {
                this.state = 'failed'
            } finally {
                this.busy = false
            }
        },

        async turnOff() {
            this.busy = true

            try {
                const subscription = await (await pushRegistration()).pushManager.getSubscription()

                if (subscription) {
                    await subscription.unsubscribe()
                    await this.$wire.removePushSubscription(subscription.endpoint)
                }

                this.state = 'off'
            } finally {
                this.busy = false
            }
        },
    }))
}

// Logging out ends push notifications on this device too (a shared computer would otherwise keep getting them):
// the form takes the browser's subscription along, the server forgets it.
document.addEventListener('submit', async (event) => {
    const form = event.target

    if (! form.matches('form[data-logout]') || form.dataset.pushChecked || ! ('serviceWorker' in navigator)) {
        return
    }

    event.preventDefault()

    try {
        const subscription = await (await navigator.serviceWorker.getRegistration('/'))?.pushManager.getSubscription()

        if (subscription) {
            const field = Object.assign(document.createElement('input'), { type: 'hidden', name: 'push_endpoint', value: subscription.endpoint })

            form.append(field)
            await subscription.unsubscribe()
        }
    } catch {}

    form.dataset.pushChecked = 'true'
    form.submit()
})

// The browser's bar (and the installed app's on Android) follows light or dark as chosen in the menu, not only the system
// (the colors come from the layout's two theme-color tags, read before the first change)
const themeColors = Object.fromEntries([...document.querySelectorAll('meta[name=theme-color]')]
    .map((meta) => [meta.media.includes('dark') ? 'dark' : 'light', meta.content]))

const syncThemeColor = () => {
    const color = document.documentElement.classList.contains('dark') ? themeColors.dark : themeColors.light

    document.querySelectorAll('meta[name=theme-color]').forEach((meta) => meta.setAttribute('content', color))
}

syncThemeColor()
new MutationObserver(syncThemeColor).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

// A browser can still hold the push subscription of someone whose session simply ran out on a shared computer. Once per
// session (and person) the server is told this browser's subscription and forgets it if it belongs to someone else.
const forgetOthersPush = async () => {
    const check = document.querySelector('meta[name=sprint-push-check]')

    if (! check || ! ('serviceWorker' in navigator)) {
        return
    }

    try {
        if (sessionStorage.getItem('sprint.push-checked') === check.dataset.user) {
            return
        }

        const subscription = await (await navigator.serviceWorker.getRegistration('/'))?.pushManager.getSubscription()

        if (subscription) {
            await fetch(check.content, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('script[data-csrf]')?.dataset.csrf ?? '' },
                body: JSON.stringify({ endpoint: subscription.endpoint }),
            })
        }

        sessionStorage.setItem('sprint.push-checked', check.dataset.user)
    } catch {}
}

forgetOthersPush()
document.addEventListener('livewire:navigated', forgetOthersPush)

// The sidebar on phones and tablets lies over the page (Flux): the keyboard moves into it when it opens and back to the
// menu button when it closes, Tab stays inside while it is open and Esc closes it.
const openMobileSidebar = () => document.querySelector('ui-sidebar[data-flux-sidebar-on-mobile]:not([data-flux-sidebar-collapsed-mobile])')
const focusablesIn = (element) => [...element.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')]
    .filter((candidate) => candidate.offsetParent !== null)
let sidebarOpener = null

new MutationObserver((records) => {
    const sidebar = records.find((record) => record.target.matches?.('ui-sidebar[data-flux-sidebar-on-mobile]'))?.target

    if (! sidebar) {
        return
    }

    if (sidebar.hasAttribute('data-flux-sidebar-collapsed-mobile')) {
        sidebarOpener?.focus()
        sidebarOpener = null
    } else {
        sidebarOpener = document.activeElement
        requestAnimationFrame(() => focusablesIn(sidebar)[0]?.focus())
    }
}).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['data-flux-sidebar-collapsed-mobile'] })

window.addEventListener('keydown', (event) => {
    const sidebar = openMobileSidebar()

    if (! sidebar) {
        return
    }

    if (event.key === 'Escape') {
        event.preventDefault()
        event.stopImmediatePropagation()
        document.querySelector('ui-sidebar-toggle[data-flux-sidebar-backdrop]')?.click()
    } else if (event.key === 'Tab') {
        const focusables = focusablesIn(sidebar)
        const edge = event.shiftKey ? focusables[0] : focusables.at(-1)

        if (focusables.length > 0 && (document.activeElement === edge || ! sidebar.contains(document.activeElement))) {
            event.preventDefault()
            const next = event.shiftKey ? focusables.at(-1) : focusables[0]
            next.focus()
        }
    }
}, true)

// What the menus that open at a button of the page have in common (the assignee menu and the move menu of the board
// cards): one menu for the whole page, shown at the button that was clicked, so its entries are in the page once and
// not once for every task.
const anchoredMenu = () => ({
    isOpen: false,
    anchor: null,

    // A second click on the same button closes it again (false then)
    openAt(anchor) {
        if (this.isOpen && this.anchor === anchor) {
            this.close(true)

            return false
        }

        this.anchor?.setAttribute('aria-expanded', 'false')
        this.anchor = anchor
        this.anchor?.setAttribute('aria-expanded', 'true')
        this.isOpen = true
        this.$nextTick(() => this.items()[0]?.focus())

        return true
    },

    close(restoreFocus = false) {
        if (! this.isOpen) {
            return
        }

        this.isOpen = false
        this.anchor?.setAttribute('aria-expanded', 'false')

        if (restoreFocus) {
            this.anchor?.focus()
        }
    },

    items() {
        return [...this.$refs.menu.querySelectorAll('[role^=menuitem]')].filter((item) => item.offsetParent !== null)
    },

    move(step) {
        const items = this.items()

        if (! items.length) {
            return
        }

        items[(items.indexOf(document.activeElement) + step + items.length) % items.length].focus()
    },
})

// The menu to hand a task to someone (components/assignee-menu.blade.php): one per page, opened at the faces of a task
// (components/assignee-picker.blade.php, which tells which task and who is the assignee), so the people are in the page once.
const registerAssigneeMenu = () => {
    window.Alpine.data('assigneeMenu', (meId) => ({
        ...anchoredMenu(),
        meId,
        taskId: null,
        assigneeId: null,

        show(detail) {
            if (! this.openAt(detail.anchor)) {
                return
            }

            this.taskId = detail.task
            this.assigneeId = detail.assignee
        },

        pick(userId) {
            const task = this.taskId

            this.close(true)
            this.$wire.assign(task, userId)
        },
    }))
}

// The menu of the cards of the board "My tasks" (components/card-move-menu.blade.php) that moves a card without dragging it.
// The button of a card (data-card-menu) tells which card it is, in which column and at which place; the columns to move
// to are read from the board when the menu opens (data-move-targets), so a renamed or new column is there without
// the menu being rendered again.
const registerCardMenu = () => {
    window.Alpine.data('cardMenu', () => ({
        ...anchoredMenu(),
        taskId: null,
        column: null,
        index: 0,
        isLast: false,
        columns: [],

        show(anchor) {
            if (! this.openAt(anchor)) {
                return
            }

            this.taskId = Number(anchor.dataset.cardMenu)
            this.column = Number(anchor.dataset.column)
            this.index = Number(anchor.dataset.index)
            this.isLast = anchor.dataset.last === '1'
            this.columns = JSON.parse(anchor.closest('[data-move-targets]')?.dataset.moveTargets ?? '[]').filter((column) => column.id !== this.column)
        },

        // Cards of "Unsorted" follow the due dates, they only move to another column
        get canMoveUp() {
            return this.column !== 0 && this.index > 0
        },

        get canMoveDown() {
            return this.column !== 0 && ! this.isLast
        },

        // To the end of another column, or one place up or down in the own
        pick(column, index = 100000) {
            const task = this.taskId

            this.close()
            this.$wire.moveTask(task, index, String(column)).then(() => this.$nextTick(() => this.focusCard(task, column)))
        },

        // The menu button of the card where it is now, so the keyboard continues from there (the card was rendered again,
        // maybe in another column). Not shown (beyond "Load more"): the button of the column's first card. In a collapsed
        // column the cards are out of sight and cannot take the focus: the button that opens the column gets it.
        focusCard(task, column) {
            const button = document.querySelector(`[data-card-menu="${task}"]`)
                ?? document.querySelector(`[data-column="${column}"][data-card-menu]`)

            const hidden = button?.checkVisibility?.({ visibilityProperty: true }) === false

            ;(hidden ? button.closest('[data-flux-kanban-column]')?.querySelector('[data-column-collapse]') : button)?.focus()
        },
    }))
}

// The task flyout (components/task-panel.blade.php). Flux does not close it by itself: Esc, a click next to it,
// the close button and switching to another task go through here and ask first if the task has unsaved input.
const registerTaskFlyout = () => {
    // The task forms (components) that sent a request while they held changes. A request takes along what was typed, and
    // from then on the server's state counts as the saved one for Livewire ($dirty() is false again), so the flyout
    // remembers it until the form is saved or reloaded. Kept here and not in the markup, which is rendered again all the time.
    const sentChanges = new WeakSet()

    // The forms for a comment, a subtask or a comment being edited in which somebody typed (see track)
    const edited = new WeakSet()

    const componentOf = (form) => {
        const id = form.closest('[wire\\:id]')?.getAttribute('wire:id')

        return id ? window.Livewire.find(id)?.__instance : null
    }

    // Whether the form differs from the state the server last sent, in the properties it names (data-dirty-properties). Not
    // what the fields say: the lists and date pickers of Flux send input and change events of their own when they start,
    // and a search in a list or a native change event after saving say nothing about the values.
    const differs = (form) => {
        const component = componentOf(form)
        const properties = JSON.parse(form.dataset.dirtyProperties ?? '[]')

        return Boolean(component) && properties.some((property) => JSON.stringify(component.canonical[property]) !== JSON.stringify(component.reactive[property]))
    }

    window.Livewire.interceptMessage(({ message }) => {
        const form = message.component.el.querySelector('form[data-task-form]')

        if (form && differs(form)) {
            sentChanges.add(message.component)
        }
    })

    window.Alpine.data('taskFlyout', (question, label) => ({
        init() {
            this.$nextTick(() => {
                window.Flux.modal('task-panel').show()
                this.$el.querySelector('dialog')?.setAttribute('aria-label', label)
                this.$nextTick(() => this.$el.querySelector('[data-flyout-close]')?.focus())
            })
        },

        // Input and change events of the forms of a comment, a subtask or a comment being edited. Not those of the task
        // form, whose lists and date pickers send events without anybody having changed anything.
        track(event) {
            const form = event.target.closest?.('form')

            if (form && ! form.hasAttribute('data-task-form')) {
                edited.add(form)
            }
        },

        // The task form is saved with its "Save" button (and by nothing else), so it stays changed until then; the others
        // hold only what is typed, which is gone once it is sent.
        forgetEdits() {
            this.$el.querySelectorAll('form[data-task-form]').forEach((form) => sentChanges.delete(componentOf(form)))
        },

        // Title, description, fields or a comment typed but not saved yet
        hasUnsavedInput() {
            return [...this.$el.querySelectorAll('dialog form')].some((form) => {
                if (form.hasAttribute('data-task-form')) {
                    return sentChanges.has(componentOf(form)) || differs(form)
                }

                return edited.has(form) && [...form.querySelectorAll('textarea, input:not([type=file], [type=hidden])')].some((field) => field.value.trim() !== '')
            })
        },

        mayLeave() {
            return ! this.hasUnsavedInput() || window.confirm(question)
        },

        close() {
            if (this.mayLeave()) {
                this.slideOut()
            }
        },

        // Livewire removes the flyout as soon as the server has answered the close, which would cut off Flux's short fade
        // out: it slides out first (data-closing, see app.css), then it closes. Not with reduced motion.
        slideOut() {
            const dialog = this.$el.querySelector('dialog')
            const finish = () => window.Flux.modal('task-panel').close()

            if (! dialog || ! dialog.open || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                finish()

                return
            }

            dialog.setAttribute('data-closing', '')
            window.setTimeout(finish, 180)
        },

        // Listens on the window before everything else (capture), also when the focus left the flyout: an open menu, list or
        // date picker inside is still open then, and Esc closes only that.
        // Flux itself ignores Esc here (escapable false) and marks the event as handled, so that cannot be the signal.
        onKeydown(event) {
            if (event.key !== 'Escape' || this.hasOpenMenu()) {
                return
            }

            event.preventDefault()
            this.close()
        },

        // Tooltips are popovers too, but one may still show after a click on a button
        hasOpenMenu() {
            return [...this.$el.querySelectorAll(':popover-open')].some((popover) => ! popover.closest('ui-tooltip'))
        },

        onClick(event) {
            const dialog = event.target

            if (dialog.tagName !== 'DIALOG') {
                return
            }

            // A click on the dimmed backdrop lands on the dialog itself, outside its box
            const box = dialog.getBoundingClientRect()

            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
                this.close()
            }
        },

        // close-task and open-task from inside the flyout (close button, subtasks, parent tasks)
        guard(event) {
            if (! this.mayLeave()) {
                event.stopImmediatePropagation()

                return
            }

            // The close button: slide out first, the close of the flyout then tells the page (wire:close)
            if (event.type === 'close-task') {
                event.stopImmediatePropagation()
                this.slideOut()
            }
        },
    }))
}

// $copyText(text, done, failed): puts the text on the clipboard and says so with a toast. Without the clipboard (a page over plain
// http that is not localhost, or a browser that refuses) the other toast says it did not work.
const registerCopyText = () => {
    window.Alpine.magic('copyText', () => (text, done, failed) => {
        const toast = (message, variant) => window.Flux.toast({ text: message, variant })

        if (! navigator.clipboard) {
            toast(failed, 'danger')

            return
        }

        navigator.clipboard.writeText(text).then(() => toast(done, 'success')).catch(() => toast(failed, 'danger'))
    })
}

// $openTask($event, id): a click on a task link opens the task in the flyout (the component task-flyout); with a modifier key or
// another button it stays a normal link to the full page, and a click a drag already used (timeline bars) does nothing.
const registerOpenTask = () => {
    window.Alpine.magic('openTask', () => (event, id) => {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
            return
        }

        event.preventDefault()
        window.Livewire.dispatch('open-task', { id })
    })
}

// A picker of the task flyout (tasks/⚡show.blade.php) holds only its chosen values until somebody uses it (focus, click
// or a key); then the component sends its list of choices (loadPicker). Merely pointing at a field loads nothing: the
// request renders the whole task again (comments and activity included), too much to spend on a mouse that passes by.
// Keys that open or move through the list while it is on its way are held back and played when it has arrived, so a fast
// keyboard user gets the same result as with a list that was there all along.
const registerLazyPicker = () => {
    // The requests for choices, by the element of the component: fields that share their choices (assignee and
    // collaborators) ask once
    const requests = new WeakMap()
    const listKeys = ['ArrowDown', 'ArrowUp', 'Enter', ' ']

    window.Alpine.data('lazyPicker', (name) => ({
        loading: false,
        replaying: false,
        held: [],

        load() {
            const component = this.$el.closest('[wire\\:id]')
            const asked = requests.get(component) ?? new Map()

            if (! asked.has(name)) {
                const request = { done: false }

                request.promise = this.$wire.loadPicker(name).catch(() => asked.delete(name)).finally(() => { request.done = true })
                asked.set(name, request)
                requests.set(component, asked)
            }

            const request = asked.get(name)

            if (! this.loading && ! request.done) {
                this.loading = true
                request.promise.finally(() => this.$nextTick(() => this.release()))
            }
        },

        // The key presses on the button of the list while it loads (not the typing in a search field)
        hold(event) {
            if (! this.loading || this.replaying || ! listKeys.includes(event.key) || event.target.matches('input, textarea')) {
                return
            }

            event.preventDefault()
            event.stopPropagation()
            this.held.push(event)
        },

        // One key at a time, as they were pressed: the list handles each of them before the next one comes
        release() {
            this.loading = false

            const play = () => {
                const event = this.held.shift()

                if (! event) {
                    return
                }

                // Space and Enter open a closed list through the click that a button makes of them; a key event sent from
                // here makes none. Once it is open they pick the active choice, which does listen to the key.
                if ([' ', 'Enter'].includes(event.key) && ! this.$el.querySelector(':popover-open')) {
                    event.target.click()
                } else {
                    this.replaying = true
                    event.target.dispatchEvent(new KeyboardEvent('keydown', {
                        key: event.key, code: event.code, shiftKey: event.shiftKey, bubbles: true, cancelable: true, composed: true,
                    }))
                    this.replaying = false
                }

                window.setTimeout(play, 20)
            }

            play()
        },
    }))
}

const registerAll = () => {
    // Which task the flyout shows (task-flyout sets it): its title links light up
    window.Alpine.store('taskFlyout', { id: '' })
    registerOpenTask()
    registerCopyText()
    registerTaskFlyout()
    registerMentionable()
    registerLazyPicker()
    registerTimelineBar()
    registerPresence()
    registerAvatarPicker()
    registerAssigneeMenu()
    registerCardMenu()
    registerEmojiField()
    registerPushToggle()
}

if (window.Alpine) {
    registerAll()
} else {
    document.addEventListener('alpine:init', registerAll)
}

// From "… commented" in a task's activity to the comment (tasks/⚡show.blade.php), which then lights up briefly
window.showComment = (id) => {
    const comment = document.getElementById(`comment-${id}`)
    const card = comment?.querySelector('[data-comment-card]')

    if (! comment) {
        return
    }

    comment.scrollIntoView({ behavior: 'smooth', block: 'center' })
    card?.classList.add('ring-2', 'ring-accent')
    setTimeout(() => card?.classList.remove('ring-2', 'ring-accent'), 1600)
}

document.addEventListener('click', (event) => {
    const image = event.target.closest?.('img[data-preview-url]')

    if (! image) {
        return
    }

    window.dispatchEvent(new CustomEvent('preview-file', {
        detail: { url: image.dataset.previewUrl, download: image.dataset.downloadUrl, name: image.dataset.name, kind: 'image' },
    }))
})

// A click anywhere on a task card (board) or row (list) opens the task in the flyout, like a click on its title. Clicks on
// something with its own job (links, buttons, checkboxes, fields) and selecting text are left alone; with Cmd/Ctrl the
// task opens in a new tab.
document.addEventListener('click', (event) => {
    const card = event.target.closest?.('[data-opens-task]')

    if (! card || event.defaultPrevented || event.button !== 0 || window.getSelection()?.toString()) {
        return
    }

    if (event.target.closest('a, button, input, textarea, select, label, [role=checkbox], [role=button], ui-checkbox, ui-select, ui-dropdown')) {
        return
    }

    if (event.metaKey || event.ctrlKey) {
        window.open(card.querySelector('a[href*="/tasks/"]')?.href, '_blank')

        return
    }

    // While tasks are being selected a click on the card ticks its box (the flyout would open over the bar and its buttons)
    const page = window.Livewire.find(card.closest('[wire\\:id]')?.getAttribute('wire:id'))

    const box = card.querySelector('[data-select-box] ui-checkbox, [data-select-box] input')

    if (page?.selecting && box) {
        box.click()

        return
    }

    window.Livewire.dispatch('open-task', { id: Number(card.dataset.opensTask) })
})

// Keyboard shortcuts, listed in the overview behind "?" (components/keyboard-shortcuts.blade.php).
// They never fire while typing, with a modifier key held or while a dialog is open (the task flyout allows j, k, e, a and m).
const typingIn = (target) => target.closest?.('input, textarea, select, [contenteditable], [role=combobox], [role=listbox], [role=menu], ui-select, ui-date-picker, ui-editor')

// A task stepped to is not seen any more when the page changes before the moment is over
document.addEventListener('livewire:navigate', () => clearTimeout(window.sprintSeenTimer))

const openTaskId = () => new URLSearchParams(window.location.search).get('task')

const stepThroughTasks = (direction) => {
    const items = [...document.querySelectorAll('[data-task-id]')].filter((item) => item.offsetParent !== null)

    if (! items.length) {
        return
    }

    const current = items.findIndex((item) => item.dataset.taskId === openTaskId())
    const next = current === -1 ? (direction > 0 ? 0 : items.length - 1) : current + direction

    if (next < 0 || next >= items.length) {
        return
    }

    items[next].scrollIntoView({ block: 'nearest' })

    // From the element of the flyout when there is one, so that its guard asks about unsaved input first (Livewire.dispatch skips it)
    const flyout = document.querySelector('[data-task-flyout]')

    const id = Number(items[next].dataset.taskId)

    ;(flyout ?? window).dispatchEvent(new CustomEvent('open-task', { detail: { id, stepping: true }, bubbles: true }))

    // Walking past tasks does not mean reading them: what the inbox reported counts as seen once a task stays open for a moment
    clearTimeout(window.sprintSeenTimer)
    window.sprintSeenTimer = setTimeout(() => window.Livewire.dispatch('task-seen', { id }), 1500)
}

document.addEventListener('keydown', (event) => {
    if (event.metaKey || event.ctrlKey || event.altKey || event.defaultPrevented || typingIn(event.target)) {
        return
    }

    // A dialog handles its own keys (Escape closes it); only the task flyout still lets j and k step through tasks and e, a and m change the open one
    if (document.querySelector('dialog[open]:not([data-modal=task-panel])')) {
        return
    }

    if (document.querySelector('dialog[open][data-modal=task-panel]') && ! ['j', 'k', 'e', 'a', 'm'].includes(event.key)) {
        return
    }

    const actions = {
        '?': () => window.Flux.modal('keyboard-shortcuts').show(),
        '/': () => window.Flux.modal('command-palette').show(),
        c: () => window.Livewire.dispatch('new-task'),
        j: () => stepThroughTasks(1),
        k: () => stepThroughTasks(-1),
        e: () => window.Livewire.dispatch('shortcut-toggle-done'),
        // Only where there is an assignee field; elsewhere the key stays a key
        a: () => {
            const field = document.querySelector('[data-shortcut-assignee] button')

            if (! field) {
                return false
            }

            field.click()
        },
        m: () => window.Livewire.dispatch('shortcut-assign-me'),
    }

    if (actions[event.key] && actions[event.key]() !== false) {
        event.preventDefault()
    }
})

// Sprint as an app on the home screen: the service worker (ProgressiveWebAppController) keeps the built assets and an offline page.
// Not with the Vite dev server, whose files are not in the build. The offline page shows the language last used here.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => navigator.serviceWorker.register(SERVICE_WORKER_URL).catch(() => {}))
}

// A deployment while the page is open: every answer of the app names its build (MarkAppResponse), and the page knows the
// one it was loaded with. A different one shows the banner of the layout, and the next page change loads the page from the
// server instead, so that its scripts fit again. Checked with Livewire's own requests, and when the tab is shown again.
const build = document.querySelector('meta[name="sprint-build"]')
let outdated = false

const noteBuild = (served) => {
    if (build && served && served !== build.content && ! outdated) {
        outdated = true
        window.dispatchEvent(new CustomEvent('sprint-outdated'))
    }
}

if (build) {
    document.addEventListener('livewire:init', () => {
        window.Livewire.interceptRequest(({ onResponse }) => onResponse(({ response }) => noteBuild(response.headers.get(build.dataset.header))))
    })

    let lastCheck = Date.now()

    document.addEventListener('visibilitychange', () => {
        // The service worker's address: no session, so the cheapest answer of the app
        if (document.visibilityState !== 'visible' || outdated || Date.now() - lastCheck < 60000) {
            return
        }

        lastCheck = Date.now()
        fetch(SERVICE_WORKER_URL, { method: 'HEAD', cache: 'no-store' }).then((response) => noteBuild(response.headers.get(build.dataset.header))).catch(() => {})
    })

    // A page change nobody could stop (the first one after a deployment, before any answer named the new build) brings the
    // new head along: its meta tag shows the build, and the old and the new scripts would run side by side
    document.addEventListener('livewire:navigated', () => {
        const served = document.querySelector('meta[name="sprint-build"]')?.content

        if (served && served !== build.content) {
            window.location.reload()
        }
    })

    document.addEventListener('livewire:navigate', (event) => {
        if (outdated) {
            event.preventDefault()
            window.location.href = event.detail.url.href
        }
    })
}

// Also after wire:navigate page changes, such as the one after logging in
const rememberLocale = () => {
    try {
        localStorage.setItem('sprint.locale', document.documentElement.lang.slice(0, 2))
    } catch {}
}

rememberLocale()
document.addEventListener('livewire:navigated', rememberLocale)

// Areas that scroll (board, calendar, timeline and the tables in a scroll-shadows wrapper) say whether more lies beyond
// their edges, for the shadows there (scroll-shadows in app.css). The names follow Flux, which sets the first two on its
// table scroll area itself: data-scrolled-right once the start is scrolled out of sight, data-scrolled-left while more
// follows at the end; data-more-below while more follows below.
const watchedScrollAreas = new WeakSet()

// How far (in pixels) an area must be scrolled from an edge before the shadow there shows
const SCROLL_EDGE_SLACK = 12

const updateScrollEdges = (area) => {
    const hiddenAtEnd = area.scrollWidth - area.clientWidth - Math.abs(area.scrollLeft)

    area.toggleAttribute('data-scrolled-right', Math.abs(area.scrollLeft) > SCROLL_EDGE_SLACK)
    area.toggleAttribute('data-scrolled-left', hiddenAtEnd > SCROLL_EDGE_SLACK)
    area.toggleAttribute('data-more-below', area.scrollHeight - area.clientHeight - area.scrollTop > SCROLL_EDGE_SLACK)
}

const watchScrollEdges = () => {
    document.querySelectorAll('[data-scroll-edges], .scroll-shadows > ui-table-scroll-area').forEach((area) => {
        updateScrollEdges(area)

        if (watchedScrollAreas.has(area)) {
            return
        }

        // Size changes of the area and changes inside it (cards loaded, a column collapsed) move the end
        const update = () => requestAnimationFrame(() => updateScrollEdges(area))
        const resizes = new ResizeObserver(update)
        resizes.observe(area)
        const mutations = new MutationObserver(update)
        mutations.observe(area, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-collapsed', 'class'] })
        area.addEventListener('scroll', () => updateScrollEdges(area), { passive: true })
        watchedScrollAreas.add(area)
    })
}

watchScrollEdges()
document.addEventListener('livewire:navigated', watchScrollEdges)
