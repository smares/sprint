/*
 * The background: soft patches of color and, over them, a fan of thin waves with small points of light running along them
 * (the same idea as the login page of Stage Force). Nothing is an image: every wave is a sum of a few sines of the position
 * across the window, the fan opens towards the left, and the time is the clock, so the picture neither starts over on a page
 * change nor on a reload. It is drawn small (at most 40% of the size of the window, the browser scales it up, and soft shapes
 * do not mind) and at 12 frames a second, and not at all while the page is hidden. With "reduce motion" one still picture is
 * drawn, with forced colors nothing.
 */

// The movement is slow: a dozen pictures a second look the same as more, and every picture costs the main thread
const FRAME_MS = 1000 / 12

const palettes = {
    // The colors of the brand: action blue, violet and accent pink (the gradient of the manual), pale on the light page
    light: {
        patches: [
            { x: 0.2, y: 0.22, radius: 0.67, color: '165,190,255', alpha: 0.22 },
            { x: 0.78, y: 0.68, radius: 0.61, color: '205,185,250', alpha: 0.2 },
            { x: 0.84, y: 0.15, radius: 0.44, color: '250,170,210', alpha: 0.14 },
        ],
        waves: ['37,99,255', '123,63,228', '229,0,122'],
        glow: 0.025,
        line: 0.14,
        point: '37,99,255',
        pointAlpha: 0.2,
        composite: 'source-over',
    },
    // Deep blue and violet on the ink of the dark mode, with a trace of magenta; the lines are the light variants of the colors
    dark: {
        patches: [
            { x: 0.2, y: 0.22, radius: 0.67, color: '37,70,190', alpha: 0.22 },
            { x: 0.78, y: 0.68, radius: 0.61, color: '88,52,170', alpha: 0.18 },
            { x: 0.84, y: 0.15, radius: 0.44, color: '170,20,100', alpha: 0.1 },
        ],
        waves: ['124,155,255', '176,140,245', '255,110,180'],
        glow: 0.02,
        line: 0.12,
        point: '181,200,255',
        pointAlpha: 0.22,
        composite: 'screen',
    },
}

/** Height of wave number $line at $at (0 to 1 across the window) as a fraction of the window; the waves spread out to the left. */
const waveHeight = (at, line, time) => {
    const spread = (1 - at) * (1 - at)
    const swell = Math.sin(at * 6.2 - time * 0.075) * 0.13 + Math.sin(at * 11 + time * 0.045) * 0.035
    const ripple = (Math.sin(at * 19 + line * 0.7 + time * 0.07) * 0.028 + Math.sin(at * 39 - line + time * 0.035) * 0.012) * spread

    return 0.49 + swell + line * 0.012 * (0.2 + spread * 2.7) + ripple
}

const draw = (canvas, context, palette, time) => {
    const { width, height } = canvas

    context.clearRect(0, 0, width, height)
    context.globalCompositeOperation = 'source-over'

    palette.patches.forEach((patch, index) => {
        const drift = index === 0 ? Math.sin(time * 0.025) * 0.04 : 0
        const lift = index === 1 ? Math.sin(time * 0.03) * 0.04 : 0
        const x = (patch.x + drift) * width
        const y = (patch.y + lift) * height
        const gradient = context.createRadialGradient(x, y, 0, x, y, patch.radius * Math.max(width, height))

        gradient.addColorStop(0, `rgba(${patch.color},${patch.alpha})`)
        gradient.addColorStop(1, `rgba(${patch.color},0)`)
        context.fillStyle = gradient
        context.fillRect(0, 0, width, height)
    })

    context.globalCompositeOperation = palette.composite

    for (let line = -12; line <= 12; line++) {
        context.beginPath()

        for (let step = 0; step <= 72; step++) {
            const at = step / 72
            const x = (at * 1.1 - 0.05) * width
            const y = waveHeight(at, line, time) * height

            step === 0 ? context.moveTo(x, y) : context.lineTo(x, y)
        }

        const color = palette.waves[(line + 12) % palette.waves.length]

        context.strokeStyle = `rgba(${color},${palette.glow})`
        context.lineWidth = 5
        context.stroke()
        context.strokeStyle = `rgba(${color},${palette.line})`
        context.lineWidth = 0.65
        context.stroke()
    }

    for (let index = 0; index < 26; index++) {
        const at = (index * 0.381966 + time * 0.0035) % 1
        const line = (index % 13 - 6) * 1.5
        const x = (at * 1.1 - 0.05) * width
        const y = waveHeight(at, line, time) * height
        const gradient = context.createRadialGradient(x, y, 0, x, y, 5)

        gradient.addColorStop(0, `rgba(${palette.point},${Math.sin(Math.PI * at) * palette.pointAlpha})`)
        gradient.addColorStop(1, `rgba(${palette.point},0)`)
        context.fillStyle = gradient
        context.fillRect(x - 5, y - 5, 10, 10)
    }

    context.globalCompositeOperation = 'source-over'
}

let stop = null

export const startAmbientBackground = () => {
    const layer = document.querySelector('.ambient')
    const canvas = layer?.querySelector('canvas')

    if (! canvas || canvas.dataset.running) {
        return
    }

    const context = canvas.getContext('2d')

    if (! context) {
        return
    }

    canvas.dataset.running = 'true'

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)')
    const forcedColors = window.matchMedia('(forced-colors: active)')
    let frame = 0
    let timer = 0

    const palette = () => (document.documentElement.classList.contains('dark') ? palettes.dark : palettes.light)
    const render = () => draw(canvas, context, palette(), Date.now() / 1000)
    const mode = () => layer.dataset.mode ?? 'animated'
    const canRun = () => mode() === 'animated' && ! document.hidden && ! reducedMotion.matches && ! forcedColors.matches

    // A timer decides when a picture is due and the frame callback draws it, so nothing runs between the pictures (a frame callback of
    // its own would wake the page 60 times a second to do nothing)
    const tick = () => {
        timer = 0

        if (! canRun()) {
            return
        }

        frame = requestAnimationFrame(render)
        timer = setTimeout(tick, FRAME_MS)
    }

    const cancel = () => {
        cancelAnimationFrame(frame)
        clearTimeout(timer)
        frame = 0
        timer = 0
    }

    const restart = () => {
        cancel()

        if (mode() === 'off' || document.hidden || forcedColors.matches) {
            return
        }

        render()

        if (canRun()) {
            timer = setTimeout(tick, FRAME_MS)
        }
    }

    const resize = () => {
        const scale = Math.min(0.4, 640 / window.innerWidth, 360 / window.innerHeight)

        canvas.width = Math.max(1, Math.round(window.innerWidth * scale))
        canvas.height = Math.max(1, Math.round(window.innerHeight * scale))
        restart()
    }

    // The choice in the user menu (see user-menu.blade.php)
    const switched = (event) => {
        layer.dataset.mode = event.detail?.mode ?? event.detail?.[0]?.mode ?? 'animated'
        restart()
    }

    const appearance = new MutationObserver(restart)

    appearance.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
    window.addEventListener('ambient-background', switched)
    window.addEventListener('resize', resize)
    document.addEventListener('visibilitychange', restart)
    reducedMotion.addEventListener('change', restart)
    forcedColors.addEventListener('change', restart)

    stop = () => {
        cancel()
        appearance.disconnect()
        window.removeEventListener('ambient-background', switched)
        window.removeEventListener('resize', resize)
        document.removeEventListener('visibilitychange', restart)
        reducedMotion.removeEventListener('change', restart)
        forcedColors.removeEventListener('change', restart)
        delete canvas.dataset.running
        stop = null
    }

    resize()
}

/** What another device chose follows on a page change (like the appearance, see followServerAppearance in interface.js). */
const followServerChoice = () => {
    const layer = document.querySelector('.ambient')
    const served = window.sprintInterface?.ambient

    // A change of this device that has not reached the server yet is newer than what the page brought
    if (! layer || ! served || window.sprintHasUnsentChanges?.() || layer.dataset.mode === served) {
        return
    }

    window.dispatchEvent(new CustomEvent('ambient-background', { detail: { mode: served } }))
}

/** The canvas is kept across page changes (@persist); should it ever be replaced, the drawing starts again on the new one. */
document.addEventListener('livewire:navigated', () => {
    if (stop && ! document.querySelector('.ambient canvas[data-running]')) {
        stop()
    }

    startAmbientBackground()
    followServerChoice()
})
