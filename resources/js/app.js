// Passkeys are only needed on the login page and in the profile; the library is loaded on first use.
window.passkeys = () => import('@laravel/passkeys').then((module) => module.Passkeys)

const registerMentionable = () => {
    // People come with the page; tasks are looked up on the server while typing (options.searchTasks).
    window.Alpine.data('mentionable', (options) => ({
        open: false,
        query: '',
        active: 0,
        matches: [],
        start: -1,
        searchTimer: null,

        people(query) {
            return options.users
                .filter((user) => user.name.toLowerCase().includes(query))
                .slice(0, 8)
                .map((user) => ({ type: 'user', id: user.id, label: user.name }))
        },

        field() {
            return this.$el.querySelector('textarea')
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
            if (event.button !== 0) {
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

// Crops a picture to the centre square and shrinks it, as WebP or (where the browser cannot encode it) JPEG on white.
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

// The task flyout (components/task-panel.blade.php). Flux does not close it by itself: Esc, a click next to it,
// the close button and switching to another task go through here and ask first if the task has unsaved input.
const registerTaskFlyout = () => {
    window.Alpine.data('taskFlyout', (question, label) => ({
        init() {
            this.$nextTick(() => {
                window.Flux.modal('task-panel').show()
                this.$el.querySelector('dialog')?.setAttribute('aria-label', label)
                this.$nextTick(() => this.$el.querySelector('[data-flyout-close]')?.focus())
            })
        },

        // Title, description, fields or a comment typed but not saved yet
        hasUnsavedInput() {
            const task = this.$el.querySelector('dialog [wire\\:id]')

            return Boolean(task && window.Livewire.find(task.getAttribute('wire:id'))?.$dirty())
        },

        mayLeave() {
            return ! this.hasUnsavedInput() || window.confirm(question)
        },

        close() {
            if (this.mayLeave()) {
                window.Flux.modal('task-panel').close()
            }
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
            }
        },
    }))
}

const registerAll = () => {
    registerTaskFlyout()
    registerMentionable()
    registerTimelineBar()
    registerPresence()
    registerAvatarPicker()
}

if (window.Alpine) {
    registerAll()
} else {
    document.addEventListener('alpine:init', registerAll)
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

// Keyboard shortcuts, listed in the overview behind "?" (components/keyboard-shortcuts.blade.php).
// They never fire while typing, with a modifier key held or while a dialog is open (the task flyout allows j, k and e).
const typingIn = (target) => target.closest?.('input, textarea, select, [contenteditable], [role=combobox], [role=listbox], ui-select, ui-date-picker, ui-editor')

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
    window.Livewire.dispatch('open-task', { id: Number(items[next].dataset.taskId) })
}

document.addEventListener('keydown', (event) => {
    if (event.metaKey || event.ctrlKey || event.altKey || event.defaultPrevented || typingIn(event.target)) {
        return
    }

    // A dialog handles its own keys (Escape closes it); only the task flyout still lets j, k and e step through and complete tasks
    if (document.querySelector('dialog[open]:not([data-modal=task-panel])')) {
        return
    }

    if (document.querySelector('dialog[open][data-modal=task-panel]') && ! ['j', 'k', 'e'].includes(event.key)) {
        return
    }

    const actions = {
        '?': () => window.Flux.modal('keyboard-shortcuts').show(),
        '/': () => window.Flux.modal('command-palette').show(),
        c: () => window.Livewire.dispatch('new-task'),
        j: () => stepThroughTasks(1),
        k: () => stepThroughTasks(-1),
        e: () => window.Livewire.dispatch('shortcut-toggle-done'),
        Escape: () => openTaskId() && window.Livewire.dispatch('close-task'),
    }

    if (actions[event.key]) {
        event.preventDefault()
        actions[event.key]()
    }
})
