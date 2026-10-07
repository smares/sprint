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

if (window.Alpine) {
    registerMentionable()
} else {
    document.addEventListener('alpine:init', registerMentionable)
}
