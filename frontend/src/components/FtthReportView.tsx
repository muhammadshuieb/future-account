import { LOGO } from '@/lib/brand'
import { formatQuantity } from '@/components/ui'
import PrintStamp from '@/components/PrintStamp'

export type FtthWorkRow = {
  type: string
  location?: string
  unit?: string
  planned?: number | string
  actual?: number | string
  notes?: string
}

export type FtthMaterialRow = {
  item: string
  unit?: string
  opening?: number | string
  received?: number | string
  used?: number | string
  notes?: string
}

export type FtthNextDayRow = {
  activity: string
  location?: string
  unit?: string
  target?: number | string
  team?: string
  resources?: string
  notes?: string
}

export type FtthResourceRow = {
  item: string
  quantity?: string
  unit?: string
  location?: string
  needed_at?: string
  owner?: string
  notes?: string
}

export type FtthReportSummary = {
  planned_excavation_m?: number
  actual_excavation_m?: number
  excavation_pct?: number
  duct_m?: number
  manholes?: number
}

export type FtthDailyReport = {
  id?: number
  report_number: string
  report_date: string
  project_name?: string
  phase?: string
  weather?: string | null
  area_sector?: string | null
  supervisor?: string | null
  contractor_team?: string | null
  workers_count?: number
  start_time?: string | null
  end_time?: string | null
  works?: FtthWorkRow[]
  materials?: FtthMaterialRow[]
  next_day_plan?: FtthNextDayRow[]
  next_day_resources?: FtthResourceRow[]
  obstacles?: string | null
  remediation?: string | null
  quality_notes?: string | null
  safety_notes?: string | null
  attachments_notes?: string | null
  next_day_obstacles?: string | null
  next_day_coordination?: string | null
  next_day_safety?: string | null
  next_day_inspection?: string | null
  next_day_goal?: string | null
  prepared_by?: string | null
  approved_by?: string | null
  status?: string
  summary?: FtthReportSummary
}

function num(v: unknown): number {
  const n = Number(v)
  return Number.isFinite(n) ? n : 0
}

function filled(v?: string | null): boolean {
  return !!v?.trim()
}

/** Rows the user never filled — hide them on print / report view. */
function isWorkFilled(w: FtthWorkRow): boolean {
  return num(w.planned) > 0 || num(w.actual) > 0 || filled(w.location) || filled(w.notes)
}

function isMaterialFilled(m: FtthMaterialRow): boolean {
  return num(m.opening) > 0 || num(m.received) > 0 || num(m.used) > 0 || filled(m.notes)
}

function isPlanFilled(p: FtthNextDayRow): boolean {
  return num(p.target) > 0 || filled(p.location) || filled(p.notes)
}

function isResourceFilled(r: FtthResourceRow): boolean {
  return filled(r.quantity) || filled(r.location) || filled(r.needed_at) || filled(r.owner) || filled(r.notes)
}

function Brand() {
  return (
    <img
      src={LOGO.print}
      alt="SYNAMOR"
      className="brand-logo brand-logo--print print-logo"
      onError={(e) => {
        const img = e.currentTarget
        if (img.dataset.fallback === '1') return
        img.dataset.fallback = '1'
        img.src = LOGO.default
      }}
    />
  )
}

function NoteBlock({ label, value }: { label: string; value?: string | null }) {
  if (!filled(value)) return null
  return (
    <div className="ftth-report__note">
      <p className="ftth-report__note-label">{label}</p>
      <p className="ftth-report__note-body">{value!.trim()}</p>
    </div>
  )
}

export default function FtthReportView({
  report,
  showNextDay = true,
}: {
  report: FtthDailyReport
  showNextDay?: boolean
}) {
  const works = (report.works || []).filter(isWorkFilled)
  const materials = (report.materials || []).filter(isMaterialFilled)
  const plan = (report.next_day_plan || []).filter(isPlanFilled)
  const resources = (report.next_day_resources || []).filter(isResourceFilled)
  const summary = report.summary || {}

  const dayNotes = [
    { label: 'المعوقات / أسباب التأخير', value: report.obstacles },
    { label: 'إجراءات المعالجة المطلوبة', value: report.remediation },
    { label: 'ملاحظات الجودة والفحص قبل الردم', value: report.quality_notes },
    { label: 'ملاحظات السلامة وحركة المرور', value: report.safety_notes },
    { label: 'الصور / أرقام المرفقات', value: report.attachments_notes },
  ].filter((n) => filled(n.value))

  const nextDayNotes = [
    { label: 'المعوقات المتوقعة', value: report.next_day_obstacles },
    { label: 'التنسيق مع البلدية / المرور / أصحاب الخدمات', value: report.next_day_coordination },
    { label: 'إجراءات السلامة قبل بدء الحفر', value: report.next_day_safety },
    { label: 'الأعمال المطلوب فحصها قبل الردم', value: report.next_day_inspection },
    { label: 'هدف نهاية اليوم', value: report.next_day_goal },
  ].filter((n) => filled(n.value))

  return (
    <div className="ftth-report" dir="rtl">
      <header className="ftth-report__header">
        <div>
          <p className="ftth-report__eyebrow">Syna Co — مشاريع البنية التحتية</p>
          <h1 className="ftth-report__title">التقرير اليومي لمشروع FTTH</h1>
          <p className="ftth-report__phase">{report.phase || 'الحفر وتمديد الدكت وتركيب المنهولات'}</p>
        </div>
        <Brand />
      </header>

      <section className="ftth-report__meta">
        <div><span>اسم المشروع</span><strong>{report.project_name || '—'}</strong></div>
        <div><span>تاريخ التقرير</span><strong>{report.report_date}</strong></div>
        <div><span>رقم التقرير</span><strong className="font-mono">{report.report_number}</strong></div>
        <div><span>حالة الطقس</span><strong>{report.weather || '—'}</strong></div>
        <div><span>المنطقة / القطاع</span><strong>{report.area_sector || '—'}</strong></div>
        <div><span>المشرف المسؤول</span><strong>{report.supervisor || '—'}</strong></div>
        <div><span>المقاول / فريق التنفيذ</span><strong>{report.contractor_team || '—'}</strong></div>
        <div><span>عدد العمال</span><strong>{report.workers_count ?? 0}</strong></div>
        <div><span>وقت البدء</span><strong>{report.start_time || '—'}</strong></div>
        <div><span>وقت الانتهاء</span><strong>{report.end_time || '—'}</strong></div>
      </section>

      <section className="ftth-report__kpis">
        <div>
          <span>الحفر المخطط</span>
          <strong>{formatQuantity(summary.planned_excavation_m ?? 0)} م</strong>
        </div>
        <div>
          <span>الحفر المنفذ</span>
          <strong>{formatQuantity(summary.actual_excavation_m ?? 0)} م</strong>
        </div>
        <div>
          <span>نسبة إنجاز الحفر</span>
          <strong>{summary.excavation_pct ?? 0}%</strong>
        </div>
        <div>
          <span>الدكت الممدد</span>
          <strong>{formatQuantity(summary.duct_m ?? 0)} م</strong>
        </div>
        <div>
          <span>المنهولات</span>
          <strong>{formatQuantity(summary.manholes ?? 0)}</strong>
        </div>
      </section>

      {works.length > 0 ? (
        <section className="ftth-report__section">
          <h2>الأعمال المنفذة خلال اليوم</h2>
          <table className="ftth-report__table">
            <thead>
              <tr>
                <th>م</th>
                <th>نوع العمل</th>
                <th>الموقع / من – إلى</th>
                <th>الوحدة</th>
                <th>المخطط</th>
                <th>المنفذ</th>
                <th>الفرق</th>
                <th>ملاحظات</th>
              </tr>
            </thead>
            <tbody>
              {works.map((w, i) => {
                const planned = num(w.planned)
                const actual = num(w.actual)
                return (
                  <tr key={i}>
                    <td>{i + 1}</td>
                    <td>{w.type}</td>
                    <td>{w.location || '—'}</td>
                    <td>{w.unit || '—'}</td>
                    <td className="tabular-nums">{formatQuantity(planned)}</td>
                    <td className="tabular-nums">{formatQuantity(actual)}</td>
                    <td className="tabular-nums">{formatQuantity(actual - planned)}</td>
                    <td>{w.notes || '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </section>
      ) : null}

      {materials.length > 0 ? (
        <section className="ftth-report__section">
          <h2>المواد والمعدات</h2>
          <table className="ftth-report__table">
            <thead>
              <tr>
                <th>م</th>
                <th>المادة / المعدة</th>
                <th>الوحدة</th>
                <th>رصيد أول اليوم</th>
                <th>المستلم</th>
                <th>المستخدم</th>
                <th>رصيد آخر اليوم</th>
                <th>ملاحظات</th>
              </tr>
            </thead>
            <tbody>
              {materials.map((m, i) => {
                const opening = num(m.opening)
                const received = num(m.received)
                const used = num(m.used)
                const closing = opening + received - used
                return (
                  <tr key={i}>
                    <td>{i + 1}</td>
                    <td>{m.item}</td>
                    <td>{m.unit || '—'}</td>
                    <td className="tabular-nums">{formatQuantity(opening)}</td>
                    <td className="tabular-nums">{formatQuantity(received)}</td>
                    <td className="tabular-nums">{formatQuantity(used)}</td>
                    <td className="tabular-nums">{formatQuantity(closing)}</td>
                    <td>{m.notes || '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </section>
      ) : null}

      {dayNotes.length > 0 ? (
        <section className="ftth-report__section">
          <h2>المعوقات والجودة والسلامة</h2>
          <div className="ftth-report__notes-grid">
            {dayNotes.map((n) => (
              <NoteBlock key={n.label} label={n.label} value={n.value} />
            ))}
          </div>
        </section>
      ) : null}

      {showNextDay ? (
        <>
          {plan.length > 0 ? (
            <section className="ftth-report__section">
              <h2>خطة العمل لليوم التالي</h2>
              <table className="ftth-report__table">
                <thead>
                  <tr>
                    <th>م</th>
                    <th>النشاط</th>
                    <th>الموقع</th>
                    <th>الوحدة</th>
                    <th>الكمية المستهدفة</th>
                    <th>الفريق</th>
                    <th>المواد / المعدات</th>
                    <th>ملاحظات</th>
                  </tr>
                </thead>
                <tbody>
                  {plan.map((p, i) => (
                    <tr key={i}>
                      <td>{i + 1}</td>
                      <td>{p.activity}</td>
                      <td>{p.location || '—'}</td>
                      <td>{p.unit || '—'}</td>
                      <td className="tabular-nums">{formatQuantity(num(p.target))}</td>
                      <td>{p.team || '—'}</td>
                      <td>{p.resources || '—'}</td>
                      <td>{p.notes || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          ) : null}

          {resources.length > 0 || nextDayNotes.length > 0 ? (
            <section className="ftth-report__section">
              {resources.length > 0 ? (
                <>
                  <h2>العمالة والمعدات والمواد المطلوبة غداً</h2>
                  <table className="ftth-report__table">
                    <thead>
                      <tr>
                        <th>م</th>
                        <th>البند</th>
                        <th>العدد / الكمية</th>
                        <th>الوحدة</th>
                        <th>الموقع / الفريق</th>
                        <th>موعد الحاجة</th>
                        <th>المسؤول</th>
                        <th>ملاحظات</th>
                      </tr>
                    </thead>
                    <tbody>
                      {resources.map((r, i) => (
                        <tr key={i}>
                          <td>{i + 1}</td>
                          <td>{r.item}</td>
                          <td>{r.quantity || '—'}</td>
                          <td>{r.unit || '—'}</td>
                          <td>{r.location || '—'}</td>
                          <td>{r.needed_at || '—'}</td>
                          <td>{r.owner || '—'}</td>
                          <td>{r.notes || '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </>
              ) : null}
              {nextDayNotes.length > 0 ? (
                <div className="ftth-report__notes-grid" style={{ marginTop: resources.length > 0 ? '0.75rem' : 0 }}>
                  {nextDayNotes.map((n) => (
                    <NoteBlock key={n.label} label={n.label} value={n.value} />
                  ))}
                </div>
              ) : null}
            </section>
          ) : null}
        </>
      ) : null}

      <footer className="ftth-report__signoff">
        <div>
          <p>إعداد المشرف</p>
          <strong>{report.prepared_by || '________________'}</strong>
        </div>
        <div>
          <p>اعتماد مدير المشروع</p>
          <strong>{report.approved_by || '________________'}</strong>
        </div>
      </footer>
      <PrintStamp />
    </div>
  )
}
