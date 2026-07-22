import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import JsBarcode from 'jsbarcode'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import { LOGO } from '@/lib/brand'
import BarcodeScanInput from '@/components/BarcodeScanInput'
import { Button, EmptyState, Field, LoadingBlock, Msg, PageHeader, Panel, inputClass, useFormMessage } from '@/components/ui'

type Product = {
  id: number
  sku: string
  name: string
  barcode?: string | null
  sale_price: number
}

type Label = {
  product_id: number
  sku: string
  name: string
  barcode: string
  format: string
  price: number
  company: string
}

function BarcodeSvg({ value, format }: { value: string; format: string }) {
  const ref = useRef<SVGSVGElement>(null)
  useEffect(() => {
    if (!ref.current || !value) return
    try {
      JsBarcode(ref.current, value, {
        format: format === 'EAN13' ? 'EAN13' : format === 'EAN8' ? 'EAN8' : 'CODE128',
        displayValue: true,
        fontSize: 12,
        height: 48,
        margin: 4,
        width: 1.4,
      })
    } catch {
      JsBarcode(ref.current, value, { format: 'CODE128', displayValue: true, fontSize: 12, height: 48, margin: 4, width: 1.4 })
    }
  }, [value, format])
  return <svg ref={ref} className="mx-auto max-w-full" />
}

export default function BarcodeLabelsPage() {
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [selected, setSelected] = useState<number[]>([])
  const [copies, setCopies] = useState(1)
  const [labels, setLabels] = useState<Label[]>([])

  const products = useQuery({
    queryKey: ['products'],
    queryFn: async () => (await api.get('/products')).data.data as Product[],
  })

  const loadLabels = useMutation({
    mutationFn: async () => {
      const params = new URLSearchParams()
      params.set('copies', String(copies))
      selected.forEach((id) => params.append('product_ids[]', String(id)))
      return (await api.get(`/barcodes/labels?${params}`)).data.data as { labels: Label[] }
    },
    onSuccess: (data) => {
      setLabels(data.labels)
      msg.setMessage(`تم تجهيز ${data.labels.length} ملصق`)
    },
    onError: msg.fromErr,
  })

  const genBarcode = useMutation({
    mutationFn: (id: number) => api.post(`/products/${id}/barcode`, { save: true }),
    onSuccess: () => {
      msg.setMessage('تم توليد وحفظ الباركود')
      void qc.invalidateQueries({ queryKey: ['products'] })
    },
    onError: msg.fromErr,
  })

  const allIds = useMemo(() => (products.data || []).map((p) => p.id), [products.data])

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  function toggleAll() {
    setSelected((prev) => (prev.length === allIds.length ? [] : allIds))
  }

  async function handleBarcodeSelect(code: string) {
    try {
      const res = await api.get(`/products?barcode=${encodeURIComponent(code)}`)
      const found = (res.data.data as Product[])[0]
      if (!found) {
        msg.setError('لم يُعثر على صنف بهذا الباركود')
        return
      }
      setSelected((prev) => (prev.includes(found.id) ? prev : [...prev, found.id]))
      msg.setMessage(`تم تحديد: ${found.name}`)
    } catch {
      msg.setError('تعذر البحث بالباركود')
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="الباركود والملصقات"
        subtitle="توليد باركود الأصناف وطباعة ملصقات Code128 / EAN"
        actions={
          <Button variant="secondary" className="print-hide" onClick={() => window.print()} disabled={!labels.length}>
            <Printer size={16} /> طباعة الملصقات
          </Button>
        }
      />

      <Msg message={msg.message} error={msg.error} />

      <div className="print-hide grid gap-6 lg:grid-cols-2">
        <Panel>
          <div className="flex items-center justify-between border-b border-[var(--color-line)] px-4 py-3">
            <h2 className="font-semibold">اختيار الأصناف</h2>
            <button type="button" className="text-xs text-teal" onClick={toggleAll}>تحديد الكل / إلغاء</button>
          </div>
          {products.isLoading && <LoadingBlock />}
          <ul className="max-h-96 divide-y divide-[var(--color-line)] overflow-y-auto">
            {(products.data || []).map((p) => (
              <li key={p.id} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                <input type="checkbox" checked={selected.includes(p.id)} onChange={() => toggle(p.id)} />
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{p.name}</p>
                  <p className="text-xs text-black/45 font-mono">{p.sku} · {p.barcode || 'بدون باركود'}</p>
                </div>
                {!p.barcode && (
                  <Button variant="ghost" className="!px-2 !py-1 text-xs" onClick={() => genBarcode.mutate(p.id)}>
                    توليد
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </Panel>

        <Panel className="space-y-4 p-4">
          <BarcodeScanInput
            label="مسح لتحديد صنف"
            hint="امسح باركوداً لإضافته لقائمة الطباعة"
            onScan={(code) => void handleBarcodeSelect(code)}
          />
          <Field label="عدد النسخ لكل صنف">
            <input type="number" min={1} max={20} className={inputClass} value={copies} onChange={(e) => setCopies(Number(e.target.value) || 1)} />
          </Field>
          <Button
            variant="primary"
            disabled={!selected.length || loadLabels.isPending}
            onClick={() => loadLabels.mutate()}
          >
            تجهيز ملصقات للطباعة
          </Button>
          <p className="text-xs text-black/45">
            عند الطباعة تُخفى القائمة والإطار — تظهر شبكة الملصقات فقط. يمكن حفظ PDF من مربع حوار الطباعة في المتصفح.
          </p>
        </Panel>
      </div>

      <div className="print-area">
        {!labels.length ? (
          <div className="print-hide">
            <EmptyState title="لا توجد ملصقات بعد" description="اختر أصنافاً ثم اضغط تجهيز ملصقات." />
          </div>
        ) : (
          <>
            <div className="mb-3 hidden w-full items-start justify-between gap-2 border-b border-black/10 pb-2 print:flex">
              <p className="text-xs font-semibold">شركة ساينا — Syna Co</p>
              <img src={LOGO.mark} alt="" className="brand-logo brand-logo--barcode-mark" />
            </div>
            <div className="barcode-sheet grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {labels.map((l, i) => (
                <div key={`${l.product_id}-${i}`} className="barcode-label rounded-lg border border-[var(--color-line)] bg-white p-3 text-center">
                  <p className="text-[10px] text-black/45">{l.company}</p>
                  <p className="text-sm font-semibold leading-tight">{l.name}</p>
                  <p className="text-xs text-black/50 font-mono">{l.sku}</p>
                  <BarcodeSvg value={l.barcode} format={l.format} />
                  <p className="text-xs font-medium">{Number(l.price).toLocaleString('ar')}</p>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
