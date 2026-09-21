import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AttachmentQueue, { type AttachmentQueueItem } from '../../app/components/bugs/AttachmentQueue.vue'

const file = new File(['report'], 'evidence.log', { type: 'text/plain' })

function item(state: AttachmentQueueItem['state'], error?: string): AttachmentQueueItem {
  return { id: 'attachment-1', file, state, error }
}

const stubs = {
  UButton: { template: '<button><slot /></button>' },
  UBadge: { template: '<span><slot /></span>' },
  UAlert: { template: '<div><slot /><slot name="description" /></div>' },
  UIcon: true,
}

describe('AttachmentQueue', () => {
  it('keeps a selected file as a local queued item before bug creation', async () => {
    const wrapper = mount(AttachmentQueue, { props: { items: [item('queued')] }, global: { stubs } })

    expect(wrapper.text()).toContain('evidence.log')
    expect(wrapper.text()).toContain('در صف بارگذاری')
    await wrapper.findAll('button').find((button) => button.text() === 'حذف')!.trigger('click')
    expect(wrapper.emitted('remove')).toEqual([['attachment-1']])
  })

  it('exposes a failed attachment reason and retries only that file', async () => {
    const wrapper = mount(AttachmentQueue, { props: { items: [item('failed', 'نوع فایل در سرور پشتیبانی نمی‌شود.')] }, global: { stubs } })

    expect(wrapper.text()).toContain('نوع فایل در سرور پشتیبانی نمی‌شود.')
    await wrapper.findAll('button').find((button) => button.text() === 'تلاش مجدد')!.trigger('click')
    expect(wrapper.emitted('retry')).toEqual([['attachment-1']])
  })
})
