<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * People (Phase 6): the people in your life, how to reach them, their birthdays
 * and other dates, keeping in touch (catch-ups), groups, and links to tasks,
 * notes and projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('relationship', 80)->nullable();   // "Supervisor", "Sister"
            $table->string('organisation', 120)->nullable();  // where they work or study
            $table->text('about')->nullable();                 // things to remember about them
            $table->unsignedSmallInteger('catch_up_days')->nullable(); // keep in touch: every n days
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'name']);
        });

        Schema::create('person_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 8);               // email | phone | link | other
            $table->string('label', 40)->nullable(); // "Work", "WhatsApp"
            $table->string('value', 255);
            $table->unsignedSmallInteger('position')->default(0);
        });

        // A birthday, an anniversary or another date: day and month, the year if known.
        Schema::create('person_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 12);               // birthday | anniversary | other
            $table->string('label', 80)->nullable();  // what an "other" date is
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->unsignedSmallInteger('year')->nullable();
            $table->boolean('yearly')->default(true); // false: happens once (needs the year)
            $table->unsignedTinyInteger('remind_days')->nullable(); // remind this many days before (0 = on the day)
            $table->date('reminded_for')->nullable();  // the date whose reminder was last shown: once a year
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('catch_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('on');
            $table->string('kind', 8);                // message | call | met | other
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['person_id', 'on']);
        });

        Schema::create('person_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('person_group_members', function (Blueprint $table) {
            $table->foreignId('person_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->primary(['person_group_id', 'person_id']);
        });

        foreach (['task' => 'tasks', 'note' => 'notes', 'project' => 'projects'] as $one => $table) {
            Schema::create("person_{$one}", function (Blueprint $t) use ($one, $table) {
                $t->foreignId('person_id')->constrained('people')->cascadeOnDelete();
                $t->foreignId("{$one}_id")->constrained($table)->cascadeOnDelete();
                $t->primary(['person_id', "{$one}_id"]);
                $t->index("{$one}_id");
            });
        }
    }

    public function down(): void
    {
        foreach (['person_project', 'person_note', 'person_task', 'person_group_members', 'person_groups', 'catch_ups', 'person_dates', 'person_contacts', 'people'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
