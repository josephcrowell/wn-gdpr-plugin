<?php namespace JosephCrowell\GDPR\Updates;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use System\Behaviors\SettingsModel;
use Winter\Storm\Database\Updates\Migration;

class RenameGdprStorage extends Migration
{
    private const TABLES = [
        'offline_gdpr_cookie_groups' => 'josephcrowell_gdpr_cookie_groups',
        'offline_gdpr_cookies' => 'josephcrowell_gdpr_cookies',
        'offline_gdpr_logs' => 'josephcrowell_gdpr_logs',
    ];

    private const SETTINGS = [
        'offline_gdpr_general_settings' => 'josephcrowell_gdpr_general_settings',
        'offline_gdpr_cookie_consent_settings' => 'josephcrowell_gdpr_cookie_consent_settings',
        'offline_gdpr_data_retention_settings' => 'josephcrowell_gdpr_data_retention_settings',
    ];

    public function up()
    {
        $this->renameStorage(self::TABLES, self::SETTINGS);
    }

    public function down()
    {
        $this->renameStorage(array_flip(self::TABLES), array_flip(self::SETTINGS));
    }

    private function renameStorage(array $tables, array $settings): void
    {
        // Check every source/destination before making changes. Never overwrite
        // another installation's tables or saved settings.
        foreach ($tables as $source => $destination) {
            $sourceExists = Schema::hasTable($source);
            $destinationExists = Schema::hasTable($destination);

            if ($sourceExists && $destinationExists) {
                throw new RuntimeException("Cannot rename GDPR storage: both {$source} and {$destination} exist.");
            }

            if (!$sourceExists && !$destinationExists) {
                throw new RuntimeException("Cannot rename GDPR storage: neither {$source} nor {$destination} exists.");
            }
        }

        if (!Schema::hasTable('system_settings')) {
            throw new RuntimeException('Cannot rename GDPR settings: system_settings does not exist.');
        }

        foreach ($settings as $source => $destination) {
            if (
                DB::table('system_settings')->where('item', $source)->exists()
                && DB::table('system_settings')->where('item', $destination)->exists()
            ) {
                throw new RuntimeException("Cannot rename GDPR settings: both {$source} and {$destination} exist.");
            }
        }

        // DDL may auto-commit on MySQL. Already renamed tables are skipped so
        // the migration can safely resume after a partial failure.
        foreach ($tables as $source => $destination) {
            if (Schema::hasTable($source)) {
                Schema::rename($source, $destination);
            }
        }

        DB::transaction(function () use ($settings) {
            foreach ($settings as $source => $destination) {
                DB::table('system_settings')->where('item', $source)->update(['item' => $destination]);
            }
        });

        foreach ($settings as $source => $destination) {
            Cache::forget('system::settings.' . $source);
            Cache::forget('system::settings.' . $destination);
        }
        SettingsModel::clearInternalCache();
    }
}
