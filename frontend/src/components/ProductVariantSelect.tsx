import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import SearchableSelect from '@/components/SearchableSelect'
import { Field } from '@/components/ui'

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
  /** A single remaining option needs no search prompt. */
  const searchHint = (key: string, count: number) => count > 1 ? t(key, { count }) : undefined

  const models = useMemo(
    () => unique(products
      .filter((product) => product.name.trim() === selectedName && clean(product.brand) === selectedBrand)
      .map((product) => clean(product.model))),
    [products, selectedName, selectedBrand],
  )

  return (
    <div className="form-grid-3">
      <Field label={t('common.product')} hint={searchHint('common.typeToSearchHint', names.length)}>
        <SearchableSelect
          options={names.map((name) => ({ value: name, label: name }))}
          value={selectedName}
          disabled={disabled}
          required
          onChange={(name) => {
            setSelectedName(name)
            setSelectedBrand(null)
            setSelectedModel(null)
            onChange('')
          }}
        />
      </Field>

      <Field label={t('warehouse.brand')} hint={selectedName ? searchHint('common.typeToSearchBrandHint', brands.length) : undefined}>
        <SearchableSelect
          options={brands.map((brand) => ({ value: encode(brand), label: brand || t('common.notSpecified') }))}
          value={selectedBrand === null ? '' : encode(selectedBrand)}
          disabled={disabled || !selectedName}
          required={Boolean(selectedName)}
          onChange={(encoded) => {
            setSelectedBrand(encoded === '' ? null : decode(encoded))
            setSelectedModel(null)
            onChange('')
          }}
        />
      </Field>

      <Field label={t('warehouse.model')} hint={selectedBrand !== null ? searchHint('common.typeToSearchModelHint', models.length) : undefined}>
        <SearchableSelect
          options={models.map((model) => ({ value: encode(model), label: model || t('common.notSpecified') }))}
          value={selectedModel === null ? '' : encode(selectedModel)}
          disabled={disabled || selectedBrand === null}
          required={selectedBrand !== null}
          onChange={(encoded) => {
            if (encoded === '') {
              setSelectedModel(null)
              onChange('')
              return
            }
            const model = decode(encoded)
            setSelectedModel(model)
            const product = products.find((candidate) =>
              candidate.name.trim() === selectedName
              && clean(candidate.brand) === selectedBrand
              && clean(candidate.model) === model)
            onChange(product ? String(product.id) : '')
          }}
        />
      </Field>
    </div>
  )
}
