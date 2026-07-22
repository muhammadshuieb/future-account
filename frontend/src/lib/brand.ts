/** Brand logo assets (SYNAMOR TECHNOLOGY visual identity for Syna Co).
 * Prefer colored (teal+gold) assets. On dark UI, place them on a light chip
 * rather than using inverted white-only marks when the full-color logo exists.
 */
export const LOGO = {
  /** Horizontal teal+gold — light backgrounds / general */
  default: '/logo.png',
  /** Horizontal teal+gold — preferred for UI & login */
  onLight: '/logo-on-light.png',
  /** White horizontal — legacy dark-bg only; prefer chip + onLight */
  onDark: '/logo-on-dark.png',
  /** Vertical stacked teal+gold */
  vertical: '/logo-vertical.png',
  /** High-res horizontal teal+gold for print headers */
  print: '/logo-print.png',
  /** Colored gear mark (teal + gold) */
  mark: '/logo-mark.png',
  /** White gear mark — legacy; prefer chip + mark */
  markOnDark: '/logo-mark-on-dark.png',
  svg: '/logo.svg',
} as const
