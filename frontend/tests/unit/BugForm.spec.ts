import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BugForm from '../../app/components/bugs/BugForm.vue'

const report = {
  project_id: 'project-1',
  title: 'Original title',
  description: 'Original description',
  steps_to_reproduce: '',
  expected_result: '',
  actual_result: '',
  environment: '',
  platform: '',
  application_version: '',
  category_id: '',
  tag_ids: [],
}

describe('BugForm', () => {
  it('renders backend field errors without clearing entered values', async () => {
    const wrapper = mount(BugForm, { props: { initialValue: report } })
    await wrapper.get('#bug-title').setValue('Value user wants to keep')
    await wrapper.get('#bug-description').setValue('Detailed reproduction context')

    await wrapper.setProps({ fieldErrors: { title: ['The title has already been used.'], description: ['More detail is required.'] } })

    expect((wrapper.get('#bug-title').element as HTMLInputElement).value).toBe('Value user wants to keep')
    expect((wrapper.get('#bug-description').element as HTMLTextAreaElement).value).toBe('Detailed reproduction context')
    expect(wrapper.get('#bug-title-error').text()).toContain('already been used')
    expect(wrapper.get('#bug-description-error').text()).toContain('More detail')
  })

  it('shows edit form when Laravel advertises edit', () => {
    const wrapper = mount(BugForm, { props: { initialValue: report, mode: 'edit', allowedActions: ['edit'] } })
    expect(wrapper.find('form').exists()).toBe(true)
    expect(wrapper.text()).toContain('ذخیره تغییرات')
  })

  it('keeps report presentation read-only without edit action', () => {
    const wrapper = mount(BugForm, { props: { initialValue: report, mode: 'edit', allowedActions: [] } })
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('فقط قابل مشاهده')
  })
})
