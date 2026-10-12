<?php

namespace moqo\watchdog;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\helpers\App;
use craft\services\Gc;
use moqo\watchdog\models\Settings;
use moqo\watchdog\services\Formie;
use moqo\watchdog\services\Freeform;
use moqo\watchdog\services\Mail;
use moqo\watchdog\services\Pairing;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * @property-read Mail $mail
 * @property-read Formie $formie
 * @property-read Freeform $freeform
 * @property-read Pairing $pairing
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const MIN_KEY_LENGTH = 32;

    /** Value put in test submissions, so they are recognisable in the CP and in mails. */
    public const TEST_VALUE = 'Watchdog test';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'mail' => Mail::class,
            'formie' => Formie::class,
            'freeform' => Freeform::class,
            'pairing' => Pairing::class,
        ]);

        $this->mail->listen();
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->mail->prune();
            $this->pairing->prune();
        });

        // The page an admin lands on after clicking "Verbinden" in the Watchdog app.
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event) {
            $event->rules['watchdog/pair'] = 'watchdog/pair/index';
        });
    }

    /** The configured key, or null while it is missing, an unresolved env reference, or too short. */
    public function getApiKey(): ?string
    {
        $raw = trim($this->getSettings()->apiKey);
        $key = App::parseEnv($raw);

        // Craft 4 hands an undefined "$VAR" back unchanged; that is not a key.
        if (!is_string($key) || (str_starts_with($raw, '$') && $key === $raw)) {
            return null;
        }

        return strlen($key) >= self::MIN_KEY_LENGTH ? $key : null;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('watchdog/settings', [
            'settings' => $this->getSettings(),
            'keyOk' => $this->getApiKey() !== null,
            'pairings' => $this->pairing->all(),
            'minLength' => self::MIN_KEY_LENGTH,
            'overridden' => array_key_exists('apiKey', Craft::$app->getConfig()->getConfigFromFile('watchdog')),
        ]);
    }
}
