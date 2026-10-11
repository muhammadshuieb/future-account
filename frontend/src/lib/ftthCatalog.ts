/** Preset catalogs for FTTH daily report dropdowns. */

export const FTTH_WEATHER = ['مشمس', 'غائم جزئياً', 'غائم', 'ممطر', 'عاصف', 'ضباب', 'حار جداً'] as const

export const FTTH_UNITS = ['متر', 'عدد', 'متر مربع', 'م³', 'طن', 'ساعة'] as const

export type FtthWorkPreset = { type: string; unit: string }

export const FTTH_WORK_TYPES: FtthWorkPreset[] = [
  { type: 'الحفر', unit: 'متر' },
  { type: 'تمديد الدكت', unit: 'متر' },
  { type: 'تركيب المنهولات', unit: 'عدد' },
  { type: 'تجهيز / تسوية قاع الخندق', unit: 'متر' },
  { type: 'الرمل التحتي / الحماية', unit: 'متر' },
  { type: 'الردم والدمك', unit: 'متر' },
  { type: 'إعادة الإسفلت / البلاط', unit: 'متر مربع' },
  { type: 'فحص المناسيب والمسار', unit: 'متر' },
  { type: 'تنظيف الموقع', unit: 'متر مربع' },
  { type: 'أعمال أخرى', unit: 'متر' },
]

export type FtthMaterialPreset = { item: string; unit: string }

export const FTTH_MATERIALS: FtthMaterialPreset[] = [
  { item: 'دكت / مواسير HDPE', unit: 'متر' },
  { item: 'منهولات', unit: 'عدد' },
  { item: 'أغطية المنهولات', unit: 'عدد' },
  { item: 'وصلات الدكت', unit: 'عدد' },
  { item: 'رمل', unit: 'م³' },
  { item: 'مواد ردم', unit: 'م³' },
  { item: 'حفارة / JCB', unit: 'عدد' },
  { item: 'شاحنة نقل', unit: 'عدد' },
  { item: 'معدات دمك', unit: 'عدد' },
  { item: 'حواجز ومعدات سلامة', unit: 'عدد' },
  { item: 'حبل سحب', unit: 'متر' },
]

export type FtthActivityPreset = { activity: string; unit: string; team: string; resources: string }

export const FTTH_ACTIVITIES: FtthActivityPreset[] = [
  { activity: 'الحفر', unit: 'متر', team: 'فريق الحفر', resources: 'حفارة، شاحنة، حواجز' },
  { activity: 'تمديد الدكت', unit: 'متر', team: 'فريق الدكت', resources: 'دكت، وصلات، حبل سحب' },
  { activity: 'تركيب المنهولات', unit: 'عدد', team: 'فريق التركيب', resources: 'منهولات، معدات رفع، ميزان' },
  { activity: 'تسوية القاع وفحص المسار', unit: 'متر', team: 'المساح / المشرف', resources: 'أدوات قياس المناسيب' },
  { activity: 'الردم والدمك', unit: 'متر', team: 'فريق الردم', resources: 'مواد ردم، مدحلة / دكاك' },
  { activity: 'إعادة الوضع / تنظيف الموقع', unit: 'متر مربع', team: 'فريق الموقع', resources: 'مواد إعادة الوضع' },
  { activity: 'أعمال أخرى', unit: 'متر', team: '', resources: '' },
]

export const FTTH_TEAMS = [
  'فريق الحفر',
  'فريق الدكت',
  'فريق التركيب',
  'فريق الردم',
  'فريق الموقع',
  'المساح / المشرف',
] as const

export type FtthResourcePreset = { item: string; unit: string }

export const FTTH_RESOURCES: FtthResourcePreset[] = [
  { item: 'عمال / فنيون', unit: 'عدد' },
  { item: 'مشرف / مسّاح', unit: 'عدد' },
  { item: 'حفارة JCB', unit: 'عدد' },
  { item: 'شاحنة نقل ناتج الحفر', unit: 'عدد' },
  { item: 'دكت ومستلزماته', unit: 'متر' },
  { item: 'منهولات وأغطية', unit: 'عدد' },
  { item: 'معدات دمك', unit: 'عدد' },
  { item: 'حواجز وإشارات سلامة', unit: 'عدد' },
]

export function unitForWorkType(type: string): string {
  return FTTH_WORK_TYPES.find((w) => w.type === type)?.unit || 'متر'
}

export function unitForMaterial(item: string): string {
  return FTTH_MATERIALS.find((m) => m.item === item)?.unit || 'عدد'
}

export function presetForActivity(activity: string): FtthActivityPreset | undefined {
  return FTTH_ACTIVITIES.find((a) => a.activity === activity)
}

export function unitForResource(item: string): string {
  return FTTH_RESOURCES.find((r) => r.item === item)?.unit || 'عدد'
}
