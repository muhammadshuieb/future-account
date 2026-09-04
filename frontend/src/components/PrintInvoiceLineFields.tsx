import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Field, inputClass } from '@/components/ui'

export type PrintInvoiceLineDraft = {
  product_id: string
  product_name: string
  brand: string
  model: string
  quantity: string
  unit_price: string
}

export type PrintInvoiceLineProduct = {
  id: number
  name: string
  brand?: string | null
  model?: string | null
  sale_price?: number
}

type Props = {
  products: PrintInvoiceLineProduct[]
  line: PrintInvoiceLineDraft
  disabled?: boolean
  onChange: (line: PrintInvoiceLineDraft) => void
  lineIndex?: number
  /** i18n namespace for hints — defaults to printInvoices */
  hintNs?: 'printInvoices' | 'quotes'
}

const clean = (value?: string | null) => value?.trim() || ''

function matchProduct(products: PrintInvoiceLineProduct[], line: PrintInvoiceLineDraft) {
  const name = clean(line.product_name)
  if (!name) return null

  const brand = clean(line.brand)
  const model = clean(line.model)
  const matches = products.filter((product) => {
    if (product.name.trim() !== name) return false
    if (brand && clean(product.brand) !== brand) return false
    if (model && clean(product.model) !== model) return false
    return true
  })

  return matches.length === 1 ? matches[0] : null
}

/** Free-text name/brand/model for print invoices, with optional catalog suggestions. */
export default function PrintInvoiceLineFields({
  products,
  line,
  disabled = false,
  onChange,
  lineIndex = 0,
  hintNs = 'printInvoices',
}: Props) {
  const { t } = useTranslation()
  const nameListId = `free-text-product-names-${hintNs}-${lineIndex}`
  const brandListId = `free-text-brands-${hintNs}-${lineIndex}`
  const modelListId = `free-text-models-${hintNs}-${lineIndex}`

  const names = useMemo(
    () => [...new Set(products.map((p) => p.name.trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b)),
    [products],
  )
  const brands = useMemo(() => {
    const name = clean(line.product_name)
    const pool = name
      ? products.filter((p) => p.name.trim() === name)
      : products
    return [...new Set(pool.map((p) => clean(p.brand)).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  }, [products, line.product_name])
  const models = useMemo(() => {
    const name = clean(line.product_name)
    const brand = clean(line.brand)
    const pool = products.filter((p) => {
      if (name && p.name.trim() !== name) return false
      if (brand && clean(p.brand) !== brand) return false
      return true
    })
    return [...new Set(pool.map((p) => clean(p.model)).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  }, [products, line.product_name, line.brand])

  const applyPatch = (patch: Partial<PrintInvoiceLineDraft>) => {
    const next = { ...line, ...patch }
    const matched = matchProduct(products, next)
    if (matched) {
      onChange({
        ...next,
        product_id: String(matched.id),
        product_name: matched.name.trim(),
        brand: clean(matched.brand),
        model: clean(matched.model),
        unit_price: next.unit_price || String(matched.sale_price ?? ''),
      })
      return
    }

    onChange({
      ...next,
      product_id: '',
    })
  }

  return (
    <div className="form-grid-3">
      <Field label={t('common.product')} hint={t(`${hintNs}.customLineHint`)}>
        <input
          type="text"
          className={inputClass}
          list={nameListId}
          value={line.product_name}
          disabled={disabled}
          required
          placeholder={t(`${hintNs}.productPlaceholder`)}
          onChange={(e) => applyPatch({ product_name: e.target.value })}
        />
        <datalist id={nameListId}>
          {names.map((name) => (
            <option key={name} value={name} />
          ))}
        </datalist>
      </Field>

      <Field label={t('warehouse.brand')}>
        <input
          type="text"
          className={inputClass}
          list={brandListId}
          value={line.brand}
          disabled={disabled}
          placeholder={t(`${hintNs}.optionalField`)}
          onChange={(e) => applyPatch({ brand: e.target.value })}
        />
        <datalist id={brandListId}>
          {brands.map((brand) => (
            <option key={brand} value={brand} />
          ))}
        </datalist>
      </Field>

      <Field label={t('warehouse.model')}>
        <input
          type="text"
          className={inputClass}
          list={modelListId}
          value={line.model}
          disabled={disabled}
          placeholder={t(`${hintNs}.optionalField`)}
          onChange={(e) => applyPatch({ model: e.target.value })}
        />
        <datalist id={modelListId}>
          {models.map((model) => (
            <option key={model} value={model} />
          ))}
        </datalist>
      </Field>
    </div>
  )
}

export function printInvoiceLineIdentity(item: {
  product_name?: string | null
  brand?: string | null
  model?: string | null
  product?: { name?: string; brand?: string | null; model?: string | null } | null
}) {
  return {
    name: clean(item.product_name) || clean(item.product?.name) || '—',
    brand: clean(item.brand) || clean(item.product?.brand) || '—',
    model: clean(item.model) || clean(item.product?.model) || '—',
  }
}
