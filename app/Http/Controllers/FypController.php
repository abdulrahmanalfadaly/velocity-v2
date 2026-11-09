<?php

namespace App\Http\Controllers;

use App\Domain\Prioritization\Criteria;
use App\Domain\Prioritization\ScenarioSet;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The evaluation tools of FYP mode (report Section 3.6). */
class FypController extends Controller
{
    /** Loads the ten tasks of the standard scenario set (Table 3.4), due relative to now. */
    public function scenario(Request $request, RankingService $ranking): RedirectResponse
    {
        $user = $request->user();
        $now = $ranking->now()->toDateTimeImmutable();

        DB::transaction(function () use ($user, $now) {
            foreach (ScenarioSet::TASKS as [$code, $title, $description, $importance, $urgency, $days, $hours]) {
                $task = $user->tasks()->create([
                    'title' => "{$code} {$title}",
                    'notes' => $description,
                    'importance' => $importance,
                    'urgency' => $urgency,
                    'due_at' => ScenarioSet::dueDate($now, (float) $days),
                    'est_hours' => (float) $hours,
                ]);
                $task->events()->create(['user_id' => $user->id, 'type' => 'created', 'snapshot' => ['scenario' => $code]]);
            }
        });

        return redirect()->route('tasks.index')->with('toast', 'Loaded the 10-task scenario set from Table 3.4.');
    }

    /** The current ranking as a JSON file, for the evaluation data. */
    public function export(Request $request, RankingService $ranking): JsonResponse
    {
        $list = $ranking->rank($request->user());
        $now = $ranking->now();

        $tasks = [];
        foreach ($list->toArray() as $t) {
            $tasks[] = [
                'rank' => $t['rank'],
                'id' => $t['id'],
                'title' => $t['title'],
                'score' => $t['score'],
                'criteria' => $t['criteria'],
                'contributions' => $t['contrib'],
                'explanation' => $t['summary'],
                'contrast' => $t['contrast'],
            ];
        }

        return response()->json([
            'ok' => true,
            'generated_at' => $now->toIso8601String(),
            'parameters' => [
                'weights' => $list->params->weights->toPercentages(),
                'horizon_days' => $list->params->horizonDays,
                'hours_per_day' => $list->params->hoursPerDay,
                'criteria' => Criteria::NAMES,
            ],
            'tasks' => $tasks,
        ], 200, [
            'Content-Disposition' => 'attachment; filename="velocity-ranking-'.$now->timezone($request->user()->timezone)->format('Y-m-d-Hi').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
