import { useId, useMemo, useRef, useState } from 'react'

/**
 * Time-series chart primitives.
 *
 * These replace a row of scaled <div>s that had no axes, no scale, no legend and
 * only native `title` tooltips — which meant the reader could see a shape but
 * could not read a value off it.
 *
 * Design rules applied here:
 *  - one measure, one axis. Never two y-scales.
 *  - recessive grid and axes; the data is the only thing with weight.
 *  - a hover layer is standard, not an extra: crosshair + tooltip on the series.
 *  - values and labels wear text tokens, never the series colour.
 *  - every chart ships a visually-hidden table, so the numbers are readable by
 *    assistive tech and by anyone who wants the figures rather than the shape.
 */

/* ------------------------------------------------------------------ helpers */

/** "Nice" axis ceiling — 1/2/5 × 10ⁿ at or above the data max. */
function niceCeil(value) {
  if (value <= 0) return 1
  const magnitude = 10 ** Math.floor(Math.log10(value))
  const scaled = value / magnitude
  const step = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10
  return step * magnitude
}

function compact(n) {
  const abs = Math.abs(n)
  if (abs >= 1_000_000) return `${(n / 1_000_000).toFixed(abs >= 10_000_000 ? 0 : 1)}M`
  if (abs >= 1000) return `${(n / 1000).toFixed(abs >= 10_000 ? 0 : 1)}k`
  return String(Math.round(n * 100) / 100)
}

/** Screen-reader table. The chart is aria-hidden; this carries the actual data. */
function DataTable({ id, caption, rows, labelHeading, valueHeading, format }) {
  return (
    <table id={id} className="sr-only">
      <caption>{caption}</caption>
      <thead>
        <tr>
          <th scope="col">{labelHeading}</th>
          <th scope="col">{valueHeading}</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.label}>
            <th scope="row">{row.label}</th>
            <td>{format(row.value)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

/* --------------------------------------------------------------- Sparkline */

/**
 * Bare trend line for a stat tile — no axes, no labels, no interaction. It answers
 * "which way is this going", and the tile's own number answers "how much".
 */
export function Sparkline({ points, tone = 'var(--yz-agate)', height = 32, className = '' }) {
  const values = (points || []).map((p) => Number(p) || 0)
  if (values.length < 2) return null

  const max = Math.max(...values)
  const min = Math.min(...values)
  const span = max - min || 1
  const width = 100

  const d = values
    .map((v, i) => {
      const x = (i / (values.length - 1)) * width
      const y = height - ((v - min) / span) * (height - 4) - 2
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`
    })
    .join(' ')

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={`w-full ${className}`}
      style={{ height }}
      aria-hidden="true"
      focusable="false"
    >
      <path d={d} fill="none" stroke={tone} strokeWidth="2" vectorEffect="non-scaling-stroke"
        strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

/* ------------------------------------------------------------- BarSeries */

/**
 * Daily magnitude over time. Bars rather than a line because each day is a
 * discrete total that gets compared to its neighbours, and because a bar anchored
 * to the baseline encodes magnitude honestly.
 *
 * @param {Array}    data      [{ label, value, meta? }]
 * @param {Function} format    value -> display string (currency, count, …)
 */
export function BarSeries({
  data,
  format = (v) => String(v),
  height = 200,
  tone = 'var(--yz-viz-1)',
  caption = 'Values over time',
  valueHeading = 'Value',
  emptyLabel = 'No data in this period.',
}) {
  const tableId = useId()
  const [hover, setHover] = useState(null)
  const wrapRef = useRef(null)

  // Memoized so the `|| []` fallback does not hand `max` a fresh array on every render.
  const rows = useMemo(() => data || [], [data])
  const max = useMemo(() => niceCeil(Math.max(0, ...rows.map((r) => Number(r.value) || 0))), [rows])

  if (!rows.length || max <= 0) {
    return <p className="py-10 text-center text-sm text-muted">{emptyLabel}</p>
  }

  // Geometry in a fixed viewBox; the SVG scales to its container.
  const W = 720
  const H = height
  const PAD_S = 46 // room for y labels
  const PAD_B = 22 // room for x labels
  const plotW = W - PAD_S - 8
  const plotH = H - PAD_B - 10

  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * max)
  const slot = plotW / rows.length
  // 2px surface gap between adjacent bars, and never thinner than a hairline.
  const barW = Math.max(1, slot - 2)

  const active = hover !== null ? rows[hover] : null

  return (
    <figure className="m-0" ref={wrapRef}>
      <div className="relative">
        <svg
          viewBox={`0 0 ${W} ${H}`}
          className="block w-full"
          style={{ height }}
          role="img"
          aria-labelledby={tableId}
          onMouseLeave={() => setHover(null)}
        >
          {/* Gridlines + y axis — deliberately recessive. */}
          {ticks.map((t) => {
            const y = 10 + plotH - (t / max) * plotH
            return (
              <g key={t}>
                <line
                  x1={PAD_S} x2={W - 8} y1={y} y2={y}
                  stroke="var(--yz-viz-grid)" strokeWidth="1" shapeRendering="crispEdges"
                />
                <text
                  x={PAD_S - 8} y={y + 3.5} textAnchor="end"
                  fill="var(--yz-viz-axis)" fontSize="10"
                  style={{ fontVariantNumeric: 'tabular-nums' }}
                >
                  {compact(t)}
                </text>
              </g>
            )
          })}

          {rows.map((row, i) => {
            const value = Number(row.value) || 0
            const barH = value > 0 ? Math.max(2, (value / max) * plotH) : 0
            const x = PAD_S + i * slot + (slot - barW) / 2
            const y = 10 + plotH - barH
            const isHover = hover === i
            return (
              <g key={row.label}>
                {/* Hit target spans the full column height, so thin bars stay hoverable. */}
                <rect
                  x={PAD_S + i * slot} y={10} width={slot} height={plotH}
                  fill="transparent"
                  onMouseEnter={() => setHover(i)}
                />
                {barH > 0 && (
                  <rect
                    x={x} y={y} width={barW} height={barH}
                    rx={Math.min(3, barW / 2)}
                    fill={tone}
                    opacity={hover === null || isHover ? 1 : 0.45}
                    pointerEvents="none"
                  />
                )}
              </g>
            )
          })}

          {/* Baseline sits above the bars so it reads as an axis, not a mark. */}
          <line
            x1={PAD_S} x2={W - 8} y1={10 + plotH} y2={10 + plotH}
            stroke="var(--yz-viz-axis)" strokeWidth="1" shapeRendering="crispEdges"
          />

          {/* Selective x labels only — never one per bar. */}
          {[0, Math.floor(rows.length / 2), rows.length - 1]
            .filter((i, idx, arr) => rows[i] && arr.indexOf(i) === idx)
            .map((i, idx, arr) => (
              <text
                key={rows[i].label}
                x={PAD_S + i * slot + slot / 2}
                y={H - 6}
                textAnchor={idx === 0 ? 'start' : idx === arr.length - 1 ? 'end' : 'middle'}
                fill="var(--yz-viz-axis)"
                fontSize="10"
              >
                {rows[i].label}
              </text>
            ))}
        </svg>

        {/* Tooltip in HTML rather than SVG so it inherits type and elevation tokens. */}
        {active && (
          <div
            className="pointer-events-none absolute top-1 rounded-md border border-edge bg-raised px-2.5 py-1.5 shadow-2"
            style={{
              insetInlineStart: `calc(${((hover + 0.5) / rows.length) * 100}% )`,
              transform: 'translateX(-50%)',
            }}
          >
            <p className="text-2xs text-faint">{active.label}</p>
            <p className="yz-num text-sm font-medium text-fg">{format(active.value)}</p>
            {active.meta && <p className="text-2xs text-muted">{active.meta}</p>}
          </div>
        )}
      </div>

      <DataTable
        id={tableId}
        caption={caption}
        rows={rows}
        labelHeading="Date"
        valueHeading={valueHeading}
        format={format}
      />
    </figure>
  )
}
