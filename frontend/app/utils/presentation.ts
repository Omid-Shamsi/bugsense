const statusLabels: Record<string, string> = {
  submitted: 'ارسال‌شده',
  review: 'در حال بررسی',
  needs_information: 'نیازمند اطلاعات بیشتر',
  assigned: 'تخصیص داده‌شده',
  in_progress: 'در حال انجام',
  resolved: 'رفع‌شده',
  qa_verification: 'بررسی QA',
  reopened: 'بازگشایی‌شده',
  closed: 'بسته‌شده',
}

const roleLabels: Record<string, string> = {
  admin: 'مدیر',
  reporter: 'گزارش‌دهنده',
  developer: 'توسعه‌دهنده',
  qa: 'کنترل کیفیت',
  system_admin: 'مدیر کل سیستم',
}

const trackingKindLabels: Record<string, string> = {
  category: 'دسته‌بندی',
  priority: 'اولویت',
  severity: 'شدت',
  tag: 'برچسب',
  resolution_label: 'برچسب نتیجه رفع',
}

const resolutionLabels: Record<string, string> = {
  fixed: 'رفع‌شده',
  duplicate: 'تکراری',
  cannot_reproduce: 'قابل بازتولید نیست',
  wont_fix: 'رفع نخواهد شد',
}

const decisionLabels: Record<string, string> = {
  approved: 'تأییدشده',
  rejected: 'ردشده',
}

const relationshipLabels: Record<string, string> = {
  duplicate_of: 'تکراریِ',
  'duplicate of': 'تکراریِ',
  'has duplicates': 'دارای موارد تکراری',
  related_to: 'مرتبط با',
  'related to': 'مرتبط با',
  blocks: 'مسدودکننده',
  'blocked by': 'مسدودشده توسط',
}

const activityLabels: Record<string, string> = {
  created: 'ایجاد شد',
  updated: 'به‌روز شد',
  review_started: 'بررسی آغاز شد',
  information_requested: 'اطلاعات بیشتر درخواست شد',
  information_responded: 'اطلاعات تکمیلی ارسال شد',
  assigned: 'تخصیص داده شد',
  priority_set: 'اولویت تعیین شد',
  severity_set: 'شدت تعیین شد',
  work_started: 'کار آغاز شد',
  progress_recorded: 'پیشرفت ثبت شد',
  resolved: 'نتیجه رفع ثبت شد',
  non_fix_recorded: 'نتیجه رفع ثبت شد',
  qa_approved: 'نتیجه رفع تأیید شد',
  qa_rejected: 'نتیجه رفع رد شد',
  closed: 'باگ بسته شد',
  work_resumed: 'کار ادامه یافت',
  review_renewed: 'بررسی دوباره آغاز شد',
  reopened: 'باگ بازگشایی شد',
  unassigned_for_remediation: 'برای اصلاح بدون مسئول شد',
  priority_changed: 'اولویت تغییر کرد',
  severity_changed: 'شدت تغییر کرد',
  relationship_created: 'ارتباط ایجاد شد',
  relationship_deactivated: 'ارتباط غیرفعال شد',
  attachment_uploaded: 'پیوست بارگذاری شد',
  attachment_removed: 'پیوست حذف شد',
}

const actionLabels: Record<string, string> = {
  begin_review: 'شروع بررسی',
  request_information: 'درخواست اطلاعات بیشتر',
  assign_developer: 'تخصیص توسعه‌دهنده',
  reassign_developer: 'تخصیص مجدد توسعه‌دهنده',
  start_work: 'شروع کار',
  add_progress: 'ثبت پیشرفت',
  record_fixed: 'ثبت رفع باگ',
  approve_resolution: 'تأیید نتیجه رفع',
  reject_resolution: 'رد نتیجه رفع',
  resume_work: 'ادامه کار',
  renew_review: 'بررسی دوباره',
  reopen: 'بازگشایی برای بررسی',
  create_relationship: 'ایجاد ارتباط',
  deactivate: 'غیرفعال کردن',
  upload_evidence: 'بارگذاری مدرک',
  download: 'دانلود',
  remove: 'حذف',
  save_changes: 'ذخیره تغییرات',
  create_bug: 'ثبت باگ',
  refresh: 'تازه‌سازی',
  retry: 'تلاش دوباره',
}

const fieldLabels: Record<string, string> = {
  status: 'وضعیت',
  outcome: 'نتیجه',
  decision: 'تصمیم',
  relationship_type: 'نوع ارتباط',
  title: 'عنوان',
  description: 'توضیحات',
  steps_to_reproduce: 'مراحل بازتولید',
  expected_result: 'نتیجه مورد انتظار',
  actual_result: 'نتیجه واقعی',
  environment: 'محیط',
  platform: 'سکو',
  application_version: 'نسخه برنامه',
  assignee_id: 'شناسه مسئول',
  assignee_membership_id: 'شناسه عضویت مسئول',
  priority_id: 'شناسه اولویت',
  severity_id: 'شناسه شدت',
  category_id: 'شناسه دسته‌بندی',
  tag_ids: 'شناسه‌های برچسب',
  attempt_number: 'شماره تلاش',
  resolution_attempt_id: 'شناسه تلاش رفع',
  progress_update_id: 'شناسه گزارش پیشرفت',
  relationship_id: 'شناسه ارتباط',
  target_bug_id: 'شناسه باگ مقصد',
  attachment_id: 'شناسه پیوست',
  original_name: 'نام اصلی فایل',
  detected_content_type: 'نوع محتوای تشخیص‌داده‌شده',
  byte_size: 'اندازه فایل',
  active: 'فعال',
}

function normalized(value: string): string {
  return value.trim().toLowerCase()
}

export const statusLabel = (value: string): string => statusLabels[normalized(value)] || value.replaceAll('_', ' ')
export const roleLabel = (value: string): string => roleLabels[normalized(value)] || value.replaceAll('_', ' ')
export const trackingKindLabel = (value: string): string => trackingKindLabels[normalized(value)] || value.replaceAll('_', ' ')
export const resolutionLabel = (value: string): string => resolutionLabels[normalized(value)] || value.replaceAll('_', ' ')
export const decisionLabel = (value: string): string => decisionLabels[normalized(value)] || value.replaceAll('_', ' ')
export const relationshipLabel = (value: string): string => relationshipLabels[normalized(value)] || value
export const actionLabel = (value: string): string => actionLabels[normalized(value)] || value.replaceAll('_', ' ')
export const activityLabel = (value: string): string => {
  const leaf = value.split('.').pop() || value
  return activityLabels[normalized(leaf)] || leaf.replaceAll('_', ' ')
}
export const activityFieldLabel = (value: string): string => fieldLabels[normalized(value)] || value.replaceAll('_', ' ')
export const specialDisplayLabel = (value: string): string => ({
  unset: 'تعیین‌نشده',
  unassigned: 'بدون مسئول',
}[normalized(value)] || value)

export function displayValue(value: string, field = ''): string {
  if (field === 'status') return statusLabel(value)
  if (field === 'outcome') return resolutionLabel(value)
  if (field === 'decision') return decisionLabel(value)
  if (field === 'relationship_type') return relationshipLabel(value)
  return value
}

export function formatDateTime(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('fa-IR').format(value)
}
