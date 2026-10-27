<?php

namespace App\Http\Controllers\Api;

use App\Models\FtthDailyReport;
use App\Models\FtthSector;
use App\Support\FtthReportDefaults;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FtthDailyReportController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('ftth.view');
        $query = FtthDailyReport::query()->latest('report_date')->latest('id');
        ListSearch::apply($query, $request, [
            'report_number', 'project_name', 'area_sector', 'supervisor', 'contractor_team', 'weather', 'status',
        ]);
        if ($request->filled('from')) {
            $query->whereDate('report_date', '>=', $request->string('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('report_date', '<=', $request->string('to'));
        }

        return $this->ok($query->get()->map(fn (FtthDailyReport $r) => $this->present($r)));
    }

    public function defaults(): JsonResponse
    {
        $this->authorizePermission('ftth.view');

        return $this->ok([
            'project_name' => 'مشروع FTTH – الأعمال المدنية',
            'phase' => 'الحفر وتمديد الدكت وتركيب المنهولات',
            'works' => FtthReportDefaults::works(),
            'materials' => FtthReportDefaults::materials(),
            'next_day_plan' => FtthReportDefaults::nextDayPlan(),
            'next_day_resources' => FtthReportDefaults::nextDayResources(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('ftth.manage');
        $data = $this->validated($request);

        $report = FtthDailyReport::query()->create([
            ...$data,
            'report_number' => $this->nextNumber(),
            'works' => $data['works'] ?? FtthReportDefaults::works(),
            'materials' => $data['materials'] ?? FtthReportDefaults::materials(),
            'next_day_plan' => $data['next_day_plan'] ?? FtthReportDefaults::nextDayPlan(),
            'next_day_resources' => $data['next_day_resources'] ?? FtthReportDefaults::nextDayResources(),
            'created_by' => $request->user()?->id,
        ]);

        return $this->ok($this->present($report->fresh()), 201);
    }

    public function show(FtthDailyReport $ftthDailyReport): JsonResponse
    {
        $this->authorizePermission('ftth.view');

        return $this->ok($this->present($ftthDailyReport));
    }

    public function update(Request $request, FtthDailyReport $ftthDailyReport): JsonResponse
    {
        $this->authorizePermission('ftth.manage');
        $data = $this->validated($request);
        $ftthDailyReport->update($data);

        return $this->ok($this->present($ftthDailyReport->fresh()));
    }

    public function destroy(FtthDailyReport $ftthDailyReport): JsonResponse
    {
        $this->authorizePermission('ftth.manage');
        $ftthDailyReport->delete();

        return response()->json(['message' => 'تم حذف التقرير اليومي.']);
    }

    public function sectors(): JsonResponse
    {
        $this->authorizePermission('ftth.view');
        $rows = FtthSector::query()->orderBy('sort_order')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $rows = collect(range(1, 10))->map(fn (int $i) => FtthSector::query()->create([
                'sort_order' => $i,
                'name' => '',
                'from_point' => '',
                'to_point' => '',
                'planned_length_m' => 0,
                'excavation_done_m' => 0,
                'duct_done_m' => 0,
                'manholes_done' => 0,
                'notes' => '',
            ]));
        }

        return $this->ok($rows->map(fn (FtthSector $s) => $this->presentSector($s))->values());
    }

    public function saveSectors(Request $request): JsonResponse
    {
        $this->authorizePermission('ftth.manage');
        $data = $request->validate([
            'sectors' => ['required', 'array', 'min:1'],
            'sectors.*.id' => ['nullable', 'integer', 'exists:ftth_sectors,id'],
            'sectors.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sectors.*.name' => ['nullable', 'string', 'max:255'],
            'sectors.*.from_point' => ['nullable', 'string', 'max:255'],
            'sectors.*.to_point' => ['nullable', 'string', 'max:255'],
            'sectors.*.planned_length_m' => ['nullable', 'numeric', 'min:0'],
            'sectors.*.excavation_done_m' => ['nullable', 'numeric', 'min:0'],
            'sectors.*.duct_done_m' => ['nullable', 'numeric', 'min:0'],
            'sectors.*.manholes_done' => ['nullable', 'integer', 'min:0'],
            'sectors.*.notes' => ['nullable', 'string'],
        ]);

        $saved = DB::transaction(function () use ($data) {
            $keepIds = [];
            foreach (array_values($data['sectors']) as $i => $row) {
                $payload = [
                    'sort_order' => $row['sort_order'] ?? ($i + 1),
                    'name' => $row['name'] ?? '',
                    'from_point' => $row['from_point'] ?? '',
                    'to_point' => $row['to_point'] ?? '',
                    'planned_length_m' => $row['planned_length_m'] ?? 0,
                    'excavation_done_m' => $row['excavation_done_m'] ?? 0,
                    'duct_done_m' => $row['duct_done_m'] ?? 0,
                    'manholes_done' => $row['manholes_done'] ?? 0,
                    'notes' => $row['notes'] ?? '',
                ];
                if (! empty($row['id'])) {
                    $sector = FtthSector::query()->findOrFail($row['id']);
                    $sector->update($payload);
                } else {
                    $sector = FtthSector::query()->create($payload);
                }
                $keepIds[] = $sector->id;
            }
            FtthSector::query()->whereNotIn('id', $keepIds)->delete();

            return FtthSector::query()->orderBy('sort_order')->orderBy('id')->get();
        });

        return $this->ok($saved->map(fn (FtthSector $s) => $this->presentSector($s))->values());
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'report_date' => ['required', 'date'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'phase' => ['nullable', 'string', 'max:255'],
            'weather' => ['nullable', 'string', 'max:120'],
            'area_sector' => ['nullable', 'string', 'max:255'],
            'supervisor' => ['nullable', 'string', 'max:255'],
            'contractor_team' => ['nullable', 'string', 'max:255'],
            'workers_count' => ['nullable', 'integer', 'min:0'],
            'start_time' => ['nullable', 'string', 'max:16'],
            'end_time' => ['nullable', 'string', 'max:16'],
            'works' => ['nullable', 'array'],
            'materials' => ['nullable', 'array'],
            'next_day_plan' => ['nullable', 'array'],
            'next_day_resources' => ['nullable', 'array'],
            'obstacles' => ['nullable', 'string'],
            'remediation' => ['nullable', 'string'],
            'quality_notes' => ['nullable', 'string'],
            'safety_notes' => ['nullable', 'string'],
            'attachments_notes' => ['nullable', 'string'],
            'next_day_obstacles' => ['nullable', 'string'],
            'next_day_coordination' => ['nullable', 'string'],
            'next_day_safety' => ['nullable', 'string'],
            'next_day_inspection' => ['nullable', 'string'],
            'next_day_goal' => ['nullable', 'string'],
            'prepared_by' => ['nullable', 'string', 'max:255'],
            'approved_by' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'final'])],
        ]);
    }

    protected function nextNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "FTTH-{$year}-";
        $last = FtthDailyReport::query()
            ->where('report_number', 'like', $prefix.'%')
            ->orderByDesc('report_number')
            ->value('report_number');
        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    protected function present(FtthDailyReport $report): array
    {
        // Keep the stored rows as-is (including empty arrays). Do not inject
        // catalog defaults here — that made blank template rows appear on print.
        $works = is_array($report->works) ? $report->works : [];
        $summary = $this->summarizeWorks($works);

        return array_merge($report->toArray(), [
            'report_date' => optional($report->report_date)?->toDateString(),
            'summary' => $summary,
        ]);
    }

    /** @param  list<array<string, mixed>>  $works
     *  @return array<string, float|int|string>
     */
    protected function summarizeWorks(array $works): array
    {
        $plannedExcavation = 0.0;
        $actualExcavation = 0.0;
        $duct = 0.0;
        $manholes = 0.0;
        foreach ($works as $row) {
            $type = (string) ($row['type'] ?? '');
            $planned = (float) ($row['planned'] ?? 0);
            $actual = (float) ($row['actual'] ?? 0);
            if (str_contains($type, 'الحفر')) {
                $plannedExcavation += $planned;
                $actualExcavation += $actual;
            } elseif (str_contains($type, 'الدكت')) {
                $duct += $actual;
            } elseif (str_contains($type, 'المنهولات')) {
                $manholes += $actual;
            }
        }
        $pct = $plannedExcavation > 0.0001
            ? round(($actualExcavation / $plannedExcavation) * 100, 1)
            : 0.0;

        return [
            'planned_excavation_m' => round($plannedExcavation, 3),
            'actual_excavation_m' => round($actualExcavation, 3),
            'excavation_pct' => $pct,
            'duct_m' => round($duct, 3),
            'manholes' => round($manholes, 3),
        ];
    }

    /** @return array<string, mixed> */
    protected function presentSector(FtthSector $sector): array
    {
        $planned = (float) $sector->planned_length_m;
        $done = (float) $sector->excavation_done_m;
        $remaining = max(0, round($planned - $done, 3));
        $pct = $planned > 0.0001 ? round(($done / $planned) * 100, 1) : 0.0;

        return array_merge($sector->toArray(), [
            'remaining_excavation_m' => $remaining,
            'completion_pct' => $pct,
        ]);
    }
}
