<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('member_promotion_pending', function (Blueprint $table) {
            $table->index(['available_at', 'user_id'], 'mpp_due_order');
        });
        $schema->table('member_promotion_jobs', function (Blueprint $table) {
            $table->index(['status', 'attempts', 'id'], 'mpj_claim');
            $table->index(['type', 'status', 'rule_id', 'discussion_id'], 'mpj_dedupe');
            $table->index(['completed_at', 'id'], 'mpj_completed_cleanup');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('member_promotion_pending', function (Blueprint $table) {
            $table->dropIndex('mpp_due_order');
        });
        $schema->table('member_promotion_jobs', function (Blueprint $table) {
            $table->dropIndex('mpj_claim');
            $table->dropIndex('mpj_dedupe');
            $table->dropIndex('mpj_completed_cleanup');
        });
    },
];
