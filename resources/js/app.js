import { Passkeys } from '@laravel/passkeys'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Passkeys = Passkeys

// Live updates: the server only passes the connection settings when Reverb is switched on (see docs/configuration.md).
if (window.sprintRealtime) {
    const { key, host, port, scheme } = window.sprintRealtime

    window.Pusher = Pusher
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    })
}

const registerMentionable = () => {
    window.Alpine.data('mentionable', (options) => ({
        open: false,
        query: '',
        active: 0,
        matches: [],
        start: -1,

        items() {
            return [
                ...options.users.map((user) => ({ type: 'user', id: user.id, label: user.name })),
                ...options.tasks.map((task) => ({ type: 'task', id: task.id, label: task.title })),
            ]
        },

        field() {
            return this.$el.querySelector('textarea')
        },

        onInput(event) {
            const field = this.field()

            if (event.target !== field) {
                return
            }

            const before = field.value.slice(0, field.selectionStart)
            const match = before.match(/(^|\s)@([^\s@[\]]{0,30})$/)

            if (!match) {
                this.open = false

                return
            }

            this.query = match[2].toLowerCase()
            this.start = before.length - match[2].length - 1
            this.matches = this.items()
                .filter((item) => item.label.toLowerCase().includes(this.query))
                .reduce((result, item) => {
                    if (result.filter((entry) => entry.type === item.type).length < 8) {
                        result.push(item)
                    }

                    return result
                }, [])
            this.active = 0
            this.open = this.matches.length > 0
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

const registerAll = () => {
    registerMentionable()
    registerTimelineBar()
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
