import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  AlertTriangle,
  ArrowLeftRight,
  ClipboardCheck,
  ClipboardList,
  Package,
  Warehouse,
} from 'lucide-react'
import api from '@/lib/api'
import { EmptyState, LoadingBlock, Panel, StatTile, formatMoney, inputClass } from '@/components/ui'

type WarehouseOption = { id: number; name: string; code: string }
type LowStockAlert = {
  product_id: number
  sku: string
  name: string
  warehouse_name: string
  on_hand: number
  reorder_level: number
}
type ApprovalRequest = {
  id: number
  action_type: string
  status: string
  created_at: string
  warehouse?: WarehouseOption
}
type PendingTransfer = {
  id: number
  transfer_number: string
  status: string
  from_warehouse?: WarehouseOption
  to_warehouse?: WarehouseOption
}
type WarehouseDashboard = {
  warehouses: WarehouseOption[]
  selected_warehouse_id: number | null
  currency: string
  product_count: number
  stock_quantity: number
  stock_value: number
  low_stock_count: number
  low_stock_alerts: LowStockAlert[]
  pending_request_count: number
  pending_transfer_request_count: number
  recent_requests: ApprovalRequest[]
  pending_transfers: PendingTransfer[]
}

export default function WarehouseDashboardPage() {
  const { t } = useTranslation()
  const [warehouseId, setWarehouseId] = useState('')
  const { data, isLoading, error } = useQuery({
    queryKey: ['warehouse-dashboard', warehouseId],
    queryFn: async () =>
      (
        await api.get('/warehouse-dashboard/summary', {
          params: warehouseId ? { warehouse_id: warehouseId } : {},
        })
      ).data.data as WarehouseDashboard,
  })

  if (isLoading && !data) return <LoadingBlock label={t('common.loading')} />
  if (error || !data) return <p className="text-danger">{t('warehouseDashboard.loadError')}</p>

  const singleWarehouse = data.warehouses.length === 1 ? data.warehouses[0] : null
  const quickActions = [
    { to: '/warehouse?tab=products', label: t('warehouseDashboard.products'), icon: Package },
    { to: '/warehouse?tab=counts', label: t('warehouseDashboard.inventoryCount'), icon: ClipboardList },
    { to: '/warehouse?tab=transfers', label: t('warehouseDashboard.transferRequest'), icon: ArrowLeftRight },
    { to: '/warehouse-approvals', label: t('warehouseDashboard.myRequests'), icon: ClipboardCheck },
  ]

  return (
    <div className="space-y-6">
      <header className="overflow-hidden rounded-2xl bg-gradient-to-l from-slate-panel to-teal px-6 py-7 text-white shadow-sm">
        <p className="text-sm text-white/70">{t('warehouseDashboard.eyebrow')}</p>
        <h1 className="mt-1 text-3xl font-extrabold">{t('warehouseDashboard.title')}</h1>
        <p className="mt-2 text-sm text-white/75">
          {singleWarehouse
            ? t('warehouseDashboard.assignedWarehouse', { name: singleWarehouse.name })
            : t('warehouseDashboard.assignedWarehouses', { count: data.warehouses.length })}
        </p>
      </header>

      {data.warehouses.length > 1 && (
        <label className="block max-w-sm text-sm font-medium">
          <span className="mb-1.5 block">{t('warehouseDashboard.warehouseFilter')}</span>
          <select
            className={inputClass}
            value={warehouseId}
            onChange={(event) => setWarehouseId(event.target.value)}
          >
            <option value="">{t('warehouseDashboard.allAssigned')}</option>
            {data.warehouses.map((warehouse) => (
              <option key={warehouse.id} value={warehouse.id}>
                {warehouse.code} — {warehouse.name}
              </option>
            ))}
          </select>
        </label>
      )}

      {data.warehouses.length === 0 ? (
        <Panel>
          <EmptyState
            title={t('warehouseDashboard.noAssignment')}
            description={t('warehouseDashboard.noAssignmentHint')}
          />
        </Panel>
      ) : (
        <>
          <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <StatTile label={t('warehouseDashboard.productCount')} value={String(data.product_count)} tone="teal" />
            <StatTile label={t('warehouseDashboard.stockQuantity')} value={data.stock_quantity.toLocaleString()} />
            <StatTile
              label={t('warehouseDashboard.stockValue')}
              value={formatMoney(data.stock_value, data.currency)}
              tone="success"
            />
            <StatTile
              label={t('warehouseDashboard.lowStock')}
              value={String(data.low_stock_count)}
              tone={data.low_stock_count ? 'amber' : undefined}
            />
            <StatTile
              label={t('warehouseDashboard.pendingRequests')}
              value={String(data.pending_request_count)}
              tone={data.pending_request_count ? 'amber' : undefined}
            />
          </section>

          <Panel>
            <div className="border-b border-[var(--color-line)] px-5 py-3">
              <h2 className="font-semibold">{t('warehouseDashboard.quickActions')}</h2>
            </div>
            <div className="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-4">
              {quickActions.map(({ to, label, icon: Icon }) => (
                <Link
                  key={to}
                  to={to}
                  className="flex items-center gap-3 rounded-xl border border-[var(--color-line)] px-4 py-3 text-sm font-medium transition hover:border-teal/40 hover:bg-mist"
                >
                  <Icon size={18} className="text-teal" />
                  {label}
                </Link>
              ))}
            </div>
          </Panel>

          <div className="grid gap-6 lg:grid-cols-2">
            <Panel>
              <div className="flex items-center justify-between border-b border-[var(--color-line)] px-5 py-3">
                <h2 className="font-semibold">{t('warehouseDashboard.lowStockAlerts')}</h2>
                <Link to="/warehouse?tab=alerts" className="text-xs font-medium text-teal hover:underline">
                  {t('warehouseDashboard.viewAll')}
                </Link>
              </div>
              {data.low_stock_alerts.length === 0 ? (
                <EmptyState title={t('warehouseDashboard.noLowStock')} />
              ) : (
                <ul className="divide-y divide-[var(--color-line)]">
                  {data.low_stock_alerts.map((alert) => (
                    <li key={`${alert.warehouse_name}-${alert.product_id}`} className="flex gap-3 px-5 py-3">
                      <AlertTriangle size={18} className="mt-0.5 shrink-0 text-amber" />
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold">{alert.name}</p>
                        <p className="text-xs text-black/50">
                          {alert.sku} · {alert.warehouse_name} · {t('warehouseDashboard.onHand')}:{' '}
                          {alert.on_hand} / {alert.reorder_level}
                        </p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            <Panel>
              <div className="flex items-center justify-between border-b border-[var(--color-line)] px-5 py-3">
                <h2 className="font-semibold">{t('warehouseDashboard.requestStatus')}</h2>
                <span className="text-xs text-black/50">
                  {t('warehouseDashboard.pendingTransfers', { count: data.pending_transfer_request_count })}
                </span>
              </div>
              {data.recent_requests.length === 0 ? (
                <EmptyState title={t('warehouseDashboard.noRequests')} />
              ) : (
                <ul className="divide-y divide-[var(--color-line)]">
                  {data.recent_requests.map((request) => (
                    <li key={request.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                      <div className="min-w-0">
                        <p className="truncate font-medium">
                          {t(`warehouseApprovals.actions.${request.action_type.replaceAll('.', '_')}`, {
                            defaultValue: request.action_type,
                          })}
                        </p>
                        <p className="text-xs text-black/45">
                          {request.warehouse?.name || '—'} · {new Date(request.created_at).toLocaleDateString()}
                        </p>
                      </div>
                      <span className="rounded-full bg-mist px-2.5 py-1 text-xs">
                        {t(`warehouseApprovals.status.${request.status}`, { defaultValue: request.status })}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          </div>

          {data.pending_transfers.length > 0 && (
            <Panel>
              <div className="border-b border-[var(--color-line)] px-5 py-3">
                <h2 className="font-semibold">{t('warehouseDashboard.openTransfers')}</h2>
              </div>
              <ul className="divide-y divide-[var(--color-line)]">
                {data.pending_transfers.map((transfer) => (
                  <li key={transfer.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                    <Warehouse size={18} className="text-teal" />
                    <span className="font-medium">{transfer.transfer_number}</span>
                    <span className="text-black/50">
                      {transfer.from_warehouse?.name} → {transfer.to_warehouse?.name}
                    </span>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </>
      )}
    </div>
  )
}
