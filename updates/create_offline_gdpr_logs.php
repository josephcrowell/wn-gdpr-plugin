<?php namespace JosephCrowell\GDPR\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateOfflineGdprLogs extends Migration
{
    public function up()
    {
        if (Schema::hasTable('offline_gdpr_logs')) {
            return;
        }

        Schema::create('offline_gdpr_logs', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->string('session_id');
            $table->string('decision');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('offline_gdpr_logs');
    }
}
