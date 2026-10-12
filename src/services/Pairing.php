<?php

namespace moqo\watchdog\services;

use Craft;
use craft\db\Query;
use yii\base\Component;

/**
 * Pairing with a Watchdog server from the control panel, as an alternative to putting a key in .env.
 *
 * The server starts with a secret "verifier" and sends only its SHA-256, the "challenge", through
 * the browser. An admin approves that challenge here; the plugin then creates a key and hands it
 * out once, to whoever can show the verifier. The key never passes through a URL or the browser.
 */
class Pairing extends Component
{
    public const TABLE = '{{%watchdog_pairings}}';

    /** How long an approved pairing waits to be collected by the server. */
    public const CLAIM_SECONDS = 600;

    /**
     * The short code shown both here and in the Watchdog app. An admin who did not just start a
     * pairing themselves sees a code that matches nothing, and should not approve.
     */
    public static function code(string $challenge): string
    {
        return implode('-', str_split(strtoupper(substr(hash('sha256', 'watchdog-pair:' . strtolower($challenge)), 0, 6)), 3));
    }

    public static function isChallenge(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1;
    }

    public function tableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }

    public function approve(string $challenge, string $label, ?int $userId): void
    {
        $db = Craft::$app->getDb();
        $key = Craft::$app->getSecurity()->generateRandomString(48);

        // Approving the same request twice replaces the first key rather than leaving two around.
        $db->createCommand()->delete(self::TABLE, ['challenge' => strtolower($challenge)])->execute();
        $db->createCommand()->insert(self::TABLE, [
            'challenge' => strtolower($challenge),
            'label' => mb_substr($label, 0, 255),
            'keyHash' => hash('sha256', $key),
            // Kept only until the server collects it; encrypted with Craft's security key meanwhile.
            'pendingKey' => base64_encode(Craft::$app->getSecurity()->encryptByKey($key)),
            'approvedBy' => $userId,
            'approvedAt' => time(),
        ])->execute();
    }

    /** Hands the key to the holder of the verifier, once. Null when there is nothing to collect. */
    public function claim(string $verifier): ?string
    {
        if (!$this->tableExists()) {
            return null;
        }
        $row = (new Query())
            ->from(self::TABLE)
            ->where(['challenge' => hash('sha256', $verifier)])
            ->andWhere(['not', ['pendingKey' => null]])
            ->andWhere(['>=', 'approvedAt', time() - self::CLAIM_SECONDS])
            ->one();
        if (!$row) {
            return null;
        }

        $key = Craft::$app->getSecurity()->decryptByKey(base64_decode((string)$row['pendingKey']));
        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, ['pendingKey' => null, 'claimedAt' => time()], ['id' => $row['id']])
            ->execute();

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** Whether this is the key of a pairing that was approved and collected. */
    public function accepts(string $candidate): bool
    {
        if ($candidate === '' || !$this->tableExists()) {
            return false;
        }
        $row = (new Query())
            ->select(['id', 'lastUsedAt'])
            ->from(self::TABLE)
            ->where(['keyHash' => hash('sha256', $candidate)])
            ->andWhere(['not', ['claimedAt' => null]])
            ->one();
        if (!$row) {
            return false;
        }
        // Good enough to show "last used"; not worth a write on every request.
        if ((int)$row['lastUsedAt'] < time() - 3600) {
            Craft::$app->getDb()->createCommand()->update(self::TABLE, ['lastUsedAt' => time()], ['id' => $row['id']])->execute();
        }

        return true;
    }

    public function hasKeys(): bool
    {
        return $this->tableExists() && (new Query())->from(self::TABLE)->where(['not', ['claimedAt' => null]])->exists();
    }

    /** Active pairings, newest first, for the settings page. */
    public function all(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return (new Query())
            ->select(['id', 'label', 'approvedBy', 'approvedAt', 'claimedAt', 'lastUsedAt'])
            ->from(self::TABLE)
            ->where(['not', ['claimedAt' => null]])
            ->orderBy(['approvedAt' => SORT_DESC])
            ->all();
    }

    public function revoke(int $id): void
    {
        Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['id' => $id])->execute();
    }

    /** Approved requests that nobody collected in time still hold a key; remove them. */
    public function prune(): void
    {
        if ($this->tableExists()) {
            Craft::$app->getDb()->createCommand()
                ->delete(self::TABLE, ['and', ['claimedAt' => null], ['<', 'approvedAt', time() - self::CLAIM_SECONDS]])
                ->execute();
        }
    }
}
