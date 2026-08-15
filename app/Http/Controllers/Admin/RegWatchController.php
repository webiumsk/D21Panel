<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegWatchChangeStatus;
use App\Enums\RegWatchTopic;
use App\Http\Controllers\Controller;
use App\Models\RegWatchChange;
use App\Models\RegWatchJurisdiction;
use App\Models\RegWatchRule;
use App\Models\RegWatchSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin review of RegWatch detections and the rule knowledge base
 * (docs/LEGAL.md): list changes, view the diff and move a change through
 * new -> reviewed -> applied/dismissed; edit rules by hand after verifying
 * the official source (verified_on carries the human verification date -
 * nothing here ever generates rule content).
 */
class RegWatchController extends Controller
{
    public function changes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(RegWatchChangeStatus::class)],
            'source_id' => ['nullable', 'uuid'],
        ]);

        $query = RegWatchChange::query()
            ->with(['source.jurisdiction', 'reviewer:id,email'])
            ->orderByDesc('detected_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['source_id'])) {
            $query->where('source_id', $validated['source_id']);
        }

        $changes = $query->paginate(20);

        return response()->json([
            'data' => collect($changes->items())->map(fn (RegWatchChange $change) => $this->formatChange($change))->all(),
            'meta' => [
                'current_page' => $changes->currentPage(),
                'last_page' => $changes->lastPage(),
                'per_page' => $changes->perPage(),
                'total' => $changes->total(),
            ],
        ]);
    }

    public function showChange(RegWatchChange $change): JsonResponse
    {
        $change->load(['source.jurisdiction', 'reviewer:id,email', 'rule:id,slug,title']);

        return response()->json([
            'data' => $this->formatChange($change, withDiff: true),
        ]);
    }

    public function updateChangeStatus(Request $request, RegWatchChange $change): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(RegWatchChangeStatus::class)],
        ]);

        $target = RegWatchChangeStatus::from($validated['status']);

        if (! in_array($target, $change->status->allowedTransitions(), true)) {
            return response()->json([
                'message' => __('Invalid status transition from :from to :to.', [
                    'from' => $change->status->value,
                    'to' => $target->value,
                ]),
            ], 422);
        }

        $change->forceFill([
            'status' => $target,
            // First move out of 'new' stamps who reviewed and when.
            'reviewed_at' => $change->reviewed_at ?? now(),
            'reviewed_by' => $change->reviewed_by ?? $request->user()->id,
        ])->save();

        $change->load(['source.jurisdiction', 'reviewer:id,email']);

        return response()->json([
            'data' => $this->formatChange($change),
            'message' => __('Status updated.'),
        ]);
    }

    public function sources(): JsonResponse
    {
        $sources = RegWatchSource::query()
            ->with('jurisdiction:id,code,name')
            ->withCount(['changes as new_changes_count' => fn ($q) => $q->where('status', RegWatchChangeStatus::New)])
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $sources->map(fn (RegWatchSource $source) => [
                'id' => $source->id,
                'slug' => $source->slug,
                'name' => $source->name,
                'url' => $source->url,
                'type' => $source->type->value,
                'active' => $source->active,
                'jurisdiction_code' => $source->jurisdiction?->code,
                'last_checked_at' => $source->last_checked_at?->toIso8601String(),
                'new_changes_count' => $source->new_changes_count,
            ])->all(),
        ]);
    }

    public function jurisdictions(): JsonResponse
    {
        return response()->json([
            'data' => RegWatchJurisdiction::query()
                ->orderBy('code')
                ->get()
                ->map(fn (RegWatchJurisdiction $jurisdiction) => [
                    'id' => $jurisdiction->id,
                    'code' => $jurisdiction->code,
                    'name' => $jurisdiction->name,
                    'active' => $jurisdiction->active,
                ])->all(),
        ]);
    }

    public function rules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jurisdiction_id' => ['nullable', 'uuid'],
            'topic' => ['nullable', Rule::enum(RegWatchTopic::class)],
            'verified' => ['nullable', 'boolean'],
        ]);

        $query = RegWatchRule::query()
            ->with(['jurisdiction:id,code,name', 'source:id,slug,name'])
            ->join('regwatch_jurisdictions', 'regwatch_jurisdictions.id', '=', 'regwatch_rules.jurisdiction_id')
            ->orderBy('regwatch_jurisdictions.code')
            ->orderBy('regwatch_rules.topic')
            ->select('regwatch_rules.*');

        if (! empty($validated['jurisdiction_id'])) {
            $query->where('regwatch_rules.jurisdiction_id', $validated['jurisdiction_id']);
        }
        if (! empty($validated['topic'])) {
            $query->where('regwatch_rules.topic', $validated['topic']);
        }
        if (array_key_exists('verified', $validated) && $validated['verified'] !== null) {
            $request->boolean('verified')
                ? $query->whereNotNull('verified_on')
                : $query->whereNull('verified_on');
        }

        $rules = $query->paginate(20);

        return response()->json([
            'data' => collect($rules->items())->map(fn (RegWatchRule $rule) => $this->formatRule($rule))->all(),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    public function showRule(RegWatchRule $rule): JsonResponse
    {
        $rule->load(['jurisdiction:id,code,name', 'source:id,slug,name']);

        return response()->json([
            'data' => $this->formatRule($rule, withText: true),
        ]);
    }

    public function updateRule(Request $request, RegWatchRule $rule): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'rule_text' => ['required', 'string', 'max:20000'],
            'source_url' => ['required', 'url', 'max:2048'],
            'source_id' => ['nullable', 'uuid', Rule::exists('regwatch_sources', 'id')],
            // The date a human verified the text against source_url - never
            // in the future, NULL while the rule is still a placeholder.
            'verified_on' => ['nullable', 'date', 'before_or_equal:today'],
            'effective_from' => ['nullable', 'date'],
        ]);

        if (! empty($validated['source_id'])) {
            $source = RegWatchSource::query()->findOrFail($validated['source_id']);
            if ($source->jurisdiction_id !== $rule->jurisdiction_id) {
                return response()->json([
                    'message' => __('The source belongs to a different jurisdiction than the rule.'),
                ], 422);
            }
        }

        // The LEGAL.md invariant: a rule whose text is still the seeded
        // placeholder has not been verified by anyone - it must not carry a
        // verification stamp.
        if (! empty($validated['verified_on'])
            && trim($validated['rule_text']) === RegWatchRule::PLACEHOLDER_RULE_TEXT) {
            return response()->json([
                'message' => __('A placeholder rule cannot be marked as verified - fill in the verified rule text first.'),
            ], 422);
        }

        $rule->forceFill([
            'title' => $validated['title'],
            'rule_text' => $validated['rule_text'],
            'source_url' => $validated['source_url'],
            'source_id' => $validated['source_id'] ?? null,
            'verified_on' => $validated['verified_on'] ?? null,
            'effective_from' => $validated['effective_from'] ?? null,
        ])->save();

        $rule->load(['jurisdiction:id,code,name', 'source:id,slug,name']);

        return response()->json([
            'data' => $this->formatRule($rule, withText: true),
            'message' => __('Rule updated.'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRule(RegWatchRule $rule, bool $withText = false): array
    {
        $data = [
            'id' => $rule->id,
            'slug' => $rule->slug,
            'topic' => $rule->topic->value,
            'title' => $rule->title,
            'source_url' => $rule->source_url,
            'verified_on' => $rule->verified_on?->toDateString(),
            'effective_from' => $rule->effective_from?->toDateString(),
            'jurisdiction' => $rule->jurisdiction ? [
                'id' => $rule->jurisdiction->id,
                'code' => $rule->jurisdiction->code,
                'name' => $rule->jurisdiction->name,
            ] : null,
            'source' => $rule->source ? [
                'id' => $rule->source->id,
                'slug' => $rule->source->slug,
                'name' => $rule->source->name,
            ] : null,
        ];

        if ($withText) {
            $data['rule_text'] = $rule->rule_text;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatChange(RegWatchChange $change, bool $withDiff = false): array
    {
        $data = [
            'id' => $change->id,
            'status' => $change->status->value,
            'summary' => $change->summary,
            'classification' => $change->classification_json,
            'detected_at' => $change->detected_at->toIso8601String(),
            'reviewed_at' => $change->reviewed_at?->toIso8601String(),
            'reviewed_by_email' => $change->reviewer?->email,
            'allowed_transitions' => array_map(
                fn (RegWatchChangeStatus $status) => $status->value,
                $change->status->allowedTransitions(),
            ),
            'source' => $change->source ? [
                'id' => $change->source->id,
                'slug' => $change->source->slug,
                'name' => $change->source->name,
                'url' => $change->source->url,
                'jurisdiction_code' => $change->source->jurisdiction?->code,
            ] : null,
        ];

        if ($withDiff) {
            $data['diff'] = $change->diff;
            $data['rule'] = $change->rule ? [
                'id' => $change->rule->id,
                'slug' => $change->rule->slug,
                'title' => $change->rule->title,
            ] : null;
        }

        return $data;
    }
}
