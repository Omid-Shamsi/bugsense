import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DashboardSummary from '../../app/components/dashboard/DashboardSummary.vue'
import { createBugFilterState, type DashboardData } from '../../app/composables/useBugFilters'

const filters = createBugFilterState()
const stubs = {
  UAlert: { props: ['title', 'description'], template: '<div><span>{{ title }}</span><span>{{ description }}</span><slot /><slot name="actions" /></div>' },
  UButton: { template: '<button><slot /></button>' },
  USkeleton: { template: '<div />' },
  UIcon: { template: '<i />' },
  RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
  TechnicalValue: { props: ['value'], template: '<span>{{ value }}</span>' },
}

function mountSummary(props: Record<string, unknown>) {
  return mount(DashboardSummary, { props, global: { stubs } })
}

function summary(overrides: Partial<DashboardData> = {}): DashboardData {
  return {
    context: { filters: {}, generated_at: '2026-09-17T12:00:00Z' },
    counts: {
      total: 0,
      open: 0,
      awaiting_review: 0,
      awaiting_qa: 0,
      resolved: 0,
      qa_verification: 0,
      in_progress: 0,
      reopened: 0,
      closed: 0,
      assigned_to_current_developer: 0,
    },
    breakdowns: { severity: [], category: [], project: [], developer: [] },
    average_resolution_time: { sample_count: 0, milliseconds: null, included_outcomes: ['fixed', 'duplicate', 'cannot_reproduce', 'wont_fix'] },
    ...overrides,
  }
}

describe('DashboardSummary', () => {
  it('renders loading and error states with retry', async () => {
    const loading = mountSummary({ summary: null, loading: true, error: '', filters })
    expect(loading.text()).toContain('در حال بارگیری داشبورد')

    const problem = mountSummary({ summary: null, loading: false, error: 'Dashboard unavailable.', filters })
    expect(problem.text()).toContain('Dashboard unavailable.')
    await problem.get('button').trigger('click')
    expect(problem.emitted('retry')).toHaveLength(1)
  })

  it('renders a filtered zero-result summary as compact empty state', () => {
    const wrapper = mountSummary({ summary: summary(), loading: false, error: '', filters, filtered: true })

    expect(wrapper.text()).toContain('باگی با این جستجو و فیلترها مطابقت ندارد')
    expect(wrapper.text()).not.toContain('0 ms')
  })

  it('formats duration, retains unset buckets, and links supported drill-downs', () => {
    const data = summary({
      counts: { ...summary().counts, total: 3, open: 2 },
      breakdowns: {
        severity: [{ id: null, code: null, name: 'Unset', count: 2 }],
        category: [],
        project: [{ id: 'project-1', key: 'ALPHA', name: 'Alpha', count: 3 }],
        developer: [{ id: null, display_name: 'Unassigned', count: 1 }],
      },
      average_resolution_time: { sample_count: 2, milliseconds: 5_400_000, included_outcomes: ['fixed'] },
    })
    const wrapper = mountSummary({ summary: data, loading: false, error: '', filters })

    expect(wrapper.text()).toContain('۱ ساعت ۳۰ دقیقه')
    expect(wrapper.text()).toContain('۲ نمونه')
    expect(wrapper.text()).toContain('تعیین‌نشده')
    expect(wrapper.text()).toContain('بدون مسئول')
    expect(wrapper.html()).toContain('project')
    expect(wrapper.html()).toContain('unassigned')
  })
})
