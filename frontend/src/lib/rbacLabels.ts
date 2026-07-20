import type { TFunction } from 'i18next'

export function roleLabel(t: TFunction, name: string): string {
  return t(`roleNames.${name}`, { defaultValue: name })
}

export function permissionLabel(t: TFunction, name: string): string {
  return t(`permissionNames.${name}`, { defaultValue: name })
}

export function formatRoleNames(t: TFunction, roles: string[]): string {
  return roles.map((name) => roleLabel(t, name)).join(' · ')
}
