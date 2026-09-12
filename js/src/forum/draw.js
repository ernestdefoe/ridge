/**
 * Draw the ridge behind the scrubber.
 *
 * 🚨 Shading, not markers. Threadmarks and friends put pins ON the scrubber,
 * and a second extension adding pins to the same forty pixels would be a
 * fight the reader loses. This paints the track itself, so the two can be
 * installed together and each still reads.
 */
const BUCKETS = 60;
// Strong enough to find at a glance in a 7px strip; the strip is small, so it
// can afford more colour than a full-width wash could.
const MAX_ALPHA = 0.85;

/**
 * A peak is one post, but a post is a fraction of a pixel on the track of a
 * 400-post thread. Spreading each one over its neighbours turns a set of
 * invisible spikes into the bands a reader can actually aim at — and it is
 * honest, because "around here" is the truth being told.
 */
const KERNEL = [0.25, 0.5, 1, 0.5, 0.25];

export function gradientFor(heat, tint) {
  if (!heat?.enough || !heat.peaks?.length || heat.last < 2) return null;

  const buckets = new Array(BUCKETS).fill(0);

  heat.peaks.forEach(({ number, value }) => {
    const centre = Math.round(((number - 1) / (heat.last - 1)) * (BUCKETS - 1));

    KERNEL.forEach((weight, i) => {
      const at = centre + i - Math.floor(KERNEL.length / 2);

      if (at < 0 || at >= BUCKETS) return;

      buckets[at] = Math.max(buckets[at], value * weight);
    });
  });

  const highest = Math.max(...buckets);

  if (highest <= 0) return null;

  const stops = buckets.map((value, i) => {
    const alpha = ((value / highest) * MAX_ALPHA).toFixed(3);
    const at = ((i / (BUCKETS - 1)) * 100).toFixed(2);

    return `rgba(${tint[0]}, ${tint[1]}, ${tint[2]}, ${alpha}) ${at}%`;
  });

  return `linear-gradient(to bottom, ${stops.join(', ')})`;
}

/**
 * The theme's accent, as numbers.
 *
 * 🚨 Read and parsed rather than used as `var(--primary-color)` inside the
 * gradient: sixty stops each carrying a `color-mix()` is a string no browser
 * should have to re-parse on every scroll, and relative colour syntax is not
 * old enough to rely on yet.
 */
export function tintFrom(element) {
  const raw = getComputedStyle(element).getPropertyValue('--primary-color').trim();

  return parseColour(raw) || [74, 144, 217];
}

function parseColour(value) {
  if (!value) return null;

  const hex = value.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);

  if (hex) {
    const digits = hex[1].length === 3 ? hex[1].split('').map((d) => d + d) : hex[1].match(/../g);

    return digits.map((d) => parseInt(d, 16));
  }

  const rgb = value.match(/rgba?\(([^)]+)\)/i);

  if (rgb) {
    const parts = rgb[1].split(/[\s,/]+/).filter(Boolean).slice(0, 3).map(Number);

    return parts.length === 3 && parts.every((n) => !Number.isNaN(n)) ? parts : null;
  }

  // hsl() and named colours: let the browser do the conversion for us.
  const probe = document.createElement('span');

  probe.style.color = value;
  document.body.appendChild(probe);

  const computed = getComputedStyle(probe).color;

  probe.remove();

  return parseColour(computed);
}
