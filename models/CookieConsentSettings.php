<?php namespace JosephCrowell\GDPR\Models;

use Model;

class CookieConsentSettings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];
    public $settingsCode = 'josephcrowell_gdpr_cookie_consent_settings';
    public $settingsFields = 'fields.yaml';
}
