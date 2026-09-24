import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'

type SettingRow = { key: string; value: string }

export function companyNameFromSettings(rows?: SettingRow[] | null): string {
  if (!rows?.length) return 'Syna Co'
  const ar = rows.find((s) => s.key === 'company_name')?.value?.trim()
  const en = rows.find((s) => s.key === 'company_name_en')?.value?.trim()
  return ar || en || 'Syna Co'
}

/** Shared company display name from settings (used in prints / WhatsApp). */
export function useCompanyDisplayName(): string {
  const settings = useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get('/settings')).data.data as SettingRow[],
    staleTime: 60_000,
  })
  return companyNameFromSettings(settings.data)
}
