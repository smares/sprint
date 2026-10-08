import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Live updates: the layout only loads this file when Reverb is switched on and passes the connection
// settings in window.sprintRealtime (see docs/configuration.md). It runs before Livewire starts, so the
// components' Echo listeners find window.Echo.
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
