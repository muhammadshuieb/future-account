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

type Selection = {
  name: string
  brand: string | null
  model: string | null
}

const EMPTY_VALUE = '__PRODUCT_VARIANT_EMPTY__'
const clean = (value?: string | null) => value?.trim() || ''
const encode = (value: string) => (value === '' ? EMPTY_VALUE : value)
const decode = (value: string) => (value === EMPTY_VALUE ? '' : value)
const unique = (values: string[]) => [...new Set(values)].sort((a, b) => a.localeCompare(b))

function filterProducts(products: ProductVariantOption[], criteria: Partial<Selection>) {
  return products.filter((product) => {
    if (criteria.name && product.name.trim() !== criteria.name) return false
    if (criteria.brand !== undefined && criteria.brand !== null && clean(product.brand) !== criteria.brand) return false
    if (criteria.model !== undefined && criteria.model !== null && clean(product.model) !== criteria.model) return false
    return true
  })
}

/** Narrow selection and auto-fill sibling fields when the match becomes unique. */
function resolveSelection(products: ProductVariantOption[], input: Selection): Selection & { productId: string } {
  let { name, brand, model } = input

  if (name) {
    const forName = filterProducts(products, { name })
    if (brand !== null && !forName.some((p) => clean(p.brand) === brand)) brand = null
    if (model !== null && !forName.some((p) => clean(p.model) === model)) model = null
  }
  if (brand !== null) {
    const forBrand = filterProducts(products, { name: name || undefined, brand })
    if (name && !forBrand.some((p) => p.name.trim() === name)) name = ''
    if (model !== null && !forBrand.some((p) => clean(p.model) === model)) model = null
  }
  if (model !== null) {
    const forModel = filterProducts(products, { name: name || undefined, brand: brand ?? undefined, model })
    if (name && !forModel.some((p) => p.name.trim() === name)) name = ''
    if (brand !== null && !forModel.some((p) => clean(p.brand) === brand)) brand = null
  }

  for (let pass = 0; pass < 4; pass++) {
    const matching = filterProducts(products, {
      name: name || undefined,
      brand: brand ?? undefined,
      model: model ?? undefined,
    })

    if (matching.length === 1) {
      const product = matching[0]
      return {
        name: product.name.trim(),
        brand: clean(product.brand),
        model: clean(product.model),
        productId: String(product.id),
      }
    }

    const names = unique(matching.map((p) => p.name.trim()).filter(Boolean))
    const brands = unique(matching.map((p) => clean(p.brand)))
    const models = unique(matching.map((p) => clean(p.model)))

    let changed = false
    if (!name && names.length === 1) {
      name = names[0]
      changed = true
    }
    if (brand === null && brands.length === 1) {
      brand = brands[0]
      changed = true
    }
    if (model === null && models.length === 1) {
      model = models[0]
      changed = true
    }
    if (!changed) break
  }

  const finalMatch = filterProducts(products, {
    name: name || undefined,
    brand: brand ?? undefined,
    model: model ?? undefined,
  })

  const productId =
    name && brand !== null && model !== null && finalMatch.length === 1
      ? String(finalMatch[0].id)
      : ''

  return { name, brand, model, productId }
}

/**
 * Pick a product via name, brand, or model — any field can be searched first;
 * sibling fields filter and auto-fill when the match is unique.
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

  const applySelection = (next: Selection) => {
    const resolved = resolveSelection(products, next)
    setSelectedName(resolved.name)
    setSelectedBrand(resolved.brand)
    setSelectedModel(resolved.model)
    onChange(resolved.productId)
  }

  const names = useMemo(
    () => unique(filterProducts(products, { brand: selectedBrand ?? undefined, model: selectedModel ?? undefined })
      .map((product) => product.name.trim())
      .filter(Boolean)),
    [products, selectedBrand, selectedModel],
  )
  const brands = useMemo(
    () => unique(filterProducts(products, { name: selectedName || undefined, model: selectedModel ?? undefined })
      .map((product) => clean(product.brand))),
    [products, selectedName, selectedModel],
  )
  const models = useMemo(
    () => unique(filterProducts(products, { name: selectedName || undefined, brand: selectedBrand ?? undefined })
      .map((product) => clean(product.model))),
    [products, selectedName, selectedBrand],
  )

  const searchHint = (key: string, count: number) => (count > 1 ? t(key, { count }) : undefined)

  return (
    <div className="form-grid-3">
      <Field label={t('common.product')} hint={searchHint('common.typeToSearchHint', names.length)}>
        <SearchableSelect
          options={names.map((name) => ({ value: name, label: name }))}
          value={selectedName}
          disabled={disabled}
          required
          onChange={(name) => {
            if (name === '') {
              applySelection({ name: '', brand: null, model: null })
              return
            }
            applySelection({ name, brand: selectedBrand, model: selectedModel })
          }}
        />
      </Field>

      <Field label={t('warehouse.brand')} hint={searchHint('common.typeToSearchBrandHint', brands.length)}>
        <SearchableSelect
          options={brands.map((brand) => ({ value: encode(brand), label: brand || t('common.notSpecified') }))}
          value={selectedBrand === null ? '' : encode(selectedBrand)}
          disabled={disabled}
          required
          onChange={(encoded) => {
            if (encoded === '') {
              applySelection({ name: selectedName, brand: null, model: selectedModel })
              return
            }
            applySelection({ name: selectedName, brand: decode(encoded), model: selectedModel })
          }}
        />
      </Field>

      <Field label={t('warehouse.model')} hint={searchHint('common.typeToSearchModelHint', models.length)}>
        <SearchableSelect
          options={models.map((model) => ({ value: encode(model), label: model || t('common.notSpecified') }))}
          value={selectedModel === null ? '' : encode(selectedModel)}
          disabled={disabled}
          required
          onChange={(encoded) => {
            if (encoded === '') {
              applySelection({ name: selectedName, brand: selectedBrand, model: null })
              return
            }
            applySelection({ name: selectedName, brand: selectedBrand, model: decode(encoded) })
          }}
        />
      </Field>
    </div>
  )
}
