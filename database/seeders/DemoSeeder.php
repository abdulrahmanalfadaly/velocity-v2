<?php

namespace Database\Seeders;

use App\Models\Habit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A realistic final-year student ("Aina") – the same data as the approved
 * mockups. Dates are relative to "now", so the demo always looks current.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Demo login (local test data only): EMAIL / PASSWORD below.
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'aina@velocity.test';

    public const PASSWORD = 'velocity-demo';

    private CarbonImmutable $today;

    private User $user;

    public function run(Clock $clock): void
    {
        User::where('email', self::EMAIL)->delete();

        $tz = config('velocity.timezone');
        $now = $clock->now()->timezone($tz);
        $this->today = $now->startOfDay();

        $this->user = $user = User::create([
            'name' => 'Aina Rahman',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'timezone' => $tz,
        ]);
        $user->forceFill(['email_verified_at' => $now->utc()])->save();
        $user->weightProfile()->create([]);

        // Areas and goals.
        $areas = [];
        foreach ([['University', 'cap'], ['Work', 'briefcase'], ['Personal', 'heart']] as $i => [$name, $icon]) {
            $areas[$name] = $user->areas()->create(['name' => $name, 'icon' => $icon, 'position' => $i]);
        }
        $goals = [];
        foreach ([
            ['g1', 'Graduate with a First', 'Finish my degree with first-class honours.', 'Jun 2027', 'cap'],
            ['g2', 'Land a software internship', 'Start a paid internship by March.', 'Mar 2027', 'briefcase'],
            ['g3', 'Stay healthy and balanced', 'Sleep, move and eat well through finals.', 'Every day', 'heart'],
        ] as $i => [$key, $title, $why, $target, $icon]) {
            $goals[$key] = $user->goals()->create(['title' => $title, 'why' => $why, 'target' => $target, 'color' => $i + 1, 'icon' => $icon, 'position' => $i]);
        }

        // Projects: each lives in an area and serves a goal.
        $p = [];
        foreach ([
            ['fyp', 'Final Year Project', 'University', 'g1', 'Velocity: a task manager that ranks tasks with a clear formula. Report due 18 Dec.', 39],
            ['swt', 'Software Testing', 'University', 'g1', null, null],
            ['dm', 'Data Mining', 'University', 'g1', null, null],
            ['mad', 'Mobile App Dev', 'University', 'g1', null, null],
            ['intern', 'Internship', 'Work', 'g2', null, null],
            ['bakery', 'Bakery website', 'Work', 'g2', 'A simple site for Pak Ali’s bakery: menu, hours and orders.', null],
            ['home', 'Home', 'Personal', 'g3', null, null],
            ['fitness', 'Run a 5K', 'Personal', 'g3', 'Train three times a week and run the Nilai 5K.', null],
        ] as $i => [$key, $name, $area, $goal, $about, $dueIn]) {
            $p[$key] = $user->projects()->create([
                'name' => $name, 'area_id' => $areas[$area]->id, 'goal_id' => $goals[$goal]->id,
                'description' => $about, 'due_date' => $dueIn ? $this->today->addDays($dueIn) : null, 'position' => $i,
            ]);
        }
        foreach ([['Proposal', -42, true], ['Chapter 3', -10, true], ['Chapter 4', 2, false], ['Slides', 4, false], ['Final report', 39, false]] as $i => [$title, $days, $done]) {
            $p['fyp']->milestones()->create([
                'title' => $title, 'due_date' => $this->today->addDays($days),
                'done_at' => $done ? $this->today->addDays($days)->utc() : null, 'position' => $i,
            ]);
        }

        // The 14 open tasks of the mockups: [key, title, importance, urgency, due (days, time) | null, hours, project, status, steps].
        $open = [
            ['t1', 'Write Chapter 4 – Implementation', 5, 4, [2, '23:59'], 8, 'fyp', 'doing', [['Engine section', true], ['Explanations section', true], ['Weights section', false], ['Screenshots and captions', false]]],
            ['t2', 'Test plan for Assignment 2', 4, 5, [1, '23:59'], 3, 'swt', 'todo', [['Test cases for login', false], ['Boundary values table', false]]],
            ['t3', 'FYP progress presentation slides', 4, 3, [4, '10:00'], 4, 'fyp', 'todo', [['Outline the 10 slides', true], ['Charts from the study', false], ['Rehearse with Hana', false]]],
            ['t4', 'Revise for Data Mining quiz 3', 3, 4, [3, '14:00'], 2, 'dm', 'todo', [['Re-watch lecture 7', false], ['Practice questions 1–20', false]]],
            ['t5', 'Lab report 3 – Mobile App Dev', 3, 3, [-1, '23:59'], 1.5, 'mad', 'todo', [['App screenshots', true], ['Write the discussion', false]]],
            ['t6', 'Email Dr. Lim about the ethics form', 3, 4, [0, '17:00'], 0.25, 'fyp', 'todo', []],
            ['t7', 'Homepage mockup for Pak Ali’s bakery', 3, 3, [6, '18:00'], 5, 'bakery', 'todo', [['Moodboard', true], ['Wireframe', false], ['Pick colours', false]]],
            ['t8', 'Renew library books', 1, 3, [2, '18:00'], 0.25, null, 'todo', []],
            ['t9', 'Submit internship logbook', 4, 2, [12, '17:00'], 1, 'intern', 'todo', []],
            ['t10', 'Past-year paper with study group', 3, 3, [8, '20:00'], 3, 'dm', 'todo', []],
            ['t11', 'Update CV for internship applications', 4, 2, [20, '23:59'], 2, 'intern', 'todo', [['Add the FYP to projects', false], ['Ask Dr. Lim for a reference', false]]],
            ['t12', 'Read: rank reversal in TOPSIS', 3, 2, null, 2, 'fyp', 'todo', []],
            ['t13', 'Book a dentist appointment', 2, 2, null, 0.25, null, 'todo', []],
            ['t14', 'Plan meals and groceries', 2, 3, [1, '12:00'], 0.5, 'home', 'todo', []],
        ];
        $t = [];
        foreach ($open as $i => [$key, $title, $imp, $urg, $due, $hours, $proj, $status, $steps]) {
            $t[$key] = $this->task([
                'title' => $title, 'importance' => $imp, 'urgency' => $urg, 'est_hours' => $hours, 'status' => $status,
                'project_id' => $proj ? $p[$proj]->id : null, 'due_at' => $due ? $this->at($due[0], $due[1]) : null,
            ], $now->subDays(20 - $i)->utc());
            foreach ($steps as $s => [$stepTitle, $done]) {
                $t[$key]->steps()->create(['title' => $stepTitle, 'position' => $s, 'done_at' => $done ? $now->subDays(1)->utc() : null]);
            }
        }
        // A step that grew into a real task: it keeps its parent and has its own score.
        $t['t12']->update(['parent_id' => $t['t1']->id]);

        // Not ready yet: waiting, blocked, and not available until later.
        $this->task(['title' => 'Revise Chapter 3 after feedback', 'importance' => 4, 'urgency' => 3, 'est_hours' => 3, 'project_id' => $p['fyp']->id,
            'status' => 'waiting', 'waiting_for' => 'Dr. Lim’s feedback since Thu'], $now->subDays(6)->utc());
        $pilot = $this->task(['title' => 'Pilot the survey with 5 friends', 'importance' => 4, 'urgency' => 3, 'est_hours' => 2, 'project_id' => $p['fyp']->id,
            'due_at' => $this->at(9, '17:00')], $now->subDays(5)->utc());
        $pilot->dependencies()->attach($t['t6']->id);
        $this->task(['title' => 'Sign up for the Nilai 5K', 'importance' => 2, 'urgency' => 2, 'est_hours' => 0.25, 'project_id' => $p['fitness']->id,
            'available_from' => $this->at(6, '09:00')], $now->subDays(4)->utc());

        // Done recently, and today.
        foreach ([
            ['Chapter 3 – Methodology', 'fyp', -10, -11, 71.2], ['Ethics form draft', 'fyp', -7, -6, 52.0],
            ['Assignment 1 – unit tests', 'swt', -20, -20, 74.5], ['Apply to Grab Tech internship', 'intern', -5, -3, 49.8],
            ['Buy running shoes', 'fitness', -8, -6, 21.4],
        ] as [$title, $proj, $doneDay, $dueDay, $score]) {
            $this->done($title, $p[$proj], $this->at($doneDay, '16:00'), $this->at($dueDay, '23:59'), $score);
        }
        $this->done('Print FYP consent forms', $p['fyp'], $this->at(0, '08:35'), $this->at(0, '12:00'), 58.4);

        // Twelve weeks of finished tasks for History (about 9 in 10 on time).
        mt_srand(7);
        $pool = ['Tutorial sheet', 'Lecture notes', 'Lab exercise', 'Reading summary', 'Quiz practice', 'Group meeting notes', 'Code review', 'Draft section', 'Reference list', 'Survey questions'];
        $uni = [$p['fyp'], $p['swt'], $p['dm'], $p['mad'], $p['intern']];
        for ($day = 84; $day >= 2; $day--) {
            $n = mt_rand(0, 100) < 42 ? 1 : 0;
            for ($k = 0; $k < $n; $k++) {
                $late = mt_rand(0, 100) < 11;
                $this->done($pool[mt_rand(0, count($pool) - 1)].' '.mt_rand(1, 9), $uni[mt_rand(0, count($uni) - 1)],
                    $this->at(-$day, sprintf('%02d:%02d', mt_rand(9, 21), mt_rand(0, 59))), $this->at(-$day + ($late ? -1 : mt_rand(0, 3)), '23:59'), mt_rand(300, 820) / 10);
            }
        }

        // Notes inside projects.
        foreach ([
            ['fyp', 'Chapter 4 outline', 1], ['fyp', 'Supervisor meeting notes', 4], ['swt', 'Testing techniques cheat sheet', 7],
            ['dm', 'Lecture 7 – Clustering', 3], ['intern', 'Interview prep', 2], ['bakery', 'Brand ideas for the bakery', 5],
        ] as [$proj, $title, $daysAgo]) {
            $note = $user->notes()->create(['project_id' => $p[$proj]->id, 'title' => $title, 'body' => '']);
            $note->forceFill(['updated_at' => $now->subDays($daysAgo)->utc(), 'created_at' => $now->subDays($daysAgo + 3)->utc()])->save();
        }

        // Habits, some inside projects, with their streaks.
        $this->habit('Write 45 min a day', $p['fyp'], '45 min', null, 1, 9, true);
        $revision = $this->habit('Morning revision', $p['dm'], '25 min', null, 1, 12, true);
        $this->habit('Read 20 pages', $p['home'], 'Before bed', null, 1, 2, false);
        $gym = $this->habit('Gym', $p['fitness'], null, [1, 3, 5], 1, 4, false);
        $gym->update(['at_time' => '18:00', 'minutes' => 45]); // on the Planner, kept free by "Plan my day"
        $water = $this->habit('Drink water', $p['fitness'], null, null, 8, 6, false);
        $water->update(['unit' => 'glasses']);
        $water->entries()->create(['day' => $this->today->toDateString(), 'count' => 3]);
        $this->user->routines()->create(['name' => 'Morning'])->habits()->sync([$water->id => ['position' => 0], $revision->id => ['position' => 1]]);
    }

    private function at(int $days, string $time): CarbonImmutable
    {
        return $this->today->addDays($days)->setTimeFromTimeString($time)->utc();
    }

    private function task(array $attributes, CarbonImmutable $created): Task
    {
        $task = new Task($attributes + ['status' => 'todo']);
        $task->forceFill(['user_id' => $this->user->id, 'created_at' => $created, 'updated_at' => $created])->save();

        return $task;
    }

    private function done(string $title, Project $project, CarbonImmutable $doneAt, CarbonImmutable $dueAt, float $score): void
    {
        $task = $this->task([
            'title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'project_id' => $project->id,
            'status' => 'done', 'due_at' => $dueAt, 'completed_at' => $doneAt, 'score_at_completion' => $score,
        ], $doneAt->subDays(4));
        $task->events()->create(['user_id' => $this->user->id, 'type' => 'completed', 'snapshot' => ['score' => $score], 'created_at' => $doneAt]);
    }

    private function habit(string $name, Project $project, ?string $detail, ?array $days, int $target, int $streak, bool $doneToday): Habit
    {
        $habit = $this->user->habits()->create(['project_id' => $project->id, 'name' => $name, 'detail' => $detail, 'days' => $days, 'target' => $target]);
        $day = $doneToday ? $this->today : $this->today->subDay();
        for ($n = 0; $n < $streak; $day = $day->subDay()) {
            if ($days === null || in_array($day->dayOfWeekIso, $days, true)) {
                $habit->entries()->create(['day' => $day->toDateString(), 'count' => $target]);
                $n++;
            }
        }

        return $habit;
    }
}
