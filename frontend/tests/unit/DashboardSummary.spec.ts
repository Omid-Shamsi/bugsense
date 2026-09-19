import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DashboardSummary from '../../app/components/dashboard/DashboardSummary.vue'
import type { DashboardData } from '../../app/composables/useBugFilters'

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
  it('renders loading and problem states with retry', async () => {
    const loading = mount(DashboardSummary, { props: { summary: null, loading: true, error: '' } })
    expect(loading.text()).toContain('در حال بارگیری داشبورد')

    const problem = mount(DashboardSummary, { props: { summary: null, loading: false, error: 'Dashboard unavailable.' } })
    expect(problem.text()).toContain('Dashboard unavailable.')
    await problem.get('button').trigger('click')
    expect(problem.emitted('retry')).toHaveLength(1)
  })

  it('renders a zero-result summary as empty and zero samples as no data', () => {
    const wrapper = mount(DashboardSummary, { props: { summary: summary(), loading: false, error: '' } })

    expect(wrapper.text()).toContain('باگی با این فیلترها مطابقت ندارد')
    expect(wrapper.text()).toContain('نمونه تکمیل‌شده‌ای وجود ندارد')
    expect(wrapper.text()).not.toContain('0 ms')
  })

  it('formats valid resolution milliseconds and retains server unset buckets', () => {
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
    const wrapper = mount(DashboardSummary, { props: { summary: data, loading: false, error: '' } })

    expect(wrapper.text()).toContain('۱ ساعت ۳۰ دقیقه')
    expect(wrapper.text()).toContain('۲ نمونه')
    expect(wrapper.text()).toContain('تعیین‌نشده')
    expect(wrapper.text()).toContain('بدون مسئول')
  })
})
