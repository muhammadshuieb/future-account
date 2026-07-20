<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function __construct(protected JournalEntryService $journalEntries) {}

    public function index(Request $request): JsonResponse
    {
        $query = JournalEntry::query()
            ->with(['creator:id,name', 'details'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('entry_date', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('entry_date', '<=', $request->date('to'));
        }

        $entries = $query->paginate($request->integer('per_page', 15));

        return response()->json($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,posted'],
            'details' => ['required', 'array', 'min:2'],
            'details.*.account_id' => ['required', 'exists:accounts,id'],
            'details.*.debit' => ['nullable', 'numeric', 'min:0'],
            'details.*.credit' => ['nullable', 'numeric', 'min:0'],
            'details.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = $this->journalEntries->create(
            $data,
            $data['details'],
            $request->user()
        );

        return response()->json(['data' => $entry], 201);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return response()->json([
            'data' => $journalEntry->load(['details.account', 'creator', 'poster']),
        ]);
    }

    public function update(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'details' => ['required', 'array', 'min:2'],
            'details.*.account_id' => ['required', 'exists:accounts,id'],
            'details.*.debit' => ['nullable', 'numeric', 'min:0'],
            'details.*.credit' => ['nullable', 'numeric', 'min:0'],
            'details.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = $this->journalEntries->update($journalEntry, $data, $data['details']);

        return response()->json(['data' => $entry]);
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->status !== 'draft') {
            return response()->json(['message' => 'يمكن حذف المسودات فقط.'], 422);
        }

        $journalEntry->delete();

        return response()->json(['message' => 'تم حذف القيد.']);
    }

    public function post(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalEntries->post($journalEntry, $request->user());

        return response()->json(['data' => $entry, 'message' => 'تم ترحيل القيد.']);
    }

    public function void(JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalEntries->void($journalEntry);

        return response()->json(['data' => $entry, 'message' => 'تم إلغاء القيد.']);
    }
}
