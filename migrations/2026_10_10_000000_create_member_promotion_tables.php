<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('member_promotion_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('archived')->default(false);
            $table->integer('priority')->default(0);
            $table->integer('prerequisite_group_id')->unsigned()->nullable();
            $table->integer('target_group_id')->unsigned();
            $table->boolean('remove_prerequisite')->default(false);
            $table->boolean('age_enabled')->default(false);
            $table->integer('age_minutes')->unsigned()->nullable();
            $table->string('age_operator', 2)->default('>=');
            $table->boolean('replies_enabled')->default(false);
            $table->integer('replies_count')->unsigned()->nullable();
            $table->string('replies_operator', 2)->default('>=');
            $table->integer('version')->unsigned()->default(1);
            $table->integer('created_by')->unsigned()->nullable();
            $table->integer('updated_by')->unsigned()->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['enabled', 'archived', 'priority'], 'mpr_active_order');
            $table->index('target_group_id', 'mpr_target_group');
            $table->index('prerequisite_group_id', 'mpr_prerequisite_group');
        });

        $schema->create('member_promotion_rule_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('rule_id')->unsigned();
            $table->integer('version')->unsigned();
            $table->longText('snapshot_json');
            $table->integer('created_by')->unsigned()->nullable();
            $table->timestamp('created_at');
            $table->unique(['rule_id', 'version'], 'mprv_rule_version');
        });

        $schema->create('member_promotion_counters', function (Blueprint $table) {
            $table->integer('user_id')->unsigned()->primary();
            $table->integer('valid_replies')->unsigned()->default(0);
            $table->boolean('initialized')->default(false);
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create('member_promotion_discussion_users', function (Blueprint $table) {
            $table->integer('discussion_id')->unsigned();
            $table->integer('user_id')->unsigned();
            $table->primary(['discussion_id', 'user_id'], 'mpdu_discussion_user');
            $table->index(['user_id', 'discussion_id'], 'mpdu_user_discussion');
        });

        $schema->create('member_promotion_user_states', function (Blueprint $table) {
            $table->integer('user_id')->unsigned()->primary();
            $table->boolean('paused')->default(false);
            $table->integer('paused_by')->unsigned()->nullable();
            $table->text('pause_reason')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create('member_promotion_pending', function (Blueprint $table) {
            $table->integer('user_id')->unsigned()->primary();
            $table->timestamp('available_at');
            $table->string('reason', 100)->default('changed');
            $table->integer('attempts')->unsigned()->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('updated_at');
            $table->index(['available_at', 'claimed_at'], 'mpp_ready');
        });

        $schema->create('member_promotion_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->unsigned();
            $table->integer('rule_id')->unsigned();
            $table->integer('rule_version')->unsigned();
            $table->integer('group_id')->unsigned();
            $table->string('status', 24)->default('active');
            $table->timestamp('granted_at');
            $table->timestamp('ended_at')->nullable();
            $table->unique(['user_id', 'rule_id', 'rule_version'], 'mpg_idempotency');
            $table->index(['user_id', 'group_id', 'status'], 'mpg_group_sources');
        });

        $schema->create('member_promotion_blocks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->unsigned();
            $table->integer('rule_id')->unsigned();
            $table->integer('group_id')->unsigned();
            $table->string('reason', 100)->default('manual_revoke');
            $table->integer('created_by')->unsigned()->nullable();
            $table->timestamp('created_at');
            $table->timestamp('cleared_at')->nullable();
            $table->integer('cleared_by')->unsigned()->nullable();
            $table->index(['user_id', 'rule_id', 'cleared_at'], 'mpb_active');
        });

        $schema->create('member_promotion_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->unsigned()->nullable();
            $table->integer('rule_id')->unsigned()->nullable();
            $table->integer('rule_version')->unsigned()->nullable();
            $table->string('status', 20);
            $table->string('reason', 120)->nullable();
            $table->integer('added_group_id')->unsigned()->nullable();
            $table->integer('removed_group_id')->unsigned()->nullable();
            $table->longText('details_json')->nullable();
            $table->timestamp('created_at');
            $table->index(['created_at', 'id'], 'mpl_time');
            $table->index(['user_id', 'created_at'], 'mpl_user_time');
            $table->index(['rule_id', 'created_at'], 'mpl_rule_time');
            $table->index(['status', 'created_at'], 'mpl_status_time');
        });

        $schema->create('member_promotion_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 32);
            $table->string('status', 20)->default('pending');
            $table->integer('rule_id')->unsigned()->nullable();
            $table->integer('discussion_id')->unsigned()->nullable();
            $table->bigInteger('cursor')->unsigned()->default(0);
            $table->bigInteger('processed')->unsigned()->default(0);
            $table->bigInteger('total')->unsigned()->nullable();
            $table->integer('attempts')->unsigned()->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->timestamp('completed_at')->nullable();
            $table->index(['status', 'created_at'], 'mpj_ready');
        });

        $schema->create('member_promotion_runtime', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->longText('value')->nullable();
            $table->timestamp('updated_at');
        });
    },
    'down' => function (Builder $schema) {
        foreach ([
            'member_promotion_runtime', 'member_promotion_jobs', 'member_promotion_logs',
            'member_promotion_blocks', 'member_promotion_grants', 'member_promotion_pending',
            'member_promotion_user_states', 'member_promotion_discussion_users', 'member_promotion_counters',
            'member_promotion_rule_versions', 'member_promotion_rules',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    },
];
