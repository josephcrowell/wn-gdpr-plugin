<?php namespace JosephCrowell\GDPR\Updates;

use Winter\Storm\Database\Updates\Migration;
use Schema;

class RemoveGroupInitialStatus extends Migration
{
    public function up()
    {
        Schema::table('offline_gdpr_cookie_groups', function ($table) {
            $table->dropColumn(['initial_status']);
        });
    }

    public function down()
    {
        Schema::table('offline_gdpr_cookie_groups', function ($table) {
            $table->boolean('initial_status')->default(1);
        });
    }
}
