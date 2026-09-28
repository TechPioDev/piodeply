<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment scopes for software policies — the same scope_type/scope_id
 * pair browser policies already use (2026_07_19_170000), reused rather than
 * reinvented: scope_type says what kind of thing the policy targets
 * (project | group | computer) and scope_id which one. Existing rows become
 * project-scoped, byte-for-byte equivalent to their old behaviour;
 * project_id is kept (now nullable) for the relation, reports, and every
 * legacy write path that still only knows about a project.
 *
 * Every step is guarded so a partially-applied earlier run can simply be
 * re-run to completion (see the browser_policies migration this mirrors for
 * why: MySQL 1553, the old unique index also backs the project_id FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('software_policies', 'scope_type')) {
            Schema::table('software_policies', function (Blueprint $table) {
                $table->string('scope_type', 20)->default('project')->after('project_id');
                $table->unsignedBigInteger('scope_id')->default(0)->after('scope_type');
            });

            DB::table('software_policies')->update(['scope_type' => 'project', 'scope_id' => DB::raw('project_id')]);
        }

        if (! Schema::hasIndex('software_policies', 'software_policies_project_id_index')) {
            Schema::table('software_policies', function (Blueprint $table) {
                $table->index('project_id', 'software_policies_project_id_index');
            });
        }

        if (Schema::hasIndex('software_policies', 'software_policies_project_id_package_id_action_unique')) {
            Schema::table('software_policies', function (Blueprint $table) {
                $table->dropUnique(['project_id', 'package_id', 'action']);
            });
        }

        // Uniqueness follows the scope now: one rule per scope+package+action.
        if (! Schema::hasIndex('software_policies', 'software_policies_scope_type_scope_id_package_id_action_unique')) {
            Schema::table('software_policies', function (Blueprint $table) {
                $table->unique(['scope_type', 'scope_id', 'package_id', 'action']);
            });
        }

        Schema::table('software_policies', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('software_policies', function (Blueprint $table) {
            $table->dropUnique(['scope_type', 'scope_id', 'package_id', 'action']);
            $table->dropColumn(['scope_type', 'scope_id']);
            $table->unique(['project_id', 'package_id', 'action']);
            $table->dropIndex('software_policies_project_id_index');
        });
    }
};
