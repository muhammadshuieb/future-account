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
const norm = (value?: string | null) => clean(value).toLocaleLowerCase()

/**
 * Unique catalog match from any filled identity fields (name / brand / model).
 * Empty fields are wildcards; all filled fields must agree. Returns null when
 * zero or multiple products match (keeps free-text custom lines working).
 */
function matchProduct(products: PrintInvoiceLineProduct[], line: PrintInvoiceLineDraft) {
  const name = norm(line.product_name)
  const brand = norm(line.brand)
  const model = norm(line.model)
  if (!name && !brand && !model) return null

  const matches = products.filter((product) => {
    if (name && norm(product.name) !== name) return false
    if (brand && norm(product.brand) !== brand) return false
    if (model && norm(product.model) !== model) return false
    return true
  })

  return matches.length === 1 ? matches[0] : null
}

/** Free-text name/brand/model for print invoices & quotes, with catalog auto-fill. */
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

  const names = useMemo(() => {
    const brand = norm(line.brand)
    const model = norm(line.model)
    const pool = products.filter((p) => {
      if (brand && norm(p.brand) !== brand) return false
      if (model && norm(p.model) !== model) return false
      return true
    })
    return [...new Set(pool.map((p) => p.name.trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  }, [products, line.brand, line.model])

  const brands = useMemo(() => {
    const name = norm(line.product_name)
    const model = norm(line.model)
    const pool = products.filter((p) => {
      if (name && norm(p.name) !== name) return false
      if (model && norm(p.model) !== model) return false
      return true
    })
    return [...new Set(pool.map((p) => clean(p.brand)).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  }, [products, line.product_name, line.model])

  const models = useMemo(() => {
    const name = norm(line.product_name)
    const brand = norm(line.brand)
    const pool = products.filter((p) => {
      if (name && norm(p.name) !== name) return false
      if (brand && norm(p.brand) !== brand) return false
      return true
    })
    return [...new Set(pool.map((p) => clean(p.model)).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  }, [products, line.product_name, line.brand])

  const applyPatch = (patch: Partial<PrintInvoiceLineDraft>) => {
    const next = { ...line, ...patch }
    const matched = matchProduct(products, next)
    if (matched) {
      const nextProductId = String(matched.id)
      const productChanged = nextProductId !== line.product_id
      onChange({
        ...next,
        product_id: nextProductId,
        product_name: matched.name.trim(),
        brand: clean(matched.brand),
        model: clean(matched.model),
        // Only replace price when the catalog product identity changes (same as sales).
        unit_price: productChanged ? String(matched.sale_price ?? '') : next.unit_price,
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
