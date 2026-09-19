import { describe, expect, it } from 'vitest'
import {
  createBugFilterState,
  getBugListState,
  serializeBugFilters,
} from '../../app/composables/useBugFilters'

describe('Bug filter serialization', () => {
  it('serializes trimmed search text and multiple values with Laravel array keys', () => {
    const filters = createBugFilterState()
    filters.q = '  BUG-000123  '
    filters.status = ['review', 'in_progress']
    filters.project = ['ALPHA', 'BETA']

    const params = new URLSearchParams(serializeBugFilters(filters, 'list'))

    expect(params.get('q')).toBe('BUG-000123')
    expect(params.getAll('status[]')).toEqual(['review', 'in_progress'])
    expect(params.getAll('project[]')).toEqual(['ALPHA', 'BETA'])
  })

  it('serializes backend sort, direction, and non-default pagination controls', () => {
    const filters = createBugFilterState()
    filters.sort = 'priority'
    filters.direction = 'asc'
    filters.page = 3
    filters.per_page = 50

    const params = new URLSearchParams(serializeBugFilters(filters, 'list'))

    expect(params.get('sort')).toBe('priority')
    expect(params.get('direction')).toBe('asc')
    expect(params.get('page')).toBe('3')
    expect(params.get('per_page')).toBe('50')
  })

  it('preserves the literal unassigned value and selected UUIDs', () => {
    const filters = createBugFilterState()
    filters.assignee = ['unassigned', '11111111-1111-4111-8111-111111111111']

    const params = new URLSearchParams(serializeBugFilters(filters, 'list'))

    expect(params.getAll('assignee[]')).toEqual(['unassigned', '11111111-1111-4111-8111-111111111111'])
  })

  it('omits empty filters and list-only defaults, and omits list controls from dashboards', () => {
    const filters = createBugFilterState()
    filters.q = '   '
    filters.status = ['', 'review'] as typeof filters.status
    filters.sort = 'severity'
    filters.direction = 'desc'
    filters.page = 2

    const list = new URLSearchParams(serializeBugFilters(filters, 'list'))
    const dashboard = new URLSearchParams(serializeBugFilters(filters, 'dashboard'))

    expect(list.has('q')).toBe(false)
    expect(list.getAll('status[]')).toEqual(['review'])
    expect(dashboard.getAll('status[]')).toEqual(['review'])
    expect(dashboard.has('sort')).toBe(false)
    expect(dashboard.has('direction')).toBe(false)
    expect(dashboard.has('page')).toBe(false)
    expect(dashboard.has('per_page')).toBe(false)
  })
})

describe('Bug list presentation state', () => {
  it('distinguishes loading, empty, problem, and ready states', () => {
    expect(getBugListState(true, '', 0)).toBe('loading')
    expect(getBugListState(false, '', 0)).toBe('empty')
    expect(getBugListState(false, 'Server unavailable.', 0)).toBe('error')
    expect(getBugListState(false, '', 2)).toBe('ready')
  })
})
