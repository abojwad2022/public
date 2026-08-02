/**
 * Dotted-path helpers for section content.
 *
 * Section content is a plain tree — `stories.0.title` addresses a repeater row's field. Every read
 * and write in the builder goes through here so nothing mutates state in place: React only
 * re-renders what actually changed if the objects above it changed too.
 */

/** Read a dotted path. */
export function getPath(source, path, fallback = undefined) {
  if (!path) return source
  let value = source
  for (const key of String(path).split('.')) {
    if (value === null || value === undefined || typeof value !== 'object') return fallback
    if (!(key in value)) return fallback
    value = value[key]
  }
  return value === undefined ? fallback : value
}

/** Return a copy of `source` with `path` set to `value`. Never mutates. */
export function setPath(source, path, value) {
  const keys = String(path).split('.')
  const clone = Array.isArray(source) ? [...source] : { ...(source || {}) }
  let cursor = clone

  for (let i = 0; i < keys.length - 1; i += 1) {
    const key = keys[i]
    const next = cursor[key]
    // A numeric next key means the container has to be an array, or pushing a row would silently
    // produce an object with "0" as a property and break every consumer that maps over it.
    const shouldBeArray = /^\d+$/.test(keys[i + 1])
    cursor[key] = Array.isArray(next) ? [...next] : next && typeof next === 'object' ? { ...next } : shouldBeArray ? [] : {}
    cursor = cursor[key]
  }

  cursor[keys[keys.length - 1]] = value
  return clone
}

/**
 * Does a field's `condition` hold for this content?
 * `{ field: 'source', equals: 'manual' }` — kept deliberately simple; anything richer belongs in
 * the schema, not in an expression language nobody can audit.
 */
export function conditionMet(condition, content) {
  if (!condition || !condition.field) return true
  const actual = getPath(content, condition.field)
  if ('equals' in condition) return actual === condition.equals
  if ('notEquals' in condition) return actual !== condition.notEquals
  if ('in' in condition) return Array.isArray(condition.in) && condition.in.includes(actual)
  return true
}

/** The value a responsive field shows for the active device, falling back up the breakpoints. */
export function responsiveValue(value, device) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return value
  if (!('desktop' in value)) return value
  if (device === 'mobile') return value.mobile ?? value.tablet ?? value.desktop
  if (device === 'tablet') return value.tablet ?? value.desktop
  return value.desktop
}
