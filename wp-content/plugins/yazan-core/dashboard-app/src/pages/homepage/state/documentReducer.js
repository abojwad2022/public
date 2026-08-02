/**
 * The builder's document state, with undo/redo.
 *
 * History holds SNAPSHOTS of the sections array, capped at 50 steps. The architecture note
 * proposed inverse patches for constant memory; a capped snapshot stack reaches the same practical
 * ceiling with a fraction of the code, and a homepage document is a few kilobytes of JSON. Noted
 * rather than quietly swapped.
 *
 * `version` is the server's optimistic-lock token. It is NOT part of the undo history: undoing an
 * edit must not rewind the server's idea of where we are, or the next save would 409.
 */
import { setPath } from '../lib/paths.js'

const HISTORY_LIMIT = 50

export const initialState = {
  sections: [],
  title: '',
  hasLiveContent: false,
  version: 0,
  status: 'draft',
  scheduledAt: null,
  previewUrl: '',
  hasUnpublishedChanges: false,
  unavailable: [],
  selectedId: null,
  dirty: false,
  past: [],
  future: [],
}

/** Push the current sections onto the undo stack. */
function remember(state) {
  return {
    past: [...state.past, state.sections].slice(-HISTORY_LIMIT),
    future: [],
  }
}

export function documentReducer(state, action) {
  switch (action.type) {
    case 'load':
      return {
        ...initialState,
        sections: action.document.sections || [],
        title: action.document.title || '',
        hasLiveContent: Boolean(action.document.has_live_content),
        version: action.document.version,
        status: action.document.status,
        scheduledAt: action.document.scheduled_at ?? null,
        previewUrl: action.document.preview_url || '',
        hasUnpublishedChanges: action.document.has_unpublished_changes,
        selectedId: action.document.sections?.[0]?.id ?? null,
      }

    // The server is the authority on version and on what was actually stored — a blocked field
    // comes back with its old value, and the editor must show that truth, not the rejected input.
    case 'synced':
      return {
        ...state,
        version: action.version,
        status: action.status ?? state.status,
        scheduledAt: action.scheduledAt ?? state.scheduledAt,
        hasUnpublishedChanges: action.hasUnpublishedChanges ?? state.hasUnpublishedChanges,
        hasLiveContent: action.hasLiveContent ?? state.hasLiveContent,
        unavailable: action.unavailable ?? state.unavailable,
        sections: action.sections ?? state.sections,
        dirty: false,
      }

    case 'select':
      return { ...state, selectedId: action.id }

    case 'setField': {
      const sections = state.sections.map((section) =>
        section.id === action.id
          ? { ...section, content: setPath(section.content, action.path, action.value) }
          : section,
      )
      return { ...state, ...remember(state), sections, dirty: true }
    }

    // The design payload is a sibling of content, not a branch of it — so it has its own action
    // rather than a magic 'design.' prefix on setField that every reader would have to know about.
    case 'setDesignField': {
      const sections = state.sections.map((section) =>
        section.id === action.id
          ? { ...section, design: setPath(section.design || {}, action.path, action.value) }
          : section,
      )
      return { ...state, ...remember(state), sections, dirty: true }
    }

    case 'setSection': {
      const sections = state.sections.map((section) =>
        section.id === action.id ? { ...section, ...action.changes } : section,
      )
      return { ...state, ...remember(state), sections, dirty: true }
    }

    case 'add': {
      const sections = [...state.sections]
      const at = action.position ?? sections.length
      sections.splice(at, 0, action.section)
      return { ...state, ...remember(state), sections, selectedId: action.section.id, dirty: true }
    }

    case 'remove': {
      const sections = state.sections.filter((section) => section.id !== action.id)
      const selectedId = state.selectedId === action.id ? (sections[0]?.id ?? null) : state.selectedId
      return { ...state, ...remember(state), sections, selectedId, dirty: true }
    }

    case 'move': {
      const sections = [...state.sections]
      const [moved] = sections.splice(action.from, 1)
      if (!moved) return state
      sections.splice(action.to, 0, moved)
      return { ...state, ...remember(state), sections, dirty: true }
    }

    case 'undo': {
      if (!state.past.length) return state
      const previous = state.past[state.past.length - 1]
      return {
        ...state,
        sections: previous,
        past: state.past.slice(0, -1),
        future: [state.sections, ...state.future].slice(0, HISTORY_LIMIT),
        dirty: true,
      }
    }

    case 'redo': {
      if (!state.future.length) return state
      const [next, ...rest] = state.future
      return {
        ...state,
        sections: next,
        past: [...state.past, state.sections].slice(-HISTORY_LIMIT),
        future: rest,
        dirty: true,
      }
    }

    default:
      return state
  }
}
