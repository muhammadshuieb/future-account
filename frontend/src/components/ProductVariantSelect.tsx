import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Field, inputClass } from '@/components/ui'

export type ProductVariantOption = {
  id: number
  name: string
  brand?: string | null
  model?: string | null
}

type Props = {
  products: ProductVariantOption[]
  value: string
  onChange: (productId: string) => void
  disabled?: boolean
}

const EMPTY_VALUE = '__PRODUCT_VARIANT_EMPTY__'
const clean = (value?: string | null) => value?.trim() || ''
const encode = (value: string) => value === '' ? EMPTY_VALUE : value
const decode = (value: string) => value === EMPTY_VALUE ? '' : value
const unique = (values: string[]) => [...new Set(values)].sort((a, b) => a.localeCompare(b))

/**
 * Selects a concrete product through unique name → brand → model choices.
 * This keeps repeated product names/brands out of transactional dropdowns.
 */
export default function ProductVariantSelect({ products, value, onChange, disabled = false }: Props) {
  const { t } = useTranslation()
  const selectedProduct = products.find((product) => String(product.id) === value)
  const [selectedName, setSelectedName] = useState(selectedProduct?.name || '')
  const [selectedBrand, setSelectedBrand] = useState<string | null>(
    selectedProduct ? clean(selectedProduct.brand) : null,
  )
  const [selectedModel, setSelectedModel] = useState<string | null>(
    selectedProduct ? clean(selectedProduct.model) : null,
  )

  useEffect(() => {
    if (!selectedProduct) return
    setSelectedName(selectedProduct.name)
    setSelectedBrand(clean(selectedProduct.brand))
    setSelectedModel(clean(selectedProduct.model))
  }, [selectedProduct])

  const names = useMemo(() => unique(products.map((product) => product.name.trim()).filter(Boolean)), [products])
  const brands = useMemo(
    () => unique(products.filter((product) => product.name.trim() === selectedName).map((product) => clean(product.brand))),
    [products, selectedName],
  )
  const models = useMemo(
    () => unique(products
      .filter((product) => product.name.trim() === selectedName && clean(product.brand) === selectedBrand)
      .map((product) => clean(product.model))),
    [products, selectedName, selectedBrand],
  )

  return (
    <>
      <Field label={t('common.product')}>
        <select
          className={inputClass}
          value={selectedName}
          disabled={disabled}
          onChange={(event) => {
            setSelectedName(event.target.value)
            setSelectedBrand(null)
            setSelectedModel(null)
            onChange('')
          }}
          required
        >
          <option value="">—</option>
          {names.map((name) => <option key={name} value={name}>{name}</option>)}
        </select>
      </Field>

      {selectedName && (
        <Field label={t('warehouse.brand')}>
          <select
            className={inputClass}
            value={selectedBrand === null ? '' : encode(selectedBrand)}
            disabled={disabled}
            onChange={(event) => {
              setSelectedBrand(decode(event.target.value))
              setSelectedModel(null)
              onChange('')
            }}
            required
          >
            <option value="">—</option>
            {brands.map((brand) => (
              <option key={encode(brand)} value={encode(brand)}>{brand || t('common.notSpecified')}</option>
            ))}
          </select>
        </Field>
      )}

      {selectedName && selectedBrand !== null && (
        <Field label={t('warehouse.model')}>
          <select
            className={inputClass}
            value={selectedModel === null ? '' : encode(selectedModel)}
            disabled={disabled}
            onChange={(event) => {
              const model = decode(event.target.value)
              setSelectedModel(model)
              const product = products.find((candidate) =>
                candidate.name.trim() === selectedName
                && clean(candidate.brand) === selectedBrand
                && clean(candidate.model) === model)
              onChange(product ? String(product.id) : '')
            }}
            required
          >
            <option value="">—</option>
            {models.map((model) => (
              <option key={encode(model)} value={encode(model)}>{model || t('common.notSpecified')}</option>
            ))}
          </select>
        </Field>
      )}
    </>
  )
}
